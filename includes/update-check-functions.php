<?php
/**
 * Remote Update Check Functions
 *
 * Checks for new versions via:
 *   1. foxdesk.org/api/version-check (primary — custom server)
 *   2. GitHub Releases API (fallback — always available)
 *
 * When a newer version is found, the admin sees a notification banner
 * in the header and a detailed card on Settings → System with changelog
 * and one-click "Download & Install" button.
 */

// Remote API endpoint for version check (primary)
if (!defined('FOXDESK_UPDATE_CHECK_URL')) {
    define('FOXDESK_UPDATE_CHECK_URL', 'https://foxdesk.org/api/version-check');
}

// GitHub repository for release fallback
if (!defined('FOXDESK_GITHUB_REPO')) {
    define('FOXDESK_GITHUB_REPO', 'lukashanes/foxdesk');
}

// How often to auto-check (in seconds) — 12 hours
if (!defined('UPDATE_CHECK_INTERVAL')) {
    define('UPDATE_CHECK_INTERVAL', 43200);
}

// Max download size for remote update packages — 50 MB
if (!defined('UPDATE_MAX_DOWNLOAD_SIZE')) {
    define('UPDATE_MAX_DOWNLOAD_SIZE', 50 * 1024 * 1024);
}

/**
 * Check whether automatic update checking is enabled.
 */
function is_update_check_enabled(): bool
{
    return (bool) get_setting('update_check_enabled', '1');
}

/**
 * Check for available updates from the remote server.
 *
 * @param bool $force Skip cache and always hit the remote server.
 * @return array|false Array with update info if newer version available, false if up-to-date or on error.
 */
function check_for_updates(bool $force = false)
{
    // Return cached result if recent enough (unless forced)
    if (!$force) {
        $last_run = get_setting('update_check_last_run', '');
        if ($last_run !== '' && (time() - strtotime($last_run)) < UPDATE_CHECK_INTERVAL) {
            return get_cached_update_info();
        }
    }

    // Make HTTP request to remote API (tries foxdesk.org first, then GitHub)
    $response = fetch_remote_version_info();
    if ($response === false) {
        // Save the timestamp so we don't retry immediately on failure
        save_setting('update_check_last_run', date('Y-m-d H:i:s'));
        return get_cached_update_info(); // Return cached data if available
    }

    // Store the check result
    save_setting('update_check_last_run', date('Y-m-d H:i:s'));
    save_setting('update_check_latest_version', (string) ($response['latest_version'] ?? ''));
    save_setting('update_check_download_url', (string) ($response['download_url'] ?? ''));
    save_setting('update_check_changelog', json_encode($response['changelog'] ?? []));
    save_setting('update_check_released_at', (string) ($response['released_at'] ?? ''));
    save_setting('update_check_min_php', (string) ($response['min_php'] ?? ''));
    save_setting('update_check_min_db_version', (string) ($response['min_db_version'] ?? ''));

    // Compare versions
    $current_version = defined('APP_VERSION') ? APP_VERSION : '0.0';
    $remote_version = (string) ($response['latest_version'] ?? '');

    if ($remote_version !== '' && version_compare($remote_version, $current_version, '>')) {
        return [
            'version' => $remote_version,
            'download_url' => (string) ($response['download_url'] ?? ''),
            'changelog' => (array) ($response['changelog'] ?? []),
            'released_at' => (string) ($response['released_at'] ?? ''),
            'min_php' => (string) ($response['min_php'] ?? ''),
            'min_db_version' => (string) ($response['min_db_version'] ?? ''),
        ];
    }

    return false;
}

/**
 * Get cached update info from settings (no HTTP request).
 *
 * @return array|false Array with update info if newer version available, false otherwise.
 */
