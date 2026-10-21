<?php
/**
 * FoxDesk Docker scheduler task runner.
 *
 * Runs the same background task set as the pseudo-cron endpoint
 * (index.php?page=cron: email ingest, recurring tickets, due-date reminders,
 * scheduled reports, attachment cleanup, maintenance and update checks) from
 * the CLI, so the scheduler container needs no HTTP access to the web server.
 * Task intervals and the run lock are handled by pages/cron.php itself, so
 * calling this every minute is safe.
 *
 * Usage (inside the container, as www-data): php /usr/local/lib/foxdesk/cron.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$foxdesk_root = rtrim((string) (getenv('FOXDESK_APP_ROOT') ?: '/var/www/html'), '/');
if (!is_file($foxdesk_root . '/config.php')) {
    fwrite(STDOUT, "FoxDesk is not installed yet.\n");
    exit(0);
}

define('BASE_PATH', $foxdesk_root);
define('SESSION_LIFETIME', 2592000);
define('REMEMBER_ME_DURATION', 30 * 86400);
chdir(BASE_PATH);

// APP_VERSION lives in index.php; the update check compares against it.
$foxdesk_index = (string) @file_get_contents(BASE_PATH . '/index.php');
if (preg_match("/define\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]/", $foxdesk_index, $foxdesk_match)) {
    define('APP_VERSION', $foxdesk_match[1]);
}
unset($foxdesk_index, $foxdesk_match);

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');

require_once BASE_PATH . '/includes/session-bootstrap.php';
require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/includes/database.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/pseudo-cron.php';

// pages/cron.php authenticates with the pseudo-cron secret; create it the
// same way pseudo_cron_trigger() does when it does not exist yet.
$foxdesk_secret = (string) get_setting('pseudo_cron_secret');
if ($foxdesk_secret === '') {
    $foxdesk_secret = bin2hex(random_bytes(20));
    save_setting('pseudo_cron_secret', $foxdesk_secret);
}

$_GET['page'] = 'cron';
$_GET['token'] = $foxdesk_secret;
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? 'cli';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
unset($foxdesk_secret, $foxdesk_root);

require BASE_PATH . '/pages/cron.php';
