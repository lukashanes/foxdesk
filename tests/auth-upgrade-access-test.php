<?php
// Real authentication helpers over a pre-migration schema; no DB connection.
define('BASE_PATH', dirname(__DIR__));
$has_epoch = false;
$admin = ['id' => 1, 'role' => 'admin', 'is_active' => 1,
    'email' => 'admin@example.test', 'first_name' => 'Test', 'last_name' => 'Admin',
    'password' => password_hash('test-password', PASSWORD_DEFAULT)];
function column_exists($table, $column) { return $column === 'auth_version' && $GLOBALS['has_epoch']; }
function schema_require($context, $tables = [], $columns = []) {
    if (isset($columns['users']) && in_array('auth_version', $columns['users'], true) && !$GLOBALS['has_epoch']) {
        throw new RuntimeException('Upgrade required: auth_version');
    }
}
function db_fetch_one($sql, $params = []) { return $GLOBALS['admin']; }
function foxdesk_effective_user_language($user) { return 'en'; }
require BASE_PATH . '/includes/auth.php';
session_start();
$_SESSION = ['user_id' => 1];
if (!is_logged_in() || !is_admin()) throw new RuntimeException('Existing admin cannot reach the updater before migration.');
$_SESSION = [];
if (!login('admin@example.test', 'test-password') || !is_admin()) throw new RuntimeException('Fresh admin login fails before migration.');
try {
    update_password(1, 'new-test-password');
    throw new LogicException('Credential revocation must still require the migration.');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'Upgrade required: auth_version') throw $e;
}
$has_epoch = true;
$admin['auth_version'] = 0;
if (!current_user(true)) throw new RuntimeException('Migration to epoch zero invalidated the admin.');
$admin['auth_version'] = 1;
if (current_user(true) !== null || $_SESSION !== []) throw new RuntimeException('Reset failed to revoke a legacy session.');
// Missing data on an upgraded schema must not be mistaken for a legacy row.
$_SESSION = ['user_id' => 1];
unset($admin['auth_version']);
if (current_user(true) !== null) throw new RuntimeException('Incomplete upgraded user row was accepted.');
echo "Pre-migration admin access and post-migration revocation OK\n";
