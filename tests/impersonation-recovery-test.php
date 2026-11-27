<?php
// Run the real protected-page guard with synthetic identities; no database or HTTP request.
define('BASE_PATH', dirname(__DIR__));
$users = [
    1 => ['id' => 1, 'role' => 'user', 'is_active' => 1, 'auth_version' => 0],
    2 => ['id' => 2, 'role' => 'admin', 'is_active' => 1, 'auth_version' => 3,
        'email' => 'admin@example.test', 'first_name' => 'Test', 'last_name' => 'Admin'],
];
function column_exists(...$args) { return true; }
function schema_require(...$args) {}
function db_fetch_one($sql, $params = []) {
    $user = $GLOBALS['users'][(int) $params[0]] ?? null;
    if (str_contains($sql, 'is_active = 1') && empty($user['is_active'])) return null;
    if (str_contains($sql, 'deleted_at IS NULL') && !empty($user['deleted_at'])) return null;
    return $user;
}
function log_security_event(...$args) {}
function flash(...$args) {}
function t($text) { return $text; }
function recovery_header($header) { $GLOBALS['redirect'] = $header; }
function recovery_regenerate_session(...$args) {}
class RecoveryRedirect extends RuntimeException {}
require BASE_PATH . '/includes/auth.php';
$source = file_get_contents(BASE_PATH . '/index.php');
$start = strpos($source, '// Check authentication');
$end = strpos($source, '// One policy', $start);
$guard = substr($source, $start, $end - $start);
$guard = str_replace(['header(', 'session_regenerate_id(', 'exit;'],
    ['recovery_header(', 'recovery_regenerate_session(', 'throw new RecoveryRedirect();'], $guard);
$assert = static function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
foreach (['inactive', 'deleted', 'missing', 'revoked_admin', 'inactive_admin', 'demoted_admin', 'revoked_target'] as $case) {
    $users[1]['is_active'] = in_array($case, ['deleted', 'revoked_target'], true) ? 1 : 0;
    $users[1]['auth_version'] = $case === 'revoked_target' ? 1 : 0;
    $users[1]['deleted_at'] = $case === 'deleted' ? '2026-11-25 10:00:00' : null;
    $users[2]['role'] = $case === 'demoted_admin' ? 'user' : 'admin';
    $users[2]['is_active'] = $case === 'inactive_admin' ? 0 : 1;
    $users[2]['auth_version'] = $case === 'revoked_admin' ? 4 : 3;
    $_SESSION = ['user_id' => $case === 'missing' ? 99 : 1, 'auth_version' => 0,
        'impersonator_id' => 2, 'impersonator_auth_version' => 3];
    $_COOKIE = [];
    $page = 'work'; $public_pages = ['login']; $redirect = '';
    try { eval($guard); } catch (RecoveryRedirect $e) {}
    if (in_array($case, ['revoked_admin', 'inactive_admin', 'demoted_admin', 'revoked_target'], true)) {
        $assert(empty($_SESSION) && $redirect === 'Location: index.php?page=login',
            'Revoked proof must never restore access: ' . $case);
    } else {
        $assert(($_SESSION['user_id'] ?? 0) === 2 && ($_SESSION['auth_version'] ?? 0) === 3,
            'Unavailable impersonated user must return to the valid administrator: ' . $case);
        $assert(!isset($_SESSION['impersonator_id']) && $redirect === 'Location: index.php?page=admin&section=users',
            'Recovery must redirect before the original request can run: ' . $case);
    }
}
echo "Impersonation recovery and revoked administrator boundaries OK\n";
