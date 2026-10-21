<?php
/**
 * FoxDesk - Database Upgrade Script
 *
 * Run this after updating files to apply database changes.
 *
 * Access control:
 *   - Logged-in admin: runs automatically
 *   - CLI: runs automatically (php upgrade.php)
 *   - Anonymous web: requires ?token=FIRST_16_CHARS_OF_SECRET_KEY
 *
 * Delete this file after use on shared hosting.
 */

define('BASE_PATH', __DIR__);
define('SESSION_LIFETIME', 2592000);

ini_set('default_charset', 'UTF-8');
header('Content-Type: text/html; charset=UTF-8');

// Check if installed
if (!file_exists(BASE_PATH . '/config.php')) {
    die('The app is not installed. Run install.php');
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/includes/database.php';
require_once BASE_PATH . '/includes/session-bootstrap.php';
foxdesk_bootstrap_session();

// ── Access control ──────────────────────────────────────────────────────────
// CLI always allowed; logged-in admins always allowed; anonymous web needs token.
$is_cli = (php_sapi_name() === 'cli');
$is_admin_session = false;

if (!$is_cli && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
    try {
        $u = db_fetch_one("SELECT role FROM users WHERE id = ?", [$_SESSION['user_id']]);
        $is_admin_session = ($u && ($u['role'] ?? '') === 'admin');
    } catch (Throwable $e) {
        // DB may be broken — allow token fallback
    }
}

if (!$is_cli && !$is_admin_session) {
    $token = trim($_GET['token'] ?? '');
    $secret = defined('SECRET_KEY') ? SECRET_KEY : '';
    if ($secret === '' || $token === '' || ($token !== substr($secret, 0, 16) && $token !== $secret)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Upgrade</title></head><body>';
        echo '<h2>Access Denied</h2>';
        echo '<p>Database upgrade requires authentication.</p>';
        echo '<p>Either log in as admin first, or use: <code>upgrade.php?token=FIRST_16_CHARS_OF_SECRET_KEY</code></p>';
        echo '</body></html>';
        exit;
    }
}

require_once BASE_PATH . '/includes/update-functions.php';
require_once BASE_PATH . '/includes/upgrade-functions.php';

$messages = foxdesk_run_database_upgrade();

if (empty($messages)) {
    $messages[] = "Database is up to date; no changes were required.";
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upgrade - FoxDesk</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="assets/css/theme.min.css" rel="stylesheet">
</head>

<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-lg max-w-lg w-full p-8">
        <h1 class="text-2xl font-bold text-gray-800 mb-6">Upgrade complete</h1>

        <div class="space-y-2 max-h-96 overflow-y-auto">
            <?php foreach ($messages as $msg): ?>
                <div
                    class="flex items-start gap-2 <?php echo strpos($msg, 'OK:') === 0 ? 'text-green-600' : (strpos($msg, 'ERROR:') === 0 ? 'text-red-600' : 'text-gray-600'); ?>">
                    <span><?php echo htmlspecialchars($msg); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-6 pt-6 border-t">
            <a href="index.php"
                class="inline-block bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg transition">
                Go to app ->
            </a>
        </div>

        <p class="text-sm text-gray-500 mt-6">
            <strong>Tip:</strong> For better security, delete <code class="bg-gray-100 px-1 rounded">upgrade.php</code>
        </p>
    </div>
</body>

</html>
