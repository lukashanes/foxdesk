<?php
/**
 * Self-hosted FoxDesk to FoxDesk Cloud migration export helpers.
 *
 * Public/self-hosted FoxDesk creates export packages only. SaaS import and
 * tenant lifecycle stay in the separate foxdesk_saas repository.
 */

function migration_reference_tables(): array
{
    return ['statuses', 'priorities', 'ticket_types'];
}

function migration_data_tables(): array
{
    return [
        'organizations',
        'users',
        'tickets',
        'comments',
        'ticket_time_entries',
        'attachments',
        'ticket_shares',
        'report_shares',
        'ticket_access',
        'activity_log',
        'api_tokens',
        'notifications',
        'allowed_senders',
        'recurring_tasks',
        'report_templates',
        'report_snapshots',
        'ticket_messages',
        'ticket_message_attachments',
        'email_ingest_logs',
        'security_log',
        'debug_log',
        'page_views',
    ];
}

function migration_export_tables(): array
{
    return array_merge(migration_reference_tables(), migration_data_tables(), ['settings', 'email_templates']);
}

function migration_table_columns(string $table): array
{
    validate_sql_identifier($table);
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!table_exists($table)) {
        return $cache[$table] = [];
    }

    $rows = db_fetch_all("SHOW COLUMNS FROM {$table}");
    $columns = [];
    foreach ($rows as $row) {
        $columns[] = (string) $row['Field'];
    }
    return $cache[$table] = $columns;
}

function migration_select_rows(string $table): array
{
    validate_sql_identifier($table);
    if (!table_exists($table)) {
        return [];
    }

    $order = in_array('id', migration_table_columns($table), true) ? ' ORDER BY id ASC' : '';
    return db_fetch_all("SELECT * FROM {$table}{$order}");
}

function migration_attachment_absolute_path(array $attachment): ?string
{
    $filename = basename((string) ($attachment['filename'] ?? ''));
    if ($filename === '') {
        return null;
    }

    $candidates = [];
    if (!empty($attachment['storage_path'])) {
        $candidates[] = BASE_PATH . '/' . ltrim(str_replace('\\', '/', (string) $attachment['storage_path']), '/');
    }

    $upload_dir = trim((defined('UPLOAD_DIR') ? UPLOAD_DIR : 'uploads/'), '/\\');
    $candidates[] = BASE_PATH . '/' . $upload_dir . '/' . $filename;

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function migration_source_base_url(): string
{
    if (defined('APP_URL') && trim((string) APP_URL) !== '') {
        return rtrim((string) APP_URL, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return $host !== '' ? $scheme . '://' . $host : '';
}

function migration_create_export_package(): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive extension is required to create migration packages.');
    }

    $export_id = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $app_name = get_setting('app_name', 'FoxDesk');
    $safe_name = preg_replace('/[^a-z0-9_-]+/i', '-', $app_name);
    $safe_name = trim((string) $safe_name, '-') ?: 'foxdesk';
    $filename = 'foxdesk-cloud-migration-' . strtolower($safe_name) . '-' . $export_id . '.zip';

    $dir = BASE_PATH . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Backup directory is not writable.');
    }

    $path = $dir . '/' . $filename;
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create migration package.');
    }

    $manifest = [
        'format' => 'foxdesk-cloud-migration',
        'format_version' => 1,
        'created_at' => gmdate('c'),
        'app_version' => defined('APP_VERSION') ? APP_VERSION : null,
        'source' => [
            'base_url' => migration_source_base_url(),
            'tenant_id' => null,
            'tenant_slug' => 'self-hosted',
            'tenant_name' => $app_name,
        ],
        'tables' => [],
        'files' => [
            'attachments' => [],
        ],
    ];

    foreach (migration_export_tables() as $table) {
        if (!table_exists($table)) {
            continue;
        }

        $rows = migration_select_rows($table);
        $manifest['tables'][$table] = count($rows);
        $zip->addFromString('tables/' . $table . '.json', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    if (table_exists('attachments')) {
        foreach (migration_select_rows('attachments') as $attachment) {
            $attachment_id = (int) ($attachment['id'] ?? 0);
            if ($attachment_id <= 0) {
                continue;
            }

            $absolute_path = migration_attachment_absolute_path($attachment);
            if ($absolute_path === null) {
                continue;
            }

            $package_path = 'files/attachments/' . $attachment_id . '/' . basename((string) ($attachment['filename'] ?? 'file.bin'));
            $zip->addFile($absolute_path, $package_path);
            $manifest['files']['attachments'][(string) $attachment_id] = [
                'package_path' => $package_path,
                'filename' => $attachment['filename'] ?? '',
                'original_name' => $attachment['original_name'] ?? '',
                'file_size' => (int) ($attachment['file_size'] ?? 0),
            ];
        }
    }

    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $zip->close();

    return [
        'path' => $path,
        'filename' => $filename,
        'bytes' => filesize($path) ?: 0,
        'manifest' => $manifest,
        'sha256' => hash_file('sha256', $path),
    ];
}

function migration_download_export_package(): void
{
    require_admin();
    require_csrf_token();

    $package = migration_create_export_package();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $package['filename'] . '"');
    header('Content-Length: ' . (string) $package['bytes']);
    header('X-FoxDesk-Migration-SHA256: ' . $package['sha256']);
    readfile($package['path']);
    @unlink($package['path']);
    exit;
}
