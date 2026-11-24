<?php
define('BASE_PATH', dirname(__DIR__));
ini_set('session.save_path', sys_get_temp_dir());
session_start();
$users = [1 => ['id' => 1, 'email' => 'TEST@example.test', 'first_name' => 'TEST', 'last_name' => '',
    'role' => 'user', 'is_active' => 1, 'auth_version' => 3, 'totp_enabled' => 0, 'remember_token' => 'TEST-old']];
$tokens = [];
$queries = [];
class EpochTransaction {
    public bool $active = false;
    function inTransaction() { return $this->active; }
    function beginTransaction() { $this->active = true; }
    function commit() { $this->active = false; }
    function rollBack() { $this->active = false; }
}
$db = new EpochTransaction();
function get_db() { return $GLOBALS['db']; }
function schema_require(...$args) {}
function schema_is_ready(...$args) { return true; }
function table_exists($table) { return true; }
function column_exists(...$args) { return true; }
function get_settings() { return []; }
function foxdesk_effective_user_language($user) { return 'en'; }
function foxdesk_db_datetime() { return '2026-11-24 12:00:00'; }
function db_fetch_one($sql, $params = []) {
    if (str_contains($sql, 'FROM api_tokens')) return $GLOBALS['tokens'][0] ?? null;
    if (str_contains($sql, 'WHERE remember_token = ?')) return $GLOBALS['users'][1];
    return $GLOBALS['users'][(int) $params[0]] ?? null;
}
function db_query($sql, $params = []) {
    $GLOBALS['queries'][] = $sql;
    if (str_contains($sql, 'auth_version = auth_version + 1')) $GLOBALS['users'][1]['auth_version']++;
    return true;
}
function db_update($table, $data, $where, $params = []) {
    $GLOBALS['queries'][] = $where;
    if (!empty($GLOBALS['remember_race']) && isset($data['remember_token'])) return 0;
    return 1;
}
function db_insert($table, $data) { $GLOBALS['tokens'][] = $data + ['id' => 7]; return 7; }
require BASE_PATH . '/includes/auth.php';
$assert = static function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
$_SESSION = ['user_id' => 1, 'auth_version' => 2];
$assert(update_password(1, 'TEST-password') === false, 'Revoked password proof must not undo recovery.');
$assert($users[1]['auth_version'] === 3 && empty($_SESSION), 'Stale password change must log out without mutation.');
$_SESSION = ['user_id' => 1, 'auth_version' => 3];
$assert(update_password(1, 'TEST-password') === 1, 'Self password change must work.');
$assert($users[1]['auth_version'] === 4 && $_SESSION['auth_version'] === 4, 'Self password change keeps only this refreshed session.');
$token = generate_api_token(1, 'TEST-epoch', null, ['tickets:read']);
$assert($tokens[0]['auth_version'] === 4, 'New API credential must record current epoch.');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token['token'];
$assert(authenticate_api_token() !== null, 'Fresh API credential should work.');
$users[1]['auth_version'] = 5;
$assert(authenticate_api_token() === null, 'API token read before a concurrent reset must be rejected.');
$_SESSION = ['user_id' => 1, 'auth_version' => 5];
$GLOBALS['remember_race'] = true;
$assert(!set_remember_token(1), 'Concurrent reset must prevent remember token issuance.');
$assert(str_contains(implode("\n", $queries), 'auth_version = ?'), 'Remember token write must compare epoch atomically.');
$_COOKIE['foxdesk_remember'] = str_repeat('a', 64);
$_SESSION = [];
$assert(!validate_remember_token() && empty($_SESSION), 'Failed remember rotation must not leave an authenticated session.');
echo "Password changes, API credential epochs and remember-token races OK\n";
