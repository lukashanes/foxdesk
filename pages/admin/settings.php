<?php
/**
 * Admin - Settings
 */

$page_title = t('Settings');
$page = 'admin';
$settings = get_settings();

// Include update functions
require_once BASE_PATH . '/includes/update-functions.php';
require_once BASE_PATH . '/includes/update-check-functions.php';

$settings_audit = function ($event_type, $context = [], $level = 'info') {
    $user_id = current_user()['id'] ?? null;
    if (function_exists('log_security_event')) {
        $payload = is_string($context) ? $context : json_encode($context, JSON_UNESCAPED_UNICODE);
        log_security_event((string) $event_type, $user_id, (string) ($payload ?: ''));
    }
    if (function_exists('debug_log')) {
        debug_log((string) $event_type, $context, $level, 'settings');
    }
};

// Handle form submissions
settings_handle_post_request($settings_audit);

// Refresh settings
$settings = get_settings();
$tab = settings_tab_from_request($_GET);

// API tab was removed — redirect to general
if ($tab === 'api') {
    redirect('admin', ['section' => 'settings', 'tab' => 'general']);
}

// Process POST handlers for workflow tab before any layout output
settings_handle_workflow_post($tab, $_POST);

$incoming_mail_logs = [];
$incoming_mail_log_error = '';

$imap_enabled_default = (defined('IMAP_ENABLED') && IMAP_ENABLED)
    || (
        (defined('IMAP_HOST') && trim((string) IMAP_HOST) !== '') &&
        (defined('IMAP_USERNAME') && trim((string) IMAP_USERNAME) !== '')
    );
$imap_view = [
    'enabled' => $settings['imap_enabled'] ?? ($imap_enabled_default ? '1' : '0'),
    'host' => $settings['imap_host'] ?? (defined('IMAP_HOST') ? (string) IMAP_HOST : ''),
    'port' => $settings['imap_port'] ?? (defined('IMAP_PORT') ? (string) IMAP_PORT : '993'),
    'encryption' => $settings['imap_encryption'] ?? (defined('IMAP_ENCRYPTION') ? strtolower((string) IMAP_ENCRYPTION) : 'ssl'),
    'username' => $settings['imap_username'] ?? (defined('IMAP_USERNAME') ? (string) IMAP_USERNAME : ''),
    'password_set' => !empty($settings['imap_password']) || (defined('IMAP_PASSWORD') && trim((string) IMAP_PASSWORD) !== ''),
    'folder' => $settings['imap_folder'] ?? (defined('IMAP_FOLDER') ? (string) IMAP_FOLDER : 'INBOX'),
    'processed_folder' => $settings['imap_processed_folder'] ?? (defined('IMAP_PROCESSED_FOLDER') ? (string) IMAP_PROCESSED_FOLDER : 'Processed'),
    'failed_folder' => $settings['imap_failed_folder'] ?? (defined('IMAP_FAILED_FOLDER') ? (string) IMAP_FAILED_FOLDER : 'Failed'),
    'max_emails_per_run' => $settings['imap_max_emails_per_run'] ?? (defined('IMAP_MAX_EMAILS_PER_RUN') ? (string) IMAP_MAX_EMAILS_PER_RUN : '50'),
    'max_attachment_size_mb' => $settings['imap_max_attachment_size_mb'] ?? (string) ((int) ((defined('IMAP_MAX_ATTACHMENT_SIZE') ? (int) IMAP_MAX_ATTACHMENT_SIZE : 10485760) / 1048576)),
    'validate_cert' => $settings['imap_validate_cert'] ?? (defined('IMAP_VALIDATE_CERT') && IMAP_VALIDATE_CERT ? '1' : '0'),
    'mark_seen_on_skip' => $settings['imap_mark_seen_on_skip'] ?? (defined('IMAP_MARK_SEEN_ON_SKIP') && IMAP_MARK_SEEN_ON_SKIP ? '1' : '0'),
    'allow_unknown_senders' => $settings['imap_allow_unknown_senders'] ?? '0',
    'storage_base' => $settings['imap_storage_base'] ?? (defined('IMAP_STORAGE_BASE') ? (string) IMAP_STORAGE_BASE : 'storage/tickets'),
];
$imap_extension_loaded = extension_loaded('imap') && function_exists('imap_open');
$microsoft_mail_view = [
    'configured' => false,
    'connected' => false,
    'status' => 'not_connected',
    'mailbox_email' => '',
    'tenant_identifier' => 'common',
    'client_id' => '',
    'client_secret_set' => false,
    'inbound_enabled' => true,
    'outbound_enabled' => true,
    'last_sync_at' => null,
    'last_error' => '',
    'redirect_uri' => '',
];

