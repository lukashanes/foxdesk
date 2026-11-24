<?php
define('BASE_PATH', dirname(__DIR__));
$users = [1 => ['id' => 1, 'role' => 'agent', 'is_active' => 1, 'auth_version' => 0, 'totp_enabled' => 0],
    2 => ['id' => 2, 'role' => 'admin', 'is_active' => 1, 'auth_version' => 0, 'totp_enabled' => 1]];
function column_exists($table, $column) { return true; }
function schema_require(...$args) {}
function get_settings() { return ['2fa_required_agent' => '1', '2fa_required_admin' => '1']; }
function db_fetch_one($sql, $params = []) {
    $row = $GLOBALS['users'][(int) $params[0]] ?? null;
    return $row && !empty($row['is_active']) && empty($row['deleted_at']) ? $row : null;
}
require BASE_PATH . '/includes/auth.php';
$assert = static function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
$_SESSION = ['user_id' => 1]; // Existing sessions at epoch zero stay valid.
$assert(is_logged_in(), 'Legacy session should survive migration before a reset.');
$assert(function_exists('auth_requires_2fa_setup'), 'Shared 2FA gate missing.');
$assert(auth_requires_2fa_setup(), 'Human agent without required 2FA must be blocked.');
$GLOBALS['users'][1]['is_ai_agent'] = 1;
current_user(true);
$assert(auth_requires_2fa_setup(), 'AI marker alone must not exempt browser sessions.');
$GLOBALS['is_api_token_auth'] = true;
$assert(!auth_requires_2fa_setup(), 'Service agent API credentials must keep working.');
unset($GLOBALS['is_api_token_auth']);
$_SESSION = ['user_id' => 2, 'auth_version' => 0];
$assert(current_user()['id'] === 2, 'Identity cache must track account switches.');
$assert(!auth_requires_2fa_setup(), 'Enrolled account must pass setup gate.');
$GLOBALS['users'][2]['auth_version'] = 1;
$assert(current_user(true) === null && !is_logged_in() && empty($_SESSION), 'Revoked sessions must also become logged out.');
$_SESSION = ['user_id' => 1, 'auth_version' => 0, 'impersonator_id' => 2, 'impersonator_auth_version' => 0];
$assert(current_user(true) === null, 'Admin reset must revoke impersonated sessions.');
$_SESSION = ['user_id' => 1, 'auth_version' => 0];
$GLOBALS['users'][1]['is_active'] = 0;
$assert(!is_logged_in() && empty($_SESSION), 'Inactive identity must not remain authenticated.');
$assert(!auth_credential_version_matches(['auth_version' => 0], ['auth_version' => 1]), 'Old credentials must fail after reset.');
echo "Authentication session and required 2FA boundaries OK\n";
