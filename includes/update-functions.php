<?php
/**
 * Application Update Functions
 *
 * Handles backup, update, and rollback functionality.
 */

define('BACKUP_DIR', BASE_PATH . '/backups');
define('MAX_BACKUPS', 5);
define('MAINTENANCE_FILE', BASE_PATH . '/.maintenance');

/**
 * Relax script limits for long-running update operations.
 */
function enable_long_running_execution(): void
{
    if (function_exists('ignore_user_abort')) {
        @ignore_user_abort(true);
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    @ini_set('max_execution_time', '0');
}

/**
 * Write update-related debug log entries when logging subsystem is available.
 */
function update_audit_log($message, $context = [], $level = 'info'): void
{
    if (function_exists('debug_log')) {
        debug_log((string) $message, $context, $level, 'update');
    }
}

/**
 * Write security event entries for update/backup actions.
 */
function update_security_event($event_type, $context = []): void
{
    if (!function_exists('log_security_event')) {
        return;
    }

    $user_id = null;
    if (function_exists('current_user')) {
        $current = current_user();
        $user_id = $current['id'] ?? null;
    }

    if (!is_string($context)) {
        $context = json_encode($context, JSON_UNESCAPED_UNICODE);
    }

    log_security_event((string) $event_type, $user_id, (string) ($context ?: ''));
}

/**
 * Enable maintenance mode — blocks all non-admin requests during update/rollback.
 * The .maintenance file contains a Unix timestamp; index.php checks it.
 * Auto-expires after 10 minutes as a safety net.
 */
function enable_maintenance_mode(): void
{
    @file_put_contents(MAINTENANCE_FILE, (string) time());

    // Set bypass flag so the admin performing the update is not locked out
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['maintenance_bypass'] = true;
    }
}

/**
 * Disable maintenance mode — removes the .maintenance file.
 */
function disable_maintenance_mode(): void
{
    if (file_exists(MAINTENANCE_FILE)) {
        @unlink(MAINTENANCE_FILE);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['maintenance_bypass']);
    }
}

/**
 * Pre-flight checks before applying an update.
 * Verifies disk space, directory permissions, and critical file writability.
 *
 * @return array{ok: bool, errors: string[], warnings: string[]}
 */
function preflight_check(): array
{
    $errors = [];
    $warnings = [];

    // 1. Disk space — require at least 100 MB free
    $free = @disk_free_space(BASE_PATH);
    if ($free !== false && $free < 100 * 1024 * 1024) {
        $errors[] = t('Less than 100 MB free disk space ({free} available).', [
            'free' => format_filesize((int) $free)
        ]);
    }

    // 2. Critical directories must be writable
    $dirs_to_check = ['includes', 'pages', 'assets', 'pages/admin'];
    foreach ($dirs_to_check as $dir) {
        $full = BASE_PATH . '/' . $dir;
        if (is_dir($full) && !is_writable($full)) {
            $errors[] = t('Directory not writable: {dir}', ['dir' => $dir]);
        }
    }

    // 3. index.php must be writable (for APP_VERSION update)
    if (file_exists(BASE_PATH . '/index.php') && !is_writable(BASE_PATH . '/index.php')) {
        $errors[] = t('File not writable: index.php (needed for version update).');
    }

    // 4. Backup directory must be writable (or creatable)
    if (is_dir(BACKUP_DIR)) {
        if (!is_writable(BACKUP_DIR)) {
            $errors[] = t('Backup directory not writable: {dir}', ['dir' => 'backups/']);
        }
    } else {
        // Try to create it
        $parent = dirname(BACKUP_DIR);
        if (!is_writable($parent)) {
            $errors[] = t('Cannot create backup directory — parent not writable.');
        }
    }

    // 5. PHP extensions required
    if (!class_exists('ZipArchive')) {
        $errors[] = t('PHP extension missing: zip (required for update packages).');
    }

    // 6. Temp directory writable
    $tmp = sys_get_temp_dir();
    if (!is_writable($tmp)) {
        $warnings[] = t('System temp directory not writable: {dir}', ['dir' => $tmp]);
    }

    return [
        'ok' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings,
    ];
}

/**
 * Resolve backup directory path safely by backup id.
 */
function resolve_backup_path($backup_id): ?string
{
    $safe_id = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $backup_id);
    if ($safe_id === '') {
        return null;
    }

    $base = realpath(BACKUP_DIR);
    if ($base === false) {
        return null;
    }

    $candidate = realpath(BACKUP_DIR . '/' . $safe_id);
    if ($candidate === false || !is_dir($candidate)) {
        return null;
    }

    if (strpos($candidate, $base) !== 0) {
        return null;
    }

    return $candidate;
}

/**
 * Prepare a backup file download payload.
 * Supported types: bundle (zip), database (sql), files (files.zip), info (json).
 */
function prepare_backup_download($backup_id, $type = 'bundle'): array
{
    $result = [
        'success' => false,
        'path' => null,
        'filename' => null,
        'mime' => 'application/octet-stream',
        'cleanup' => false,
        'error' => null,
    ];

    $backup_path = resolve_backup_path($backup_id);
    if ($backup_path === null) {
        $result['error'] = t('Backup not found.');
        return $result;
    }

    $type = strtolower(trim((string) $type));
    if (!in_array($type, ['bundle', 'database', 'files', 'info'], true)) {
        $type = 'bundle';
    }

    $safe_id = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $backup_id);
    $safe_id = $safe_id !== '' ? $safe_id : 'backup';

    if ($type === 'database') {
        $path = $backup_path . '/database.sql';
        if (!file_exists($path)) {
            $result['error'] = t('Backup file not available.');
            return $result;
        }
        $result['success'] = true;
        $result['path'] = $path;
        $result['filename'] = 'foxdesk-backup-' . $safe_id . '-database.sql';
        $result['mime'] = 'application/sql';
        return $result;
    }

    if ($type === 'files') {
        $path = $backup_path . '/files.zip';
        if (!file_exists($path)) {
            $result['error'] = t('Backup file not available.');
            return $result;
        }
        $result['success'] = true;
        $result['path'] = $path;
        $result['filename'] = 'foxdesk-backup-' . $safe_id . '-files.zip';
        $result['mime'] = 'application/zip';
        return $result;
    }

    if ($type === 'info') {
        $path = $backup_path . '/info.json';
        if (!file_exists($path)) {
            $result['error'] = t('Backup file not available.');
            return $result;
        }
        $result['success'] = true;
        $result['path'] = $path;
        $result['filename'] = 'foxdesk-backup-' . $safe_id . '-info.json';
        $result['mime'] = 'application/json';
        return $result;
    }

    // Bundle: build temporary zip containing files.zip + database.sql + info.json.
    $tmp_zip = sys_get_temp_dir() . '/foxdesk_backup_download_' . uniqid('', true) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $result['error'] = t('Failed to prepare backup download.');
        return $result;
    }

    $included = 0;
    foreach (['files.zip', 'database.sql', 'info.json'] as $name) {
        $src = $backup_path . '/' . $name;
        if (file_exists($src)) {
            $zip->addFile($src, $name);
            $included++;
        }
    }
    $zip->close();

    if ($included === 0 || !file_exists($tmp_zip)) {
        @unlink($tmp_zip);
        $result['error'] = t('Backup file not available.');
        return $result;
    }

    $result['success'] = true;
    $result['path'] = $tmp_zip;
    $result['filename'] = 'foxdesk-backup-' . $safe_id . '.zip';
    $result['mime'] = 'application/zip';
    $result['cleanup'] = true;
    return $result;
}

