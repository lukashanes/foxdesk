<?php
// Local-only negative access fixture. No production credentials or HTTP entry point.
if (PHP_SAPI !== 'cli' || getenv('FOXDESK_LOCAL_TESTS') !== '1') { http_response_code(403); exit(1); }
define('BASE_PATH', dirname(__DIR__));
define('APP_VERSION', 'test');
$_SESSION = ['tenant_id' => 1, 'user_id' => 1];
require BASE_PATH . '/config.php';
if (!in_array(parse_url(APP_URL, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Local fixtures only');
require BASE_PATH . '/includes/database.php';
if (file_exists(BASE_PATH . '/includes/tenant-functions.php')) require BASE_PATH . '/includes/tenant-functions.php';
require BASE_PATH . '/includes/functions.php';
require BASE_PATH . '/includes/auth.php';
$admin = db_fetch_one('SELECT * FROM users WHERE email = ?', ['admin@example.test']);
if (!$admin) throw new RuntimeException('Synthetic admin missing');
$_SESSION['user_id'] = (int) $admin['id'];
$_SESSION['tenant_id'] = (int) ($admin['tenant_id'] ?? 1);
$email = 'done-links-blocked@example.test';
$blocked = db_fetch_one('SELECT * FROM users WHERE email = ?', [$email]);
if (!$blocked) {
    $id = db_insert('users', [
        'email' => $email, 'password' => password_hash('AdminPass123!', PASSWORD_DEFAULT),
        'first_name' => 'Local', 'last_name' => 'Unrelated client', 'role' => 'user',
        'permissions' => json_encode(['ticket_scope' => 'organization', 'organization_ids' => []]),
        'is_active' => 1,
    ]);
    $blocked = get_user((int) $id);
}
$ticket = get_ticket((int) ($argv[1] ?? 0));
if (!$ticket || empty($ticket['is_closed'])) throw new RuntimeException('Expected local completed ticket');
$checks = ['same_tenant_unrelated_denied' => !can_see_ticket($ticket, $blocked)];
$by_hash = get_ticket_by_hash((string) $ticket['hash']);
$checks['hash_lookup_preserves_closed_metadata'] = !empty($by_hash['is_closed']) && (int) $by_hash['id'] === (int) $ticket['id'];
if (function_exists('tenant_scoped_table_has_column') && tenant_scoped_table_has_column('tickets')) {
    // Exercise the real tenant guard even for a privileged foreign user.
    $foreign = $admin;
    $foreign['is_platform_admin'] = 0;
    $foreign['tenant_id'] = (int) $ticket['tenant_id'] + 10000;
    $checks['cross_tenant_admin_denied'] = !can_see_ticket($ticket, $foreign);
}
echo json_encode(['blocked_email' => $email, 'checks' => $checks]);