function get_cached_update_info()
{
    $version = get_setting('update_check_latest_version', '');
    if ($version === '') {
        return false;
    }

    $current_version = defined('APP_VERSION') ? APP_VERSION : '0.0';
    if (!version_compare($version, $current_version, '>')) {
        return false;
    }

    $changelog_raw = get_setting('update_check_changelog', '[]');
    $changelog = json_decode($changelog_raw, true);

    return [
        'version' => $version,
        'download_url' => get_setting('update_check_download_url', ''),
        'changelog' => is_array($changelog) ? $changelog : [],
        'released_at' => get_setting('update_check_released_at', ''),
        'min_php' => get_setting('update_check_min_php', ''),
        'min_db_version' => get_setting('update_check_min_db_version', ''),
    ];
}

/**
 * Fetch version info from the remote FoxDesk update server.
 * Falls back to GitHub Releases API if the primary server is unreachable.
 *
 * @return array|false Parsed response with latest_version, download_url, changelog, etc.
 */
function fetch_remote_version_info()
{
    // 1. Try foxdesk.org first (custom endpoint — may not exist yet)
    $response = fetch_foxdesk_org_version_info();
    if ($response !== false) {
        return $response;
    }

    // 2. Fallback to GitHub Releases API (always available)
    $response = fetch_github_release_info();
    if ($response !== false) {
        return $response;
    }

    return false;
}

/**
 * Fetch version info from foxdesk.org custom API.
 *
 * @return array|false Parsed JSON response, or false on failure.
 */
function fetch_foxdesk_org_version_info()
{
    $url = FOXDESK_UPDATE_CHECK_URL;

    // Build query string with instance info for analytics
    $params = http_build_query([
        'v' => defined('APP_VERSION') ? APP_VERSION : '0.0',
        'php' => PHP_VERSION,
    ]);

    $full_url = $url . '?' . $params;

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8, // short timeout — GitHub is the reliable fallback
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: FoxDesk/" . (defined('APP_VERSION') ? APP_VERSION : '0.0') . "\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($full_url, false, $context);
    if ($response === false) {
        // Silently fall through to GitHub — foxdesk.org may not be set up yet
        return false;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['latest_version'])) {
        return false;
    }

    return $data;
}

/**
 * Fetch latest release info from GitHub Releases API.
 *
 * Maps GitHub release data to the standard format expected by check_for_updates():
 *   - latest_version: tag name (stripped of 'v' prefix)
 *   - download_url:   browser_download_url of first .zip asset
 *   - changelog:      parsed from release body (lines starting with "- ")
 *   - released_at:    ISO date
 *   - min_php:        extracted from body or version.json inside the ZIP
 *
 * @return array|false Parsed release info, or false on failure.
 */
function fetch_github_release_info()
{
    $repo = FOXDESK_GITHUB_REPO;
    if (empty($repo)) {
        return false;
    }

    $url = "https://api.github.com/repos/$repo/releases/latest";

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true,
            'header' => implode("\r\n", [
                'Accept: application/vnd.github.v3+json',
                'User-Agent: FoxDesk/' . (defined('APP_VERSION') ? APP_VERSION : '0.0'),
            ]) . "\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        if (function_exists('debug_log')) {
            debug_log('GitHub release check failed: could not reach API', ['repo' => $repo], 'warning', 'update-check');
        }
        return false;
    }

    $release = json_decode($response, true);
    if (!is_array($release) || empty($release['tag_name'])) {
        if (function_exists('debug_log')) {
            debug_log('GitHub release check: invalid response', ['response' => substr($response, 0, 500)], 'warning', 'update-check');
        }
        return false;
    }

    // Skip draft/prerelease
    if (!empty($release['draft']) || !empty($release['prerelease'])) {
        return false;
    }

    // Parse version from tag (strip 'v' prefix: "v0.3.47" → "0.3.47")
    $tag = (string) $release['tag_name'];
    $version = ltrim($tag, 'vV');
    if (!preg_match('/^\d+\.\d+/', $version)) {
        return false; // not a version tag
    }

    // Find the .zip asset (update package)
    $download_url = '';
    $assets = (array) ($release['assets'] ?? []);
    foreach ($assets as $asset) {
        $name = strtolower((string) ($asset['name'] ?? ''));
        if (str_ends_with($name, '.zip') && str_contains($name, 'foxdesk')) {
            $download_url = (string) ($asset['browser_download_url'] ?? '');
            break;
        }
    }

    // If no named asset found, try the first .zip
    if ($download_url === '') {
        foreach ($assets as $asset) {
            $name = strtolower((string) ($asset['name'] ?? ''));
            if (str_ends_with($name, '.zip')) {
                $download_url = (string) ($asset['browser_download_url'] ?? '');
                break;
            }
        }
    }

    // Parse changelog from release body
    $changelog = parse_github_release_body((string) ($release['body'] ?? ''));

    // Parse release date
    $released_at = '';
    if (!empty($release['published_at'])) {
        $released_at = date('Y-m-d', strtotime($release['published_at']));
    }

    // Extract min_php from body (look for "min_php: X.Y" or "Requires PHP X.Y+")
    $min_php = '';
    $body = (string) ($release['body'] ?? '');
    if (preg_match('/(?:min[_ ]?php|requires?\s+php)\s*[:=]?\s*(\d+\.\d+)/i', $body, $m)) {
        $min_php = $m[1];
    }

    return [
        'latest_version' => $version,
        'download_url' => $download_url,
        'changelog' => $changelog,
        'released_at' => $released_at,
        'min_php' => $min_php,
        'min_db_version' => '',
    ];
}

