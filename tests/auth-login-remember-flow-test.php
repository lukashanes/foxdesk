<?php
// Run the actual login page (including its exit), with local identity/storage substitutes.
define('BASE_PATH', dirname(__DIR__));
$mode = $argv[1] ?? 'password';
$epoch = $mode === 'legacy-totp' ? 0 : 3;
$user = ['id' => 1, 'role' => 'user', 'is_active' => 1, 'auth_version' => 3,
    'email' => 'member@example.test', 'first_name' => 'Test', 'last_name' => 'Member',
    'password' => password_hash('test-password', PASSWORD_DEFAULT),
    'totp_enabled' => in_array($mode, ['totp', 'backup', 'legacy-totp'], true) ? 1 : 0,
    'totp_secret' => 'JBSWY3DPEHPK3PXP',
    'totp_backup_codes' => json_encode([hash('sha256', 'abcd-1234')])];
$remember_hash = null;
if ($mode === 'legacy-totp') unset($user['auth_version']);
function column_exists($table, $column) { return $column !== 'auth_version' || $GLOBALS['mode'] !== 'legacy-totp'; }
function schema_require(...$args) {}
function schema_is_ready(...$args) { return true; }
function get_settings() { return ['2fa_required_user' => $GLOBALS['mode'] === 'required' ? '1' : '0']; }
function db_fetch_one($sql, $params = []) {
    if ($GLOBALS['mode'] === 'legacy-totp' && str_contains($sql, ', auth_version FROM')) throw new RuntimeException('Unknown column auth_version.');
    return $GLOBALS['user'];
}
function db_update($table, $data, $where, $params = []) {
    if (isset($data['remember_token'])) {
        if (($_SESSION['user_id'] ?? 0) !== 1 || ($_SESSION['auth_version'] ?? 0) !== $GLOBALS['epoch']) throw new RuntimeException('Token issued before session identity.');
        $GLOBALS['remember_hash'] = $data['remember_token'];
    }
    $GLOBALS['user'] = array_replace($GLOBALS['user'], $data);
    return 1;
}
function foxdesk_effective_user_language($user) { return 'en'; }
function get_flash() { return []; }
function get_app_language() { return 'en'; }
function get_supported_languages() { return []; }
function csrf_is_valid() { return true; }
function rate_limit_key(...$args) { return 'test-login'; }
function rate_limit_is_blocked(...$args) { return false; }
function rate_limit_clear(...$args) {}
function log_security_event(...$args) {}
require BASE_PATH . '/includes/auth.php';
require BASE_PATH . '/includes/totp.php';
session_start();
$_SESSION = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTPS'] = 'on';
$_POST = ['email' => $user['email'], 'password' => 'test-password', 'remember_me' => '1'];
if (in_array($mode, ['totp', 'backup', 'legacy-totp'], true)) {
    $_SESSION['2fa_pending'] = ['user_id' => 1, 'auth_version' => $epoch, 'user_email' => $user['email'],
        'user_name' => 'Test Member', 'user_role' => 'user', 'lang' => 'en', 'remember_me' => true, 'timestamp' => time()];
    $_POST = ['verify_2fa' => '1', 'code' => $mode === 'backup' ? 'abcd-1234' : totp_get_code($user['totp_secret'])];
}
register_shutdown_function(function () use ($mode) {
    if (error_get_last() && in_array(error_get_last()['type'], [E_ERROR, E_PARSE], true)) return;
    if (($_SESSION['user_id'] ?? 0) !== 1 || ($_SESSION['auth_version'] ?? 0) !== $GLOBALS['epoch']) throw new RuntimeException('Login did not complete: ' . $mode);
    $cookies = implode("\n", headers_list());
    if ($mode === 'password') {
        if (!is_string($GLOBALS['remember_hash']) || strlen($GLOBALS['remember_hash']) !== 64) {
            throw new RuntimeException('Password login did not store a remember token.');
        }
        // CLI SAPI discards response headers; CGI/WASM also verifies the cookie.
        if (PHP_SAPI !== 'cli' && (!preg_match('/foxdesk_remember=([a-f0-9]{64})/', $cookies, $match)
            || !hash_equals($GLOBALS['remember_hash'], hash('sha256', $match[1])))) {
            throw new RuntimeException('Password login did not issue the matching remember cookie.');
        }
    } elseif ($GLOBALS['remember_hash'] !== null) {
        throw new RuntimeException('Persistent login must not bypass required/enrolled 2FA.');
    }
    if ($mode === 'required' && empty($_SESSION['2fa_setup_required'])) throw new RuntimeException('Missing enrollment redirect.');
    if ($mode === 'backup' && json_decode($GLOBALS['user']['totp_backup_codes'], true) !== []) throw new RuntimeException('Backup code was not consumed.');
    echo 'Actual login page and remember policy OK: ' . $mode . "\n";
});
require BASE_PATH . '/pages/login.php';
throw new RuntimeException('Successful login did not redirect.');