/**
 * Find entry in ZIP package, supporting optional top-level folder wrappers.
 */
function find_zip_entry(ZipArchive $zip, $relative_path): ?string
{
    $target = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
    if ($target === '') {
        return null;
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $normalized = ltrim(str_replace('\\', '/', $name), '/');
        if ($normalized === $target) {
            return $name;
        }
        if (preg_match('#(^|/)' . preg_quote($target, '#') . '$#i', $normalized)) {
            return $name;
        }
    }

    return null;
}

/**
 * Determine package root prefix from matched version.json path.
 */
function get_package_root_prefix($version_entry): string
{
    $normalized = trim(str_replace('\\', '/', (string) $version_entry), '/');
    if ($normalized === '' || preg_match('#(^|/)version\.json$#i', $normalized) !== 1) {
        return '';
    }

    $prefix = preg_replace('#/version\.json$#i', '', $normalized);
    return $prefix === 'version.json' ? '' : trim((string) $prefix, '/');
}

/**
 * Build absolute path to package-relative file/dir in extracted temp folder.
 */
function package_extract_path($temp_dir, $package_root, $relative_path): string
{
    $base = rtrim((string) $temp_dir, '/\\');
    $prefix = trim((string) $package_root, '/\\');
    $rel = ltrim((string) $relative_path, '/\\');
    if ($prefix !== '') {
        $base .= '/' . $prefix;
    }
    return $base . '/' . $rel;
}

/**
 * Detect whether the ZIP contains application payload at package root.
 *
 * Compatible flat packages contain normal app files like index.php, includes/,
 * pages/, or assets/ directly next to version.json instead of under files/.
 */
function zip_has_flat_app_payload(ZipArchive $zip, string $package_root = ''): bool
{
    $prefix = trim($package_root, '/\\');
    if ($prefix !== '') {
        $prefix .= '/';
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = ltrim(str_replace('\\', '/', (string) $zip->getNameIndex($i)), '/');
        if ($prefix !== '' && strpos($name, $prefix) === 0) {
            $name = substr($name, strlen($prefix));
        }

        if ($name === '' || $name === 'version.json') {
            continue;
        }

        if (
            $name === 'index.php'
            || $name === 'attachment.php'
            || $name === 'image.php'
            || strpos($name, 'includes/') === 0
            || strpos($name, 'pages/') === 0
            || strpos($name, 'assets/') === 0
        ) {
            return true;
        }
    }

    return false;
}

/**
 * Resolve the extracted directory that should be copied into BASE_PATH.
 *
 * Preferred format is files/, but we also support flat packages where the app
 * payload lives directly at package root next to version.json.
 *
 * @return array{path:string, mode:string}|null
 */
function resolve_package_payload_dir(string $temp_dir, string $package_root): ?array
{
    $files_dir = package_extract_path($temp_dir, $package_root, 'files');
    if (is_dir($files_dir)) {
        return [
            'path' => $files_dir,
            'mode' => 'files',
        ];
    }

    $root_dir = rtrim(package_extract_path($temp_dir, $package_root, ''), '/\\');
    if ($root_dir !== '' && is_dir($root_dir)) {
        return [
            'path' => $root_dir,
            'mode' => 'flat',
        ];
    }

    return null;
}

/**
 * Split SQL script into statements while respecting strings/comments.
 */
function split_sql_statements($sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen((string) $sql);

    $in_single = false;
    $in_double = false;
    $in_backtick = false;
    $in_line_comment = false;
    $in_block_comment = false;
    $escaped = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = ($i + 1 < $length) ? $sql[$i + 1] : '';
        $after_next = ($i + 2 < $length) ? $sql[$i + 2] : '';

        if ($in_line_comment) {
            if ($char === "\n") {
                $in_line_comment = false;
                $buffer .= $char;
            }
            continue;
        }

        if ($in_block_comment) {
            if ($char === '*' && $next === '/') {
                $in_block_comment = false;
                $i++;
            }
            continue;
        }

        if (!$in_single && !$in_double && !$in_backtick) {
            if ($char === '-' && $next === '-' && ($after_next === '' || ctype_space($after_next))) {
                $in_line_comment = true;
                $i++;
                continue;
            }
            if ($char === '#') {
                $in_line_comment = true;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $in_block_comment = true;
                $i++;
                continue;
            }
        }

        if ($char === "'" && !$in_double && !$in_backtick) {
            if (!$escaped) {
                $in_single = !$in_single;
            }
            $buffer .= $char;
            $escaped = false;
            continue;
        }

        if ($char === '"' && !$in_single && !$in_backtick) {
            if (!$escaped) {
                $in_double = !$in_double;
            }
            $buffer .= $char;
            $escaped = false;
            continue;
        }

        if ($char === '`' && !$in_single && !$in_double) {
            $in_backtick = !$in_backtick;
            $buffer .= $char;
            $escaped = false;
            continue;
        }

        if ($char === ';' && !$in_single && !$in_double && !$in_backtick) {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            $escaped = false;
            continue;
        }

        $buffer .= $char;
        if (($in_single || $in_double) && $char === '\\') {
            $escaped = !$escaped;
        } else {
            $escaped = false;
        }
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

/**
 * Compare two version strings
 * @return int -1 if v1 < v2, 0 if equal, 1 if v1 > v2
 */
function compare_versions($v1, $v2): int
{
    return version_compare($v1, $v2);
}

/**
 * Get current application version
 */
function get_current_version(): string
{
    return defined('APP_VERSION') ? APP_VERSION : '0.0';
}

/**
 * Ensure backup directory exists and is protected.
 *
 * Creates .htaccess (Apache) and index.html (nginx/fallback) to prevent
 * direct web access to backup files (database dumps contain passwords).
 */
function ensure_backup_dir(): bool
{
    if (!is_dir(BACKUP_DIR)) {
        if (!mkdir(BACKUP_DIR, 0755, true)) {
            return false;
        }
    }

    // Apache protection — works on both 2.2 and 2.4+
    $htaccess = BACKUP_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        $rules = "# Deny all access to backup files.\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order deny,allow\n"
            . "    Deny from all\n"
            . "</IfModule>\n";
        file_put_contents($htaccess, $rules);
    }

    // Nginx / fallback — prevents directory listing and shows 403 page
    $index = BACKUP_DIR . '/index.html';
    if (!file_exists($index)) {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>403 Forbidden</title></head>'
            . '<body><h1>Forbidden</h1><p>You don\'t have permission to access this resource.</p></body></html>';
        file_put_contents($index, $html);
    }

    return true;
}

