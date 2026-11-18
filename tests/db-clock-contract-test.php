<?php

/**
 * Contract: SQL must not compare PHP-written timestamps with the database clock.
 *
 * PHP runs in Europe/Prague while production MariaDB runs in UTC, so
 * NOW()/CURDATE()/CURRENT_TIMESTAMP are 1-2 hours away from values PHP writes
 * with date(). Incident 2026-11-12: a 1-minute API rate-limit window became
 * an hour. Bounds must be computed in PHP and passed as parameters:
 *   foxdesk_db_datetime(), foxdesk_db_datetime_shift(-24, 'hour'),
 *   foxdesk_db_date(), foxdesk_db_week_bounds()  (includes/database.php)
 *
 * Every database-clock call left in application SQL is listed below with the
 * reason it is safe. A new one fails this test. Add it to the list only when
 * the column is written AND compared on the database clock (or only written
 * and never compared with PHP time).
 */

$root = dirname(__DIR__);

const DB_CLOCK_WRITE_ONLY = 'write-only audit timestamp, never compared with PHP time';
const DB_CLOCK_BOTH_SIDES = 'written and compared on the database clock only';

$allowed = [
    'includes/api/allowed-senders-handler.php' => [
        'ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), active = 1, updated_at = NOW()",' => DB_CLOCK_WRITE_ONLY,
    ],
    'bin/allowed-senders.php' => [
        'VALUES (?, ?, ?, ?, NOW(), NOW())' => DB_CLOCK_WRITE_ONLY,
        'updated_at = NOW()",' => DB_CLOCK_WRITE_ONLY,
        'db_query("UPDATE allowed_senders SET active = 0, updated_at = NOW() WHERE id = ?", [$id]);' => DB_CLOCK_WRITE_ONLY,
        'db_query("UPDATE allowed_senders SET active = 0, updated_at = NOW() WHERE type = ? AND value = ?", [$type, $value]);' => DB_CLOCK_WRITE_ONLY,
    ],
    'includes/email-ingest-functions.php' => [
        // email_ingest_state.updated_at and email_ingest_logs.created_at are
        // only displayed; no query compares them with PHP time.
        'VALUES (?, ?, NOW())' => DB_CLOCK_WRITE_ONLY,
        'updated_at = NOW()",' => DB_CLOCK_WRITE_ONLY,
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())' => DB_CLOCK_WRITE_ONLY,
        'created_at = NOW()",' => DB_CLOCK_WRITE_ONLY,
    ],
    'includes/microsoft-mail-functions.php' => [
        // created_at/updated_at only; oauth_state_expires_at is a PHP parameter.
        "VALUES (?, 'microsoft', ?, ?, ?, 'pending', ?, ?, ?, ?, NOW(), NOW())" => DB_CLOCK_WRITE_ONLY,
        'updated_at = NOW()",' => DB_CLOCK_WRITE_ONLY,
    ],
    'includes/session-bootstrap.php' => [
        // Session expiry uses last_activity (PHP time()); created_at/updated_at are audit only.
        'VALUES (?, ?, ?, NOW(), NOW())' => DB_CLOCK_WRITE_ONLY,
        "updated_at = NOW()'" => DB_CLOCK_WRITE_ONLY,
    ],
    'install.php' => [
        // First admin account; created_at is only displayed.
        '$stmt = $pdo->prepare("INSERT INTO users (email, password, first_name, last_name, role, is_active, created_at) VALUES (?, ?, ?, ?, \'admin\', 1, NOW())");' => DB_CLOCK_WRITE_ONLY,
    ],
    'includes/ticket-time-functions.php' => [
        'VALUES (?, ?, ?, ?, 1, NOW(), NOW())' => DB_CLOCK_WRITE_ONLY, // agent_client_billable_rates
        'updated_at = NOW()",' => DB_CLOCK_WRITE_ONLY,
    ],
];