if ($tab === 'email') {
    require_once BASE_PATH . '/includes/email-ingest-functions.php';
    require_once BASE_PATH . '/includes/microsoft-mail-functions.php';
    try {
        $microsoft_mail_view = microsoft_mail_status();
    } catch (Throwable $e) {
        $microsoft_mail_view['last_error'] = $e->getMessage();
    }
    try {
        email_ingest_ensure_schema();
        $incoming_mail_logs = db_fetch_all("
            SELECT
                l.created_at,
                l.mailbox,
                l.uid,
                l.status,
                l.reason,
                l.error,
                COALESCE(l.sender_email, tm.sender_email) AS sender_email,
                COALESCE(l.subject, tm.subject) AS subject,
                COALESCE(l.ticket_id, tm.ticket_id) AS ticket_id,
                t.hash AS ticket_hash,
                t.title AS ticket_title
            FROM email_ingest_logs l
            LEFT JOIN ticket_messages tm
                ON tm.id = (
                    SELECT tm2.id
                    FROM ticket_messages tm2
                    WHERE (tm2.mailbox = l.mailbox AND tm2.uid = l.uid)
                       OR (l.message_id IS NOT NULL AND l.message_id <> '' AND tm2.message_id = l.message_id)
                    ORDER BY tm2.id DESC
                    LIMIT 1
                )
            LEFT JOIN tickets t ON t.id = COALESCE(l.ticket_id, tm.ticket_id)
            ORDER BY l.created_at DESC
            LIMIT 100
        ");
    } catch (Throwable $e) {
        $incoming_mail_log_error = $e->getMessage();
    }

    // Load allowed senders for the allowlist UI
    try {
        $allowed_senders = db_fetch_all(
            "SELECT s.*, CONCAT(u.first_name, ' ', u.last_name) AS user_name
             FROM allowed_senders s
             LEFT JOIN users u ON s.user_id = u.id
             ORDER BY s.type, s.value"
        );
    } catch (Throwable $e) {
        $allowed_senders = [];
    }
    $all_users = db_fetch_all("SELECT id, first_name, last_name, email FROM users WHERE is_active = 1 ORDER BY first_name, last_name");
}

// Get template language
$template_lang = normalize_locale_tag($_GET['lang'] ?? get_app_language()) ?? 'en';

// Get email templates for selected language
try {
    $templates = db_fetch_all("
        SELECT t.* 
        FROM email_templates t 
        WHERE t.language = ?
        ORDER BY t.template_key
    ", [$template_lang]);

    // If we have missing templates for this language, we might want to show defaults from English or code
    // But for now, let's just show what's in DB.
} catch (Exception $e) {
    $templates = [];
}

require_once BASE_PATH . '/includes/header.php';
?>

<?php
$page_header_title = $page_title;
$page_header_subtitle = '';
include BASE_PATH . '/includes/components/page-header.php';
?>

<div class="admin-shell settings-workspace" data-settings-workspace>

    <!-- Tabs -->
    <?php render_admin_settings_tabs($tab); ?>
    <div class="settings-workspace__content settings-section-content">
    <?php render_admin_settings_subtabs($tab); ?>

    <?php
    $settings_view = settings_view_file($tab);
    if ($settings_view === null) {
        http_response_code(404);
        echo '<div class="card card-body">' . e(t('Settings section not found.')) . '</div>';
    } else {
        require $settings_view;
    }
    ?>
    </div>
</div>
<script defer src="assets/js/settings-workspace.js?v=<?php echo (int) filemtime(BASE_PATH . '/assets/js/settings-workspace.js'); ?>"></script>
<?php require_once BASE_PATH . '/includes/footer.php';