/**
 * Validate an update package (ZIP file)
 */
function validate_update_package($zip_path): array
{
    $result = [
        'valid' => false,
        'error' => null,
        'errors' => [],
        'warnings' => [],
        'version' => null,
        'changelog' => [],
        'version_info' => null,
        'package_root' => ''
    ];

    // Check file exists
    if (!file_exists($zip_path)) {
        $result['errors'][] = t('Update package not found.');
        $result['error'] = t('Update package not found.');
        return $result;
    }

    // Check it's a valid ZIP
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        $result['errors'][] = t('Invalid ZIP file.');
        $result['error'] = t('Invalid ZIP file.');
        return $result;
    }

    // Check for version.json (root or wrapped folder)
    $version_entry = find_zip_entry($zip, 'version.json');
    $version_content = $version_entry !== null ? $zip->getFromName($version_entry) : false;
    if ($version_content === false) {
        $zip->close();
        $result['errors'][] = t('Missing version.json in update package.');
        $result['error'] = t('Missing version.json in update package.');
        return $result;
    }

    // Parse version.json
    $version_info = json_decode($version_content, true);
    if (!$version_info || !isset($version_info['version'])) {
        $zip->close();
        $result['errors'][] = t('Invalid version.json format.');
        $result['error'] = t('Invalid version.json format.');
        return $result;
    }

    $result['version_info'] = $version_info;
    $result['version'] = $version_info['version'];
    $result['changelog'] = $version_info['changelog'] ?? [];
    $result['package_root'] = get_package_root_prefix($version_entry);
    if (!empty($result['version']) && !empty($result['changelog'])) {
        cache_update_changelog((string) $result['version'], (array) $result['changelog']);
    }

    // Check PHP version requirement
    if (isset($version_info['min_php'])) {
        if (version_compare(PHP_VERSION, $version_info['min_php'], '<')) {
            $result['errors'][] = t('PHP version {required} or higher required. You have {current}.', [
                'required' => $version_info['min_php'],
                'current' => PHP_VERSION
            ]);
        }
    }

    // Check minimum app version requirement
    if (isset($version_info['min_db_version'])) {
        $current = get_current_version();
        if (compare_versions($current, $version_info['min_db_version']) < 0) {
            $result['errors'][] = t('You need at least version {required} to apply this update. You have {current}.', [
                'required' => $version_info['min_db_version'],
                'current' => $current
            ]);
        }
    }

    // Check we're not downgrading
    $new_version = $version_info['version'];
    $current = get_current_version();
    if (compare_versions($new_version, $current) <= 0) {
        $result['warnings'][] = t('Update version ({new}) is not newer than current version ({current}).', [
            'new' => $new_version,
            'current' => $current
        ]);
    }

    // Verify SHA-256 checksum if provided by remote update server
    if (isset($version_info['checksum_sha256'])) {
        $expected_hash = strtolower(trim((string) $version_info['checksum_sha256']));
        // The checksum in version.json is computed over the ZIP excluding version.json itself,
        // so we verify against the whole ZIP file (the hash was computed before packaging).
        // For simplicity, the remote server provides the hash of the entire ZIP package.
        $actual_hash = strtolower(hash_file('sha256', $zip_path));
        if ($expected_hash !== '' && $actual_hash !== $expected_hash) {
            $result['errors'][] = t('Checksum verification failed. Package may be corrupted or tampered with.');
        } else {
            $result['messages'][] = t('SHA-256 checksum verified.');
        }
    }

    // Verify individual file checksums if provided
    if (isset($version_info['files_checksums']) && is_array($version_info['files_checksums'])) {
        $result['files_checksums'] = $version_info['files_checksums'];
    }

    // Check for files/ directory
    $has_files = false;
    $prefix = $result['package_root'] !== '' ? $result['package_root'] . '/' : '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = ltrim(str_replace('\\', '/', (string) $zip->getNameIndex($i)), '/');
        if ($prefix !== '' && strpos($name, $prefix) === 0) {
            $name = substr($name, strlen($prefix));
        }
        if (strpos($name, 'files/') === 0) {
            $has_files = true;
            break;
        }
    }

    if (!$has_files) {
        if (zip_has_flat_app_payload($zip, $result['package_root'])) {
            $result['warnings'][] = t('Flat update package detected. Root files will be applied for compatibility.');
        } else {
            $result['errors'][] = t('Update package contains no application files.');
            $result['error'] = t('Update package contains no application files.');
        }
    }

    $zip->close();

    // Valid if no errors
    $result['valid'] = empty($result['errors']);
    if (!$result['valid'] && ($result['error'] === null || trim((string) $result['error']) === '') && !empty($result['errors'])) {
        $result['error'] = implode(' | ', array_values(array_filter((array) $result['errors'], static function ($item) {
            return is_string($item) && trim($item) !== '';
        })));
    }

    return $result;
}

/**
 * Create a backup of current application
 */
function create_backup(): array
{
    enable_long_running_execution();
    update_audit_log('Backup creation started.');
    update_security_event('backup_create_started');

    $result = [
        'success' => false,
        'backup_id' => null,
        'backup_path' => null,
        'errors' => []
    ];

    // Ensure backup directory exists
    if (!ensure_backup_dir()) {
        $result['errors'][] = t('Failed to create backup directory.');
        return $result;
    }

    // Create backup folder with timestamp
    $version = get_current_version();
    $backup_id = date('Y-m-d_His') . '_v' . str_replace('.', '-', $version);
    $backup_path = BACKUP_DIR . '/' . $backup_id;

    if (!mkdir($backup_path, 0755, true)) {
        $result['errors'][] = t('Failed to create backup folder.');
        return $result;
    }

    // Create ZIP of application files
    $files_zip_path = $backup_path . '/files.zip';
    if (!create_files_backup($files_zip_path)) {
        $result['errors'][] = t('Failed to create files backup.');
        return $result;
    }

    // Create database backup
    $db_path = $backup_path . '/database.sql';
    if (!create_database_backup($db_path)) {
        $result['errors'][] = t('Failed to create database backup.');
        // Continue anyway, files backup is more important
    }

    // Save backup info
    $info = [
        'backup_id' => $backup_id,
        'version' => $version,
        'created_at' => date('Y-m-d H:i:s'),
        'created_by_user_id' => function_exists('current_user') ? (current_user()['id'] ?? null) : null,
        'php_version' => PHP_VERSION,
        'files_size' => file_exists($files_zip_path) ? filesize($files_zip_path) : 0,
        'db_size' => file_exists($db_path) ? filesize($db_path) : 0
    ];

    file_put_contents($backup_path . '/info.json', json_encode($info, JSON_PRETTY_PRINT));

    $result['success'] = true;
    $result['backup_id'] = $backup_id;
    $result['backup_path'] = $backup_path;

    // Log backup creation
    log_update($version, 'backup', $backup_id, true);
    update_audit_log('Backup created successfully.', ['backup_id' => $backup_id, 'version' => $version]);
    update_security_event('backup_created', ['backup_id' => $backup_id, 'version' => $version]);

    // Cleanup old backups
    cleanup_old_backups();

    return $result;
}