/**
 * Parse changelog entries from GitHub release body (Markdown).
 *
 * Looks for lines starting with "- " or "* " under a "Changes" heading,
 * or just any bullet-point lines if no heading is found.
 *
 * @param string $body Raw Markdown body from GitHub release.
 * @return array List of changelog strings.
 */
function parse_github_release_body(string $body): array
{
    if (trim($body) === '') {
        return [];
    }

    $lines = preg_split('/\r?\n/', $body);
    $changelog = [];
    $in_changes_section = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Detect "### Changes" or "## Changelog" or "## What's Changed" heading
        if (preg_match('/^#{1,4}\s*(changes|changelog|what\'?s?\s*changed)/i', $trimmed)) {
            $in_changes_section = true;
            continue;
        }

        // Stop at next heading
        if ($in_changes_section && preg_match('/^#{1,4}\s/', $trimmed) && !preg_match('/^#{1,4}\s*(changes|changelog)/i', $trimmed)) {
            $in_changes_section = false;
            continue;
        }

        // Collect bullet points
        if (preg_match('/^[-*]\s+(.+)/', $trimmed, $m)) {
            $entry = trim($m[1]);
            // Skip common GitHub auto-generated entries
            if (preg_match('/^(Full Changelog|New Contributors|http)/i', $entry)) {
                continue;
            }
            $changelog[] = $entry;
        }
    }

    // If we found no changelog entries in a "Changes" section, collect all bullets
    if (empty($changelog)) {
        foreach ($lines as $line) {
            if (preg_match('/^[-*]\s+(.+)/', trim($line), $m)) {
                $entry = trim($m[1]);
                if (!preg_match('/^(Full Changelog|New Contributors|http)/i', $entry)) {
                    $changelog[] = $entry;
                }
            }
        }
    }

    return $changelog;
}

/**
 * Download an update package from a remote URL.
 *
 * Allowed sources:
 *   - foxdesk.org (HTTPS)
 *   - GitHub (github.com, objects.githubusercontent.com)
 *
 * @param string $url The URL to download from.
 * @return string|false Local file path on success, false on failure.
 */
