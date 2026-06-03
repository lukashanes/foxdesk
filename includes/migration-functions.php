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

function migration_count_rows(string $table): int
{
    validate_sql_identifier($table);
    if (!table_exists($table)) {
        return 0;
    }

    $row = db_fetch_one("SELECT COUNT(*) AS row_count FROM {$table}");
    return (int) ($row['row_count'] ?? 0);
}

function migration_json_encode($value, int $flags = 0): string
{
    $json_flags = $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
    return json_encode($value, $json_flags);
}

function migration_backup_directory_status(): array
{
    $dir = BASE_PATH . '/backups';
    $exists = is_dir($dir);
    if (!$exists) {
        @mkdir($dir, 0755, true);
        $exists = is_dir($dir);
    }

    return [
        'path' => $dir,
        'exists' => $exists,
        'writable' => $exists && is_writable($dir),
    ];
}

function migration_export_readiness(): array
{
    $backup_dir = migration_backup_directory_status();

    return [
        'zip_available' => class_exists('ZipArchive'),
        'backup_dir' => $backup_dir,
        'ready' => class_exists('ZipArchive') && !empty($backup_dir['writable']),
    ];
}

function migration_attachment_absolute_path(array $attachment): ?string
{
    $filename = basename((string) ($attachment['filename'] ?? ''));
    $candidates = [];
    if (!empty($attachment['storage_path'])) {
        $candidates[] = BASE_PATH . '/' . ltrim(str_replace('\\', '/', (string) $attachment['storage_path']), '/');
    }
    if (!empty($attachment['storage_key'])) {
        $candidates[] = BASE_PATH . '/' . ltrim(str_replace('\\', '/', (string) $attachment['storage_key']), '/');
    }

    $attachment_id = (int) ($attachment['id'] ?? 0);
    if ($attachment_id > 0 && table_exists('ticket_message_attachments')) {
        try {
            $rows = db_fetch_all(
                "SELECT storage_path FROM ticket_message_attachments WHERE attachment_id = ? ORDER BY id ASC",
                [$attachment_id]
            );
            foreach ($rows as $row) {
                if (!empty($row['storage_path'])) {
                    $candidates[] = BASE_PATH . '/' . ltrim(str_replace('\\', '/', (string) $row['storage_path']), '/');
                }
            }
        } catch (Throwable $e) {
            // Keep migration usable if the optional message attachment table is incomplete.
        }
    }

    if ($filename !== '') {
        $upload_dir = trim((defined('UPLOAD_DIR') ? UPLOAD_DIR : 'uploads/'), '/\\');
        $candidates[] = BASE_PATH . '/' . $upload_dir . '/' . $filename;
    }

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

    $backup_dir = migration_backup_directory_status();
    $dir = (string) $backup_dir['path'];
    if (empty($backup_dir['writable'])) {
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
        $zip->addFromString('tables/' . $table . '.json', migration_json_encode($rows));
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

    $zip->addFromString('manifest.json', migration_json_encode($manifest, JSON_PRETTY_PRINT));
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
    if (!is_admin()) {
        http_response_code(403);
        exit;
    }

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

function migration_source_instance_id(): string
{
    $existing = trim((string) get_setting('migration_source_instance_id', ''));
    if ($existing !== '') {
        return $existing;
    }

    $id = 'selfhost_' . bin2hex(random_bytes(16));
    save_setting('migration_source_instance_id', $id);
    return $id;
}

function migration_table_max_id(string $table): ?int
{
    validate_sql_identifier($table);
    if (!table_exists($table) || !in_array('id', migration_table_columns($table), true)) {
        return null;
    }

    $row = db_fetch_one("SELECT MAX(id) AS max_id FROM {$table}");
    return $row && $row['max_id'] !== null ? (int) $row['max_id'] : null;
}

function migration_table_max_timestamp(string $table): ?string
{
    validate_sql_identifier($table);
    if (!table_exists($table)) {
        return null;
    }

    foreach (['updated_at', 'created_at'] as $column) {
        if (!in_array($column, migration_table_columns($table), true)) {
            continue;
        }
        $row = db_fetch_one("SELECT MAX({$column}) AS max_timestamp FROM {$table}");
        if ($row && !empty($row['max_timestamp'])) {
            return (string) $row['max_timestamp'];
        }
    }

    return null;
}

function migration_attachment_storage_bytes(): int
{
    if (!table_exists('attachments') || !in_array('file_size', migration_table_columns('attachments'), true)) {
        return 0;
    }

    $row = db_fetch_one("SELECT COALESCE(SUM(file_size), 0) AS bytes FROM attachments");
    return (int) ($row['bytes'] ?? 0);
}

function migration_inventory(): array
{
    $tables = [];
    foreach (migration_export_tables() as $table) {
        if (!table_exists($table)) {
            continue;
        }

        $tables[$table] = [
            'rows' => migration_count_rows($table),
            'columns' => migration_table_columns($table),
            'max_id' => migration_table_max_id($table),
            'max_timestamp' => migration_table_max_timestamp($table),
        ];
    }

    return [
        'format' => 'foxdesk-cloud-sync-inventory',
        'format_version' => 1,
        'generated_at' => gmdate('c'),
        'source' => [
            'instance_id' => migration_source_instance_id(),
            'base_url' => migration_source_base_url(),
            'app_version' => defined('APP_VERSION') ? APP_VERSION : null,
            'name' => get_setting('app_name', 'FoxDesk'),
        ],
        'mode' => migration_cloud_mode(),
        'tables' => $tables,
        'files' => [
            'attachments' => [
                'rows' => (int) ($tables['attachments']['rows'] ?? 0),
                'bytes' => migration_attachment_storage_bytes(),
            ],
        ],
        'sync_policy' => [
            'direction' => 'self_hosted_to_saas',
            'cutover' => 'single_active_instance',
            'secrets' => 'redacted_or_reentered',
        ],
    ];
}

function migration_cloud_mode(): string
{
    $mode = trim((string) get_setting('migration_cloud_mode', 'disconnected'));
    return in_array($mode, ['disconnected', 'connected', 'syncing', 'ready_for_cutover', 'cutover_complete'], true)
        ? $mode
        : 'disconnected';
}

function migration_cloud_cutover_active(): bool
{
    return migration_cloud_mode() === 'cutover_complete';
}

function migration_cloud_target_url(): string
{
    return rtrim(trim((string) get_setting('migration_cloud_target_url', '')), '/');
}

function migration_cloud_should_redirect_after_cutover(string $page): bool
{
    if (!migration_cloud_cutover_active()) {
        return false;
    }

    return !in_array($page, ['health', 'logout'], true);
}

function migration_cloud_cutover_response(): void
{
    $target = migration_cloud_target_url();
    if ($target !== '') {
        header('Location: ' . $target, true, 302);
        exit;
    }

    http_response_code(410);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>FoxDesk moved</title></head>';
    echo '<body style="font-family:system-ui,sans-serif;max-width:680px;margin:64px auto;padding:0 20px;color:#1f2937">';
    echo '<h1>This FoxDesk has moved to FoxDesk Cloud</h1>';
    echo '<p>The self-hosted instance has completed cutover and is no longer the active helpdesk.</p>';
    echo '</body></html>';
    exit;
}

function migration_cloud_mark_connected(string $cloud_url, string $token, array $response = []): void
{
    save_setting('migration_cloud_url', rtrim(trim($cloud_url), '/'));
    save_setting('migration_cloud_token', trim($token));
    save_setting('migration_cloud_mode', 'connected');
    save_setting('migration_cloud_last_connected_at', gmdate('c'));
    if (!empty($response)) {
        save_setting('migration_cloud_last_response_json', migration_json_encode($response));
    }
}

function migration_cloud_mark_cutover(string $target_url, array $response = []): void
{
    $target_url = rtrim(trim($target_url), '/');
    save_setting('migration_cloud_mode', 'cutover_complete');
    save_setting('migration_cloud_target_url', $target_url);
    save_setting('migration_cloud_cutover_at', gmdate('c'));
    save_setting('imap_enabled', '0');
    save_setting('notifications_enabled', '0');
    save_setting('email_notifications_enabled', '0');
    if (!empty($response)) {
        save_setting('migration_cloud_cutover_response_json', migration_json_encode($response));
    }
}

function migration_cloud_endpoint(string $cloud_url, string $action): string
{
    $base = rtrim(trim($cloud_url), '/');
    if ($base === '') {
        throw new InvalidArgumentException('Cloud URL is required.');
    }
    return $base . '/index.php?page=api&action=' . rawurlencode($action);
}

function migration_cloud_http_json(string $cloud_url, string $action, string $token, array $payload): array
{
    $token = trim($token);
    if ($token === '') {
        throw new InvalidArgumentException('Migration token is required.');
    }

    $body = migration_json_encode($payload);
    $url = migration_cloud_endpoint($cloud_url, $action);
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'X-FoxDesk-Migration-Version: 1',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\n", $headers),
                'content' => $body,
                'timeout' => 25,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        $status = 0;
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        $error = $raw === false ? 'Unable to reach FoxDesk Cloud.' : '';
    }

    if ($raw === false || $raw === null || $raw === '') {
        throw new RuntimeException($error !== '' ? $error : 'FoxDesk Cloud returned an empty response.');
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('FoxDesk Cloud returned invalid JSON.');
    }
    if ($status >= 400 || empty($decoded['success'])) {
        throw new RuntimeException((string) ($decoded['error'] ?? 'FoxDesk Cloud migration API request failed.'));
    }

    return $decoded;
}

function migration_cloud_connect(string $cloud_url, string $token): array
{
    $response = migration_cloud_http_json($cloud_url, 'migration-connect', $token, [
        'inventory' => migration_inventory(),
    ]);
    migration_cloud_mark_connected($cloud_url, $token, $response);
    return $response;
}

function migration_cloud_plan(string $cloud_url, string $token): array
{
    $response = migration_cloud_http_json($cloud_url, 'migration-plan', $token, [
        'inventory' => migration_inventory(),
    ]);
    migration_cloud_mark_connected($cloud_url, $token, $response);
    save_setting('migration_cloud_last_plan_json', migration_json_encode($response));
    save_setting('migration_cloud_last_plan_at', gmdate('c'));
    return $response;
}

function migration_cloud_chunk_tables(): array
{
    return array_values(array_diff(migration_export_tables(), [
        'settings',
        'email_templates',
        'api_tokens',
        'attachments',
        'ticket_message_attachments',
    ]));
}

function migration_cloud_post_attachment_tables(): array
{
    return ['ticket_message_attachments'];
}

function migration_select_chunk(string $table, int $offset = 0, int $limit = 500): array
{
    validate_sql_identifier($table);
    if (!table_exists($table)) {
        return [];
    }

    $offset = max(0, $offset);
    $limit = max(1, min(1000, $limit));
    $order = in_array('id', migration_table_columns($table), true) ? ' ORDER BY id ASC' : '';
    return db_fetch_all("SELECT * FROM {$table}{$order} LIMIT {$limit} OFFSET {$offset}");
}

function migration_cloud_push_table_chunk(string $cloud_url, string $token, string $table, int $offset = 0, int $limit = 500): array
{
    if (!in_array($table, array_merge(migration_cloud_chunk_tables(), migration_cloud_post_attachment_tables()), true)) {
        throw new InvalidArgumentException('This table is not enabled for API chunk sync yet.');
    }

    $rows = migration_select_chunk($table, $offset, $limit);
    if (!$rows) {
        return [
            'success' => true,
            'table' => $table,
            'sent_rows' => 0,
            'done' => true,
            'summary' => ['created' => 0, 'updated' => 0, 'mapped' => 0, 'skipped' => 0, 'rows' => 0],
        ];
    }

    $checksum = hash('sha256', migration_json_encode($rows));
    $response = migration_cloud_http_json($cloud_url, 'migration-push-table', $token, [
        'table' => $table,
        'offset' => $offset,
        'limit' => $limit,
        'rows' => $rows,
        'checksum' => $checksum,
    ]);

    save_setting('migration_cloud_mode', 'syncing');
    save_setting('migration_cloud_last_sync_at', gmdate('c'));
    return array_merge($response, [
        'sent_rows' => count($rows),
        'done' => count($rows) < $limit,
    ]);
}

function migration_cloud_http_multipart(string $cloud_url, string $action, string $token, array $fields, string $file_path, string $file_field = 'file'): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for streaming attachment migration.');
    }

    if (!is_file($file_path)) {
        throw new RuntimeException('Attachment file is missing on disk.');
    }

    $mime = (string) ($fields['mime_type'] ?? 'application/octet-stream');
    $name = (string) ($fields['original_name'] ?? basename($file_path));
    $post = $fields;
    $post[$file_field] = new CURLFile($file_path, $mime !== '' ? $mime : 'application/octet-stream', $name !== '' ? $name : basename($file_path));

    $ch = curl_init(migration_cloud_endpoint($cloud_url, $action));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . trim($token),
            'X-FoxDesk-Migration-Version: 1',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $raw === null || $raw === '') {
        throw new RuntimeException($error !== '' ? $error : 'FoxDesk Cloud returned an empty attachment response.');
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('FoxDesk Cloud returned invalid attachment JSON.');
    }
    if ($status >= 400 || empty($decoded['success'])) {
        throw new RuntimeException((string) ($decoded['error'] ?? 'FoxDesk Cloud attachment upload failed.'));
    }

    return $decoded;
}

