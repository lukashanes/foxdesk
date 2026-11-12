<?php

/**
 * PHP-written timestamps compared on the PHP clock, against a real MariaDB
 * whose session clock is UTC while PHP runs in Europe/Prague.
 *
 * Every case puts a row just past a boundary (e.g. "expired 30 minutes ago").
 * With MySQL NOW() in UTC the old queries got these wrong by 1-2 hours.
 *
 * Run (needs a disposable MariaDB database; it creates and drops its own tables):
 *   FOXDESK_DB_CLOCK_INTEGRATION=1 DB_HOST=... DB_USER=... DB_PASS=... DB_NAME=... \
 *     php tests/db-clock-integration-test.php
 */

if (getenv('FOXDESK_DB_CLOCK_INTEGRATION') !== '1') {
    echo "DB clock integration skipped (set FOXDESK_DB_CLOCK_INTEGRATION=1)\n";
    exit(0);
}

date_default_timezone_set('Europe/Prague');

foreach (['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_NAME' => 'foxdesk_db_clock_test', 'DB_USER' => 'root', 'DB_PASS' => ''] as $name => $default) {
    $value = getenv($name);
    define($name, $value === false ? $default : $value);
}

$root = dirname(__DIR__);
require_once $root . '/includes/database.php';

function ticket_access_table_exists() { return false; }

require_once $root . '/includes/report-functions.php';
require_once $root . '/includes/ticket-share-functions.php';
require_once $root . '/includes/ticket-query-functions.php';
require_once $root . '/includes/microsoft-mail-functions.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $message . PHP_EOL;
    if (!$ok) {
        $failures[] = $message;
    }
};

$db = get_db();
$db->exec("SET time_zone = '+00:00'");
$offset = (int) (new DateTime())->getOffset();
if ($offset === 0) {
    fwrite(STDERR, "PHP must run in a non-UTC zone (Europe/Prague) for this test.\n");
    exit(1);
}
$db_drift = strtotime(date('Y-m-d H:i:s')) - strtotime((string) $db->query('SELECT NOW()')->fetchColumn());
if (abs($db_drift - $offset) > 5) {
    fwrite(STDERR, "Precondition: MySQL NOW() must be UTC while PHP date() is local.\n");
    exit(1);
}

$tables = ['users', 'organizations', 'report_templates', 'report_shares', 'tickets', 'email_provider_connections'];
foreach ($tables as $table) {
    $db->exec("DROP TABLE IF EXISTS {$table}");
}
$db->exec('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(190), reset_token VARCHAR(128) NULL, reset_token_expires DATETIME NULL, is_active TINYINT DEFAULT 1)');
$db->exec('CREATE TABLE organizations (id INT PRIMARY KEY, name VARCHAR(190))');
$db->exec('CREATE TABLE report_templates (id INT AUTO_INCREMENT PRIMARY KEY, uuid VARCHAR(64), organization_id INT, is_draft TINYINT DEFAULT 0, is_archived TINYINT DEFAULT 0, expires_at DATETIME NULL)');
$db->exec('CREATE TABLE report_shares (id INT AUTO_INCREMENT PRIMARY KEY, organization_id INT, report_template_id INT NULL, token_hash VARCHAR(64), is_revoked TINYINT DEFAULT 0, expires_at DATETIME NULL, created_at DATETIME)');
$db->exec('CREATE TABLE tickets (id INT PRIMARY KEY, assignee_id INT NULL, due_date DATETIME NULL, created_at DATETIME)');
$db->exec("CREATE TABLE email_provider_connections (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT, provider VARCHAR(30), tenant_identifier VARCHAR(190), client_id VARCHAR(190), client_secret_ciphertext TEXT NULL, status VARCHAR(30), oauth_state_hash VARCHAR(64) NULL, oauth_state_expires_at DATETIME NULL, code_verifier_ciphertext TEXT NULL, created_by INT NULL, last_error TEXT NULL, created_at DATETIME, updated_at DATETIME, UNIQUE KEY uniq_tenant_provider (tenant_id, provider))");

$now = time();
$at = static fn(int $seconds): string => date('Y-m-d H:i:s', $now + $seconds);

// 1) Password reset page query: a link that expired 30 minutes ago must not work.
$reset_source = (string) file_get_contents($root . '/pages/reset-password.php');
preg_match('/db_fetch_one\("(SELECT id, email FROM users WHERE reset_token = \?[^"]*)", \[\$token_hash(, foxdesk_db_datetime\(\))?\]\)/', $reset_source, $reset_match);
$check(!empty($reset_match[2]), 'Reset page passes the PHP clock as the expiry bound');
db_insert('users', ['id' => 1, 'email' => 'expired@example.test', 'reset_token' => 'expired-hash', 'reset_token_expires' => $at(-1800), 'is_active' => 1]);
db_insert('users', ['id' => 2, 'email' => 'valid@example.test', 'reset_token' => 'valid-hash', 'reset_token_expires' => $at(1800), 'is_active' => 1]);
$reset_lookup = static function (string $hash) use ($reset_match) {
    $params = !empty($reset_match[2]) ? [$hash, foxdesk_db_datetime()] : [$hash];
    return db_fetch_one($reset_match[1] ?? 'SELECT 1 WHERE 0', $params);
};
$check(empty($reset_lookup('expired-hash')), 'Password reset link expired 30 min ago is rejected');
$check((int) ($reset_lookup('valid-hash')['id'] ?? 0) === 2, 'Password reset link valid for 30 more min still works');

