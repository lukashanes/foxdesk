<?php
/**
 * CLI entrypoint: sync self-hosted FoxDesk data to FoxDesk Cloud.
 *
 * Usage:
 *   php bin/sync-to-cloud.php --cloud-url=https://app.foxdesk.net --token=fdmig_...
 *   php bin/sync-to-cloud.php --limit=250 --json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run in CLI mode.\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

if (!file_exists(BASE_PATH . '/config.php')) {
    fwrite(STDERR, "Missing config.php. Install/configure the app first.\n");
    exit(1);
}

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/includes/database.php';
require_once BASE_PATH . '/includes/settings-functions.php';
require_once BASE_PATH . '/includes/migration-functions.php';

$opts = getopt('', ['cloud-url::', 'token::', 'limit::', 'json']);
$cloud_url = trim((string) ($opts['cloud-url'] ?? get_setting('migration_cloud_url', 'https://app.foxdesk.net')));
$token = trim((string) ($opts['token'] ?? get_setting('migration_cloud_token', '')));
$limit = isset($opts['limit']) ? (int) $opts['limit'] : 500;
$json = array_key_exists('json', $opts);

try {
    $summary = migration_cloud_sync_all_tables($cloud_url, $token, $limit);
} catch (Throwable $e) {
    if ($json) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, '[cloud-sync] ERROR: ' . $e->getMessage() . PHP_EOL);
    }
    exit(2);
}

if ($json) {
    echo json_encode(['ok' => true, 'summary' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

echo '[cloud-sync] done total_sent=' . (int) ($summary['total_sent'] ?? 0) . PHP_EOL;
foreach (($summary['tables'] ?? []) as $table => $table_summary) {
    echo $table
        . ' sent=' . (int) ($table_summary['sent'] ?? 0)
        . ' created=' . (int) ($table_summary['created'] ?? 0)
        . ' updated=' . (int) ($table_summary['updated'] ?? 0)
        . ' mapped=' . (int) ($table_summary['mapped'] ?? 0)
        . ' skipped=' . (int) ($table_summary['skipped'] ?? 0)
        . PHP_EOL;
}
if (isset($summary['attachments']) && is_array($summary['attachments'])) {
    $attachments = $summary['attachments'];
    echo 'attachments'
        . ' sent=' . (int) ($attachments['sent'] ?? 0)
        . ' uploaded=' . (int) ($attachments['uploaded'] ?? 0)
        . ' mapped=' . (int) ($attachments['mapped'] ?? 0)
        . ' skipped=' . (int) ($attachments['skipped'] ?? 0)
        . ' failed=' . (int) ($attachments['failed'] ?? 0)
        . PHP_EOL;
}