/**
 * Create ZIP backup of application files
 */
function create_files_backup($zip_path): bool
{
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    $base = BASE_PATH;
    $exclude = ['backups', 'uploads', '.git', 'node_modules', 'vendor', 'build'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $path = $file->getPathname();
        $relative = substr($path, strlen($base) + 1);

        // Skip excluded directories
        $skip = false;
        foreach ($exclude as $ex) {
            if (strpos($relative, $ex) === 0 || strpos($relative, DIRECTORY_SEPARATOR . $ex) !== false) {
                $skip = true;
                break;
            }
        }

        if ($skip) continue;

        if ($file->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($path, $relative);
        }
    }

    return $zip->close();
}

/**
 * Create database backup
 */
function create_database_backup($path): bool
{
    enable_long_running_execution();

    $handle = null;
    try {
        $db = get_db();
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            return false;
        }

        // Get all tables
        $tables = [];
        $stmt = $db->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        fwrite($handle, "-- FoxDesk Database Backup\n");
        fwrite($handle, "-- Created: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Version: " . get_current_version() . "\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

        foreach ($tables as $table) {
            // Get create statement
            $stmt = $db->query("SHOW CREATE TABLE `$table`");
            $row = $stmt->fetch(PDO::FETCH_NUM);
            fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
            fwrite($handle, $row[1] . ";\n\n");

            // Get data
            $stmt = $db->query("SELECT * FROM `$table`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_keys($row);
                $values = array_map(function($v) use ($db) {
                    if ($v === null) return 'NULL';
                    return $db->quote($v);
                }, array_values($row));

                $line = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
                fwrite($handle, $line);
            }
            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");

        fclose($handle);
        return true;
    } catch (Throwable $e) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        error_log("Database backup failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Apply an update from a ZIP package.
 *
 * @param string      $zip_path   Path to the update ZIP.
 * @param string|null $backup_id  Existing backup ID to reuse (null = create new).
 * @param bool        $dry_run    If true, only report what would change — no files are modified.
 */
function apply_update($zip_path, $backup_id = null, bool $dry_run = false): array
{
    enable_long_running_execution();
    update_audit_log('Update apply started.', ['zip_path' => basename((string) $zip_path), 'dry_run' => $dry_run]);
    update_security_event('update_apply_started', ['zip' => basename((string) $zip_path), 'dry_run' => $dry_run]);

    $result = [
        'success' => false,
        'error' => null,
        'errors' => [],
        'messages' => [],
        'new_version' => null,
        'backup_id' => null,
        'dry_run' => $dry_run,
        'changes' => [],     // populated in dry_run mode
    ];

    $temp_dir = null;
    $version_info = null;
    try {
        // Validate package first
        $validation = validate_update_package($zip_path);
        if (!$validation['valid']) {
            $result['errors'] = $validation['errors'];
            $result['error'] = $validation['error'] ?? implode(', ', $validation['errors']);
            return $result;
        }

        $version_info = $validation['version_info'];
        $package_root = (string) ($validation['package_root'] ?? '');
        $result['new_version'] = $version_info['version'];

        // Pre-flight checks — verify disk space, permissions, PHP extensions
        $preflight = preflight_check();
        if (!$preflight['ok']) {
            $result['errors'] = array_merge($result['errors'], $preflight['errors']);
            $result['error'] = implode(' | ', $preflight['errors']);
            return $result;
        }
        if (!empty($preflight['warnings'])) {
            foreach ($preflight['warnings'] as $w) {
                $result['messages'][] = '⚠ ' . $w;
            }
        }

        // Extract update to temp directory (needed for both dry-run and real apply)
        $temp_dir = sys_get_temp_dir() . '/foxdesk_update_' . uniqid();
        if (!mkdir($temp_dir, 0755, true)) {
            throw new RuntimeException(t('Failed to create temporary directory.'));
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            throw new RuntimeException(t('Failed to open update package.'));
        }
        if (!$zip->extractTo($temp_dir)) {
            $zip->close();
            throw new RuntimeException(t('Failed to extract update package.'));
        }
        $zip->close();

        // ── DRY-RUN MODE ───────────────────────────────────────────────
        if ($dry_run) {
            $payload = resolve_package_payload_dir($temp_dir, $package_root);
            $files_dir = $payload['path'] ?? null;
            $migrations_dir = package_extract_path($temp_dir, $package_root, 'migrations');
            $changes = [];

            if ($files_dir !== null && is_dir($files_dir)) {
                $iter = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($files_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iter as $item) {
                    if ($item->isDir()) continue;
                    $relative = substr($item->getPathname(), strlen($files_dir) + 1);
                    if (($payload['mode'] ?? '') === 'flat' && ($relative === 'version.json' || strpos($relative, 'migrations/') === 0)) {
                        continue;
                    }
                    $target = BASE_PATH . '/' . $relative;
                    if (!file_exists($target)) {
                        $changes[] = ['action' => 'new', 'file' => $relative];
                    } elseif (md5_file($item->getPathname()) !== md5_file($target)) {
                        $changes[] = ['action' => 'modified', 'file' => $relative];
                    }
                }
            }

            // Files to delete
            $delete_files = (array) ($version_info['delete_files'] ?? []);
            foreach ($delete_files as $del) {
                $del = ltrim(str_replace('\\', '/', (string) $del), '/');
                if ($del !== '' && file_exists(BASE_PATH . '/' . $del)) {
                    $changes[] = ['action' => 'delete', 'file' => $del];
                }
            }

            // Migrations
            $migration_files = [];
            if (is_dir($migrations_dir)) {
                $migration_files = glob($migrations_dir . '/*.sql') ?: [];
                sort($migration_files);
            }

            $result['success'] = true;
            $result['changes'] = $changes;
            $result['messages'][] = t('{new} new, {mod} modified, {del} deleted files.', [
                'new' => count(array_filter($changes, fn($c) => $c['action'] === 'new')),
                'mod' => count(array_filter($changes, fn($c) => $c['action'] === 'modified')),
                'del' => count(array_filter($changes, fn($c) => $c['action'] === 'delete')),
            ]);
            if (!empty($migration_files)) {
                $result['messages'][] = t('{count} SQL migration(s) will run.', ['count' => count($migration_files)]);
            }
            return $result;
        }

        // ── REAL UPDATE ────────────────────────────────────────────────

        // Create backup if not provided
        if (!$backup_id) {
            $backup = create_backup();
            if (!$backup['success']) {
                $result['errors'][] = t('Failed to create backup before update.');
                $result['errors'] = array_merge($result['errors'], $backup['errors']);
                $result['error'] = implode(', ', $result['errors']);
                return $result;
            }
            $backup_id = $backup['backup_id'];
            $result['backup_id'] = $backup_id;
            $result['messages'][] = t('Backup created: {id}', ['id' => $backup_id]);
        } else {
            $result['backup_id'] = $backup_id;
        }

        // Enable maintenance mode — blocks other users during file replacement
        enable_maintenance_mode();

        // Copy files from update package
        $payload = resolve_package_payload_dir($temp_dir, $package_root);
        if ($payload === null || !is_dir($payload['path'])) {
            throw new RuntimeException(t('Update package contains no application files.'));
        }

        if (($payload['mode'] ?? '') === 'flat') {
            $result['messages'][] = t('Flat update package compatibility mode used.');
        }

        $files_dir = $payload['path'];
        if (is_dir($files_dir)) {
            $copied = copy_directory($files_dir, BASE_PATH, ($payload['mode'] ?? '') === 'flat' ? ['version.json', 'migrations'] : []);
            if ($copied > 0) {
                $result['messages'][] = t('{count} files updated.', ['count' => $copied]);
            }
        }

        // Delete files listed in version.json delete_files array
        $delete_files = (array) ($version_info['delete_files'] ?? []);
        $deleted_count = 0;
        foreach ($delete_files as $del) {
            $del = ltrim(str_replace('\\', '/', (string) $del), '/');
            if ($del === '' || strpos($del, '..') !== false) continue;
            $target = BASE_PATH . '/' . $del;
            if (file_exists($target) && !is_dir($target)) {
                if (@unlink($target)) {
                    $deleted_count++;
                }
            }
        }
        if ($deleted_count > 0) {
            $result['messages'][] = t('{count} obsolete files removed.', ['count' => $deleted_count]);
        }

        // Run migrations if present
        $migrations_dir = package_extract_path($temp_dir, $package_root, 'migrations');
        if (is_dir($migrations_dir)) {
            $migration_result = run_migrations($migrations_dir);
            $result['messages'] = array_merge($result['messages'], $migration_result['messages']);
            if (!empty($migration_result['errors'])) {
                $result['errors'] = array_merge($result['errors'], $migration_result['errors']);
            }
        }
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
        error_log('apply_update failed: ' . $e->getMessage());
        update_audit_log('Update apply failed.', ['error' => $e->getMessage()], 'error');
        update_security_event('update_apply_failed', ['error' => $e->getMessage()]);
    } finally {
        if ($temp_dir && is_dir($temp_dir)) {
            delete_directory($temp_dir);
        }
        // Always disable maintenance mode — even on failure
        disable_maintenance_mode();
    }

    $result['success'] = empty($result['errors']);
    if (!empty($result['errors']) && $result['error'] === null) {
        $result['error'] = implode(', ', $result['errors']);
    }
    if ($version_info && isset($version_info['version'])) {
        log_update($version_info['version'], 'update', $backup_id, $result['success'], [
            'changelog' => (array) ($version_info['changelog'] ?? []),
            'messages' => (array) ($result['messages'] ?? []),
            'errors' => (array) ($result['errors'] ?? []),
        ]);
        if ($result['success']) {
            // Auto-update APP_VERSION in index.php so the displayed version stays current
            update_config_version($version_info['version']);

            // Clear cached update check data — prevents stale "update available" banner
            if (function_exists('clear_update_check_cache')) {
                clear_update_check_cache();
            }

            $result['messages'][] = t('Update to version {version} completed.', ['version' => $version_info['version']]);
            update_audit_log('Update apply completed.', ['version' => $version_info['version'], 'backup_id' => $backup_id]);
            update_security_event('update_applied', ['version' => $version_info['version'], 'backup_id' => $backup_id]);

            // Send email notification to all admins
            try {
                notify_admins_about_update(
                    $version_info['version'],
                    get_current_version(),
                    (string) $backup_id,
                    (array) ($version_info['changelog'] ?? [])
                );
            } catch (Throwable $e) {
                // Non-fatal — don't fail the update because of email
                error_log('Update email notification failed: ' . $e->getMessage());
            }
        } else {
            $result['messages'][] = t('Update to version {version} finished with errors.', ['version' => $version_info['version']]);
            update_audit_log('Update apply finished with errors.', ['version' => $version_info['version'], 'errors' => $result['errors']], 'warning');
            update_security_event('update_applied_with_errors', ['version' => $version_info['version'], 'errors' => $result['errors']]);
        }
    }

    // After files are replaced on disk, PHP includes in the calling script
    // may be inconsistent (old code in memory vs new files on disk).
    // On success, flush opcache, set flash message, output a safe HTML
    // interstitial and exit immediately — before the caller can load
    // any changed PHP files that would cause HTTP 500.
    if ($result['success']) {
        // opcache already reset in copy_directory(), but reset again as safety net
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Clear pending update from session — the caller (settings.php) won't
            // reach its own unset() because we exit here, so we must do it now.
            if (!empty($_SESSION['pending_update']['file'])) {
                @unlink($_SESSION['pending_update']['file']);
            }
            unset($_SESSION['pending_update']);

            $_SESSION['flash'] = [
                'message' => t('Update applied successfully! Backup created: {backup}', ['backup' => $result['backup_id'] ?? '-']),
                'type' => 'success'
            ];
            session_write_close();
        }
        // Output a safe HTML interstitial and EXIT immediately.
        // This prevents the calling PHP script (settings.php etc.) from
        // trying to include/require any changed files, which would crash.
        // The 2-second delay gives opcache time to expire across all
        // PHP-FPM workers before the browser loads the new page.
        $redir = function_exists('url') ? url('admin', ['section' => 'settings', 'tab' => 'system']) : '?page=admin&section=settings&tab=system';
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
        echo '<meta http-equiv="refresh" content="2;url=' . htmlspecialchars($redir) . '">';
        echo '<title>' . t('Updating...') . '</title>';
        echo '<style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;font-family:system-ui,sans-serif;background:#f8fafc;color:#334155}';
        echo '.box{text-align:center;padding:2rem}.spinner{width:24px;height:24px;border:3px solid #e2e8f0;border-top-color:#3b82f6;border-radius:50%;animation:spin .6s linear infinite;margin:0 auto 1rem}';
        echo '@keyframes spin{to{transform:rotate(360deg)}}</style></head>';
        echo '<body><div class="box"><div class="spinner"></div>';
        echo '<div style="font-weight:600;font-size:1.1rem">' . t('Update complete') . '</div>';
        echo '<div style="color:#64748b;margin-top:.5rem;font-size:.875rem">' . t('Redirecting...') . '</div>';
        echo '</div></body></html>';
        exit;
    }

    return $result;
}

/**
 * Run SQL migrations from a directory
 */
function run_migrations($migrations_dir): array
{
    $result = [
        'success' => true,
        'messages' => [],
        'errors' => []
    ];

    $files = glob($migrations_dir . '/*.sql');
    sort($files); // Ensure order

    foreach ($files as $file) {
        $filename = basename($file);
        try {
            $sql = file_get_contents($file);
            $db = get_db();
            $statements = split_sql_statements((string) $sql);

            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $db->exec($statement);
                }
            }

            $result['messages'][] = t('Migration {file} applied.', ['file' => $filename]);
        } catch (Throwable $e) {
            $result['errors'][] = t('Migration {file} failed: {error}', [
                'file' => $filename,
                'error' => $e->getMessage()
            ]);
            $result['success'] = false;
        }
    }

    return $result;
}

