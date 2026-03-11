<?php
/**
 * Pseudo-cron runner endpoint.
 *
 * Called via non-blocking HTTP from pseudo_cron_trigger().
 * Validates a secret token, acquires a lock, and runs overdue tasks.
 *
 * URL: index.php?page=cron&token=SECRET
 */

header('Content-Type: text/plain; charset=utf-8');

// --- Validate token ---
$token  = $_GET['token'] ?? '';
$secret = get_setting('pseudo_cron_secret');

if (!$secret || !hash_equals($secret, $token)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// --- Acquire lock (prevent concurrent runs) ---
$lock_time = (int) get_setting('pseudo_cron_lock', '0');
if ($lock_time > 0 && (time() - $lock_time) < 300) {
    echo 'Locked';
    exit;
}
save_setting('pseudo_cron_lock', (string) time());

// Allow up to 2 minutes for task execution
set_time_limit(120);

$now = time();
$errors = [];

// -----------------------------------------------------------------
// 1. Email ingestion (every 5 minutes)
// -----------------------------------------------------------------
$last_email = (int) get_setting('pseudo_cron_last_email', '0');
if ($now - $last_email >= 300) {
    save_setting('pseudo_cron_last_email', (string) $now);
    try {
        require_once BASE_PATH . '/includes/email-ingest-functions.php';
        $cfg = email_ingest_config();
        $enabled = trim((string) ($cfg['host'] ?? '')) !== ''
            && trim((string) ($cfg['username'] ?? '')) !== ''
            && trim((string) ($cfg['password'] ?? '')) !== '';

        if ($enabled && function_exists('email_ingest_run')) {
            email_ingest_run();
        }
    } catch (Throwable $e) {
        $errors[] = 'email: ' . $e->getMessage();
        error_log('[pseudo-cron] email error: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------
// 2. Recurring tasks (every 60 minutes)
// -----------------------------------------------------------------
$last_recurring = (int) get_setting('pseudo_cron_last_recurring', '0');
if ($now - $last_recurring >= 3600) {
    save_setting('pseudo_cron_last_recurring', (string) $now);
    try {
        require_once BASE_PATH . '/includes/recurring-task-functions.php';
        if (function_exists('process_recurring_tasks')) {
            process_recurring_tasks();
        }
    } catch (Throwable $e) {
        $errors[] = 'recurring: ' . $e->getMessage();
        error_log('[pseudo-cron] recurring tasks error: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------
// 3. Maintenance — notification cleanup, update check (every 24 hours)
// -----------------------------------------------------------------
$last_maintenance = (int) get_setting('pseudo_cron_last_maintenance', '0');
if ($now - $last_maintenance >= 86400) {
    save_setting('pseudo_cron_last_maintenance', (string) $now);

    // Cleanup old notifications
    try {
        if (function_exists('cleanup_old_notifications')) {
            cleanup_old_notifications(90);
        }
    } catch (Throwable $e) {
        $errors[] = 'notification_cleanup: ' . $e->getMessage();
    }

    // Update check
    try {
        if (file_exists(BASE_PATH . '/includes/update-check-functions.php')) {
            require_once BASE_PATH . '/includes/update-check-functions.php';
            if (function_exists('is_update_check_enabled') && is_update_check_enabled()) {
                @check_for_updates();
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'update_check: ' . $e->getMessage();
    }

    // Prune page_views older than 90 days
    try {
        if (function_exists('page_views_table_exists') && page_views_table_exists()) {
            db_query("DELETE FROM page_views WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        }
    } catch (Throwable $e) {
        $errors[] = 'page_views_cleanup: ' . $e->getMessage();
    }
}

// --- Release lock ---
save_setting('pseudo_cron_lock', '0');

// --- Log to debug_log if errors occurred ---
if (!empty($errors)) {
    try {
        $has_table = (bool) db_fetch_one("SHOW TABLES LIKE 'debug_log'");
        if ($has_table) {
            db_insert('debug_log', [
                'channel'    => 'pseudo_cron',
                'level'      => 'error',
                'message'    => 'Pseudo-cron errors',
                'context'    => json_encode($errors, JSON_UNESCAPED_UNICODE),
                'user_id'    => null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    } catch (Throwable $e) {
        // Silent
    }
}

echo 'OK';
exit;
