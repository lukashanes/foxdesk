<?php

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/locale-functions.php';
require_once BASE_PATH . '/includes/security-helpers.php';

// Use the real login query, password verification and session lifecycle in memory.
$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database->exec('CREATE TABLE users (
    id INTEGER, email TEXT, password TEXT, first_name TEXT, last_name TEXT,
    role TEXT, language TEXT, is_active INTEGER, deleted_at TEXT
)');
function db_fetch_one(string $sql, array $params): ?array
{
    $statement = $GLOBALS['database']->prepare($sql);
    $statement->execute($params);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function column_exists(string $table, string $column): bool
{
    return $table === 'users' && $column === 'deleted_at';
}

$password = bin2hex(random_bytes(24));
$hash = password_hash($password, PASSWORD_DEFAULT);
$insert = $database->prepare('INSERT INTO users VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insert->execute([1, 'active@example.test', $hash, 'Test', 'Agent', 'agent', 'en', 1, null]);
$insert->execute([2, 'disabled@example.test', $hash, 'Test', 'Agent', 'agent', 'en', 0, null]);
$insert->execute([3, 'removed@example.test', $hash, 'Test', 'Agent', 'agent', 'en', 1, '2026-01-01']);

ini_set('session.use_cookies', '0');
session_cache_limiter('');
session_set_save_handler(
    static fn (): bool => true,
    static fn (): bool => true,
    static fn (): string => '',
    static fn (): bool => true,
    static fn (): bool => true,
    static fn (): int => 0
);
if (!session_start()) {
    throw new RuntimeException('Could not start the in-memory test session.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$anonymous_session = session_id();
foreach ([
    'unknown account' => ['unknown@example.test', $password],
    'wrong password' => ['active@example.test', $password . '-wrong'],
    'disabled account' => ['disabled@example.test', $password],
    'removed account' => ['removed@example.test', $password],
] as $case => [$email, $input]) {
    $assert(!login($email, $input), 'Login must reject ' . $case);
    $assert(!is_logged_in() && session_id() === $anonymous_session, 'Failed login must preserve the anonymous session.');
}

$assert(login('active@example.test', $password), 'Login must accept the active account.');
$assert(is_logged_in() && session_id() !== $anonymous_session, 'Successful login must rotate the session ID.');
$assert(current_user()['id'] === 1 && is_agent() && !is_admin(), 'The session must resolve the active agent with its original role.');

$token = csrf_token();
foreach ([
    'matching form token' => [$token, null, true],
    'matching header token' => [null, $token, true],
    'missing token' => [null, null, false],
    'form token takes precedence' => ['different-token', $token, false],
    'non-string form token' => [[], $token, false],
] as $case => [$posted, $header, $expected]) {
    $_POST = $posted === null ? [] : ['csrf_token' => $posted];
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $header ?? '';
    $assert(csrf_is_valid() === $expected, $case);
}

session_destroy();
echo "Authentication and CSRF tests passed (10 cases).\n";