/**
 * Rollback to a previous backup.
 *
 * Enables maintenance mode during the operation and uses the same
 * safe HTML interstitial as apply_update() to avoid opcache/include
 * inconsistencies after files are replaced on disk.
 */
function rollback_update($backup_id, $restore_database = false): array
{
    enable_long_running_execution();
    update_audit_log('Rollback started.', ['backup_id' => $backup_id, 'restore_database' => $restore_database ? 1 : 0]);
    update_security_event('rollback_started', ['backup_id' => $backup_id, 'restore_database' => $restore_database ? 1 : 0]);

    $result = [
        'success' => false,
        'errors' => [],
        'messages' => []
    ];

    $backup_path = BACKUP_DIR . '/' . $backup_id;

    // Check backup exists
    if (!is_dir($backup_path)) {
        $result['errors'][] = t('Backup not found: {id}', ['id' => $backup_id]);
        return $result;
    }

    // Load backup info
    $info_path = $backup_path . '/info.json';
    if (!file_exists($info_path)) {
        $result['errors'][] = t('Backup info file missing.');
        return $result;
    }

    $info = json_decode(file_get_contents($info_path), true);

    // Enable maintenance mode — blocks other users during file replacement
    enable_maintenance_mode();

    try {
        // Restore files
        $files_zip = $backup_path . '/files.zip';
        if (file_exists($files_zip)) {
            $zip = new ZipArchive();
            if ($zip->open($files_zip) === true) {
                // Extract to temp first
                $temp_dir = sys_get_temp_dir() . '/foxdesk_restore_' . uniqid();
                mkdir($temp_dir, 0755, true);
                $zip->extractTo($temp_dir);
                $zip->close();

                // Copy files
                $copied = copy_directory($temp_dir, BASE_PATH);
                delete_directory($temp_dir);

                $result['messages'][] = t('{count} files restored.', ['count' => $copied]);
            } else {
                $result['errors'][] = t('Failed to open backup archive.');
            }
        }

        // Restore database if requested — use split_sql_statements() for safety
        if ($restore_database) {
            $db_file = $backup_path . '/database.sql';
            if (file_exists($db_file)) {
                try {
                    $sql = file_get_contents($db_file);
                    if ($sql === false) {
                        throw new RuntimeException('Could not read database backup file.');
                    }
                    $db = get_db();
                    $statements = split_sql_statements($sql);
                    $executed = 0;
                    $stmt_errors = 0;
                    foreach ($statements as $statement) {
                        if (trim($statement) === '') continue;
                        try {
                            $db->exec($statement);
                            $executed++;
                        } catch (Throwable $se) {
                            $stmt_errors++;
                            error_log('DB rollback statement failed: ' . $se->getMessage());
                        }
                    }
                    $result['messages'][] = t('Database restored ({executed} statements, {errors} errors).', [
                        'executed' => $executed,
                        'errors' => $stmt_errors,
                    ]);
                    if ($stmt_errors > 0) {
                        $result['errors'][] = t('{count} database statements failed during restore.', ['count' => $stmt_errors]);
                    }
                } catch (Throwable $e) {
                    $result['errors'][] = t('Database restore failed: {error}', ['error' => $e->getMessage()]);
                }
            }
        }
    } finally {
        // Always disable maintenance mode — even on failure
        disable_maintenance_mode();
    }

    // Log rollback
    $result['success'] = empty($result['errors']);
    log_update($info['version'] ?? 'unknown', 'rollback', $backup_id, $result['success']);
    if ($result['success']) {
        $result['messages'][] = t('Rollback to version {version} completed.', ['version' => $info['version'] ?? 'unknown']);
        update_audit_log('Rollback completed.', ['backup_id' => $backup_id, 'version' => $info['version'] ?? 'unknown']);
        update_security_event('rollback_completed', ['backup_id' => $backup_id, 'version' => $info['version'] ?? 'unknown']);
    } else {
        $result['messages'][] = t('Rollback to version {version} finished with errors.', ['version' => $info['version'] ?? 'unknown']);
        update_audit_log('Rollback failed.', ['backup_id' => $backup_id, 'errors' => $result['errors']], 'error');
        update_security_event('rollback_failed', ['backup_id' => $backup_id, 'errors' => $result['errors']]);
    }

    // Same interstitial pattern as apply_update(): exit immediately
    // to prevent loading inconsistent PHP files after rollback.
    if ($result['success']) {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['flash'] = [
                'message' => t('Rollback to version {version} completed successfully.', ['version' => $info['version'] ?? 'unknown']),
                'type' => 'success'
            ];
            session_write_close();
        }
        $redir = function_exists('url') ? url('admin', ['section' => 'settings', 'tab' => 'system']) : '?page=admin&section=settings&tab=system';
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
        echo '<meta http-equiv="refresh" content="2;url=' . htmlspecialchars($redir) . '">';
        echo '<title>' . t('Rolling back...') . '</title>';
        echo '<style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;font-family:system-ui,sans-serif;background:#f8fafc;color:#334155}';
        echo '.box{text-align:center;padding:2rem}.spinner{width:24px;height:24px;border:3px solid #e2e8f0;border-top-color:#f59e0b;border-radius:50%;animation:spin .6s linear infinite;margin:0 auto 1rem}';
        echo '@keyframes spin{to{transform:rotate(360deg)}}</style></head>';
        echo '<body><div class="box"><div class="spinner"></div>';
        echo '<div style="font-weight:600;font-size:1.1rem">' . t('Rollback complete') . '</div>';
        echo '<div style="color:#64748b;margin-top:.5rem;font-size:.875rem">' . t('Redirecting...') . '</div>';
        echo '</div></body></html>';
        exit;
    }

    return $result;
}