// 2) Public report link (UUID) that expired 30 minutes ago is no longer served.
db_insert('organizations', ['id' => 7, 'name' => 'Client']);
db_insert('report_templates', ['uuid' => 'expired-report', 'organization_id' => 7, 'expires_at' => $at(-1800)]);
db_insert('report_templates', ['uuid' => 'valid-report', 'organization_id' => 7, 'expires_at' => $at(1800)]);
$check(empty(get_report_template_by_uuid('expired-report')), 'Report expired 30 min ago is not served');
$check(!empty(get_report_template_by_uuid('valid-report')), 'Report valid for 30 more min is served');
db_insert('report_shares', ['organization_id' => 7, 'token_hash' => str_repeat('a', 64), 'expires_at' => $at(-1800), 'created_at' => $at(-7200)]);
$check(empty(get_active_report_share(7)), 'Report share expired 30 min ago is not active');
db_insert('report_shares', ['organization_id' => 7, 'token_hash' => str_repeat('b', 64), 'expires_at' => $at(1800), 'created_at' => $at(-3600)]);
$check((string) (get_active_report_share(7)['token_hash'] ?? '') === str_repeat('b', 64), 'Report share valid for 30 more min is active');

// 3) Ticket list filters: overdue, due today, this week, "new" window.
//    The application clock is pinned to 00:30 local time three days ahead, so
//    the result can only be right if the SQL follows the PHP clock.
$GLOBALS['foxdesk_clock_override'] = strtotime(date('Y-m-d 00:30:00', strtotime('+3 days', $now)));
$clock = $GLOBALS['foxdesk_clock_override'];
$tickets = [
    [1, null, date('Y-m-d H:i:s', $clock - 600), date('Y-m-d H:i:s', $clock - 3600)],
    [2, null, date('Y-m-d 23:00:00', $clock), date('Y-m-d H:i:s', strtotime('-47 hours', $clock))],
    [3, null, date('Y-m-d 23:00:00', strtotime('-1 day', $clock)), date('Y-m-d H:i:s', strtotime('-49 hours', $clock))],
];
foreach ($tickets as [$id, $assignee, $due, $created]) {
    db_insert('tickets', ['id' => $id, 'assignee_id' => $assignee, 'due_date' => $due, 'created_at' => $created]);
}
$ids_for = static function (array $filters): array {
    $params = [];
    $where = build_ticket_where_clause($filters, $params);
    return array_map('intval', array_column(db_fetch_all('SELECT t.id FROM tickets t' . $where . ' ORDER BY t.id', $params), 'id'));
};
$check($ids_for(['due_date_filter' => 'overdue']) === [1, 3], 'Overdue = due before the local "now"');
$check($ids_for(['due_date_filter' => 'today']) === [1, 2], 'Due today uses the local date right after midnight');
[$monday, $sunday] = foxdesk_db_week_bounds();
$expected_week = array_values(array_filter([1, 2, 3], static function (int $id) use ($tickets, $monday, $sunday): bool {
    $day = substr($tickets[$id - 1][2], 0, 10);
    return $day >= $monday && $day <= $sunday;
}));
$check($ids_for(['due_date_filter' => 'this_week']) === $expected_week, 'Due this week uses the local Monday-Sunday week');
$check($ids_for(['triage_state' => 'new']) === [1, 2], 'New = unassigned and created within the local 48-hour window');
$check($ids_for(['triage_state' => 'open']) === [3], 'Open = assigned or older than the 48-hour window');
unset($GLOBALS['foxdesk_clock_override']);

// 4) Microsoft OAuth state: stored expiry must be 10 minutes ahead on the
//    clock that later checks it (strtotime() vs time()).
putenv('MICROSOFT_MAIL_CLIENT_SECRET=env-managed-secret');
microsoft_mail_save_pending('contoso.onmicrosoft.com', 'client-id', 'unused', hash('sha256', 'state'), 'verifier', null);
$connection = microsoft_mail_connection();
$state_expires = strtotime((string) ($connection['oauth_state_expires_at'] ?? ''));
$check($state_expires !== false && $state_expires >= time() + 590 && $state_expires <= time() + 610, 'Microsoft OAuth state stays valid for 10 minutes on the PHP clock');

foreach ($tables as $table) {
    $db->exec("DROP TABLE IF EXISTS {$table}");
}

if ($failures) {
    fwrite(STDERR, count($failures) . " DB clock check(s) failed.\n");
    exit(1);
}
echo "DB clock integration OK\n";