function migration_cloud_push_attachment(string $cloud_url, string $token, array $attachment): array
{
    $path = migration_attachment_absolute_path($attachment);
    if ($path === null) {
        return [
            'success' => true,
            'skipped' => true,
            'reason' => 'missing_file',
            'source_id' => (int) ($attachment['id'] ?? 0),
        ];
    }

    $metadata = $attachment;
    $metadata['source_instance_id'] = migration_source_instance_id();
    $metadata_json = migration_json_encode($metadata);

    return migration_cloud_http_multipart($cloud_url, 'migration-push-attachment', $token, [
        'metadata' => $metadata_json,
        'checksum' => hash_file('sha256', $path),
        'source_id' => (string) ((int) ($attachment['id'] ?? 0)),
        'mime_type' => (string) ($attachment['mime_type'] ?? 'application/octet-stream'),
        'original_name' => (string) ($attachment['original_name'] ?? $attachment['filename'] ?? basename($path)),
    ], $path);
}

function migration_cloud_sync_attachments(string $cloud_url, string $token, int $limit = 50): array
{
    if (!table_exists('attachments')) {
        return ['sent' => 0, 'uploaded' => 0, 'mapped' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    }

    $limit = max(1, min(200, $limit));
    $offset = 0;
    $summary = ['sent' => 0, 'uploaded' => 0, 'mapped' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    do {
        $rows = migration_select_chunk('attachments', $offset, $limit);
        foreach ($rows as $row) {
            try {
                $result = migration_cloud_push_attachment($cloud_url, $token, $row);
                $summary['sent']++;
                if (!empty($result['skipped'])) {
                    $summary['skipped']++;
                } elseif (!empty($result['mapped'])) {
                    $summary['mapped']++;
                } else {
                    $summary['uploaded']++;
                }
            } catch (Throwable $e) {
                $summary['failed']++;
                if (count($summary['errors']) < 10) {
                    $summary['errors'][] = [
                        'attachment_id' => (int) ($row['id'] ?? 0),
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }
        $offset += $limit;
    } while (count($rows) >= $limit);

    save_setting('migration_cloud_last_attachment_sync_json', migration_json_encode($summary));
    return $summary;
}

function migration_cloud_sync_all_tables(string $cloud_url, string $token, int $limit = 500): array
{
    migration_cloud_plan($cloud_url, $token);

    $summary = [
        'tables' => [],
        'total_sent' => 0,
        'started_at' => gmdate('c'),
        'finished_at' => null,
    ];

    foreach (migration_cloud_chunk_tables() as $table) {
        if (!table_exists($table)) {
            continue;
        }
        $offset = 0;
        $table_summary = ['sent' => 0, 'chunks' => 0, 'created' => 0, 'updated' => 0, 'mapped' => 0, 'skipped' => 0];
        do {
            $result = migration_cloud_push_table_chunk($cloud_url, $token, $table, $offset, $limit);
            $sent = (int) ($result['sent_rows'] ?? 0);
            $table_summary['sent'] += $sent;
            $table_summary['chunks']++;
            $api_summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
            foreach (['created', 'updated', 'mapped', 'skipped'] as $key) {
                $table_summary[$key] += (int) ($api_summary[$key] ?? 0);
            }
            $offset += $limit;
        } while ($sent >= $limit);

        $summary['tables'][$table] = $table_summary;
        $summary['total_sent'] += $table_summary['sent'];
    }

    $summary['attachments'] = migration_cloud_sync_attachments($cloud_url, $token, max(1, min(100, $limit)));

    foreach (migration_cloud_post_attachment_tables() as $table) {
        if (!table_exists($table)) {
            continue;
        }
        $offset = 0;
        $table_summary = ['sent' => 0, 'chunks' => 0, 'created' => 0, 'updated' => 0, 'mapped' => 0, 'skipped' => 0];
        do {
            $result = migration_cloud_push_table_chunk($cloud_url, $token, $table, $offset, $limit);
            $sent = (int) ($result['sent_rows'] ?? 0);
            $table_summary['sent'] += $sent;
            $table_summary['chunks']++;
            $api_summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
            foreach (['created', 'updated', 'mapped', 'skipped'] as $key) {
                $table_summary[$key] += (int) ($api_summary[$key] ?? 0);
            }
            $offset += $limit;
        } while ($sent >= $limit);

        $summary['tables'][$table] = $table_summary;
        $summary['total_sent'] += $table_summary['sent'];
    }

    $summary['finished_at'] = gmdate('c');
    save_setting('migration_cloud_last_sync_summary_json', migration_json_encode($summary));
    save_setting('migration_cloud_mode', 'ready_for_cutover');
    return $summary;
}
