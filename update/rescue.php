<?php
/**
 * FoxDesk Rescue Script
 *
 * Restores config.php from the latest backup after a failed update.
 * Upload this file to your web root via your hosting file manager (FTP/cPanel).
 * Then visit: https://your-domain.tld/rescue.php?token=YOUR_SECRET_KEY
 *
 * Security: requires ?token= parameter matching SECRET_KEY from backup config.
 * Delete this file after use!
 */

// Do not expose errors to unauthenticated visitors
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ── Authentication ──────────────────────────────────────────────────────────
// The rescue script requires a token to prevent unauthorized access.
// The token is the first 16 characters of your SECRET_KEY.
// Example: rescue.php?token=abcdef1234567890
//
// If config.php is broken, you can find SECRET_KEY in your backup's files.zip
// or in your hosting panel's environment variables.

$token = trim($_GET['token'] ?? '');
$authenticated = false;

if ($token === '' || strlen($token) < 12) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>FoxDesk Rescue</title></head><body>';
    echo '<h2>FoxDesk Rescue Script</h2>';
    echo '<p>This script requires a security token to proceed.</p>';
    echo '<p>Usage: <code>rescue.php?token=FIRST_16_CHARS_OF_SECRET_KEY</code></p>';
    echo '<p style="color:gray;font-size:12px">You can find your SECRET_KEY in your backup config.php or hosting panel.</p>';
    echo '</body></html>';
    exit;
}

// Try to load SECRET_KEY from current config.php
$config_path = __DIR__ . '/config.php';
if (file_exists($config_path)) {
    // Temporarily load config without triggering errors
    $config_content_raw = @file_get_contents($config_path);
    if ($config_content_raw !== false && preg_match("/define\('SECRET_KEY',\s*'([^']+)'\)/", $config_content_raw, $sk)) {
        $secret = $sk[1];
        if ($token === substr($secret, 0, 16) || $token === $secret) {
            $authenticated = true;
        }
    }
}

// If config.php is broken, check SECRET_KEY from the latest backup
if (!$authenticated) {
    $backup_dir = __DIR__ . '/backups';
    if (is_dir($backup_dir)) {
        $dirs = @scandir($backup_dir);
        if ($dirs) {
            $backup_configs = [];
            foreach ($dirs as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $info = $backup_dir . '/' . $entry . '/info.json';
                $fzip = $backup_dir . '/' . $entry . '/files.zip';
                if (file_exists($fzip)) {
                    $backup_configs[$entry] = $fzip;
                }
            }
            krsort($backup_configs);

            // Check each backup for a matching SECRET_KEY
            foreach ($backup_configs as $bid => $zpath) {
                $zip = new ZipArchive();
                if ($zip->open($zpath) !== true) continue;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (basename($name) === 'config.php' && strpos($name, 'config.example') === false) {
                        $cfg = $zip->getFromIndex($i);
                        if ($cfg !== false && preg_match("/define\('SECRET_KEY',\s*'([^']+)'\)/", $cfg, $bk)) {
                            if ($token === substr($bk[1], 0, 16) || $token === $bk[1]) {
                                $authenticated = true;
                                break 2;
                            }
                        }
                    }
                }
                $zip->close();
            }
        }
    }
}

if (!$authenticated) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>FoxDesk Rescue</title></head><body>';
    echo '<h2>Access Denied</h2>';
    echo '<p style="color:red">Invalid security token. Please use the first 16 characters of your SECRET_KEY.</p>';
    echo '</body></html>';
    exit;
}

// ── Authenticated — proceed with rescue ─────────────────────────────────────

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>FoxDesk Rescue</title>';
echo '<style>body{font-family:system-ui,sans-serif;max-width:700px;margin:40px auto;padding:0 20px;color:#334155}';
echo 'pre{background:#f1f5f9;padding:12px;border-radius:8px;overflow-x:auto;font-size:13px}';
echo '.btn{background:#3b82f6;color:white;padding:10px 20px;border:none;border-radius:6px;font-size:15px;cursor:pointer}';
echo '.btn:hover{background:#2563eb}</style></head><body>';

echo "<h2>FoxDesk Rescue Script</h2>\n";

// Find backup directory
$backup_dir = __DIR__ . '/backups';
if (!is_dir($backup_dir)) {
    echo "<p style='color:red'>ERROR: Backup directory not found.</p></body></html>";
    exit;
}

// Find backups with files.zip
$backups = [];
foreach (scandir($backup_dir) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $path = $backup_dir . '/' . $entry;
    if (is_dir($path) && file_exists($path . '/files.zip')) {
        $backups[$entry] = $path;
    }
}

if (empty($backups)) {
    echo "<p style='color:red'>ERROR: No backups found with files.zip</p></body></html>";
    exit;
}

krsort($backups);
$latest_id = key($backups);
$latest_path = $backups[$latest_id];

echo "<p>Found " . count($backups) . " backup(s). Latest: <b>" . htmlspecialchars($latest_id) . "</b></p>\n";

// Extract config.php from the backup ZIP
$zip = new ZipArchive();
if ($zip->open($latest_path . '/files.zip') !== true) {
    echo "<p style='color:red'>ERROR: Cannot open backup ZIP</p></body></html>";
    exit;
}

$config_content = null;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (basename($name) === 'config.php' && strpos($name, 'config.example') === false) {
        $config_content = $zip->getFromIndex($i);
        break;
    }
}
$zip->close();

if ($config_content === null) {
    echo "<p style='color:red'>ERROR: config.php not found in backup ZIP</p></body></html>";
    exit;
}

// Show what we found (hide sensitive values)
$display = preg_replace("/define\('DB_PASS',\s*'[^']*'\)/", "define('DB_PASS', '***HIDDEN***')", $config_content);
$display = preg_replace("/define\('SECRET_KEY',\s*'[^']*'\)/", "define('SECRET_KEY', '***HIDDEN***')", $display);
echo "<h3>Config from backup:</h3>\n";
echo "<pre>" . htmlspecialchars($display) . "</pre>\n";

// Handle restore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_restore'])) {
    // Simple CSRF: verify the token is still in the POST
    if (($_POST['rescue_token'] ?? '') !== $token) {
        echo "<p style='color:red'>ERROR: Token mismatch. Please reload and try again.</p></body></html>";
        exit;
    }

    $target = __DIR__ . '/config.php';
    if (file_put_contents($target, $config_content) !== false) {
        echo "<p style='color:green;font-size:18px'><b>SUCCESS!</b> config.php has been restored from backup.</p>\n";
        echo "<p><a href='index.php'>Go to FoxDesk &rarr;</a></p>\n";
        echo "<p style='color:#d97706'><b>IMPORTANT:</b> Delete this rescue.php file from your server now!</p>\n";
    } else {
        echo "<p style='color:red'>ERROR: Failed to write config.php. Check file permissions.</p>\n";
    }
} else {
    echo "<form method='post'>\n";
    echo "<input type='hidden' name='rescue_token' value='" . htmlspecialchars($token) . "'>\n";
    echo "<button type='submit' name='confirm_restore' value='1' class='btn'>Restore config.php from backup</button>\n";
    echo "</form>\n";
    echo "<p style='color:gray;font-size:12px'>This will overwrite the current config.php with the one from backup " . htmlspecialchars($latest_id) . ".</p>\n";
}

echo "</body></html>";