$clock_patterns = [
    // Function forms, any case; not JS Date.now() / performance.now().
    '/(?<![.\w$>])(NOW|CURDATE|CURRENT_DATE|CURTIME|CURRENT_TIME|SYSDATE|UTC_TIMESTAMP|UTC_DATE|UTC_TIME|LOCALTIME|LOCALTIMESTAMP)\(/i',
    '/(?<![\w])UNIX_TIMESTAMP\(\s*\)/i',
    // Bare SQL keywords (upper case); column defaults in DDL are fine.
    '/(?<!DEFAULT )(?<!UPDATE )\bCURRENT_TIMESTAMP\b(?!\()/',
    '/\bCURRENT_DATE\b(?!\()/',
];

$files = [];
foreach (['includes', 'pages', 'bin'] as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if ($file->getExtension() !== 'php' || str_starts_with($path, 'includes/lang/')) {
            continue;
        }
        $files[] = $path;
    }
}
foreach (glob($root . '/*.php') as $file) {
    $path = basename($file);
    if ($path !== 'upgrade.php') { // schema DDL only (DEFAULT CURRENT_TIMESTAMP)
        $files[] = $path;
    }
}
sort($files);

$found = [];
$unexpected = [];
foreach ($files as $path) {
    $source = (string) file_get_contents($root . '/' . $path);
    if (!preg_match('/now|cur|current|sysdate|utc_|localtime|unix_timestamp/i', $source)) {
        continue;
    }
    $lines = preg_split('/\R/', $source);
    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue; // comments, inline HTML/JS and code are not SQL
        }
        foreach ($clock_patterns as $pattern) {
            if (!preg_match_all($pattern, $token[1], $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as [, $offset]) {
                $line_no = $token[2] + substr_count(substr($token[1], 0, $offset), "\n");
                $line = trim((string) ($lines[$line_no - 1] ?? ''));
                $found[$path][$line] = true;
                if (!isset($allowed[$path][$line])) {
                    $unexpected[] = "{$path}:{$line_no}: {$line}";
                }
            }
        }
    }
}

$failures = [];
if ($unexpected) {
    $failures[] = "Database clock used in SQL (NOW()/CURDATE()/CURRENT_TIMESTAMP...).\n"
        . "PHP writes local time (Europe/Prague) but MariaDB runs in UTC, so these windows are off by 1-2 hours.\n"
        . "Pass a PHP-computed bound instead, e.g. 'created_at >= ?' with foxdesk_db_datetime_shift(-24, 'hour').\n"
        . "Only if the column is written and compared on the DB clock, add the line to the allow list in this test.\n  "
        . implode("\n  ", array_unique($unexpected));
}
foreach ($allowed as $path => $lines) {
    foreach (array_keys($lines) as $line) {
        if (!isset($found[$path][$line])) {
            $failures[] = "Stale allow-list entry (no longer in {$path}), remove it from this test: {$line}";
        }
    }
}

// The fixed call sites must keep using the PHP clock.
$must_contain = [
    'pages/reset-password.php' => ['reset_token_expires > ?', 'foxdesk_db_datetime()'],
    'includes/report-functions.php' => ["'(rt.expires_at IS NULL OR rt.expires_at > ?)'"],
    'includes/ticket-share-functions.php' => ['expires_at > ?) ORDER BY created_at DESC LIMIT 1", [$organization_id, foxdesk_db_datetime()]'],
    'includes/microsoft-mail-functions.php' => ['foxdesk_db_datetime(600)'],
    'includes/ticket-query-functions.php' => ['foxdesk_db_week_bounds()', 'DATE(t.due_date) = ?'],
    'includes/dashboard-functions.php' => ['$clock_today = foxdesk_db_date();'],
];
foreach ($must_contain as $path => $needles) {
    $source = (string) file_get_contents($root . '/' . $path);
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            $failures[] = "{$path} must keep using the PHP clock: missing {$needle}";
        }
    }
}

if ($failures) {
    fwrite(STDERR, implode("\n\n", $failures) . "\n");
    exit(1);
}
echo 'DB clock contract OK (' . count($files) . " files scanned)\n";
