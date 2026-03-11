<?php
/**
 * Incoming Email Ingest Functions
 *
 * IMAP-based inbound email processing:
 * - validates sender against allowed_senders
 * - creates/appends tickets
 * - stores inbound email metadata + attachments
 * - keeps idempotent ingest logs
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Load incoming-mail settings from DB.
 */
function email_ingest_load_settings()
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $settings = [];
    try {
        $rows = db_fetch_all("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'imap_%'");
        foreach ($rows as $row) {
            $settings[(string) $row['setting_key']] = $row['setting_value'];
        }
    } catch (Throwable $e) {
        // settings table may not exist yet
    }

    return $settings;
}

/**
 * Parse bool-like value with fallback.
 */
function email_ingest_parse_bool($value, $fallback = false)
{
    $raw = strtolower(trim((string) $value));
    if ($raw === '') {
        return (bool) $fallback;
    }
    if (in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($raw, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return (bool) $fallback;
}

/**
 * Resolve bool from env -> settings -> default.
 */
function email_ingest_resolve_bool($env_key, $setting_value, $default = false)
{
    $env = getenv($env_key);
    if ($env !== false && trim((string) $env) !== '') {
        return email_ingest_parse_bool($env, $default);
    }
    if ($setting_value !== null && trim((string) $setting_value) !== '') {
        return email_ingest_parse_bool($setting_value, $default);
    }
    return (bool) $default;
}

/**
 * Load ingest configuration from environment and optional constants.
 */
function email_ingest_config()
{
    $settings = email_ingest_load_settings();

    $deny_default = ['php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'exe', 'bat', 'cmd', 'js', 'vbs', 'ps1', 'sh'];
    $deny_from_env = trim((string) getenv('IMAP_DENY_EXTENSIONS'));
    if ($deny_from_env === '' && !empty($settings['imap_deny_extensions'])) {
        $deny_from_env = trim((string) $settings['imap_deny_extensions']);
    }
    $denylist = $deny_default;
    if ($deny_from_env !== '') {
        $parts = array_map('trim', explode(',', strtolower($deny_from_env)));
        $parts = array_values(array_filter($parts, function ($v) {
            return $v !== '';
        }));
        if (!empty($parts)) {
            $denylist = $parts;
        }
    }

    $host_setting = trim((string) ($settings['imap_host'] ?? ''));
    $username_setting = trim((string) ($settings['imap_username'] ?? ''));
    $password_setting = (string) ($settings['imap_password'] ?? '');
    $folder_setting = trim((string) ($settings['imap_folder'] ?? ''));
    $processed_folder_setting = trim((string) ($settings['imap_processed_folder'] ?? ''));
    $failed_folder_setting = trim((string) ($settings['imap_failed_folder'] ?? ''));
    $storage_base_setting = trim((string) ($settings['imap_storage_base'] ?? ''));

    $port_env = (int) getenv('IMAP_PORT');
    $port_setting = (int) ($settings['imap_port'] ?? 0);

    $max_per_run_env = (int) getenv('IMAP_MAX_EMAILS_PER_RUN');
    $max_per_run_setting = (int) ($settings['imap_max_emails_per_run'] ?? 0);

    $max_attachment_env = (int) getenv('IMAP_MAX_ATTACHMENT_SIZE');
    $max_attachment_mb_setting = (int) ($settings['imap_max_attachment_size_mb'] ?? 0);
    $max_attachment_setting = $max_attachment_mb_setting > 0 ? $max_attachment_mb_setting * 1024 * 1024 : 0;

    $encryption_value = getenv('IMAP_ENCRYPTION');
    if ($encryption_value === false || trim((string) $encryption_value) === '') {
        $encryption_value = $settings['imap_encryption'] ?? (defined('IMAP_ENCRYPTION') ? IMAP_ENCRYPTION : 'ssl');
    }
    $encryption = strtolower(trim((string) $encryption_value));
    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
        $encryption = 'ssl';
    }

    $host = getenv('IMAP_HOST');
    if ($host === false || trim((string) $host) === '') {
        $host = $host_setting !== '' ? $host_setting : (defined('IMAP_HOST') ? IMAP_HOST : '');
    }
    $host = trim((string) $host);

    $username = getenv('IMAP_USERNAME');
    if ($username === false || trim((string) $username) === '') {
        $username = $username_setting !== '' ? $username_setting : (defined('IMAP_USERNAME') ? IMAP_USERNAME : '');
    }
    $username = trim((string) $username);

    $password = getenv('IMAP_PASSWORD');
    if ($password === false || trim((string) $password) === '') {
        $password = $password_setting !== '' ? $password_setting : (defined('IMAP_PASSWORD') ? IMAP_PASSWORD : '');
    }
    $password = (string) $password;

    $folder = getenv('IMAP_FOLDER');
    if ($folder === false || trim((string) $folder) === '') {
        $folder = $folder_setting !== '' ? $folder_setting : (defined('IMAP_FOLDER') ? IMAP_FOLDER : 'INBOX');
    }
    $folder = trim((string) $folder);

    $processed_folder = getenv('IMAP_PROCESSED_FOLDER');
    if ($processed_folder === false || trim((string) $processed_folder) === '') {
        $processed_folder = $processed_folder_setting !== '' ? $processed_folder_setting : (defined('IMAP_PROCESSED_FOLDER') ? IMAP_PROCESSED_FOLDER : 'Processed');
    }
    $processed_folder = trim((string) $processed_folder);

    $failed_folder = getenv('IMAP_FAILED_FOLDER');
    if ($failed_folder === false || trim((string) $failed_folder) === '') {
        $failed_folder = $failed_folder_setting !== '' ? $failed_folder_setting : (defined('IMAP_FAILED_FOLDER') ? IMAP_FAILED_FOLDER : 'Failed');
    }
    $failed_folder = trim((string) $failed_folder);

    $storage_base = getenv('IMAP_STORAGE_BASE');
    if ($storage_base === false || trim((string) $storage_base) === '') {
        $storage_base = $storage_base_setting !== '' ? $storage_base_setting : (defined('IMAP_STORAGE_BASE') ? IMAP_STORAGE_BASE : 'storage/tickets');
    }
    $storage_base = trim((string) $storage_base);

    $enabled_default = defined('IMAP_ENABLED') ? (bool) IMAP_ENABLED : ($host !== '' && $username !== '' && $password !== '');
    $enabled = email_ingest_resolve_bool('IMAP_ENABLED', $settings['imap_enabled'] ?? null, $enabled_default);

    return [
        'enabled' => $enabled,
        'host' => $host,
        'port' => $port_env > 0 ? $port_env : ($port_setting > 0 ? $port_setting : (defined('IMAP_PORT') ? (int) IMAP_PORT : 993)),
        'encryption' => $encryption,
        'username' => $username,
        'password' => $password,
        'folder' => $folder !== '' ? $folder : 'INBOX',
        'processed_folder' => $processed_folder !== '' ? $processed_folder : 'Processed',
        'failed_folder' => $failed_folder !== '' ? $failed_folder : 'Failed',
        'max_per_run' => $max_per_run_env > 0 ? $max_per_run_env : ($max_per_run_setting > 0 ? $max_per_run_setting : (defined('IMAP_MAX_EMAILS_PER_RUN') ? (int) IMAP_MAX_EMAILS_PER_RUN : 50)),
        'max_attachment_size' => $max_attachment_env > 0 ? $max_attachment_env : ($max_attachment_setting > 0 ? $max_attachment_setting : (defined('IMAP_MAX_ATTACHMENT_SIZE') ? (int) IMAP_MAX_ATTACHMENT_SIZE : 10485760)),
        'deny_extensions' => $denylist,
        'validate_cert' => email_ingest_resolve_bool('IMAP_VALIDATE_CERT', $settings['imap_validate_cert'] ?? null, defined('IMAP_VALIDATE_CERT') ? (bool) IMAP_VALIDATE_CERT : false),
        'storage_base' => $storage_base !== '' ? $storage_base : 'storage/tickets',
        'mark_seen_on_skip' => email_ingest_resolve_bool('IMAP_MARK_SEEN_ON_SKIP', $settings['imap_mark_seen_on_skip'] ?? null, defined('IMAP_MARK_SEEN_ON_SKIP') ? (bool) IMAP_MARK_SEEN_ON_SKIP : true),
        'allow_unknown_senders' => email_ingest_resolve_bool('IMAP_ALLOW_UNKNOWN_SENDERS', $settings['imap_allow_unknown_senders'] ?? null, false),
    ];
}

/**
 * Execute one ingest run.
 */
function email_ingest_run($options = [])
{
    $result = [
        'processed' => 0,
        'skipped' => 0,
        'failed' => 0,
        'checked' => 0,
        'details' => [],
    ];

    $cfg = email_ingest_config();
    if (empty($cfg['enabled'])) {
        $result['disabled'] = true;
        return $result;
    }

    if (!extension_loaded('imap')) {
        throw new RuntimeException('PHP IMAP extension is not loaded.');
    }

    $cfg['max_per_run'] = isset($options['limit']) && (int) $options['limit'] > 0 ? (int) $options['limit'] : $cfg['max_per_run'];
    $dry_run = !empty($options['dry_run']);
    $mailbox_name = $cfg['folder'];

    email_ingest_validate_config($cfg);
    email_ingest_ensure_schema();

    $mailbox_path = email_ingest_mailbox_path($cfg, $cfg['folder']);
    $imap = @imap_open($mailbox_path, $cfg['username'], $cfg['password']);
    if (!$imap) {
        $last_error = imap_last_error();
        imap_errors();
        imap_alerts();
        throw new RuntimeException('IMAP connection failed: ' . $last_error);
    }

    try {
        $uids = imap_search($imap, 'ALL', SE_UID);
        if (!is_array($uids)) {
            return $result;
        }

        sort($uids, SORT_NUMERIC);
        $to_process = [];
        foreach ($uids as $uid) {
            if (count($to_process) >= $cfg['max_per_run']) {
                break;
            }
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }
            if (email_ingest_log_exists($mailbox_name, $uid)) {
                continue;
            }
            $to_process[] = $uid;
        }

        foreach ($to_process as $uid) {
            $result['checked']++;
            try {
                $message_outcome = email_ingest_process_uid($imap, $uid, $cfg, $dry_run);
                $result[$message_outcome['status']]++;
                $result['details'][] = $message_outcome;
            } catch (Throwable $e) {
                $result['failed']++;
                $result['details'][] = [
                    'uid' => $uid,
                    'status' => 'failed',
                    'reason' => 'exception',
                    'error' => $e->getMessage(),
                ];
                email_ingest_log($mailbox_name, $uid, null, 'failed', 'exception', $e->getMessage());
                if (!$dry_run) {
                    email_ingest_try_move($imap, $uid, $cfg, $cfg['failed_folder']);
                }
            }

            email_ingest_update_state($mailbox_name, $uid);
        }
    } finally {
        imap_close($imap, CL_EXPUNGE);
    }

    return $result;
}

/**
 * Test IMAP connection using current config or provided overrides.
 */
function email_ingest_test_connection($overrides = [])
{
    $cfg = array_merge(email_ingest_config(), $overrides);
    email_ingest_validate_config($cfg);

    if (!extension_loaded('imap')) {
        throw new RuntimeException('PHP IMAP extension is not loaded.');
    }

    $mailbox_path = email_ingest_mailbox_path($cfg, $cfg['folder']);
    $imap = @imap_open($mailbox_path, $cfg['username'], $cfg['password']);
    if (!$imap) {
        $last_error = imap_last_error();
        imap_errors();
        imap_alerts();
        throw new RuntimeException('IMAP connection failed: ' . $last_error);
    }

    $messages = (int) @imap_num_msg($imap);
    imap_close($imap);

    return [
        'messages' => $messages,
        'mailbox' => $cfg['folder'],
    ];
}

/**
 * Process one UID from IMAP mailbox.
 */
function email_ingest_process_uid($imap, $uid, $cfg, $dry_run = false)
{
    $mailbox_name = $cfg['folder'];
    $overview_rows = imap_fetch_overview($imap, (string) $uid, FT_UID);
    if (empty($overview_rows) || !isset($overview_rows[0])) {
        email_ingest_log($mailbox_name, $uid, null, 'failed', 'overview_missing', 'Unable to fetch overview');
        return [
            'uid' => $uid,
            'status' => 'failed',
            'reason' => 'overview_missing',
            'error' => 'Unable to fetch overview',
        ];
    }

    $overview = $overview_rows[0];
    $raw_headers = imap_fetchheader($imap, (string) $uid, FT_UID);
    if (!is_string($raw_headers) || $raw_headers === '') {
        $raw_headers = '';
    }

    $parsed_headers = @imap_rfc822_parse_headers($raw_headers);
    $message_id = email_ingest_normalize_message_id(
        email_ingest_header_value($parsed_headers, 'message_id', (string) ($overview->message_id ?? ''))
    );

    $from_email = email_ingest_extract_from_email($parsed_headers, $raw_headers);
    $subject_raw = (string) ($overview->subject ?? email_ingest_header_value($parsed_headers, 'subject', ''));
    $subject = email_ingest_decode_mime_string($subject_raw);
    if ($subject === '') {
        $subject = '(No subject)';
    }

    if ($message_id !== null && email_ingest_message_id_exists($message_id)) {
        email_ingest_log($mailbox_name, $uid, $message_id, 'skipped', 'duplicate_message_id', null, [
            'sender_email' => $from_email,
            'subject' => $subject,
        ]);
        if (!$dry_run) {
            email_ingest_try_move($imap, $uid, $cfg, $cfg['processed_folder']);
        }
        return [
            'uid' => $uid,
            'status' => 'skipped',
            'reason' => 'duplicate_message_id',
            'message_id' => $message_id,
        ];
    }

    if ($from_email === '') {
        email_ingest_log($mailbox_name, $uid, $message_id, 'skipped', 'missing_from', null, [
            'subject' => $subject,
        ]);
        if (!$dry_run) {
            email_ingest_try_move($imap, $uid, $cfg, $cfg['failed_folder']);
        }
        return [
            'uid' => $uid,
            'status' => 'skipped',
            'reason' => 'missing_from',
            'message_id' => $message_id,
        ];
    }

    if (email_ingest_is_auto_reply($parsed_headers, $raw_headers)) {
        email_ingest_log($mailbox_name, $uid, $message_id, 'skipped', 'auto_reply_or_bulk', null, [
            'sender_email' => $from_email,
            'subject' => $subject,
        ]);
        if (!$dry_run) {
            email_ingest_try_move($imap, $uid, $cfg, $cfg['processed_folder']);
        }
        return [
            'uid' => $uid,
            'status' => 'skipped',
            'reason' => 'auto_reply_or_bulk',
            'message_id' => $message_id,
            'from' => $from_email,
        ];
    }

    $allowed_sender = email_ingest_allowed_sender($from_email);
    if (!$allowed_sender && empty($cfg['allow_unknown_senders'])) {
        email_ingest_log($mailbox_name, $uid, $message_id, 'skipped', 'sender_not_allowed', null, [
            'sender_email' => $from_email,
            'subject' => $subject,
        ]);
        if (!$dry_run) {
            if (!$cfg['mark_seen_on_skip']) {
                email_ingest_try_move($imap, $uid, $cfg, $cfg['failed_folder']);
            } else {
                imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
            }
        }
        return [
            'uid' => $uid,
            'status' => 'skipped',
            'reason' => 'sender_not_allowed',
            'message_id' => $message_id,
            'from' => $from_email,
        ];
    }

    $message_payload = email_ingest_extract_message_payload($imap, $uid);
    $body_text = trim($message_payload['text']);
    $body_html_raw = trim($message_payload['html']);
    $body_html = email_ingest_sanitize_html($body_html_raw);
    if ($body_text === '' && $body_html !== '') {
        $body_text = email_ingest_html_to_text($body_html);
    }
    if ($body_text === '') {
        $body_text = '(No content)';
    }

    $in_reply_to = email_ingest_normalize_message_id(
        email_ingest_header_value($parsed_headers, 'in_reply_to', '')
    );
    $references = email_ingest_header_value($parsed_headers, 'references', '');
    $reference_ids = email_ingest_extract_message_ids($references);

    $requester = email_ingest_resolve_requester_user_id($from_email, $allowed_sender);
    $requester_user_id = (int) ($requester['user_id'] ?? 0);
    if ($requester_user_id <= 0) {
        email_ingest_log($mailbox_name, $uid, $message_id, 'failed', 'requester_resolution_failed', null, [
            'sender_email' => $from_email,
            'subject' => $subject,
        ]);
        if (!$dry_run) {
            email_ingest_try_move($imap, $uid, $cfg, $cfg['failed_folder']);
        }
        return [
            'uid' => $uid,
            'status' => 'failed',
            'reason' => 'requester_resolution_failed',
            'message_id' => $message_id,
        ];
    }

    $attachment_errors = [];
    $saved_files = [];
    $db = get_db();
    $ticket_id = 0;
    $comment_id = null;
    $ticket_created = false;

    try {
        if (!$dry_run) {
            $db->beginTransaction();
        }

        $ticket_id = email_ingest_resolve_ticket_id($subject, $in_reply_to, $reference_ids);
        if ($ticket_id <= 0) {
            $ticket_id = email_ingest_create_ticket_from_email($requester_user_id, $subject, $body_text);
            $ticket_created = true;
        } else {
            $comment_id = email_ingest_add_inbound_comment($ticket_id, $requester_user_id, $body_text);
        }

        if ($ticket_id <= 0) {
            throw new RuntimeException('Ticket could not be created/resolved');
        }

        $ticket_message_id = email_ingest_insert_ticket_message([
            'ticket_id' => $ticket_id,
            'user_id' => $requester_user_id,
            'comment_id' => $comment_id,
            'sender_email' => $from_email,
            'subject' => $subject,
            'body_text' => $body_text,
            'body_html' => $body_html,
            'body_html_raw' => $body_html_raw,
            'raw_headers' => $raw_headers,
            'message_id' => $message_id,
            'in_reply_to' => $in_reply_to,
            'references' => trim($references) !== '' ? trim($references) : null,
            'mailbox' => $mailbox_name,
            'uid' => $uid,
        ]);

        foreach ($message_payload['attachments'] as $attachment) {
            $store = email_ingest_store_attachment(
                $ticket_id,
                $ticket_message_id,
                $comment_id,
                $requester_user_id,
                $attachment,
                $cfg
            );

            if ($store['stored']) {
                $saved_files[] = $store['absolute_path'];
            } else {
                $attachment_errors[] = $store['error'];
            }
        }

        if (!$dry_run) {
            $db->commit();
        } else {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach ($saved_files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        email_ingest_log($mailbox_name, $uid, $message_id, 'failed', 'processing_failed', $e->getMessage(), [
            'sender_email' => $from_email,
            'subject' => $subject,
            'ticket_id' => $ticket_id > 0 ? $ticket_id : null,
        ]);
        if (!$dry_run) {
            email_ingest_try_move($imap, $uid, $cfg, $cfg['failed_folder']);
        }

        return [
            'uid' => $uid,
            'status' => 'failed',
            'reason' => 'processing_failed',
            'error' => $e->getMessage(),
            'message_id' => $message_id,
        ];
    }

    email_ingest_log($mailbox_name, $uid, $message_id, 'processed', null, null, [
        'sender_email' => $from_email,
        'subject' => $subject,
        'ticket_id' => $ticket_id,
    ]);
    if (!$dry_run) {
        email_ingest_try_move($imap, $uid, $cfg, $cfg['processed_folder']);
    }

    if (!$dry_run) {
        try {
            email_ingest_send_requester_notifications($ticket_id, $ticket_created, $requester);
        } catch (Throwable $e) {
            error_log('email_ingest_send_requester_notifications failed: ' . $e->getMessage());
        }
    }

    return [
        'uid' => $uid,
        'status' => 'processed',
        'message_id' => $message_id,
        'from' => $from_email,
        'ticket_id' => $ticket_id,
        'ticket_created' => $ticket_created,
        'attachment_errors' => $attachment_errors,
    ];
}

/**
 * Validate required configuration fields.
 */
function email_ingest_validate_config($cfg)
{
    $required = ['host', 'port', 'username', 'password', 'folder'];
    foreach ($required as $key) {
        if (!isset($cfg[$key]) || trim((string) $cfg[$key]) === '') {
            throw new InvalidArgumentException("Missing incoming-mail config: {$key}");
        }
    }
}

/**
 * Ensure required inbound email tables/columns exist.
 */
function email_ingest_ensure_schema()
{
    $checks = [
        "SHOW TABLES LIKE 'allowed_senders'" => "
            CREATE TABLE allowed_senders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type ENUM('email','domain') NOT NULL,
                value VARCHAR(255) NOT NULL,
                user_id INT NULL,
                active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_type_value (type, value),
                INDEX idx_active (active),
                INDEX idx_user (user_id),
                CONSTRAINT fk_allowed_senders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        "SHOW TABLES LIKE 'ticket_messages'" => "
            CREATE TABLE ticket_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                direction ENUM('in','out') NOT NULL DEFAULT 'in',
                user_id INT NULL,
                comment_id INT NULL,
                sender_email VARCHAR(255) NULL,
                subject VARCHAR(255) NULL,
                body_text MEDIUMTEXT,
                body_html MEDIUMTEXT NULL,
                body_html_raw MEDIUMTEXT NULL,
                raw_headers MEDIUMTEXT,
                message_id VARCHAR(255) NULL,
                in_reply_to VARCHAR(255) NULL,
                references_header TEXT NULL,
                mailbox VARCHAR(120) NULL,
                uid INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ticket_messages_message_id (message_id),
                UNIQUE KEY uniq_ticket_messages_mailbox_uid (mailbox, uid),
                INDEX idx_ticket (ticket_id),
                INDEX idx_comment (comment_id),
                INDEX idx_user (user_id),
                INDEX idx_created (created_at),
                CONSTRAINT fk_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                CONSTRAINT fk_ticket_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_ticket_messages_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        "SHOW TABLES LIKE 'ticket_message_attachments'" => "
            CREATE TABLE ticket_message_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_message_id INT NOT NULL,
                attachment_id INT NULL,
                filename VARCHAR(255) NOT NULL,
                mime VARCHAR(120) NULL,
                size INT DEFAULT 0,
                storage_path VARCHAR(500) NOT NULL,
                content_id VARCHAR(255) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_message (ticket_message_id),
                INDEX idx_attachment (attachment_id),
                CONSTRAINT fk_tma_message FOREIGN KEY (ticket_message_id) REFERENCES ticket_messages(id) ON DELETE CASCADE,
                CONSTRAINT fk_tma_attachment FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        "SHOW TABLES LIKE 'email_ingest_logs'" => "
            CREATE TABLE email_ingest_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                mailbox VARCHAR(120) NOT NULL,
                uid INT NOT NULL,
                message_id VARCHAR(255) NULL,
                sender_email VARCHAR(255) NULL,
                subject VARCHAR(255) NULL,
                ticket_id INT NULL,
                status ENUM('processed','skipped','failed') NOT NULL,
                reason VARCHAR(100) NULL,
                error TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_mailbox_uid (mailbox, uid),
                INDEX idx_message_id (message_id),
                INDEX idx_sender_email (sender_email),
                INDEX idx_ticket_id (ticket_id),
                INDEX idx_status_created (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        "SHOW TABLES LIKE 'email_ingest_state'" => "
            CREATE TABLE email_ingest_state (
                mailbox VARCHAR(120) PRIMARY KEY,
                last_seen_uid INT DEFAULT 0,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
    ];

    foreach ($checks as $check_sql => $create_sql) {
        $exists = db_fetch_one($check_sql);
        if (!$exists) {
            db_query($create_sql);
        }
    }

    $source_col = db_fetch_one("SHOW COLUMNS FROM tickets LIKE 'source'");
    if (!$source_col) {
        db_query("ALTER TABLE tickets ADD COLUMN source VARCHAR(20) DEFAULT 'web' AFTER ticket_type_id");
        db_query("CREATE INDEX idx_source ON tickets (source)");
    }

    $sender_col = db_fetch_one("SHOW COLUMNS FROM email_ingest_logs LIKE 'sender_email'");
    if (!$sender_col) {
        db_query("ALTER TABLE email_ingest_logs ADD COLUMN sender_email VARCHAR(255) NULL AFTER message_id");
        db_query("CREATE INDEX idx_sender_email ON email_ingest_logs (sender_email)");
    }

    $subject_col = db_fetch_one("SHOW COLUMNS FROM email_ingest_logs LIKE 'subject'");
    if (!$subject_col) {
        db_query("ALTER TABLE email_ingest_logs ADD COLUMN subject VARCHAR(255) NULL AFTER sender_email");
    }

    $ticket_col = db_fetch_one("SHOW COLUMNS FROM email_ingest_logs LIKE 'ticket_id'");
    if (!$ticket_col) {
        db_query("ALTER TABLE email_ingest_logs ADD COLUMN ticket_id INT NULL AFTER subject");
        db_query("CREATE INDEX idx_ticket_id ON email_ingest_logs (ticket_id)");
    }
}

/**
 * Build full IMAP mailbox path.
 */
function email_ingest_mailbox_path($cfg, $folder)
{
    $enc = strtolower((string) $cfg['encryption']);
    $flags = '/imap';
    if ($enc === 'ssl') {
        $flags .= '/ssl';
    } elseif ($enc === 'tls') {
        $flags .= '/tls';
    } else {
        $flags .= '/notls';
    }
    if (empty($cfg['validate_cert'])) {
        $flags .= '/novalidate-cert';
    }

    return '{' . $cfg['host'] . ':' . (int) $cfg['port'] . $flags . '}' . $folder;
}

/**
 * Determine if log entry exists for mailbox+uid.
 */
function email_ingest_log_exists($mailbox, $uid)
{
    $row = db_fetch_one("SELECT id FROM email_ingest_logs WHERE mailbox = ? AND uid = ? LIMIT 1", [$mailbox, $uid]);
    return !empty($row);
}

/**
 * Upsert ingest state.
 */
function email_ingest_update_state($mailbox, $uid)
{
    db_query(
        "INSERT INTO email_ingest_state (mailbox, last_seen_uid, updated_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            last_seen_uid = GREATEST(last_seen_uid, VALUES(last_seen_uid)),
            updated_at = NOW()",
        [$mailbox, $uid]
    );
}

/**
 * Log one message ingest outcome.
 */
function email_ingest_log($mailbox, $uid, $message_id, $status, $reason = null, $error = null, $meta = [])
{
    try {
        $sender_email = email_ingest_normalize_email($meta['sender_email'] ?? '');
        if ($sender_email === '') {
            $sender_email = null;
        }

        $subject = trim((string) ($meta['subject'] ?? ''));
        if ($subject !== '') {
            if (function_exists('mb_substr')) {
                $subject = mb_substr($subject, 0, 255);
            } else {
                $subject = substr($subject, 0, 255);
            }
        } else {
            $subject = null;
        }

        $ticket_id = isset($meta['ticket_id']) ? (int) $meta['ticket_id'] : 0;
        if ($ticket_id <= 0) {
            $ticket_id = null;
        }

        db_query(
            "INSERT INTO email_ingest_logs (mailbox, uid, message_id, sender_email, subject, ticket_id, status, reason, error, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                message_id = VALUES(message_id),
                sender_email = VALUES(sender_email),
                subject = VALUES(subject),
                ticket_id = VALUES(ticket_id),
                status = VALUES(status),
                reason = VALUES(reason),
                error = VALUES(error),
                created_at = NOW()",
            [$mailbox, $uid, $message_id, $sender_email, $subject, $ticket_id, $status, $reason, $error]
        );
    } catch (Throwable $e) {
        error_log('email_ingest_log failed: ' . $e->getMessage());
    }
}

/**
 * Check if message ID already exists in ticket_messages.
 */
function email_ingest_message_id_exists($message_id)
{
    if ($message_id === null || $message_id === '') {
        return false;
    }
    $row = db_fetch_one("SELECT id FROM ticket_messages WHERE message_id = ? LIMIT 1", [$message_id]);
    return !empty($row);
}

/**
 * Normalize email for lookups.
 */
function email_ingest_normalize_email($email)
{
    $email = strtolower(trim((string) $email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

/**
 * Extract sender email from parsed headers/raw headers.
 */
function email_ingest_extract_from_email($parsed_headers, $raw_headers)
{
    if (is_object($parsed_headers) && !empty($parsed_headers->from) && isset($parsed_headers->from[0])) {
        $from = $parsed_headers->from[0];
        if (!empty($from->mailbox) && !empty($from->host)) {
            $candidate = $from->mailbox . '@' . $from->host;
            return email_ingest_normalize_email($candidate);
        }
    }

    if (preg_match('/^From:\s*(.+)$/im', $raw_headers, $m)) {
        $line = trim($m[1]);
        if (preg_match('/<([^>]+)>/', $line, $m2)) {
            return email_ingest_normalize_email($m2[1]);
        }
        return email_ingest_normalize_email($line);
    }

    return '';
}

/**
 * Determine whether message should be treated as auto-reply/bulk.
 */
function email_ingest_is_auto_reply($parsed_headers, $raw_headers)
{
    $auto_submitted = strtolower(trim((string) email_ingest_header_value($parsed_headers, 'auto_submitted', '')));
    if ($auto_submitted !== '' && $auto_submitted !== 'no') {
        return true;
    }

    $precedence = strtolower(trim((string) email_ingest_header_value($parsed_headers, 'precedence', '')));
    if (in_array($precedence, ['bulk', 'junk', 'list'], true)) {
        return true;
    }

    $x_auto_response = strtolower(trim((string) email_ingest_header_value($parsed_headers, 'x_auto_response_suppress', '')));
    if ($x_auto_response !== '') {
        return true;
    }

    if (preg_match('/^Auto-Submitted:\s*(.+)$/im', $raw_headers, $m)) {
        $v = strtolower(trim($m[1]));
        if ($v !== '' && $v !== 'no') {
            return true;
        }
    }

    if (preg_match('/^Precedence:\s*(bulk|junk|list)\s*$/im', $raw_headers)) {
        return true;
    }

    if (preg_match('/^X-Autoreply:\s*(yes|true|1)\s*$/im', $raw_headers)) {
        return true;
    }

    return false;
}

/**
 * Get header value from parsed headers object.
 */
function email_ingest_header_value($parsed_headers, $field, $fallback = '')
{
    if (is_object($parsed_headers) && isset($parsed_headers->{$field})) {
        $value = $parsed_headers->{$field};
        if (is_array($value)) {
            return implode(' ', array_map('strval', $value));
        }
        return (string) $value;
    }
    return (string) $fallback;
}

/**
 * Normalize Message-ID format for storage and matching.
 */
function email_ingest_normalize_message_id($message_id)
{
    $message_id = trim((string) $message_id);
    if ($message_id === '') {
        return null;
    }
    $message_id = trim($message_id, "<> \t\r\n");
    if ($message_id === '') {
        return null;
    }
    return strtolower($message_id);
}

/**
 * Extract message IDs from References header.
 */
function email_ingest_extract_message_ids($references_value)
{
    $references_value = (string) $references_value;
    if ($references_value === '') {
        return [];
    }
    preg_match_all('/<([^>]+)>/', $references_value, $matches);
    if (empty($matches[1])) {
        return [];
    }
    $ids = [];
    foreach ($matches[1] as $id) {
        $norm = email_ingest_normalize_message_id($id);
        if ($norm !== null) {
            $ids[] = $norm;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Decode MIME encoded header string.
 */
function email_ingest_decode_mime_string($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (function_exists('iconv_mime_decode')) {
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if (is_string($decoded) && $decoded !== '') {
            return email_ingest_convert_to_utf8($decoded, 'UTF-8');
        }
    }

    if (function_exists('mb_decode_mimeheader')) {
        $decoded = @mb_decode_mimeheader($value);
        if (is_string($decoded) && $decoded !== '') {
            return email_ingest_convert_to_utf8($decoded);
        }
    }

    return email_ingest_convert_to_utf8($value);
}

/**
 * Normalize charset aliases to canonical names.
 */
function email_ingest_normalize_charset($charset)
{
    $charset = trim((string) $charset, " \t\r\n\"'");
    if ($charset === '') {
        return '';
    }

    $key = strtoupper(preg_replace('/[^A-Z0-9]/', '', $charset));
    $map = [
        'UTF8' => 'UTF-8',
        'UTF16' => 'UTF-16',
        'USASCII' => 'ASCII',
        'ASCII' => 'ASCII',
        'CP1250' => 'WINDOWS-1250',
        'WINDOWS1250' => 'WINDOWS-1250',
        'XCP1250' => 'WINDOWS-1250',
        'CP1252' => 'WINDOWS-1252',
        'WINDOWS1252' => 'WINDOWS-1252',
        'XCP1252' => 'WINDOWS-1252',
        'ISO88592' => 'ISO-8859-2',
        'ISO88591' => 'ISO-8859-1',
    ];

    if (isset($map[$key])) {
        return $map[$key];
    }

    return strtoupper($charset);
}

/**
 * Convert unknown/legacy encoded text to UTF-8.
 */
function email_ingest_convert_to_utf8($value, $charset = '')
{
    $value = (string) $value;
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_check_encoding') && @mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }

    $candidates = [];
    $normalized_charset = email_ingest_normalize_charset($charset);
    if ($normalized_charset !== '') {
        $candidates[] = $normalized_charset;
    }

    $fallbacks = ['WINDOWS-1250', 'ISO-8859-2', 'WINDOWS-1252', 'ISO-8859-1'];
    foreach ($fallbacks as $fallback) {
        if (!in_array($fallback, $candidates, true)) {
            $candidates[] = $fallback;
        }
    }

    foreach ($candidates as $candidate) {
        if ($candidate === '' || $candidate === 'UTF-8') {
            continue;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $candidate);
            if (is_string($converted) && $converted !== '') {
                if (!function_exists('mb_check_encoding') || @mb_check_encoding($converted, 'UTF-8')) {
                    return $converted;
                }
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv($candidate, 'UTF-8//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
    }

    if (function_exists('mb_detect_encoding')) {
        $detected = @mb_detect_encoding(
            $value,
            ['UTF-8', 'WINDOWS-1250', 'ISO-8859-2', 'WINDOWS-1252', 'ISO-8859-1'],
            true
        );
        if (is_string($detected) && $detected !== '' && strtoupper($detected) !== 'UTF-8') {
            $detected = email_ingest_normalize_charset($detected);

            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($value, 'UTF-8', $detected);
                if (is_string($converted) && $converted !== '') {
                    if (!function_exists('mb_check_encoding') || @mb_check_encoding($converted, 'UTF-8')) {
                        return $converted;
                    }
                }
            }

            if (function_exists('iconv')) {
                $converted = @iconv($detected, 'UTF-8//IGNORE', $value);
                if (is_string($converted) && $converted !== '') {
                    return $converted;
                }
            }
        }
    }

    if (function_exists('iconv')) {
        $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if (is_string($cleaned) && $cleaned !== '') {
            return $cleaned;
        }
    }

    return $value;
}

/**
 * Resolve charset from MIME params.
 */
function email_ingest_extract_part_charset($params)
{
    if (empty($params) || !is_array($params)) {
        return '';
    }

    foreach (['charset', 'x-charset'] as $key) {
        if (!empty($params[$key])) {
            return email_ingest_normalize_charset((string) $params[$key]);
        }
    }

    return '';
}

/**
 * Find allowed sender match for email/domain.
 */
function email_ingest_allowed_sender($email)
{
    $email = email_ingest_normalize_email($email);
    if ($email === '') {
        return null;
    }

    $domain = '';
    $at_pos = strrpos($email, '@');
    if ($at_pos !== false) {
        $domain = substr($email, $at_pos + 1);
    }

    $row = db_fetch_one(
        "SELECT * FROM allowed_senders
         WHERE active = 1
           AND ((type = 'email' AND value = ?) OR (type = 'domain' AND value = ?))
         ORDER BY CASE WHEN type = 'email' THEN 0 ELSE 1 END
         LIMIT 1",
        [$email, $domain]
    );

    return $row ?: null;
}

/**
 * Resolve or create requester user from sender email.
 */
function email_ingest_resolve_requester_user_id($email, $allowed_sender)
{
    $result = [
        'user_id' => 0,
        'created' => false,
        'setup_token' => null,
    ];

    $email = email_ingest_normalize_email($email);
    if ($email === '') {
        return $result;
    }

    if (!empty($allowed_sender['user_id'])) {
        $user = db_fetch_one("SELECT id FROM users WHERE id = ? LIMIT 1", [(int) $allowed_sender['user_id']]);
        if ($user) {
            $result['user_id'] = (int) $user['id'];
            return $result;
        }
    }

    $existing = db_fetch_one("SELECT id FROM users WHERE email = ? LIMIT 1", [$email]);
    if ($existing) {
        $result['user_id'] = (int) $existing['id'];
        return $result;
    }

    $local = strstr($email, '@', true);
    $local = $local !== false ? $local : $email;
    $local = preg_replace('/[^a-z0-9._-]+/i', ' ', $local);
    $local = trim($local);
    if ($local === '') {
        $local = 'Email';
    }
    $first_name = ucfirst(substr($local, 0, 60));
    $password = bin2hex(random_bytes(16));
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $user_id = (int) db_insert('users', [
        'email' => $email,
        'password' => $hash,
        'first_name' => $first_name,
        'last_name' => '',
        'role' => 'user',
        'language' => 'en',
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $result['user_id'] = $user_id;
    $result['created'] = $user_id > 0;
    if ($user_id > 0) {
        $result['setup_token'] = email_ingest_prepare_user_password_setup($user_id);
    }

    return $result;
}

/**
 * Prepare password-setup token for newly auto-created users.
 */
function email_ingest_prepare_user_password_setup($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return null;
    }

    try {
        $token = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

        db_update('users', [
            'reset_token' => $token_hash,
            'reset_token_expires' => $expires,
        ], 'id = ?', [$user_id]);

        return $token;
    } catch (Throwable $e) {
        error_log('email_ingest_prepare_user_password_setup failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Lazy-load dependencies needed for notification sending from CLI/web ingest.
 */
function email_ingest_require_mailer_dependencies()
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    require_once BASE_PATH . '/includes/settings-functions.php';
    require_once BASE_PATH . '/includes/mailer.php';
    $loaded = true;
}

/**
 * Build ticket code without requiring ticket helper includes.
 */
function email_ingest_ticket_code($ticket_id)
{
    $ticket_id = (int) $ticket_id;
    $settings = function_exists('get_settings') ? get_settings() : [];
    $prefix = !empty($settings['ticket_prefix']) ? $settings['ticket_prefix'] : 'TK';
    return $prefix . '-' . str_pad($ticket_id + 10000, 5, '0', STR_PAD_LEFT);
}

/**
 * Send post-processing notifications for inbound email requester.
 */
function email_ingest_send_requester_notifications($ticket_id, $ticket_created, $requester)
{
    $user_id = (int) ($requester['user_id'] ?? 0);
    if ($user_id <= 0) {
        return;
    }

    email_ingest_require_mailer_dependencies();

    $user = db_fetch_one("SELECT id, first_name, last_name, email, language FROM users WHERE id = ? LIMIT 1", [$user_id]);
    if (!$user || empty($user['email'])) {
        return;
    }

    $setup_token = trim((string) ($requester['setup_token'] ?? ''));
    if (!empty($requester['created']) && $setup_token !== '' && function_exists('send_password_reset_email')) {
        $reset_link = rtrim(get_app_url(), '/') . '/index.php?page=reset-password&token=' . urlencode($setup_token);
        @send_password_reset_email($user['email'], (string) ($user['first_name'] ?? ''), $reset_link);
    }

    if (!$ticket_created) {
        return;
    }

    $ticket = db_fetch_one("SELECT id, hash, title FROM tickets WHERE id = ? LIMIT 1", [(int) $ticket_id]);
    if (!$ticket) {
        return;
    }

    $settings = get_settings();
    $lang = strtolower(trim((string) ($user['language'] ?? 'en')));
    if ($lang === '') {
        $lang = 'en';
    }

    $ticket_code = email_ingest_ticket_code((int) $ticket['id']);
    $ticket_param = !empty($ticket['hash']) ? 't=' . urlencode($ticket['hash']) : 'id=' . (int) $ticket['id'];
    $ticket_url = rtrim(get_app_url(), '/') . '/index.php?page=ticket&' . $ticket_param;
    $recipient_name = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
    $app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');

    $template = function_exists('get_email_template') ? get_email_template('ticket_confirmation', $lang) : null;
    if (!$template) {
        $subject = 'Ticket received #{ticket_code}: {ticket_title}';
        $body = "Hello,\n\nYour ticket #{ticket_code} \"{ticket_title}\" was received successfully.\nWe will keep you updated on its progress.\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}";
    } else {
        $subject = $template['subject'];
        $body = $template['body'];
    }

    $placeholders = [
        '{ticket_id}' => (string) $ticket['id'],
        '{ticket_code}' => $ticket_code,
        '{ticket_title}' => (string) ($ticket['title'] ?? ''),
        '{ticket_url}' => $ticket_url,
        '{app_name}' => $app_name,
        '{recipient_name}' => $recipient_name,
        '{name}' => $recipient_name,
    ];

    $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
    $body = str_replace(array_keys($placeholders), array_values($placeholders), $body);

    if (function_exists('send_email')) {
        @send_email($user['email'], $subject, $body);
    }
}

/**
 * Resolve existing ticket from subject code / reply headers.
 */
function email_ingest_resolve_ticket_id($subject, $in_reply_to, $reference_ids)
{
    $subject = (string) $subject;

    if (preg_match('/\[(?:[A-Z]+)-(\d+)\]/', $subject, $m)) {
        $num = (int) $m[1];
        $candidates = [$num, $num - 10000];
        foreach ($candidates as $candidate) {
            if ($candidate <= 0) {
                continue;
            }
            $ticket = db_fetch_one("SELECT id FROM tickets WHERE id = ? LIMIT 1", [$candidate]);
            if ($ticket) {
                return (int) $ticket['id'];
            }
        }
    }

    $ids_for_lookup = [];
    if ($in_reply_to !== null) {
        $ids_for_lookup[] = $in_reply_to;
    }
    foreach ((array) $reference_ids as $ref) {
        $ids_for_lookup[] = $ref;
    }
    $ids_for_lookup = array_values(array_unique(array_filter($ids_for_lookup)));

    if (!empty($ids_for_lookup)) {
        $placeholders = implode(',', array_fill(0, count($ids_for_lookup), '?'));
        $row = db_fetch_one(
            "SELECT ticket_id FROM ticket_messages WHERE message_id IN ($placeholders) ORDER BY id DESC LIMIT 1",
            $ids_for_lookup
        );
        if ($row && !empty($row['ticket_id'])) {
            return (int) $row['ticket_id'];
        }
    }

    return 0;
}

/**
 * Create a ticket from inbound email.
 */
function email_ingest_create_ticket_from_email($requester_user_id, $subject, $body_text)
{
    $default_status = db_fetch_one("SELECT id FROM statuses WHERE is_default = 1 ORDER BY id ASC LIMIT 1");
    if (!$default_status) {
        $default_status = db_fetch_one("SELECT id FROM statuses ORDER BY sort_order ASC, id ASC LIMIT 1");
    }
    if (!$default_status) {
        throw new RuntimeException('No status configured for ticket creation.');
    }
    $status_id = (int) $default_status['id'];

    $default_priority = db_fetch_one("SELECT id FROM priorities WHERE is_default = 1 ORDER BY id ASC LIMIT 1");
    if (!$default_priority) {
        $default_priority = db_fetch_one("SELECT id FROM priorities ORDER BY sort_order ASC, id ASC LIMIT 1");
    }
    $priority_id = $default_priority ? (int) $default_priority['id'] : null;

    $user = db_fetch_one("SELECT organization_id FROM users WHERE id = ? LIMIT 1", [$requester_user_id]);
    $organization_id = $user ? ($user['organization_id'] !== null ? (int) $user['organization_id'] : null) : null;

    $ticket_data = [
        'hash' => email_ingest_generate_ticket_hash(),
        'title' => mb_substr(email_ingest_convert_to_utf8((string) $subject), 0, 255),
        'description' => email_ingest_convert_to_utf8((string) $body_text),
        'type' => 'general',
        'priority_id' => $priority_id,
        'user_id' => $requester_user_id,
        'organization_id' => $organization_id,
        'status_id' => $status_id,
        'ticket_type_id' => null,
        'source' => 'email',
        'created_at' => date('Y-m-d H:i:s'),
    ];

    return (int) db_insert('tickets', $ticket_data);
}

/**
 * Add inbound comment for appended ticket message.
 */
function email_ingest_add_inbound_comment($ticket_id, $user_id, $body_text)
{
    $content = trim(email_ingest_convert_to_utf8((string) $body_text));
    if ($content === '') {
        $content = '(No content)';
    }
    return (int) db_insert('comments', [
        'ticket_id' => $ticket_id,
        'user_id' => $user_id,
        'content' => $content,
        'is_internal' => 0,
        'time_spent' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Insert ticket_messages row.
 */
function email_ingest_insert_ticket_message($data)
{
    $sender_email = email_ingest_convert_to_utf8((string) ($data['sender_email'] ?? ''));
    $subject = email_ingest_convert_to_utf8((string) ($data['subject'] ?? ''));
    $body_text = email_ingest_convert_to_utf8((string) ($data['body_text'] ?? ''));
    $body_html = email_ingest_convert_to_utf8((string) ($data['body_html'] ?? ''));
    $body_html_raw = email_ingest_convert_to_utf8((string) ($data['body_html_raw'] ?? ''));
    $raw_headers = email_ingest_convert_to_utf8((string) ($data['raw_headers'] ?? ''));

    $insert = [
        'ticket_id' => (int) $data['ticket_id'],
        'direction' => 'in',
        'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
        'comment_id' => isset($data['comment_id']) ? $data['comment_id'] : null,
        'sender_email' => mb_substr($sender_email, 0, 255),
        'subject' => mb_substr($subject, 0, 255),
        'body_text' => $body_text,
        'body_html' => $body_html,
        'body_html_raw' => $body_html_raw,
        'raw_headers' => $raw_headers,
        'message_id' => $data['message_id'] ?? null,
        'in_reply_to' => $data['in_reply_to'] ?? null,
        'references_header' => $data['references'] ?? null,
        'mailbox' => $data['mailbox'] ?? null,
        'uid' => isset($data['uid']) ? (int) $data['uid'] : null,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    return (int) db_insert('ticket_messages', $insert);
}

/**
 * Extract message text/html and attachment metadata/data from MIME structure.
 */
function email_ingest_extract_message_payload($imap, $uid)
{
    $structure = imap_fetchstructure($imap, (string) $uid, FT_UID);
    if (!$structure) {
        $body = imap_body($imap, (string) $uid, FT_UID);
        return [
            'text' => is_string($body) ? trim(email_ingest_convert_to_utf8($body)) : '',
            'html' => '',
            'attachments' => [],
        ];
    }

    $result = [
        'text' => '',
        'html' => '',
        'attachments' => [],
    ];

    email_ingest_walk_parts($imap, $uid, $structure, '', $result);
    return $result;
}

/**
 * Recursive MIME part walker.
 */
function email_ingest_walk_parts($imap, $uid, $part, $part_number, &$result)
{
    $is_multipart = isset($part->parts) && is_array($part->parts) && count($part->parts) > 0;
    if ($is_multipart) {
        $index = 1;
        foreach ($part->parts as $sub_part) {
            $sub_number = $part_number === '' ? (string) $index : $part_number . '.' . $index;
            email_ingest_walk_parts($imap, $uid, $sub_part, $sub_number, $result);
            $index++;
        }
        return;
    }

    $part_id = $part_number === '' ? '1' : $part_number;
    $raw = imap_fetchbody($imap, (string) $uid, $part_id, FT_UID);
    if (!is_string($raw)) {
        $raw = '';
    }
    $decoded = email_ingest_decode_part_body($raw, (int) ($part->encoding ?? 0));

    $subtype = strtolower((string) ($part->subtype ?? 'plain'));
    $type = (int) ($part->type ?? 0);
    $disposition = strtolower((string) ($part->disposition ?? ''));

    $params = [];
    if (!empty($part->parameters) && is_array($part->parameters)) {
        foreach ($part->parameters as $p) {
            $params[strtolower($p->attribute)] = $p->value;
        }
    }
    if (!empty($part->dparameters) && is_array($part->dparameters)) {
        foreach ($part->dparameters as $p) {
            $params[strtolower($p->attribute)] = $p->value;
        }
    }
    $part_charset = email_ingest_extract_part_charset($params);

    $filename = '';
    if (!empty($params['filename'])) {
        $filename = email_ingest_decode_mime_string($params['filename']);
    } elseif (!empty($params['name'])) {
        $filename = email_ingest_decode_mime_string($params['name']);
    }

    $content_id = '';
    if (!empty($part->id)) {
        $content_id = trim((string) $part->id, "<> \t\r\n");
    } elseif (!empty($params['content-id'])) {
        $content_id = trim((string) $params['content-id'], "<> \t\r\n");
    }

    $is_attachment = ($disposition === 'attachment' || $disposition === 'inline') && $filename !== '';
    if (!$is_attachment && $filename !== '') {
        $is_attachment = true;
    }

    if ($is_attachment) {
        $mime = email_ingest_guess_mime($type, $subtype);
        $result['attachments'][] = [
            'filename' => $filename,
            'mime' => $mime,
            'size' => strlen($decoded),
            'content_id' => $content_id !== '' ? $content_id : null,
            'data' => $decoded,
        ];
        return;
    }

    if ($type === 0) {
        $decoded = email_ingest_convert_to_utf8($decoded, $part_charset);
        if ($subtype === 'plain' && $result['text'] === '') {
            $result['text'] = trim($decoded);
        } elseif ($subtype === 'html' && $result['html'] === '') {
            $result['html'] = trim($decoded);
        }
    }
}

/**
 * Decode one MIME part payload.
 */
function email_ingest_decode_part_body($raw, $encoding)
{
    switch ($encoding) {
        case 3:
            return (string) imap_base64($raw);
        case 4:
            return (string) imap_qprint($raw);
        default:
            return $raw;
    }
}

/**
 * MIME type guess fallback.
 */
function email_ingest_guess_mime($type, $subtype)
{
    $subtype = strtolower((string) $subtype);
    switch ((int) $type) {
        case 0:
            return 'text/' . ($subtype ?: 'plain');
        case 1:
            return 'multipart/' . ($subtype ?: 'mixed');
        case 2:
            return 'message/' . ($subtype ?: 'rfc822');
        case 3:
            return 'application/' . ($subtype ?: 'octet-stream');
        case 4:
            return 'audio/' . ($subtype ?: 'basic');
        case 5:
            return 'image/' . ($subtype ?: 'jpeg');
        case 6:
            return 'video/' . ($subtype ?: 'mpeg');
        default:
            return 'application/octet-stream';
    }
}

/**
 * Store attachment file and attachment metadata rows.
 */
function email_ingest_store_attachment($ticket_id, $ticket_message_id, $comment_id, $uploaded_by, $attachment, $cfg)
{
    $filename = trim((string) ($attachment['filename'] ?? ''));
    if ($filename === '') {
        $filename = 'attachment.bin';
    }
    $filename = email_ingest_sanitize_filename($filename);
    $mime = trim((string) ($attachment['mime'] ?? 'application/octet-stream'));
    $size = (int) ($attachment['size'] ?? strlen((string) ($attachment['data'] ?? '')));
    $content_id = $attachment['content_id'] ?? null;

    if ($size > (int) $cfg['max_attachment_size']) {
        return [
            'stored' => false,
            'error' => "Attachment {$filename} exceeds size limit",
        ];
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext !== '' && in_array($ext, $cfg['deny_extensions'], true)) {
        return [
            'stored' => false,
            'error' => "Attachment {$filename} blocked by extension denylist",
        ];
    }

    $relative_dir = trim($cfg['storage_base'], '/\\') . '/' . (int) $ticket_id . '/' . (int) $ticket_message_id;
    $absolute_dir = BASE_PATH . '/' . $relative_dir;
    if (!is_dir($absolute_dir)) {
        if (!mkdir($absolute_dir, 0755, true) && !is_dir($absolute_dir)) {
            throw new RuntimeException('Unable to create attachment directory: ' . $absolute_dir);
        }
    }

    $stored_name = uniqid('mail_', true) . '_' . $filename;
    $absolute_path = $absolute_dir . '/' . $stored_name;
    $relative_path = $relative_dir . '/' . $stored_name;
    $data = (string) ($attachment['data'] ?? '');
    if (file_put_contents($absolute_path, $data) === false) {
        throw new RuntimeException('Unable to write attachment file: ' . $filename);
    }

    $attachment_id = (int) db_insert('attachments', [
        'ticket_id' => (int) $ticket_id,
        'comment_id' => $comment_id,
        'filename' => $stored_name,
        'original_name' => $filename,
        'mime_type' => $mime,
        'file_size' => $size,
        'uploaded_by' => (int) $uploaded_by,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    db_insert('ticket_message_attachments', [
        'ticket_message_id' => (int) $ticket_message_id,
        'attachment_id' => $attachment_id > 0 ? $attachment_id : null,
        'filename' => $filename,
        'mime' => $mime,
        'size' => $size,
        'storage_path' => $relative_path,
        'content_id' => $content_id,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    return [
        'stored' => true,
        'absolute_path' => $absolute_path,
        'relative_path' => $relative_path,
    ];
}

/**
 * Basic filename sanitization.
 */
function email_ingest_sanitize_filename($filename)
{
    $filename = trim((string) $filename);
    if ($filename === '') {
        return 'attachment.bin';
    }
    $filename = preg_replace('/[^\w.\-]+/u', '_', $filename);
    $filename = trim($filename, '._');
    if ($filename === '') {
        $filename = 'attachment.bin';
    }
    if (strlen($filename) > 200) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = substr($base, 0, 180);
        $filename = $ext !== '' ? ($base . '.' . $ext) : $base;
    }
    return $filename;
}

/**
 * Sanitize HTML for safe storage/display.
 */
function email_ingest_sanitize_html($html)
{
    $html = (string) $html;
    if ($html === '') {
        return '';
    }

    $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    $clean = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $clean);
    $clean = preg_replace('/\s*on\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $clean);
    $clean = preg_replace('/\s*javascript:/i', '', $clean);
    $allowed_tags = '<p><br><strong><b><em><i><u><s><ul><ol><li><a><blockquote><pre><code><div><span><h1><h2><h3><h4><h5><h6>';
    $clean = strip_tags($clean, $allowed_tags);

    return trim($clean);
}

/**
 * Convert HTML to text fallback.
 */
function email_ingest_html_to_text($html)
{
    $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\r\n|\r/", "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

/**
 * Generate unique ticket hash.
 */
function email_ingest_generate_ticket_hash()
{
    $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $hash = '';
        for ($i = 0; $i < 12; $i++) {
            $hash .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $exists = db_fetch_one("SELECT id FROM tickets WHERE hash = ? LIMIT 1", [$hash]);
        if (!$exists) {
            return $hash;
        }
    }
    return substr(bin2hex(random_bytes(8)), 0, 12);
}

/**
 * Try moving message to folder, create folder when needed.
 */
function email_ingest_try_move($imap, $uid, $cfg, $folder)
{
    $folder = trim((string) $folder);
    if ($folder === '') {
        imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
        return true;
    }

    $root = email_ingest_mailbox_path($cfg, '');
    $mailboxes = imap_getmailboxes($imap, $root, '*');
    $exists = false;
    if (is_array($mailboxes)) {
        foreach ($mailboxes as $box) {
            $name = str_replace($root, '', $box->name);
            if ($name === $folder) {
                $exists = true;
                break;
            }
        }
    }

    if (!$exists) {
        @imap_createmailbox($imap, imap_utf7_encode($root . $folder));
    }

    $moved = @imap_mail_move($imap, (string) $uid, $folder, CP_UID);
    if ($moved) {
        imap_expunge($imap);
        return true;
    }

    imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
    return false;
}


