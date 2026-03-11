<?php
/**
 * CLI entrypoint: ingest incoming emails via IMAP.
 *
 * Usage:
 *   php bin/ingest-emails.php
 *   php bin/ingest-emails.php --limit=20
 *   php bin/ingest-emails.php --dry-run
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
require_once BASE_PATH . '/includes/email-ingest-functions.php';

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

$opts = getopt('', ['limit::', 'dry-run', 'json']);
$limit = isset($opts['limit']) ? (int) $opts['limit'] : null;
$dry_run = array_key_exists('dry-run', $opts);
$json = array_key_exists('json', $opts);

cli_scheduler_log('scheduler', 'info', 'Incoming email ingest started', [
    'limit' => $limit,
    'dry_run' => $dry_run ? 1 : 0,
]);

try {
    $result = email_ingest_run([
        'limit' => $limit,
        'dry_run' => $dry_run,
    ]);
} catch (Throwable $e) {
    if ($json) {
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, '[incoming-mail] ERROR: ' . $e->getMessage() . PHP_EOL);
    }
    cli_scheduler_log('scheduler', 'error', 'Incoming email ingest failed', [
        'error' => $e->getMessage(),
        'limit' => $limit,
        'dry_run' => $dry_run ? 1 : 0,
    ]);
    exit(2);
}

if ($json) {
    echo json_encode([
        'ok' => true,
        'dry_run' => $dry_run,
        'disabled' => !empty($result['disabled']),
        'result' => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

if (!empty($result['disabled'])) {
    echo "[incoming-mail] disabled (enable IMAP in Settings -> Emails)\n";
    cli_scheduler_log('scheduler', 'warning', 'Incoming email ingest disabled', [
        'reason' => 'disabled',
    ]);
    exit(0);
}

echo '[incoming-mail] done' . PHP_EOL;
echo 'checked=' . (int) $result['checked']
    . ' processed=' . (int) $result['processed']
    . ' skipped=' . (int) $result['skipped']
    . ' failed=' . (int) $result['failed'] . PHP_EOL;

foreach ($result['details'] as $detail) {
    $uid = isset($detail['uid']) ? (int) $detail['uid'] : 0;
    $status = $detail['status'] ?? 'unknown';
    $reason = $detail['reason'] ?? '';
    $ticket_id = isset($detail['ticket_id']) ? (int) $detail['ticket_id'] : 0;
    $line = "uid={$uid} status={$status}";
    if ($ticket_id > 0) {
        $line .= " ticket_id={$ticket_id}";
    }
    if ($reason !== '') {
        $line .= " reason={$reason}";
    }
    if (!empty($detail['error'])) {
        $line .= " error=" . $detail['error'];
    }
    echo $line . PHP_EOL;
}

cli_scheduler_log('scheduler', 'info', 'Incoming email ingest completed', [
    'checked' => (int) ($result['checked'] ?? 0),
    'processed' => (int) ($result['processed'] ?? 0),
    'skipped' => (int) ($result['skipped'] ?? 0),
    'failed' => (int) ($result['failed'] ?? 0),
]);