/**
 * Get list of available backups
 */
function get_backups(): array
{
    $backups = [];

    if (!is_dir(BACKUP_DIR)) {
        return $backups;
    }

    $dirs = glob(BACKUP_DIR . '/*', GLOB_ONLYDIR);

    foreach ($dirs as $dir) {
        $info_path = $dir . '/info.json';
        if (file_exists($info_path)) {
            $info = json_decode(file_get_contents($info_path), true);

            // Calculate total size
            $total_size = 0;
            if (file_exists($dir . '/files.zip')) {
                $total_size += filesize($dir . '/files.zip');
            }
            if (file_exists($dir . '/database.sql')) {
                $total_size += filesize($dir . '/database.sql');
            }

            $backups[] = [
                'id' => $info['backup_id'] ?? basename($dir),
                'version' => $info['version'] ?? 'unknown',
                'date' => $info['created_at'] ?? '',
                'created_by_user_id' => $info['created_by_user_id'] ?? null,
                'size' => $total_size,
                'path' => $dir,
                'has_files' => file_exists($dir . '/files.zip'),
                'has_database' => file_exists($dir . '/database.sql')
            ];
        }
    }

    // Sort by date, newest first
    usort($backups, function($a, $b) {
        return strcmp($b['date'] ?? '', $a['date'] ?? '');
    });

    return $backups;
}

