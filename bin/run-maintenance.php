<?php
/**
 * CLI entrypoint: run scheduled maintenance jobs.
 *
 * Jobs:
 * - recurring task generation
 * - incoming email ingest
 * - remote update check
 *
 * Usage:
 *   php bin/run-maintenance.php
 *   php bin/run-maintenance.php --json
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
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/settings-functions.php';
require_once BASE_PATH . '/includes/email-ingest-functions.php';
require_once BASE_PATH . '/includes/update-check-functions.php';

function cli_scheduler_log($channel, $level, $message, $context = [])
{
    try {
        $has_table = (bool) db_fetch_one("SHOW TABLES LIKE 'debug_log'");
        if (!$has_table) {
            return;
        }

        if (!is_string($context)) {
            $context = json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        db_insert('debug_log', [
            'channel' => (string) $channel,
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => (string) ($context ?: ''),
            'user_id' => null,
            'ip_address' => 'cli',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // Silent by design for CLI tasks.
    }
}

$opts = getopt('', ['json']);
$json = array_key_exists('json', $opts);

$result = [
    'ok' => true,
    'recurring_processed' => 0,
    'email_ingest' => null,
    'update_check' => null,
    'errors' => [],
];

cli_scheduler_log('scheduler', 'info', 'Maintenance run started');

try {
    $result['recurring_processed'] = (int) process_recurring_tasks();
} catch (Throwable $e) {
    $result['ok'] = false;
    $result['errors'][] = 'recurring: ' . $e->getMessage();
}

try {
    $ingest_cfg = email_ingest_config();
    $ingest_enabled = trim((string) ($ingest_cfg['host'] ?? '')) !== ''
        && trim((string) ($ingest_cfg['username'] ?? '')) !== ''
        && trim((string) ($ingest_cfg['password'] ?? '')) !== '';

    if ($ingest_enabled) {
        $result['email_ingest'] = email_ingest_run();
    } else {
        $result['email_ingest'] = [
            'checked' => 0,
            'processed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'status' => 'disabled',
            'reason' => 'IMAP config missing',
        ];
    }
} catch (Throwable $e) {
    $result['ok'] = false;
    $result['errors'][] = 'ingest: ' . $e->getMessage();
}

// --- Notification cleanup ---
try {
    if (function_exists('cleanup_old_notifications')) {
        $deleted = cleanup_old_notifications(90);
        $result['notifications_cleanup'] = ['deleted' => $deleted];
    }
} catch (Throwable $e) {
    $result['errors'][] = 'notification_cleanup: ' . $e->getMessage();
}

// --- Update check ---
try {
    if (is_update_check_enabled()) {
        $update_result = check_for_updates();
        $result['update_check'] = $update_result
            ? ['status' => 'available', 'version' => $update_result['version']]
            : ['status' => 'up_to_date'];
    } else {
        $result['update_check'] = ['status' => 'disabled'];
    }
} catch (Throwable $e) {
    $result['errors'][] = 'update_check: ' . $e->getMessage();
    $result['update_check'] = ['status' => 'error', 'error' => $e->getMessage()];
}

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($result['ok'] ? 0 : 2);
}

echo '[maintenance] recurring_processed=' . (int) $result['recurring_processed'] . PHP_EOL;
if (is_array($result['email_ingest'])) {
    echo '[maintenance] ingest checked=' . (int) ($result['email_ingest']['checked'] ?? 0)
        . ' processed=' . (int) ($result['email_ingest']['processed'] ?? 0)
        . ' skipped=' . (int) ($result['email_ingest']['skipped'] ?? 0)
        . ' failed=' . (int) ($result['email_ingest']['failed'] ?? 0) . PHP_EOL;
}
if (is_array($result['update_check'])) {
    $uc_status = $result['update_check']['status'] ?? 'unknown';
    $uc_version = $result['update_check']['version'] ?? '';
    echo '[maintenance] update_check=' . $uc_status . ($uc_version ? ' version=' . $uc_version : '') . PHP_EOL;
}

if (!$result['ok']) {
    cli_scheduler_log('scheduler', 'error', 'Maintenance run failed', [
        'errors' => $result['errors'],
        'recurring_processed' => (int) $result['recurring_processed'],
        'email_ingest' => $result['email_ingest'],
    ]);
    foreach ($result['errors'] as $error) {
        fwrite(STDERR, '[maintenance] ERROR: ' . $error . PHP_EOL);
    }
    exit(2);
}

cli_scheduler_log('scheduler', 'info', 'Maintenance run completed', [
    'recurring_processed' => (int) $result['recurring_processed'],
    'email_ingest' => $result['email_ingest'],
    'update_check' => $result['update_check'],
]);

