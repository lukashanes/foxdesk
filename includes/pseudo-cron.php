<?php
/**
 * Pseudo-cron system (WordPress-style)
 *
 * Triggered on page loads. When tasks are due, fires a non-blocking
 * HTTP request to the cron endpoint so the user's page load is not delayed.
 *
 * Tasks:
 *  - email ingestion   (every 5 minutes)
 *  - recurring tasks    (every 60 minutes)
 *  - maintenance/cleanup (every 24 hours)
 */

// Task definitions: setting key => interval in seconds
define('PSEUDO_CRON_TASKS', [
    'pseudo_cron_last_email'       => 300,   // 5 minutes
    'pseudo_cron_last_recurring'   => 3600,  // 1 hour
    'pseudo_cron_last_reports'     => 21600, // 6 hours
    'pseudo_cron_last_maintenance' => 86400, // 24 hours
]);

/**
 * Check if any pseudo-cron task is due and trigger if so.
 * Called on every page load from header.php.
 */
function pseudo_cron_check()
{
    // Only run if enabled
    if (!get_setting('pseudo_cron_enabled')) {
        return;
    }

    $now = time();

    // Quick check: is any task overdue?
    $due = false;
    foreach (PSEUDO_CRON_TASKS as $setting_key => $interval) {
        $last_run = (int) get_setting($setting_key, '0');
        if ($now - $last_run >= $interval) {
            $due = true;
            break;
        }
    }

    if (!$due) {
        return;
    }

    // Fire non-blocking request to cron endpoint
    pseudo_cron_trigger();
}

/**
 * Fire a non-blocking HTTP request to the cron endpoint.
 * The request is "fire and forget" — we don't wait for a response.
 */
function pseudo_cron_trigger()
{
    $secret = get_setting('pseudo_cron_secret');
    if (!$secret) {
        // Generate secret on first use
        $secret = bin2hex(random_bytes(20));
        save_setting('pseudo_cron_secret', $secret);
    }

    // Build URL to cron endpoint
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script_path = dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $url = $protocol . '://' . $host . rtrim($script_path, '/') . '/index.php?page=cron&token=' . urlencode($secret);

    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return;
    }

    $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
    $ssl_prefix = ($parts['scheme'] === 'https') ? 'ssl://' : '';

    $fp = @fsockopen($ssl_prefix . $parts['host'], $port, $errno, $errstr, 2);
    if (!$fp) {
        // fsockopen failed — try file_get_contents as fallback (blocking but works on more hosts)
        $ctx = stream_context_create(['http' => [
            'timeout' => 1,
            'method'  => 'GET',
            'header'  => "Connection: close\r\n",
        ]]);
        @file_get_contents($url, false, $ctx);
        return;
    }

    $path_query = ($parts['path'] ?? '/') . '?' . ($parts['query'] ?? '');
    $request  = "GET {$path_query} HTTP/1.1\r\n";
    $request .= "Host: {$parts['host']}\r\n";
    $request .= "Connection: close\r\n";
    $request .= "User-Agent: FoxDesk-PseudoCron/1.0\r\n";
    $request .= "\r\n";

    fwrite($fp, $request);
    fclose($fp); // Don't wait for response
}