/**
 * Delete a backup
 */
function delete_backup($backup_id): array
{
    $result = ['success' => false, 'error' => null];

    $backup_path = BACKUP_DIR . '/' . $backup_id;

    if (!is_dir($backup_path)) {
        $result['error'] = t('Backup not found.');
        return $result;
    }

    if (delete_directory($backup_path)) {
        $result['success'] = true;
        update_audit_log('Backup deleted.', ['backup_id' => $backup_id]);
        update_security_event('backup_deleted', ['backup_id' => $backup_id]);
    } else {
        $result['error'] = t('Failed to delete backup directory.');
        update_audit_log('Backup delete failed.', ['backup_id' => $backup_id, 'error' => $result['error']], 'error');
        update_security_event('backup_delete_failed', ['backup_id' => $backup_id, 'error' => $result['error']]);
    }

    return $result;
}

/**
 * Cleanup old backups, keeping only MAX_BACKUPS
 */
function cleanup_old_backups(): void
{
    $backups = get_backups();

    if (count($backups) > MAX_BACKUPS) {
        $to_delete = array_slice($backups, MAX_BACKUPS);
        foreach ($to_delete as $backup) {
            delete_backup($backup['id'] ?? '');
        }
    }
}

/**
 * Get update history
 */
function get_update_history(): array
{
    $history = get_setting('update_history', '[]');
    $rows = json_decode($history, true) ?: [];
    foreach ($rows as &$row) {
        if (!is_array($row)) {
            $row = [];
        }
        if (!isset($row['changelog']) || !is_array($row['changelog'])) {
            $row['changelog'] = [];
        }
        if (!isset($row['messages']) || !is_array($row['messages'])) {
            $row['messages'] = [];
        }
        if (!isset($row['errors']) || !is_array($row['errors'])) {
            $row['errors'] = [];
        }
        if (($row['action'] ?? '') === 'update' && empty($row['changelog']) && !empty($row['version'])) {
            $row['changelog'] = get_cached_update_changelog((string) $row['version']);
        }
    }
    unset($row);
    return $rows;
}

/**
 * Load changelog cache map for updates.
 */
function get_update_changelog_cache(): array
{
    $raw = get_setting('update_changelog_cache', '{}');
    $cache = json_decode((string) $raw, true);
    return is_array($cache) ? $cache : [];
}

/**
 * Cache changelog by version for later history display.
 */
function cache_update_changelog($version, array $changelog): void
{
    $version = trim((string) $version);
    if ($version === '') {
        return;
    }

    $changes = array_values(array_filter($changelog, static function ($item) {
        return is_string($item) && trim($item) !== '';
    }));
    if (empty($changes)) {
        return;
    }

    $cache = get_update_changelog_cache();
    $cache[$version] = $changes;

    // Keep only the last 100 versions in cache.
    if (count($cache) > 100) {
        $versions = array_keys($cache);
        $trim_versions = array_slice($versions, 0, count($versions) - 100);
        foreach ($trim_versions as $old_version) {
            unset($cache[$old_version]);
        }
    }

    save_setting('update_changelog_cache', json_encode($cache));
}

/**
 * Resolve cached changelog for an update version.
 */
function get_cached_update_changelog($version): array
{
    $version = trim((string) $version);
    if ($version === '') {
        return [];
    }

    $cache = get_update_changelog_cache();
    $changes = $cache[$version] ?? [];
    if (is_array($changes) && !empty($changes)) {
        return array_values(array_filter($changes, static function ($item) {
            return is_string($item) && trim($item) !== '';
        }));
    }

    // Fallback for local builds if available.
    $local_version_file = BASE_PATH . '/build/update-' . $version . '/version.json';
    if (is_file($local_version_file)) {
        $parsed = json_decode((string) @file_get_contents($local_version_file), true);
        $local_changes = is_array($parsed['changelog'] ?? null) ? $parsed['changelog'] : [];
        $local_changes = array_values(array_filter($local_changes, static function ($item) {
            return is_string($item) && trim($item) !== '';
        }));
        if (!empty($local_changes)) {
            cache_update_changelog($version, $local_changes);
            return $local_changes;
        }
    }

    return [];
}

/**
 * Update APP_VERSION in config.php after a successful update.
 * Uses regex replacement to change the define() line in-place.
 */
