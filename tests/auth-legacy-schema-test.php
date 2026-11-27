<?php
// Execute SQL-building boundaries with/without the optional legacy deleted_at column.
// No database, SMTP or HTTP connection is made.
define('BASE_PATH', dirname(__DIR__));
$has_deleted_at = false;
$queries = [];
class LegacyAuthTransaction {
    public bool $active = false;
    function inTransaction() { return $this->active; }
    function beginTransaction() { $this->active = true; }
    function commit() { $this->active = false; }
    function rollBack() { $this->active = false; }
}
$db = new LegacyAuthTransaction();
function get_db() { return $GLOBALS['db']; }
function schema_require(...$args) {}
function column_exists($table, $column) { return $column !== 'deleted_at' || $GLOBALS['has_deleted_at']; }
function table_exists($table) { return false; }
function foxdesk_db_datetime() { return '2026-11-25 12:00:00'; }
function db_query(...$args) { return true; }
function db_fetch_one($sql, $params = []) {
    $GLOBALS['queries'][] = $sql;
    if (!$GLOBALS['has_deleted_at'] && str_contains($sql, 'deleted_at')) throw new RuntimeException('Unknown column deleted_at');
    return ['id' => 2, 'auth_version' => 3, 'role' => 'admin', 'is_active' => 1];
}
require BASE_PATH . '/includes/auth.php';
require BASE_PATH . '/includes/security-helpers.php';
$login = file_get_contents(BASE_PATH . '/pages/login.php');
$start = strpos($login, '                $user_sql = ');
if ($start === false) $start = strpos($login, '                $user_row = db_fetch_one(');
$end = strpos($login, '                $valid = false;', $start);
$login_query = substr($login, $start, $end - $start);
foreach ([false, true] as $has_deleted_at) {
    $queries = [];
    $_SESSION = ['user_id' => 1, 'auth_version' => 0, 'impersonator_id' => 2, 'impersonator_auth_version' => 3];
    if (!auth_session_version_matches(['auth_version' => 0])) throw new RuntimeException('Valid impersonation rejected.');
    if (!password_reset_complete(['id' => 1], 'TEST-hash', 'TEST-token')) throw new RuntimeException('Reset rejected.');
    $pending = ['user_id' => 1];
    eval($login_query);
    if (count($queries) !== 3) throw new RuntimeException('A query boundary was not exercised.');
    foreach ($queries as $query) {
        if (str_contains($query, 'deleted_at IS NULL') !== $has_deleted_at) throw new RuntimeException('Soft deletion filter was omitted or required on a legacy schema.');
    }
}
$valid = ['auth_version' => 3, 'role' => 'admin', 'is_active' => 1];
foreach ([$valid + ['deleted_at' => '2026-11-25'], array_replace($valid, ['role' => 'user']), array_replace($valid, ['is_active' => 0])] as $invalid) {
    if (auth_impersonator_version_matches($invalid)) throw new RuntimeException('Unavailable/demoted administrator kept impersonation.');
}
echo "Legacy-schema session, password reset, 2FA and impersonator boundaries OK (SQL substitutes)\n";
