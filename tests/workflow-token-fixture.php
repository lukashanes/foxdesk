<?php
// Synthetic local integration credentials, never available through HTTP.
if (PHP_SAPI !== 'cli' || getenv('FOXDESK_LOCAL_TESTS') !== '1') { http_response_code(403); exit(1); }
define('BASE_PATH', dirname(__DIR__));
define('APP_VERSION', 'test');
$_SESSION = ['tenant_id' => 1, 'user_id' => 1];
require BASE_PATH . '/config.php';
require BASE_PATH . '/includes/database.php';
if (file_exists(BASE_PATH . '/includes/tenant-functions.php')) require BASE_PATH . '/includes/tenant-functions.php';
require BASE_PATH . '/includes/functions.php';
require BASE_PATH . '/includes/auth.php';
if (($argv[1] ?? '') === 'revoke') {
    foreach (array_slice($argv, 2) as $id) revoke_api_token((int) $id);
    exit;
}
$admin = db_fetch_one('SELECT * FROM users WHERE email = ?', ['admin@example.test']);
if (!$admin) throw new RuntimeException('Synthetic admin missing');
$_SESSION['user_id'] = (int) $admin['id'];
$_SESSION['tenant_id'] = (int) ($admin['tenant_id'] ?? 1);
$until = date('Y-m-d H:i:s', time() + 3600);
$full = generate_api_token((int) $admin['id'], 'Local workflow integration', $until, ['tickets:read','tickets:write','comments:write','time:read','time:write']);
$read = generate_api_token((int) $admin['id'], 'Local workflow read-only test', $until, ['tickets:read']);
$write_only = generate_api_token((int) $admin['id'], 'Local workflow assignment-only test', $until, ['tickets:read','tickets:write']);
$delete = generate_api_token((int) $admin['id'], 'Local idempotent delete test', $until, ['tickets:read','tickets:write','comments:write','time:read','time:write','delete:write']);
echo json_encode(['full' => $full, 'read' => $read, 'write_only' => $write_only, 'delete' => $delete]);
