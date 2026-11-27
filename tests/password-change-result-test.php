<?php
// Exercise the actual action blocks with a failed/successful password write and a mail stub.
define('BASE_PATH', dirname(__DIR__));
$password_changed = false; $flashes = []; $mail_calls = 0;
function update_password(...$args) { return $GLOBALS['password_changed'] ? 1 : false; }
function validate_password($password) { return ['valid' => true]; }
function generate_password(...$args) { return 'Test-new-password'; }
function get_user($id) { return $GLOBALS['user']; }
function get_settings() { return []; }
function foxdesk_effective_user_language($user) { return 'en'; }
function send_ticket_notification_email(...$args) { $GLOBALS['mail_calls']++; return true; }
function t($text, $params = []) { return $text; }
function flash($text, $type) { $GLOBALS['flashes'][] = $type; }
class PasswordActionRedirect extends RuntimeException {}
function redirect(...$args) { throw new PasswordActionRedirect(); }
$assert = static function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
$actions = [
    ['pages/profile.php', '// Change password', '// Notification preferences', 'change_password'],
    ['includes/modules/team/team-users-actions.php', '// Reset password', '// Send password reset email', 'reset_password'],
    ['pages/admin/clients.php', '// Reset password', '// Generate and send password', 'reset_password'],
    ['pages/admin/clients.php', '// Generate and send password', "require_once BASE_PATH . '/includes/header.php'", 'generate_password'],
];
$user = ['id' => 1, 'password' => password_hash('Test-current-password', PASSWORD_DEFAULT),
    'email' => 'person@example.test', 'first_name' => 'Test'];
foreach ($actions as [$file, $start_marker, $end_marker, $action]) {
    $source = file_get_contents(BASE_PATH . '/' . $file);
    $start = strpos($source, $start_marker);
    $end = strpos($source, $end_marker, $start);
    $block = substr($source, $start, $end - $start);
    if ($action === 'generate_password') {
        // Omit the surrounding POST-handler closing brace and replace the mail transport include.
        $block = preg_replace('/\}\s*$/', '', $block);
        $block = str_replace("require_once BASE_PATH . '/includes/mailer.php';", '', $block);
    }
    foreach ([false, true] as $password_changed) {
        $_POST = [$action => '1', 'id' => 1, 'current_password' => 'Test-current-password',
            'new_password' => 'Test-new-password', 'confirm_password' => 'Test-new-password'];
        $flashes = []; $mail_calls = 0;
        try { eval($block); } catch (PasswordActionRedirect $e) {}
        $assert($flashes === [$password_changed ? 'success' : 'error'],
            'Action must report the actual write result: ' . $file . '/' . $action);
        $assert($mail_calls === (($password_changed && $action === 'generate_password') ? 1 : 0),
            'A password that was not saved must never reach mail delivery.');
    }
}
$pending_login_flash = ['message' => 'TEST-revoked password proof', 'type' => 'error'];
function get_flash() { $value = $GLOBALS['pending_login_flash']; $GLOBALS['pending_login_flash'] = null; return $value; }
$login = file_get_contents(BASE_PATH . '/pages/login.php');
$start = strpos($login, '$login_flash = get_flash();');
if ($start === false) $start = strpos($login, '$error = \'\';');
$end = strpos($login, '$show_2fa_form =', $start);
eval(substr($login, $start, $end - $start));
$assert($error === 'TEST-revoked password proof' && $pending_login_flash === null, 'Login must display and consume the error after a revoked profile request.');
echo "Password action results and mail suppression OK (no delivery)\n";