function update_config_version(string $new_version): bool
{
    // APP_VERSION is defined in index.php (the main entry point), not config.php.
    // config.php is server-specific and loaded AFTER index.php sets the constant.
    $index_path = defined('BASE_PATH') ? BASE_PATH . '/index.php' : __DIR__ . '/../index.php';
    if (!is_file($index_path) || !is_writable($index_path)) {
        error_log("update_config_version: index.php not writable at $index_path");
        return false;
    }

    $content = file_get_contents($index_path);
    if ($content === false) {
        return false;
    }

    // Match: define('APP_VERSION', '...');  or define("APP_VERSION", "...");
    $pattern = "/^(\s*define\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"])([^'\"]*?)(['\"])\s*\)/m";
    $replacement = '${1}' . $new_version . '${3})';

    $updated = preg_replace($pattern, $replacement, $content, 1, $count);
    if ($count === 0 || $updated === null) {
        error_log("update_config_version: APP_VERSION define not found in index.php");
        return false;
    }

    if (file_put_contents($index_path, $updated) === false) {
        error_log("update_config_version: failed to write index.php");
        return false;
    }

    return true;
}

/**
 * Log an update to history
 */
function log_update($version, $action, $backup_id = null, $success = true, array $meta = []): void
{
    $history = get_update_history();
    $changelog = array_values(array_filter((array) ($meta['changelog'] ?? []), static function ($item) {
        return is_string($item) && trim($item) !== '';
    }));
    if (!empty($changelog)) {
        cache_update_changelog($version, $changelog);
    }

    $entry = [
        'version' => $version,
        'action' => $action, // 'update', 'rollback', 'backup'
        'success' => $success,
        'backup_id' => $backup_id,
        'date' => date('Y-m-d H:i:s'),
        'user_id' => (function_exists('current_user') ? (current_user()['id'] ?? null) : null),
        'changelog' => $changelog,
        'messages' => array_values(array_filter((array) ($meta['messages'] ?? []), static function ($item) {
            return is_string($item) && trim($item) !== '';
        })),
        'errors' => array_values(array_filter((array) ($meta['errors'] ?? []), static function ($item) {
            return is_string($item) && trim($item) !== '';
        })),
    ];

    array_unshift($history, $entry);

    // Keep last 50 entries
    $history = array_slice($history, 0, 50);

    save_setting('update_history', json_encode($history));
}

/**
 * Helper: Copy directory recursively
 */
function copy_directory($source, $dest, array $exclude_top_level = []): int
{
    $count = 0;
    $has_opcache = function_exists('opcache_invalidate');
    $exclude_lookup = [];
    foreach ($exclude_top_level as $name) {
        $clean = trim(str_replace('\\', '/', (string) $name), '/');
        if ($clean !== '') {
            $exclude_lookup[$clean] = true;
        }
    }

    if (!is_dir($dest)) {
        mkdir($dest, 0755, true);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $sub_path = str_replace('\\', '/', $iterator->getSubPathName());
        $top_level = strtok($sub_path, '/');
        if ($top_level !== false && isset($exclude_lookup[$top_level])) {
            continue;
        }

        $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();

        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0755, true);
            }
        } else {
            copy($item, $target);
            $count++;
            // Immediately invalidate opcache for each replaced PHP file
            // so the next request loads the new version from disk.
            if ($has_opcache && str_ends_with($target, '.php')) {
                @opcache_invalidate($target, true);
            }
        }
    }

    // Full opcache reset as final safety net
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    return $count;
}

/**
 * Helper: Delete directory recursively
 */
function delete_directory($dir): bool
{
    if (!is_dir($dir)) {
        return false;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item);
        } else {
            unlink($item);
        }
    }

    return rmdir($dir);
}

/**
 * Helper: Format file size for display
 */
function format_filesize($bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, 1) . ' ' . $units[$pow];
}

/**
 * Post-update health check.
 *
 * Verifies that critical application components work after an update.
 * Called automatically after redirect from the interstitial page.
 *
 * @return array{ok: bool, checks: array<string, bool>, errors: string[]}
 */
function post_update_health_check(): array
{
    $checks = [];
    $errors = [];

    // 1. Database connectivity
    try {
        $row = db_fetch_one("SELECT 1 AS ok");
        $checks['database'] = !empty($row);
    } catch (Throwable $e) {
        $checks['database'] = false;
        $errors[] = t('Database connection failed: {error}', ['error' => $e->getMessage()]);
    }

    // 2. Critical files exist
    $critical_files = [
        'index.php',
        'includes/functions.php',
        'includes/database.php',
        'includes/auth.php',
    ];
    $checks['critical_files'] = true;
    foreach ($critical_files as $file) {
        if (!file_exists(BASE_PATH . '/' . $file)) {
            $checks['critical_files'] = false;
            $errors[] = t('Critical file missing: {file}', ['file' => $file]);
        }
    }

    // 3. Session working
    $checks['session'] = (session_status() === PHP_SESSION_ACTIVE);

    // 4. Version file readable
    $version_file = BASE_PATH . '/version.json';
    if (file_exists($version_file)) {
        $vdata = json_decode((string) @file_get_contents($version_file), true);
        $checks['version_file'] = is_array($vdata) && !empty($vdata['version']);
    } else {
        $checks['version_file'] = false;
        $errors[] = t('version.json missing or unreadable.');
    }

    // 5. Uploads directory writable
    $upload_dir = defined('UPLOAD_DIR') ? UPLOAD_DIR : BASE_PATH . '/uploads';
    $checks['uploads_writable'] = is_dir($upload_dir) && is_writable($upload_dir);
    if (!$checks['uploads_writable']) {
        $errors[] = t('Uploads directory not writable.');
    }

    // 6. Maintenance mode disabled
    $checks['maintenance_off'] = !file_exists(BASE_PATH . '/.maintenance');
    if (!$checks['maintenance_off']) {
        // Auto-cleanup stale maintenance file
        @unlink(BASE_PATH . '/.maintenance');
        $checks['maintenance_off'] = true;
    }

    return [
        'ok' => empty($errors),
        'checks' => $checks,
        'errors' => $errors,
    ];
}

/**
 * Send email notification to all admins about a completed update.
 *
 * @param string $new_version   The new application version.
 * @param string $old_version   The previous version.
 * @param string $backup_id     Backup ID created before the update.
 * @param array  $changelog     List of changelog entries.
 */
function notify_admins_about_update(string $new_version, string $old_version, string $backup_id, array $changelog = []): void
{
    // Only send if email notifications are configured and enabled
    if (!function_exists('get_setting') || !function_exists('send_email')) {
        return;
    }

    $smtp_host = get_setting('smtp_host', '');
    if ($smtp_host === '') {
        return; // SMTP not configured, skip silently
    }

    $email_enabled = get_setting('email_notifications_enabled', '0');
    if ($email_enabled !== '1') {
        return;
    }

    // Get all admin users
    try {
        $admins = db_fetch_all("SELECT email, first_name FROM users WHERE role = 'admin' AND is_active = 1 AND deleted_at IS NULL");
    } catch (Throwable $e) {
        error_log('notify_admins_about_update: failed to fetch admins: ' . $e->getMessage());
        return;
    }

    if (empty($admins)) {
        return;
    }

    $app_name = get_setting('app_name', 'FoxDesk');
    $app_url = defined('APP_URL') ? APP_URL : '';

    $changelog_text = '';
    if (!empty($changelog)) {
        $changelog_text = "\n\nChangelog:\n";
        foreach ($changelog as $entry) {
            $changelog_text .= "  - " . $entry . "\n";
        }
    }

    $subject = "$app_name updated to v$new_version";
    $body = "Hello,\n\n"
        . "$app_name has been updated successfully.\n\n"
        . "Previous version: $old_version\n"
        . "New version: $new_version\n"
        . "Backup ID: $backup_id\n"
        . "Date: " . date('Y-m-d H:i:s') . "\n"
        . "Updated by: " . (function_exists('current_user') ? (current_user()['email'] ?? 'unknown') : 'unknown')
        . $changelog_text
        . "\n\n"
        . ($app_url !== '' ? "Open application: $app_url\n\n" : '')
        . "Regards,\n$app_name";

    foreach ($admins as $admin) {
        try {
            send_email($admin['email'], $subject, $body);
        } catch (Throwable $e) {
            error_log('notify_admins_about_update: failed to send to ' . $admin['email'] . ': ' . $e->getMessage());
        }
    }
}