function download_remote_update(string $url): string|false
{
    if (empty($url)) {
        return false;
    }

    // Security: only allow HTTPS downloads from trusted hosts
    $parsed = parse_url($url);
    $host = strtolower($parsed['host'] ?? '');
    $scheme = strtolower($parsed['scheme'] ?? '');

    $allowed_hosts = [
        'foxdesk.org',
        'github.com',
        'objects.githubusercontent.com',
    ];

    $is_trusted = false;
    if ($scheme === 'https') {
        foreach ($allowed_hosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                $is_trusted = true;
                break;
            }
        }
    }

    if (!$is_trusted) {
        if (function_exists('debug_log')) {
            debug_log('Update download blocked: untrusted source', ['url' => $url, 'host' => $host], 'warning', 'update-check');
        }
        return false;
    }

    // Ensure backup directory exists for temp storage
    $backup_dir = defined('BACKUP_DIR') ? BACKUP_DIR : (defined('BASE_PATH') ? BASE_PATH . '/backups' : __DIR__ . '/../backups');
    if (!is_dir($backup_dir)) {
        @mkdir($backup_dir, 0755, true);
    }

    $temp_file = $backup_dir . '/foxdesk_remote_update_' . uniqid('', true) . '.zip';

    // GitHub asset downloads may redirect — use follow_location
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 120,
            'ignore_errors' => true,
            'follow_location' => true,
            'max_redirects' => 5,
            'header' => implode("\r\n", [
                'User-Agent: FoxDesk/' . (defined('APP_VERSION') ? APP_VERSION : '0.0'),
                'Accept: application/octet-stream',
            ]) . "\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    // Stream download to avoid memory issues
    $remote = @fopen($url, 'rb', false, $context);
    if ($remote === false) {
        if (function_exists('debug_log')) {
            debug_log('Update download failed: could not open URL', ['url' => $url], 'error', 'update-check');
        }
        return false;
    }

    $local = @fopen($temp_file, 'wb');
    if ($local === false) {
        fclose($remote);
        if (function_exists('debug_log')) {
            debug_log('Update download failed: could not create temp file', ['path' => $temp_file], 'error', 'update-check');
        }
        return false;
    }

    $bytes_written = 0;
    while (!feof($remote)) {
        $chunk = fread($remote, 8192);
        if ($chunk === false) {
            break;
        }
        $bytes_written += fwrite($local, $chunk);

        // Size limit check
        if ($bytes_written > UPDATE_MAX_DOWNLOAD_SIZE) {
            fclose($remote);
            fclose($local);
            @unlink($temp_file);
            if (function_exists('debug_log')) {
                debug_log('Update download aborted: file too large', ['bytes' => $bytes_written], 'warning', 'update-check');
            }
            return false;
        }
    }

    fclose($remote);
    fclose($local);

    // Verify we actually got data
    if ($bytes_written < 100) {
        @unlink($temp_file);
        if (function_exists('debug_log')) {
            debug_log('Update download failed: file too small', ['bytes' => $bytes_written], 'warning', 'update-check');
        }
        return false;
    }

    // Verify it's a valid ZIP file
    $zip = new ZipArchive();
    if ($zip->open($temp_file) !== true) {
        @unlink($temp_file);
        if (function_exists('debug_log')) {
            debug_log('Update download failed: not a valid ZIP', ['path' => $temp_file], 'error', 'update-check');
        }
        return false;
    }
    $zip->close();

    return $temp_file;
}

/**
 * Dismiss the update notice for a specific version.
 */
function dismiss_update_notice(string $version): void
{
    save_setting('update_check_dismissed_version', $version);
}

/**
 * Check if the update notice was dismissed for the given version.
 */
function is_update_dismissed(string $version): bool
{
    return get_setting('update_check_dismissed_version', '') === $version;
}

/**
 * Get timestamp of last update check.
 */
function get_last_update_check_time(): string
{
    return get_setting('update_check_last_run', '');
}

/**
 * Clear all cached update check data (e.g. after successful update).
 */
function clear_update_check_cache(): void
{
    save_setting('update_check_latest_version', '');
    save_setting('update_check_download_url', '');
    save_setting('update_check_changelog', '[]');
    save_setting('update_check_released_at', '');
    save_setting('update_check_min_php', '');
    save_setting('update_check_min_db_version', '');
    save_setting('update_check_dismissed_version', '');
    save_setting('update_check_last_run', '');
}
