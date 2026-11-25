<?php
define('BASE_PATH', dirname(__DIR__));
$user = ['id' => 1, 'role' => 'agent', 'is_active' => 1, 'auth_version' => 0, 'totp_enabled' => 0];
function column_exists(...$args) { return true; }
function schema_require(...$args) {}
function get_settings() { return ['2fa_required_agent' => '1']; }
function db_fetch_one($sql, $params = []) { return $GLOBALS['user']; }
function db_update($table, $data, $where, $params = []) { $GLOBALS['user'] = array_replace($GLOBALS['user'], $data); return 1; }
function t($text) { return $text; }
function require_csrf_token() { $GLOBALS['csrf_calls']++; }
function flash(...$args) {}
function log_security_event(...$args) {}
class EnrollmentRedirect extends RuntimeException {}
function redirect($page, $params = []) {
    if ($page !== 'profile') throw new RuntimeException('Unexpected enrollment destination.');
    throw new EnrollmentRedirect();
}
require BASE_PATH . '/includes/auth.php';
session_start();
$_SESSION = ['user_id' => 1, 'auth_version' => 0];
$_SERVER['REQUEST_METHOD'] = 'POST';
$csrf_calls = 0;
if (!auth_requires_2fa_setup()) throw new RuntimeException('Test account must require enrollment.');
$_POST = ['start_2fa_setup' => '1'];
try { require BASE_PATH . '/pages/profile.php'; } catch (EnrollmentRedirect $e) {}
$secret = $_SESSION['2fa_setup_secret'] ?? '';
if ($secret === '') throw new RuntimeException('Profile POST did not start enrollment.');
$_POST = ['verify_2fa_setup' => '1', 'setup_code' => totp_get_code($secret)];
try { require BASE_PATH . '/pages/profile.php'; } catch (EnrollmentRedirect $e) {}
current_user(true); // The redirect starts a new request with a fresh user row.
if (auth_requires_2fa_setup() || empty($user['totp_enabled']) || $user['totp_secret'] !== $secret
    || count($_SESSION['2fa_backup_codes_show'] ?? []) !== 8 || $csrf_calls !== 2) {
    throw new RuntimeException('Actual profile POST enrollment failed.');
}
echo "Required 2FA enrollment through real profile POST handlers OK (no API or network)\n";
