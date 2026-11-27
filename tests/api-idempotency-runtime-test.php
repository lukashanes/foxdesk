<?php

require_once dirname(__DIR__) . '/includes/auth.php';

$db = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('CREATE TABLE api_idempotency_keys (
    id INTEGER PRIMARY KEY, token_id INTEGER, user_id INTEGER, idempotency_key TEXT,
    action TEXT, request_hash TEXT, response_json TEXT, status_code INTEGER,
    created_at TEXT, expires_at TEXT, UNIQUE(token_id, action, idempotency_key)
)');
$db->exec('CREATE TABLE fixture_writes (id INTEGER PRIMARY KEY)');

function get_db(): PDO
{
    return $GLOBALS['db'];
}

function table_exists($table): bool
{
    return $table === 'api_idempotency_keys';
}

function db_insert($table, $data): int
{
    $columns = implode(', ', array_keys($data));
    $parameters = implode(', ', array_fill(0, count($data), '?'));
    get_db()->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$parameters})")->execute(array_values($data));
    return (int) get_db()->lastInsertId();
}

function db_update($table, $data, $where, $params): void
{
    $assignments = implode(', ', array_map(static fn($column) => "{$column} = ?", array_keys($data)));
    get_db()->prepare("UPDATE {$table} SET {$assignments} WHERE {$where}")->execute(array_merge(array_values($data), $params));
}

function ticket_permanent_delete_run_after_commit_callbacks(): void
{
    foreach ($GLOBALS['ticket_after_commit_callbacks'] ?? [] as $callback) {
        $callback();
    }
    $GLOBALS['ticket_after_commit_callbacks'] = [];
}

function api_error($message, $code): void
{
    throw new DomainException($message, $code);
}

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'ok   ' : 'FAIL ') . $message . PHP_EOL;
    $failures += $condition ? 0 : 1;
};

$GLOBALS['api_token_row'] = ['id' => 21, 'user_id' => 7];
$action = 'agent-update-work-entry';
$callback_runs = 0;
$callback = static function () use (&$callback_runs): void { $callback_runs++; };
$write = static function (string $key, int $id) use ($action, $callback): void {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $key;
    api_idempotency_replay_if_available($action);
    get_db()->prepare('INSERT INTO fixture_writes VALUES (?)')->execute([$id]);
    $GLOBALS['ticket_after_commit_callbacks'][] = $callback;
};

$write('valid-key', 1);
api_idempotency_store_success(['success' => true, 'label' => 'Příliš žluťoučký']);
$saved = $db->query("SELECT response_json, status_code FROM api_idempotency_keys WHERE idempotency_key = 'valid-key'")->fetch();
$check(!$db->inTransaction() && $callback_runs === 1
    && (int) $db->query('SELECT COUNT(*) FROM fixture_writes')->fetchColumn() === 1
    && (int) $saved['status_code'] === 200
    && json_decode($saved['response_json'], true)['label'] === 'Příliš žluťoučký',
    'A valid response commits the write and reservation before callbacks run.');

$write('abandoned-key', 2);
api_idempotency_release_pending();
$check(!$db->inTransaction() && $callback_runs === 1
    && (int) $db->query('SELECT COUNT(*) FROM fixture_writes WHERE id = 2')->fetchColumn() === 0
    && (int) $db->query("SELECT COUNT(*) FROM api_idempotency_keys WHERE idempotency_key = 'abandoned-key'")->fetchColumn() === 0,
    'Releasing an abandoned write rolls back its side effects and reservation.');

$write('retry-key', 3);
$encoding_failed = false;
try {
    api_idempotency_store_success(['success' => true, 'label' => "\xFF"]);
} catch (RuntimeException $e) {
    $encoding_failed = $e->getPrevious() instanceof JsonException;
}
$rolled_back = !$db->inTransaction() && $callback_runs === 1
    && (int) $db->query('SELECT COUNT(*) FROM fixture_writes WHERE id = 3')->fetchColumn() === 0
    && (int) $db->query("SELECT COUNT(*) FROM api_idempotency_keys WHERE idempotency_key = 'retry-key'")->fetchColumn() === 0;
$check($encoding_failed && $rolled_back, 'An unencodable response rolls back the write with its reservation and callbacks.');
if ($rolled_back) {
    $write('retry-key', 3);
    api_idempotency_store_success(['success' => true]);
}
$check($rolled_back && $callback_runs === 2
    && (int) $db->query('SELECT COUNT(*) FROM fixture_writes WHERE id = 3')->fetchColumn() === 1,
    'The original key can be retried and commits once after serialization recovers.');

$GLOBALS['is_api_token_auth'] = true;
$GLOBALS['api_token_row']['scopes_json'] = '["tickets:read"]';
$status_for = static function (string $action): int {
    try {
        api_token_enforce_action_scope($action);
        return 200;
    } catch (DomainException $e) {
        return $e->getCode();
    }
};
$check($status_for('agent-get-ticket') === 200, 'A read-scoped key can access the ticket read action.');
$check($status_for('agent-create-ticket') === 403, 'A read-scoped key cannot access the ticket write action.');
$check($status_for('app-notification-review') === 403, 'A token cannot access a session-only action.');
$check($status_for('agent-docs') === 200, 'A restricted key can still read its own API documentation.');
$GLOBALS['api_token_row']['scopes_json'] = '["notifications:write"]';
$check($status_for('app-notification-read-state') === 200 && $status_for('app-notifications') === 403,
    'Notification write permission is independent of read permission.');

if ($failures > 0) {
    exit(1);
}
