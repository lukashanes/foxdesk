<?php
/**
 * Ticket Detail Page
 */

// Support both hash-based URLs (t=hash) and legacy ID-based URLs (id=123)
$ticket_hash = isset($_GET['t']) ? trim($_GET['t']) : null;
$ticket_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Migrate ticket hashes on first access (one-time operation)
if (function_exists('migrate_ticket_hashes')) {
    migrate_ticket_hashes();
}

// Get ticket by hash or ID
if (!empty($ticket_hash)) {
    $ticket = get_ticket_by_hash($ticket_hash);
    if ($ticket) {
        $ticket_id = (int) $ticket['id'];
    }
} else {
    $ticket = get_ticket($ticket_id);
}

$user = current_user();
$can_view_edit_history = can_view_edit_history($user);

// Check if ticket exists
if (!$ticket) {
    flash(t('Ticket not found.'), 'error');
    redirect('tickets');
}

// Check permissions
if (!can_see_ticket($ticket, $user)) {
    flash(t('You do not have permission to view this ticket.'), 'error');
    redirect('tickets');
}

// Auto mark ALL notifications for this ticket as read when viewing it
if (function_exists('mark_ticket_notifications_read')) {
    mark_ticket_notifications_read($ticket_id, (int) $user['id']);
}

$page_title = $ticket['title'];
$page = 'ticket';
$all_comments = get_ticket_comments($ticket_id);
$attachments = get_ticket_attachments($ticket_id);
$statuses = get_statuses();
$tags_supported = function_exists('ticket_tags_column_exists') && ticket_tags_column_exists();
$organizations = [];
if (is_agent()) {
    $organizations = get_organizations(true);
    if (!is_admin()) {
        $allowed_org_ids = get_user_organization_ids($user['id']);
        if (!empty($allowed_org_ids)) {
            $allowed_lookup = array_flip($allowed_org_ids);
            if (!empty($ticket['organization_id'])) {
                $allowed_lookup[(int) $ticket['organization_id']] = true;
            }
            $organizations = array_values(array_filter($organizations, function ($org) use ($allowed_lookup) {
                return isset($allowed_lookup[(int) ($org['id'] ?? 0)]);
            }));
        }
    }
}
$ticket_tags = $tags_supported ? get_ticket_tags_array($ticket['tags'] ?? '') : [];
$ticket_tag_filter_url = static function ($tag_value) use ($ticket) {
    $params = ['tags' => $tag_value];
    if (!empty($ticket['is_archived'])) {
        $params['archived'] = '1';
    }
    return url('tickets', $params);
};
$all_users = is_agent() ? get_all_users() : []; // For CC selection
$shared_users = is_agent() ? get_ticket_access_users($ticket_id) : [];
$shared_user_ids = array_map('intval', array_column($shared_users, 'id'));
// Share link state
$latest_share = get_latest_ticket_share($ticket_id);
$share_status = 'none';
if ($latest_share) {
    if (!empty($latest_share['is_revoked'])) {
        $share_status = 'revoked';
    } elseif (!empty($latest_share['expires_at']) && strtotime($latest_share['expires_at']) <= time()) {
        $share_status = 'expired';
    } else {
        $share_status = 'active';
    }
}

$share_token = null;
if (!empty($_SESSION['share_token']) && (int) ($_SESSION['share_token_ticket_id'] ?? 0) === $ticket_id) {
    $share_token = $_SESSION['share_token'];
    unset($_SESSION['share_token'], $_SESSION['share_token_ticket_id']);
}

$share_url = $share_token ? get_ticket_share_url($share_token) : null;
$share_status_label = t('None');
$share_status_class = 'td-text-muted';
if ($share_status === 'active') {
    $share_status_label = t('Active');
    $share_status_class = 'text-green-600';
} elseif ($share_status === 'expired') {
    $share_status_label = t('Expired');
    $share_status_class = 'text-orange-600';
} elseif ($share_status === 'revoked') {
    $share_status_label = t('Revoked');
    $share_status_class = 'text-red-600';
}

// Time tracking state
$time_tracking_available = ticket_time_table_exists();
$active_timer = null;
$active_timer_elapsed = 0;
$timer_is_paused = false;
$time_breakdown = $time_tracking_available ? get_ticket_time_breakdown($ticket_id) : ['total' => 0, 'human' => 0, 'ai' => 0];
$total_time_minutes = $time_breakdown['total'];
$org_billable_rate = 0.0;
$user_cost_rate = (float) ($user['cost_rate'] ?? 0);
if (!empty($ticket['organization_id'])) {
    $org = get_organization($ticket['organization_id']);
    if ($org && isset($org['billable_rate'])) {
        $org_billable_rate = (float) $org['billable_rate'];
    }
}
if (is_agent() && $time_tracking_available) {
    // Ensure pause columns exist (auto-migrate)
    migrate_timer_pause_columns();
    $active_timer = get_active_ticket_timer($ticket_id, $user['id']);
    if (!empty($active_timer['started_at'])) {
        $timer_is_paused = is_timer_paused($active_timer);
        // Calculate elapsed accounting for pauses
        $elapsed_seconds = calculate_timer_elapsed($active_timer);
        $active_timer_elapsed = max(0, (int) floor($elapsed_seconds / 60));
    }
}
// Timer state (used by toolbar + comment area timer)
$timer_state = 'stopped';
if ($active_timer) {
    $timer_state = $timer_is_paused ? 'paused' : 'running';
}

// Filter internal comments for non-agents (for display only)
$comments = $all_comments;
if (!is_agent()) {
    $comments = array_filter($comments, function ($c) {
        return !$c['is_internal'];
    });
    $comments = array_values($comments);
}

$visible_comment_ids = [];
foreach ($comments as $comment) {
    $visible_comment_ids[$comment['id']] = true;
}

$attachment_list = $attachments;
if (!is_agent()) {
    $attachment_list = array_filter($attachments, function ($attachment) use ($visible_comment_ids) {
        return empty($attachment['comment_id']) || isset($visible_comment_ids[$attachment['comment_id']]);
    });
    $attachment_list = array_values($attachment_list);
}

// Handle form submissions (extracted to includes/components/ticket-form-handlers.php)
require_once BASE_PATH . '/includes/components/ticket-form-handlers.php';


// Get priority info
$priority_name = $ticket['priority_name'] ?? get_priority_label($ticket['priority_id'] ?? 'medium');
$priority_color = $ticket['priority_color'] ?? get_priority_color($ticket['priority_id'] ?? 'medium');

require_once BASE_PATH . '/includes/header.php';
?>

<!-- Quill Editor CSS (1.3.7 stable) -->
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<style>
    /* Quill Editor - Unified rounded container */
    .editor-wrapper {
        border: 1px solid var(--border-light);
        border-radius: var(--radius-lg);
        overflow: hidden;
        background: var(--surface-primary);
    }

    .editor-wrapper--internal {
        border-color: #fde047;
        background: #fffef7;
    }

    #comment-editor,
    #internal-editor,
    #edit-description-editor,
    #edit-comment-editor {
        border: none !important;
    }

    #comment-editor .ql-toolbar,
    #internal-editor .ql-toolbar,
    #edit-description-editor .ql-toolbar,
    #edit-comment-editor .ql-toolbar {
        border: none !important;
        border-bottom: 1px solid var(--border-light) !important;
        background: var(--surface-secondary);
        padding: 10px 12px;
    }

    #comment-editor .ql-container,
    #internal-editor .ql-container,
    #edit-description-editor .ql-container,
    #edit-comment-editor .ql-container {
        border: none !important;
        background: var(--surface-primary);
    }

    #comment-editor .ql-editor,
    #internal-editor .ql-editor,
    #edit-description-editor .ql-editor,
    #edit-comment-editor .ql-editor {
        min-height: 100px;
        font-size: 0.9375rem;
        line-height: 1.6;
        padding: 14px;
    }

    #internal-editor .ql-toolbar {
        background: #fef9c3;
        border-bottom-color: #fde047 !important;
    }

    #internal-editor .ql-container {
        background: #fffef7;
    }

    .ql-editor.ql-blank::before {
        font-style: normal;
        color: #9ca3af;
        padding-left: 0;
    }

    /* Override Quill snow theme borders in light mode - fix corner issues */
    .ql-snow.ql-toolbar {
        border: none !important;
        border-bottom: 1px solid var(--border-light) !important;
        border-radius: 0 !important;
    }

    .ql-snow.ql-container {
        border: none !important;
        border-radius: 0 !important;
    }

    /* Rich content display styles */
    .rich-content h1 {
        font-size: 1.5em;
        font-weight: bold;
        margin: 0.5em 0;
    }

    .rich-content h2 {
        font-size: 1.25em;
        font-weight: bold;
        margin: 0.5em 0;
    }

    .rich-content h3 {
        font-size: 1.1em;
        font-weight: bold;
        margin: 0.5em 0;
    }

    .rich-content ul,
    .rich-content ol {
        margin: 0.5em 0;
        padding-left: 1.5em;
    }

    .rich-content li {
        margin: 0.25em 0;
    }

    .rich-content a {
        color: #2563eb;
        text-decoration: underline;
    }

    .rich-content a:hover {
        color: #1d4ed8;
    }

    .rich-content blockquote {
        border-left: 3px solid #e5e7eb;
        padding-left: 1em;
        margin: 0.5em 0;
        color: #6b7280;
    }

    .rich-content img {
        max-width: 100%;
        height: auto;
    }

    /* ── Link preview cards ──────────────────────────────────────────────── */
    .link-preview-card {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 14px;
        margin: 8px 0;
        border: 1px solid var(--border-light, #e2e8f0);
        border-radius: 10px;
        background: var(--surface-secondary, #f8fafc);
        text-decoration: none !important;
        color: inherit;
        transition: border-color 0.15s, box-shadow 0.15s;
        max-width: 480px;
        overflow: hidden;
    }
    .link-preview-card:hover {
        border-color: var(--primary, #3b82f6);
        box-shadow: 0 2px 8px rgba(59,130,246,0.08);
    }
    .lp-thumb {
        flex-shrink: 0;
        display: block;
        width: 64px;
        height: 48px;
        border-radius: 6px;
        overflow: hidden;
        background: var(--surface-tertiary, #e2e8f0);
    }
    .lp-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .lp-youtube .lp-thumb {
        width: 120px;
        height: 68px;
        position: relative;
    }
    .lp-youtube .lp-thumb::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 32px;
        height: 32px;
        background: rgba(0,0,0,0.7);
        border-radius: 50%;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M8 5v14l11-7z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: center;
        background-size: 16px;
    }
    .lp-image .lp-thumb {
        width: 120px;
        height: 80px;
    }
    .lp-info {
        flex: 1;
        min-width: 0;
        display: block;
    }
    .lp-title {
        display: block;
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--text-primary);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        line-height: 1.4;
    }
    .lp-service {
        display: flex;
        align-items: center;
        gap: 5px;
        margin-top: 3px;
        font-size: 0.75rem;
        color: var(--text-muted, #6b7280);
    }
    .lp-service svg {
        flex-shrink: 0;
    }
    [data-theme="dark"] .link-preview-card {
        background: var(--surface-tertiary, #1e293b);
        border-color: var(--border-dark, #334155);
    }
    [data-theme="dark"] .link-preview-card:hover {
        border-color: var(--primary, #3b82f6);
    }
    [data-theme="dark"] .lp-thumb {
        background: var(--surface-secondary, #334155);
    }
    @media (max-width: 640px) {
        .link-preview-card { max-width: 100%; }
        .lp-youtube .lp-thumb { width: 80px; height: 45px; }
    }

    /* Dark mode support for Quill editors */
    [data-theme="dark"] .editor-wrapper {
        border-color: var(--corp-slate-600) !important;
        background: var(--corp-slate-800) !important;
    }

    [data-theme="dark"] #comment-editor .ql-toolbar,
    [data-theme="dark"] #edit-description-editor .ql-toolbar,
    [data-theme="dark"] #edit-comment-editor .ql-toolbar {
        background: var(--corp-slate-800) !important;
        border-bottom: 1px solid var(--corp-slate-600) !important;
    }

    [data-theme="dark"] #comment-editor .ql-container,
    [data-theme="dark"] #edit-description-editor .ql-container,
    [data-theme="dark"] #edit-comment-editor .ql-container {
        background: var(--corp-slate-800) !important;
    }

    [data-theme="dark"] #comment-editor .ql-editor,
    [data-theme="dark"] #edit-description-editor .ql-editor,
    [data-theme="dark"] #edit-comment-editor .ql-editor {
        color: var(--corp-slate-100) !important;
        background: var(--corp-slate-800) !important;
    }

    [data-theme="dark"] .ql-editor.ql-blank::before {
        color: var(--corp-slate-400) !important;
    }

    /* Toolbar icons - light grey in dark mode for visibility */
    [data-theme="dark"] .ql-toolbar .ql-stroke {
        stroke: #e5e7eb !important;
    }

    [data-theme="dark"] .ql-toolbar .ql-fill {
        fill: #e5e7eb !important;
    }

    [data-theme="dark"] .ql-toolbar .ql-picker {
        color: #e5e7eb !important;
    }

    [data-theme="dark"] .ql-toolbar .ql-picker-label {
        color: #e5e7eb !important;
    }

    [data-theme="dark"] .ql-toolbar .ql-picker-label::before {
        color: #e5e7eb !important;
    }

    [data-theme="dark"] .ql-toolbar button {
        color: #e5e7eb !important;
    }

    /* Dropdown menus */
    [data-theme="dark"] .ql-picker-options {
        background: var(--corp-slate-700) !important;
        border-color: var(--corp-slate-600) !important;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    }

    [data-theme="dark"] .ql-picker-item {
        color: #e5e7eb !important;
    }

    [data-theme="dark"] .ql-picker-item:hover {
        color: #fff !important;
        background: var(--corp-slate-600) !important;
    }

    /* Hover states */
    [data-theme="dark"] button:hover .ql-stroke,
    [data-theme="dark"] .ql-picker-label:hover .ql-stroke {
        stroke: #fff !important;
    }

    [data-theme="dark"] button:hover .ql-fill,
    [data-theme="dark"] .ql-picker-label:hover .ql-fill {
        fill: #fff !important;
    }

    /* Active states */
    [data-theme="dark"] button.ql-active .ql-stroke {
        stroke: var(--primary) !important;
    }

    [data-theme="dark"] button.ql-active .ql-fill {
        fill: var(--primary) !important;
    }

    /* Link tooltip/popup - dark mode */
    [data-theme="dark"] .ql-tooltip {
        background: var(--corp-slate-700) !important;
        border-color: var(--corp-slate-600) !important;
        color: var(--corp-slate-200) !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4) !important;
        border-radius: 8px !important;
    }

    [data-theme="dark"] .ql-tooltip input[type="text"] {
        background: var(--corp-slate-800) !important;
        border: 1px solid var(--corp-slate-600) !important;
        color: var(--corp-slate-100) !important;
        border-radius: 6px !important;
        padding: 6px 10px !important;
    }

    [data-theme="dark"] .ql-tooltip a {
        color: var(--primary) !important;
    }

    [data-theme="dark"] .ql-snow .ql-tooltip::before {
        color: #e5e7eb !important;
    }

    /* Override Quill snow theme borders in dark mode */
    [data-theme="dark"] .ql-snow.ql-toolbar {
        border: none !important;
        border-bottom: 1px solid var(--corp-slate-600) !important;
    }

    [data-theme="dark"] .ql-snow.ql-container {
        border: none !important;
    }

    [data-theme="dark"] .ql-snow .ql-toolbar {
        border: none !important;
    }

    /* Quill tooltip positioning - keep within viewport */
    .ql-tooltip {
        z-index: 9999 !important;
        transform: none !important;
    }

    .ql-tooltip.ql-editing {
        left: 8px !important;
        right: auto !important;
    }

    .ql-snow .ql-tooltip {
        white-space: nowrap;
        max-width: calc(100vw - 32px);
    }

    .ql-snow .ql-tooltip input[type="text"] {
        width: 200px;
        max-width: 50vw;
    }

    .editor-wrapper .ql-tooltip {
        position: absolute !important;
        left: 0 !important;
        margin-left: 8px;
    }

    /* Rich content in dark mode */
    [data-theme="dark"] .rich-content a {
        color: var(--primary);
    }

    [data-theme="dark"] .rich-content blockquote {
        border-color: var(--corp-slate-600);
        color: var(--corp-slate-400);
    }

    /* CC Dropdown - opens upward from submit row */
    #agent-cc-dropdown-container {
        position: relative;
    }

    #agent-cc-list {
        position: absolute;
        bottom: 100%;
        right: 0;
        z-index: 100;
        margin-bottom: 4px;
    }

    /* Dark mode for CC dropdown */
    [data-theme="dark"] #agent-cc-list {
        background: var(--corp-slate-700) !important;
        border-color: var(--corp-slate-600) !important;
    }

    [data-theme="dark"] #agent-cc-list label:hover {
        background: var(--corp-slate-600) !important;
    }

    [data-theme="dark"] #agent-cc-list span {
        color: var(--corp-slate-200) !important;
    }

    [data-theme="dark"] #agent-cc-toggle {
        background: var(--corp-slate-700) !important;
        border-color: var(--corp-slate-600) !important;
        color: var(--corp-slate-200) !important;
    }

    [data-theme="dark"] #agent-cc-toggle:hover {
        background: var(--corp-slate-600) !important;
    }
</style>

<div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-3">
    <!-- Main Content -->
    <div class="md:col-span-2 lg:col-span-3 space-y-3">
        <!-- Ticket Toolbar - compact icon bar -->
        <div class="card px-2 py-1.5">
            <div class="flex items-center gap-1">
                <?php
                $back_ref = $_GET['ref'] ?? '';
                if ($back_ref === 'dashboard') {
                    $back_url = url('dashboard');
                } elseif ($back_ref === 'notifications') {
                    $back_url = url('notifications');
                } else {
                    $back_url = url('tickets');
                }
                ?>
                <a href="<?php echo $back_url; ?>" class="td-tool-btn" title="<?php echo e(t('Back')); ?>">
                    <?php echo get_icon('arrow-left', 'w-3.5 h-3.5'); ?>
                </a>
                <span class="td-tool-sep"></span>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold"
                    style="background-color: <?php echo e($ticket['status_color']); ?>15; color: <?php echo e($ticket['status_color']); ?>; border: 1px solid <?php echo e($ticket['status_color']); ?>30;">
                    <?php echo e($ticket['status_name']); ?>
                </span>
                <?php if (!empty($ticket['is_archived'])): ?>
                    <span class="px-1.5 py-0.5 rounded text-[11px] font-medium"
                        style="background: var(--surface-tertiary); color: var(--text-secondary);"><?php echo e(t('Archived')); ?></span>
                <?php endif; ?>
                <span class="td-tool-sep"></span>
                <?php if (can_edit_ticket($ticket, $user)): ?>
                    <button type="button" onclick="openEditTicketModal()" class="td-tool-btn"
                        title="<?php echo e(t('Edit')); ?>">
                        <?php echo get_icon('edit', 'w-3.5 h-3.5'); ?>
                    </button>
                <?php endif; ?>
                <a href="#comment-form" class="td-tool-btn" title="<?php echo e(t('Comment')); ?>">
                    <?php echo get_icon('comment', 'w-3.5 h-3.5'); ?>
                </a>
                <?php if (is_agent() && $time_tracking_available): ?>
                    <span class="td-tool-sep"></span>
                    <button type="button" id="toolbar-timer-btn"
                        class="td-tool-btn <?php echo $timer_state === 'running' ? 'td-tool-btn--active-timer' : ''; ?>"
                        title="<?php echo $timer_state === 'running' ? e(t('Pause timer')) : ($timer_state === 'paused' ? e(t('Resume timer')) : e(t('Start timer'))); ?>">
                        <?php echo get_icon($timer_state === 'running' ? 'pause' : 'play', 'w-3.5 h-3.5'); ?>
                    </button>
                    <?php if ($timer_state !== 'stopped'): ?>
                        <span id="toolbar-timer-elapsed" class="text-xs tabular-nums"
                            style="color: <?php echo $timer_state === 'running' ? 'var(--warning)' : 'var(--success)'; ?>;">
                            <?php echo format_duration_minutes($active_timer_elapsed); ?>
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Description Card -->
        <?php
        $initial_attachments = array_filter($attachments, function ($a) {
            return empty($a['comment_id']);
        });
        ?>
        <?php if (!empty($ticket['description']) || !empty($initial_attachments)): ?>
                <div class="card card-body">
                    <?php if (!empty($ticket['description'])): ?>
                            <div class="prose max-w-none rich-content" style="color: var(--text-secondary);">
                                <?php echo render_content($ticket['description']); ?>
                            </div>
                    <?php endif; ?>

                    <?php if (!empty($initial_attachments)): ?>
                            <div class="<?php echo !empty($ticket['description']) ? 'mt-4 pt-4 border-t' : ''; ?>">
                                <h4 class="text-sm font-medium mb-1" style="color: var(--text-secondary);">
                                    <?php echo e(t('Attachments')); ?></h4>
                                <?php $component_attachments = $initial_attachments; $component_layout = 'grid'; include BASE_PATH . '/includes/components/attachment-grid.php'; ?>
                            </div>
                    <?php endif; ?>

                    <div class="mt-3 pt-2.5 border-t flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 text-xs"
                        style="color: var(--text-muted);">
                        <div class="flex items-center space-x-3">
                            <?php if (!empty($ticket['avatar'])): ?>
                                    <img src="<?php echo e(upload_url($ticket['avatar'])); ?>" alt="" class="w-6 h-6 rounded-full">
                            <?php endif; ?>
                            <span><?php echo e(t('Created by')); ?>:
                                <?php if (is_agent()): ?>
                                        <a href="<?php echo url('user-profile', ['id' => $ticket['user_id']]); ?>"
                                            class="font-medium text-blue-600 hover:text-blue-700 hover:underline">
                                            <?php echo e($ticket['first_name'] . ' ' . $ticket['last_name']); ?>
                                        </a>
                                <?php else: ?>
                                        <strong><?php echo e($ticket['first_name'] . ' ' . $ticket['last_name']); ?></strong>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div>
                            <?php echo format_date($ticket['created_at']); ?>
                        </div>
                    </div>

                    <?php
                    // Show edit history only to users explicitly allowed (admins always allowed)
                    $ticket_history = $can_view_edit_history ? get_ticket_history($ticket_id) : [];
                    if ($can_view_edit_history && !empty($ticket_history)):
                        ?>
                            <details class="mt-4 pt-4 border-t">
                                <summary class="flex items-center gap-2 cursor-pointer text-sm" style="color: var(--text-muted);">
                                    <?php echo get_icon('history', 'w-4 h-4'); ?>
                                    <?php echo e(t('Edit history')); ?> (<?php echo count($ticket_history); ?>)
                                </summary>
                                <div class="mt-3 space-y-2">
                                    <?php foreach ($ticket_history as $history): ?>
                                            <?php
                                            $is_long_text_change = in_array($history['field_name'], ['description', 'comment_content', 'comment_deleted'], true);
                                            $is_attachment_event = in_array($history['field_name'], ['attachment_added', 'attachment_unlinked'], true);
                                            ?>
                                            <div class="flex items-start gap-3 text-xs p-2 rounded-lg"
                                                style="background: var(--surface-secondary);">
                                                <div class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center"
                                                    style="background: var(--surface-tertiary);">
                                                    <span class="font-medium text-xs" style="color: var(--text-secondary);">
                                                        <?php echo strtoupper(substr($history['first_name'] ?? 'U', 0, 1)); ?>
                                                    </span>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex flex-wrap items-center gap-1" style="color: var(--text-secondary);">
                                                        <strong><?php echo e(($history['first_name'] ?? '') . ' ' . ($history['last_name'] ?? '')); ?></strong>
                                                        <span><?php echo e(t('changed')); ?></span>
                                                        <span
                                                            class="font-medium"><?php echo get_history_field_label($history['field_name']); ?></span>
                                                    </div>
                                                    <?php if ($is_long_text_change): ?>
                                                            <div class="mt-2 space-y-2">
                                                                <div class="rounded border border-red-200 bg-red-50 px-2 py-1.5">
                                                                    <div class="text-xs uppercase tracking-wide text-red-700 mb-1">
                                                                        <?php echo e(t('Previous')); ?></div>
                                                                    <div class="text-xs text-red-800 whitespace-pre-wrap break-words">
                                                                        <?php echo format_history_value($history['field_name'], $history['old_value']); ?>
                                                                    </div>
                                                                </div>
                                                                <div class="rounded border border-green-200 bg-green-50 px-2 py-1.5">
                                                                    <div class="text-xs uppercase tracking-wide text-green-700 mb-1">
                                                                        <?php echo e(t('New')); ?></div>
                                                                    <div class="text-xs text-green-800 whitespace-pre-wrap break-words">
                                                                        <?php echo format_history_value($history['field_name'], $history['new_value']); ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                    <?php elseif ($is_attachment_event): ?>
                                                            <div class="mt-1 flex flex-wrap items-center gap-2" style="color: var(--text-muted);">
                                                                <?php if ($history['field_name'] === 'attachment_added'): ?>
                                                                        <span
                                                                            class="inline-flex items-center px-1.5 py-0.5 rounded bg-green-100 text-green-700 font-medium">+
                                                                            <?php echo format_history_value($history['field_name'], $history['new_value']); ?></span>
                                                                <?php else: ?>
                                                                        <span
                                                                            class="inline-flex items-center px-1.5 py-0.5 rounded bg-red-100 text-red-700 font-medium">-
                                                                            <?php echo format_history_value($history['field_name'], $history['old_value']); ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                    <?php else: ?>
                                                            <div class="mt-1 flex flex-wrap items-center gap-2" style="color: var(--text-muted);">
                                                                <span
                                                                    class="line-through"><?php echo format_history_value($history['field_name'], $history['old_value']); ?></span>
                                                                <span>→</span>
                                                                <span class="font-medium"
                                                                    style="color: var(--text-secondary);"><?php echo format_history_value($history['field_name'], $history['new_value']); ?></span>
                                                            </div>
                                                    <?php endif; ?>
                                                    <div class="mt-1" style="color: var(--text-muted);">
                                                        <?php echo format_date($history['created_at']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                    <?php endif; ?>
                </div>
        <?php endif; ?>

        <?php
        // Load time entries and index by comment_id for easy lookup
        $time_entries = [];
        $time_entries_by_comment = [];
        $orphan_time_entries = [];

        if ($time_tracking_available && can_view_time($user)) {
            $time_entries = get_ticket_time_entries($ticket_id);
            foreach ($time_entries as $entry) {
                if (!empty($entry['comment_id'])) {
                    if (!isset($time_entries_by_comment[$entry['comment_id']])) {
                        $time_entries_by_comment[$entry['comment_id']] = [];
                    }
                    $time_entries_by_comment[$entry['comment_id']][] = $entry;
                } else {
                    $orphan_time_entries[] = $entry;
                }
            }
        }

        // Build merged chronological timeline: comments + orphan time entries
        $timeline_items = [];
        foreach ($comments as $c) {
            $timeline_items[] = [
                'type' => 'comment',
                'data' => $c,
                'sort_time' => strtotime($c['created_at'])
            ];
        }
        foreach ($orphan_time_entries as $oe) {
            $timeline_items[] = [
                'type' => 'time_entry',
                'data' => $oe,
                'sort_time' => strtotime($oe['started_at'])
            ];
        }
        usort($timeline_items, function ($a, $b) {
            return $a['sort_time'] - $b['sort_time'];
        });
        ?>

        <!-- Comments & Time Log Combined -->
        <div class="card">
            <div class="card-header">
                <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Activity')); ?>
                    (<?php echo count($comments); ?> <?php echo e(t('comments')); ?>)</h3>
                <?php if ($time_tracking_available && $total_time_minutes > 0 && can_view_time($user)): ?>
                        <span
                            class="text-xs font-semibold px-2 py-1 bg-blue-50 text-blue-700 rounded flex items-center gap-1">
                            <?php echo get_icon('clock', 'w-3 h-3'); ?>
                            <?php echo format_duration_minutes($total_time_minutes); ?>
                        </span>
                <?php endif; ?>
            </div>

            <?php if (empty($timeline_items)): ?>
                    <div class="p-4 text-center" style="color: var(--text-muted);">
                        <?php echo e(t('No comments yet.')); ?>
                    </div>
            <?php else: ?>
                    <div class="divide-y" style="border-color: var(--border-light);">
                        <?php foreach ($timeline_items as $timeline_item): ?>
                                <?php if ($timeline_item['type'] === 'time_entry'): ?>
                                        <?php $entry = $timeline_item['data']; ?>
                                        <?php if (can_view_time($user)): ?>
                                                <div class="flex justify-center py-2.5">
                                                    <div class="time-entry-row inline-flex flex-wrap items-center gap-1.5 text-xs px-3 py-1.5 rounded-full"
                                                        style="background: var(--surface-secondary); color: var(--text-muted);">
                                                        <?php echo get_icon('clock', 'w-3.5 h-3.5 flex-shrink-0'); ?>
                                                        <span class="font-medium" style="color: var(--text-secondary);"><?php
                                                        if (empty($entry['ended_at'])) {
                                                            $elapsed = max(0, time() - strtotime($entry['started_at']));
                                                            if (!empty($entry['paused_at'])) {
                                                                $elapsed = max(0, strtotime($entry['paused_at']) - strtotime($entry['started_at']));
                                                            }
                                                            $elapsed -= (int) ($entry['paused_seconds'] ?? 0);
                                                            echo format_duration_minutes(max(0, floor($elapsed / 60)));
                                                            if (!empty($entry['paused_at'])) {
                                                                echo ' <span class="text-yellow-600">(' . t('Paused') . ')</span>';
                                                            } else {
                                                                echo ' <span class="text-green-600">(' . t('Running') . ')</span>';
                                                            }
                                                        } else {
                                                            echo format_duration_minutes($entry['duration_minutes']);
                                                        }
                                                        ?></span>
                                                        <span style="color: var(--border-light);">·</span>
                                                        <span><?php echo e(trim($entry['first_name'] . ' ' . $entry['last_name'])); ?></span>
                                                        <?php if (!empty($entry['summary'])): ?>
                                                                <span style="color: var(--border-light);">·</span>
                                                                <span class="truncate max-w-[200px]"
                                                                    title="<?php echo e($entry['summary']); ?>"><?php echo e($entry['summary']); ?></span>
                                                        <?php endif; ?>
                                                        <span style="color: var(--border-light);">·</span>
                                                        <span><?php echo format_date($entry['started_at']); ?></span>
                                                        <?php $can_edit_this_entry = is_admin() || (is_agent() && (int) $entry['user_id'] === (int) $user['id']); ?>
                                                        <?php if ($can_edit_this_entry): ?>
                                                                <span class="time-entry-actions">
                                                                    <?php if (!empty($entry['ended_at'])): ?>
                                                                            <button type="button"
                                                                                onclick="openEditTimeEntry(<?php echo htmlspecialchars(json_encode($entry)); ?>)"
                                                                                class="p-0.5 hover:text-blue-600 transition" style="color: var(--text-muted);"
                                                                                title="<?php echo e(t('Edit')); ?>">
                                                                                <?php echo get_icon('pencil', 'w-3 h-3'); ?>
                                                                            </button>
                                                                    <?php endif; ?>
                                                                    <form method="post" class="inline">
                                                                        <?php echo csrf_field(); ?>
                                                                        <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                                                        <button type="submit" name="delete_time_entry"
                                                                            class="p-0.5 hover:text-red-500 transition" style="color: var(--text-muted);"
                                                                            title="<?php echo e(t('Delete')); ?>"
                                                                            onclick="return confirm('<?php echo e(t('Delete this time entry?')); ?>')">
                                                                            <?php echo get_icon('trash', 'w-3 h-3'); ?>
                                                                        </button>
                                                                    </form>
                                                                </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                        <?php endif; ?>
                                <?php else: ?>
                                        <?php $comment = $timeline_item['data']; ?>
                                        <?php
                                        $comment_attachments = array_filter($attachments, function ($a) use ($comment) {
                                            return $a['comment_id'] == $comment['id'];
                                        });
                                        $is_own_comment = ((int) $comment['user_id'] === (int) $user['id']);
                                        ?>
                                        <div id="comment-<?php echo $comment['id']; ?>"
                                            class="comment-item group px-4 lg:px-5 py-4 transition-colors hover:bg-[var(--surface-secondary)]/40 <?php echo $comment['is_internal'] ? 'comment-internal' : ''; ?>">
                                            <div class="flex gap-3">
                                                <!-- Avatar -->
                                                <?php if (!empty($comment['avatar'])): ?>
                                                        <img src="<?php echo e(upload_url($comment['avatar'])); ?>" alt=""
                                                            class="w-9 h-9 rounded-full object-cover flex-shrink-0 mt-0.5">
                                                <?php else: ?>
                                                        <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5"
                                                            style="background: <?php echo $is_own_comment ? 'var(--primary-soft-strong)' : 'var(--surface-tertiary)'; ?>;">
                                                            <span class="font-semibold text-sm"
                                                                style="color: <?php echo $is_own_comment ? 'var(--primary)' : 'var(--text-muted)'; ?>;">
                                                                <?php echo strtoupper(substr($comment['first_name'], 0, 1)); ?>
                                                            </span>
                                                        </div>
                                                <?php endif; ?>

                                                <!-- Content -->
                                                <div class="flex-1 min-w-0">
                                                    <!-- Header: name + badges + timestamp + actions -->
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <span class="font-semibold text-sm" style="color: var(--text-primary);">
                                                            <?php echo e($comment['first_name'] . ' ' . $comment['last_name']); ?>
                                                        </span>
                                                        <?php if ($is_own_comment): ?>
                                                                <span class="text-xs px-1.5 py-0.5 rounded font-medium"
                                                                    style="background: var(--primary-soft); color: var(--primary);"><?php echo e(t('You')); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($comment['is_internal']): ?>
                                                                <span
                                                                    class="text-xs px-1.5 py-0.5 rounded font-medium bg-amber-50 text-amber-700"><?php echo e(t('Internal')); ?></span>
                                                        <?php endif; ?>
                                                        <span class="text-xs"
                                                            style="color: var(--text-muted);"><?php echo format_date($comment['created_at']); ?></span>
                                                        <?php if ($can_view_edit_history && !empty($comment['updated_at']) && $comment['updated_at'] !== $comment['created_at']): ?>
                                                                <span class="text-xs italic"
                                                                    style="color: var(--text-muted);">(<?php echo e(t('edited')); ?>)</span>
                                                        <?php endif; ?>

                                                        <!-- Edit/Delete actions (visible on hover) -->
                                                        <?php if (is_admin() || (is_agent() && (int) $comment['user_id'] === (int) $user['id'])): ?>
                                                                <div class="comment-actions">
                                                                    <button type="button"
                                                                        onclick="openEditCommentModal(<?php echo $comment['id']; ?>, <?php echo htmlspecialchars(json_encode($comment['content']), ENT_QUOTES, 'UTF-8'); ?>)"
                                                                        class="hover:text-blue-600 p-1 rounded transition"
                                                                        style="color: var(--text-muted);" title="<?php echo e(t('Edit comment')); ?>">
                                                                        <?php echo get_icon('pencil', 'w-3.5 h-3.5'); ?>
                                                                    </button>
                                                                    <button type="button" onclick="deleteComment(<?php echo $comment['id']; ?>)"
                                                                        class="hover:text-red-600 p-1 rounded transition"
                                                                        style="color: var(--text-muted);" title="<?php echo e(t('Delete comment')); ?>">
                                                                        <?php echo get_icon('trash', 'w-3.5 h-3.5'); ?>
                                                                    </button>
                                                                </div>
                                                        <?php endif; ?>
                                                    </div>

                                                    <!-- Comment body -->
                                                    <div class="break-words rich-content text-sm"
                                                        id="comment-content-<?php echo $comment['id']; ?>"
                                                        style="color: var(--text-secondary);">
                                                        <?php echo render_content($comment['content']); ?>
                                                    </div>

                                                    <!-- Attachments -->
                                                    <?php if (!empty($comment_attachments)): ?>
                                                        <?php $component_attachments = $comment_attachments; $component_layout = 'inline'; include BASE_PATH . '/includes/components/attachment-grid.php'; ?>
                                                    <?php endif; ?>

                                                    <?php
                                                    // Linked time entries (detail rows)
                                                    $comment_time_entries = $time_entries_by_comment[$comment['id']] ?? [];
                                                    $comment_linked_time = 0;
                                                    foreach ($comment_time_entries as $te) {
                                                        $comment_linked_time += (int) ($te['duration_minutes'] ?? 0);
                                                    }
                                                    // Show summary badge only if NO detailed entries (fallback for old time_spent)
                                                    $display_time = $comment_linked_time > 0 ? 0 : ($comment['time_spent'] ?? 0);
                                                    if ($display_time > 0 && can_view_time($user)): ?>
                                                            <div class="mt-2 inline-flex items-center gap-1.5 text-xs px-2 py-1 rounded-md"
                                                                style="background: var(--surface-secondary); color: var(--text-muted);">
                                                                <?php echo get_icon('clock', 'w-3 h-3'); ?>
                                                                <span><?php echo e(format_duration_minutes($display_time)); ?></span>
                                                            </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($comment_time_entries) && can_view_time($user)): ?>
                                                            <div class="mt-2 space-y-1.5">
                                                                <?php foreach ($comment_time_entries as $entry): ?>
                                                                        <?php $can_edit_this_entry = is_admin() || (is_agent() && (int) $entry['user_id'] === (int) $user['id']); ?>
                                                                        <div class="time-entry-row inline-flex flex-wrap items-center gap-1.5 text-xs px-3 py-1.5 rounded-full"
                                                                            style="background: var(--surface-secondary); color: var(--text-muted);">
                                                                            <?php echo get_icon('clock', 'w-3.5 h-3.5 flex-shrink-0'); ?>
                                                                            <span class="font-medium" style="color: var(--text-secondary);"><?php
                                                                            if (empty($entry['ended_at'])) {
                                                                                echo format_duration_minutes(max(0, (int) floor(calculate_timer_elapsed($entry) / 60)));
                                                                                if (!empty($entry['paused_at'])) {
                                                                                    echo ' <span class="text-yellow-600">(' . t('Paused') . ')</span>';
                                                                                } else {
                                                                                    echo ' <span class="text-green-600">(' . t('Running') . ')</span>';
                                                                                }
                                                                            } else {
                                                                                echo format_duration_minutes($entry['duration_minutes']);
                                                                            }
                                                                            ?></span>
                                                                            <span style="color: var(--border-light);">·</span>
                                                                            <span><?php echo e(trim($entry['first_name'] . ' ' . $entry['last_name'])); ?></span>
                                                                            <?php if (!empty($entry['summary'])): ?>
                                                                                    <span style="color: var(--border-light);">·</span>
                                                                                    <span class="truncate max-w-[200px]"
                                                                                        title="<?php echo e($entry['summary']); ?>"><?php echo e($entry['summary']); ?></span>
                                                                            <?php endif; ?>
                                                                            <span style="color: var(--border-light);">·</span>
                                                                            <span><?php echo format_date($entry['started_at']); ?></span>
                                                                            <?php if ($can_edit_this_entry): ?>
                                                                                    <span class="time-entry-actions">
                                                                                        <?php if (!empty($entry['ended_at'])): ?>
                                                                                                <button type="button"
                                                                                                    onclick="openEditTimeEntry(<?php echo htmlspecialchars(json_encode($entry)); ?>)"
                                                                                                    class="p-0.5 hover:text-blue-600 transition"
                                                                                                    style="color: var(--text-muted);" title="<?php echo e(t('Edit time')); ?>">
                                                                                                    <?php echo get_icon('pencil', 'w-3 h-3'); ?>
                                                                                                </button>
                                                                                        <?php endif; ?>
                                                                                        <form method="post" class="inline">
                                                                                            <?php echo csrf_field(); ?>
                                                                                            <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                                                                            <button type="submit" name="delete_time_entry"
                                                                                                class="p-0.5 hover:text-red-500 transition"
                                                                                                style="color: var(--text-muted);"
                                                                                                title="<?php echo e(t('Delete time')); ?>"
                                                                                                onclick="return confirm('<?php echo e(t('Delete this time entry?')); ?>')">
                                                                                                <?php echo get_icon('trash', 'w-3 h-3'); ?>
                                                                                            </button>
                                                                                        </form>
                                                                                    </span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
            <?php endif; ?>

            <!-- Add Comment Form -->
            <form method="post" enctype="multipart/form-data" class="p-3 lg:p-4 border-t"
                style="background: var(--surface-secondary);" id="comment-form">
                <?php echo csrf_field(); ?>
                <?php
                // Capture referrer for redirect after status change (back to tickets list or dashboard)
                $referrer = $_SERVER['HTTP_REFERER'] ?? '';
                if (preg_match('/page=(tickets|dashboard)/', $referrer)) {
                    echo '<input type="hidden" name="redirect_to" value="' . e($referrer) . '">';
                }
                ?>
                <?php if (is_agent()): ?>
                        <input type="hidden" name="change_status_with_comment" value="1">
                <?php endif; ?>

                <?php if (is_agent()): ?>
                        <!-- Comment Mode Toggle - Primary Choice -->
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <div class="inline-flex items-center gap-0.5 rounded-lg p-1"
                                style="background: var(--surface-secondary);">
                                <button type="button"
                                    class="comment-mode-btn flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-all"
                                    data-mode="public" title="<?php echo e(t('Public reply')); ?>">
                                    <?php echo get_icon('eye', 'w-4 h-4'); ?>
                                    <span><?php echo e(t('Public')); ?></span>
                                </button>
                                <button type="button"
                                    class="comment-mode-btn flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-all"
                                    data-mode="internal" title="<?php echo e(t('Internal note')); ?>">
                                    <?php echo get_icon('lock', 'w-4 h-4'); ?>
                                    <span><?php echo e(t('Internal')); ?></span>
                                </button>
                            </div>
                            <input type="checkbox" id="is_internal_toggle" name="is_internal" class="hidden">
                            <p class="text-xs" style="color: var(--text-muted);" id="comment-mode-hint">
                                <?php echo e(t('Visible to customer')); ?></p>
                        </div>
                <?php endif; ?>

                <!-- Public Reply Section -->
                <div id="public-comment-section">
                    <?php if (!is_agent()): ?>
                            <label class="block text-sm mb-2"
                                style="color: var(--text-secondary);"><?php echo e(t('Your reply')); ?>
                                <span class="text-red-500">*</span></label>
                    <?php endif; ?>
                    <div class="editor-wrapper">
                        <div id="comment-editor"></div>
                    </div>
                    <input type="hidden" name="comment" id="comment-text">
                </div>

                <?php if (is_agent()): ?>
                        <!-- Internal Note Section (hidden by default) -->
                        <div id="internal-comment-section" class="hidden">
                            <div class="editor-wrapper editor-wrapper--internal">
                                <div id="internal-editor"></div>
                            </div>
                            <input type="hidden" name="internal_text" id="internal-text">
                        </div>
                <?php endif; ?>

                <!-- Status + Attachments -->
                <div class="mt-3">
                    <?php if (is_agent()): ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <select name="status_id" class="form-select text-sm w-full" style="height: 42px;">
                                        <?php foreach ($statuses as $status): ?>
                                                <option value="<?php echo $status['id']; ?>" <?php echo $status['id'] == $ticket['status_id'] ? 'selected' : ''; ?>>
                                                    <?php echo e(t('Status')); ?>: <?php echo e($status['name']); ?>
                                                </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <div id="comment-upload-zone"
                                        class="upload-zone rounded-lg text-center cursor-pointer border-2 border-dashed hover:border-blue-300 transition-colors flex items-center justify-center"
                                        style="border-color: var(--border-light); height: 42px;">
                                        <input type="file" name="comment_attachments[]" id="comment-file-input" multiple
                                            class="hidden"
                                            accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                                        <div class="flex items-center justify-center gap-2" style="color: var(--text-muted);">
                                            <?php echo get_icon('paperclip', 'w-4 h-4'); ?>
                                            <span class="text-sm">
                                                <span
                                                    class="text-blue-500 font-medium"><?php echo e(t('Add attachments')); ?></span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div id="comment-file-preview" class="mt-2 space-y-1 hidden"></div>
                    <?php else: ?>
                            <!-- Non-agent: attachments only -->
                            <div>
                                <div id="comment-upload-zone"
                                    class="upload-zone rounded-lg p-2.5 text-center cursor-pointer border-2 border-dashed hover:border-blue-300 transition-colors"
                                    style="border-color: var(--border-light);">
                                    <input type="file" name="comment_attachments[]" id="comment-file-input" multiple
                                        class="hidden"
                                        accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                                    <div class="flex items-center justify-center gap-2" style="color: var(--text-muted);">
                                        <?php echo get_icon('paperclip', 'w-4 h-4'); ?>
                                        <span class="text-sm">
                                            <span
                                                class="text-blue-500 font-medium"><?php echo e(t('Add attachments')); ?></span>
                                            <span class="text-xs ml-1"
                                                style="color: var(--text-muted);">(<?php echo e(t('or drag files')); ?>)</span>
                                        </span>
                                    </div>
                                </div>
                                <div id="comment-file-preview" class="mt-2 space-y-1 hidden"></div>
                            </div>
                    <?php endif; ?>
                </div>

                <?php if (is_agent() && $time_tracking_available): ?>
                        <!-- Manual Time Entry (expandable, between attachments and submit row) -->
                        <div id="manual-entry-row" class="hidden mt-2 pt-2 border-t" style="border-color: var(--border-light);">
                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <label class="form-label-sm mb-1"><?php echo e(t('Date')); ?></label>
                                    <input type="date" name="manual_date" value="<?php echo e(date('Y-m-d')); ?>"
                                        class="form-input text-sm h-9">
                                </div>
                                <div>
                                    <label class="form-label-sm mb-1"><?php echo e(t('Start')); ?></label>
                                    <input type="time" name="manual_start_time" class="form-input text-sm h-9">
                                </div>
                                <div>
                                    <label class="form-label-sm mb-1"><?php echo e(t('End')); ?></label>
                                    <input type="time" name="manual_end_time" class="form-input text-sm h-9">
                                </div>
                            </div>
                        </div>
                <?php endif; ?>

                <!-- Submit row: timer + notification on LEFT, CC + send on RIGHT -->
                <div class="mt-3 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-3">
                    <div class="flex items-center gap-2 flex-wrap min-w-0">
                        <?php if (is_agent() && $time_tracking_available): ?>
                                <!-- Unified timer control — single button that changes state -->
                                <div id="timer-controls" data-ticket-id="<?php echo $ticket_id; ?>"
                                    data-paused="<?php echo $timer_is_paused ? '1' : '0'; ?>" class="flex items-center gap-2">
                                    <button type="button" id="btn-timer-action"
                                        class="btn <?php echo $timer_state === 'running' ? 'btn-warning' : 'btn-success'; ?> px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors"
                                        data-state="<?php echo $timer_state; ?>"
                                        title="<?php echo $timer_state === 'running' ? e(t('Pause timer')) : ($timer_state === 'paused' ? e(t('Resume timer')) : e(t('Start timer'))); ?>">
                                        <span class="btn-timer-icon">
                                            <?php if ($timer_state === 'running'): ?>
                                                    <?php echo get_icon('pause', 'w-4 h-4'); ?>
                                            <?php else: ?>
                                                    <?php echo get_icon('play', 'w-4 h-4'); ?>
                                            <?php endif; ?>
                                        </span>
                                        <span class="btn-timer-text">
                                            <?php if ($timer_state === 'stopped'): ?>
                                                    <?php echo e(t('Start timer')); ?>
                                            <?php else: ?>
                                                    <span id="timer-elapsed" class="tabular-nums"
                                                        data-started="<?php echo strtotime($active_timer['started_at']); ?>"
                                                        data-paused-seconds="<?php echo (int) ($active_timer['paused_seconds'] ?? 0); ?>"
                                                        <?php if ($timer_is_paused && !empty($active_timer['paused_at'])): ?>
                                                                data-paused-at="<?php echo strtotime($active_timer['paused_at']); ?>" <?php endif; ?>><?php echo format_duration_minutes($active_timer_elapsed); ?></span>
                                                    <?php if ($timer_state === 'paused'): ?>
                                                            <span class="text-xs uppercase ml-1"><?php echo e(t('Paused')); ?></span>
                                                    <?php endif; ?>
                                            <?php endif; ?>
                                        </span>
                                    </button>
                                    <!-- Log on submit checkbox (visible when timer active) -->
                                    <label id="timer-log-toggle"
                                        class="<?php echo $timer_state === 'stopped' ? 'hidden' : ''; ?> inline-flex items-center gap-1.5 text-xs cursor-pointer select-none whitespace-nowrap"
                                        style="color: var(--text-secondary);">
                                        <input type="checkbox" name="stop_timer" id="stop-timer-toggle" value="1" <?php echo $timer_state !== 'stopped' ? 'checked' : 'disabled'; ?>
                                            class="rounded text-blue-600 focus:ring-blue-500 w-4 h-4">
                                        <span><?php echo e(t('Log on submit')); ?></span>
                                    </label>
                                    <!-- Discard button (visible when timer active) -->
                                    <button type="button" id="btn-discard-timer"
                                        class="<?php echo $timer_state === 'stopped' ? 'hidden' : ''; ?> btn btn-ghost px-2 py-1.5 hover:text-red-500 transition-colors"
                                        style="color: var(--text-muted);" title="<?php echo e(t('Discard timer')); ?>">
                                        <?php echo get_icon('trash', 'w-4 h-4'); ?>
                                    </button>
                                </div>
                                <!-- Manual entry toggle -->
                                <button type="button" id="manual-toggle" class="btn btn-ghost px-2 py-1.5"
                                    style="color: var(--text-muted);" aria-expanded="false"
                                    title="<?php echo e(t('Manual entry')); ?>">
                                    <?php echo get_icon('pen', 'w-4 h-4'); ?>
                                </button>
                        <?php endif; ?>
                        <label class="flex items-center text-sm cursor-pointer whitespace-nowrap"
                            style="color: var(--text-secondary);">
                            <input type="checkbox" name="skip_notification" value="1" class="mr-2 rounded">
                            <span><?php echo e(t('Do not send email notification')); ?></span>
                        </label>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <?php if (is_agent()): ?>
                                <!-- CC compact -->
                                <div class="relative" id="agent-cc-dropdown-container">
                                    <button type="button" id="agent-cc-toggle"
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-sm border rounded-lg transition-colors"
                                        style="color: var(--text-secondary); background: var(--bg-primary); border-color: var(--border-light);"
                                        data-none-text="<?php echo e(t('CC')); ?>"
                                        data-selected-text="<?php echo e(t('CC')); ?>">
                                        <?php echo get_icon('users', 'w-3.5 h-3.5 td-text-muted'); ?>
                                        <span id="agent-cc-display" class="text-sm"><?php echo e(t('CC')); ?></span>
                                        <?php echo get_icon('chevron-down', 'w-3 h-3 td-text-muted flex-shrink-0'); ?>
                                    </button>
                                    <div id="agent-cc-list"
                                        class="hidden absolute z-50 bottom-full mb-1 right-0 w-64 border rounded-lg shadow-lg max-h-48 overflow-y-auto"
                                        style="background: var(--bg-primary); border-color: var(--border-light);">
                                        <?php foreach ($all_users as $u): ?>
                                                <?php if ($u['id'] !== $user['id'] && $u['id'] !== $ticket['user_id']): ?>
                                                        <label class="flex items-center px-3 py-2 cursor-pointer tr-hover">
                                                            <input type="checkbox" name="cc_users[]" value="<?php echo $u['id']; ?>"
                                                                class="agent-cc-checkbox rounded text-blue-600 mr-2">
                                                            <span
                                                                class="text-sm truncate"><?php echo e($u['first_name'] . ' ' . $u['last_name']); ?></span>
                                                        </label>
                                                <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                        <?php endif; ?>
                        <button type="submit" name="add_comment" id="comment-submit-btn"
                            class="btn btn-primary whitespace-nowrap"
                            data-default-text="<?php echo e(t('Send update')); ?>"
                            data-log-time-text="<?php echo e(t('Log time & send update')); ?>"
                            data-stop-text="<?php echo e(t('Stop timer & send update')); ?>"
                            data-has-active-timer="<?php echo $active_timer ? '1' : '0'; ?>">
                            <?php echo get_icon('paper-plane'); ?><span
                                class="btn-text"><?php echo e(t('Send update')); ?></span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Sidebar -->
    <?php
    // Pre-fetch data for sidebar dropdowns (agents only)
    if (is_agent()) {
        $_ai_excl = (function_exists('ai_agent_column_exists') && ai_agent_column_exists()) ? ' AND is_ai_agent = 0' : '';
        $_sidebar_agents = db_fetch_all("SELECT id, first_name, last_name FROM users WHERE role IN ('agent', 'admin') AND is_active = 1{$_ai_excl} ORDER BY first_name");
        $_sidebar_priorities = db_fetch_all("SELECT id, name FROM priorities ORDER BY sort_order");
        $_sidebar_types = db_fetch_all("SELECT slug, name FROM ticket_types WHERE is_active = 1 ORDER BY sort_order");
    }
    ?>
    <div class="ticket-sidebar">
        <!-- Details -->
        <div class="card card-body">
            <?php if (!empty($ticket['organization_name'])): ?>
                    <div class="flex items-center gap-2 px-2.5 py-2 -mx-1 mb-2 rounded-lg"
                        style="background: var(--primary-soft);">
                        <span style="color: var(--primary);"><?php echo get_icon('building', 'w-4 h-4 flex-shrink-0'); ?></span>
                        <span class="text-sm font-semibold truncate" style="color: var(--primary-dark);"
                            title="<?php echo e($ticket['organization_name']); ?>">
                            <?php echo e($ticket['organization_name']); ?>
                        </span>
                    </div>
            <?php endif; ?>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-xs" style="color: var(--text-muted);">ID</dt>
                    <dd class="text-xs font-mono font-medium" style="color: var(--text-primary);">
                        <?php echo get_ticket_code($ticket_id); ?>
                    </dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Status')); ?></dt>
                    <dd>
                        <span class="badge px-2 py-0.5 text-xs"
                            style="background-color: <?php echo e($ticket['status_color']); ?>20; color: <?php echo e($ticket['status_color']); ?>">
                            <?php echo e($ticket['status_name']); ?>
                        </span>
                    </dd>
                </div>
                <?php if (is_agent()): ?>
                <div class="flex justify-between items-center">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Assigned')); ?></dt>
                    <dd>
                        <select class="text-xs py-0.5 px-1 rounded border-0 cursor-pointer" style="color: var(--text-primary); background: var(--surface-secondary);" onchange="quickEditField('quick-assign', {assignee_id: this.value})">
                            <option value=""><?php echo e(t('-- Unassigned --')); ?></option>
                            <?php foreach ($_sidebar_agents as $_ag): ?>
                                <option value="<?php echo $_ag['id']; ?>" <?php echo ($ticket['assignee_id'] ?? 0) == $_ag['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($_ag['first_name'] . ' ' . $_ag['last_name']); ?><?php if ($_ag['id'] == $user['id']): ?> (<?php echo e(t('me')); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </dd>
                </div>
                <?php endif; ?>
                <div class="flex justify-between items-center">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Priority')); ?></dt>
                    <dd>
                        <?php if (is_agent()): ?>
                        <select class="text-xs py-0.5 px-1 rounded border-0 cursor-pointer" style="color: var(--text-primary); background: var(--surface-secondary);" onchange="quickEditField('quick-priority', {priority_id: this.value})">
                            <option value=""><?php echo e(t('-- Select --')); ?></option>
                            <?php foreach ($_sidebar_priorities as $_pr): ?>
                                <option value="<?php echo $_pr['id']; ?>" <?php echo ($ticket['priority_id'] ?? 0) == $_pr['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($_pr['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <span class="badge px-2 py-0.5 text-xs"
                            style="background-color: <?php echo e($priority_color); ?>20; color: <?php echo e($priority_color); ?>">
                            <?php echo e($priority_name); ?>
                        </span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Type')); ?></dt>
                    <dd>
                        <?php if (is_agent()): ?>
                        <select class="text-xs py-0.5 px-1 rounded border-0 cursor-pointer" style="color: var(--text-primary); background: var(--surface-secondary);" onchange="quickEditField('quick-type', {type: this.value})">
                            <option value=""><?php echo e(t('-- Select --')); ?></option>
                            <?php foreach ($_sidebar_types as $_tp): ?>
                                <option value="<?php echo $_tp['slug']; ?>" <?php echo ($ticket['type'] ?? '') === $_tp['slug'] ? 'selected' : ''; ?>>
                                    <?php echo e($_tp['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <span class="text-xs" style="color: var(--text-primary);"><?php echo e(get_type_label($ticket['type'])); ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
                <?php if ($tags_supported): ?>
                <div class="space-y-1" id="sidebar-tags-section">
                    <dt class="text-xs flex items-center justify-between" style="color: var(--text-muted);">
                        <?php echo e(t('Tags')); ?>
                        <?php if (can_edit_ticket($ticket, $user)): ?>
                            <button type="button" id="sidebar-tags-edit-btn"
                                class="text-xs font-medium" style="color: var(--primary); cursor: pointer;">
                                <?php echo e(t('Edit')); ?>
                            </button>
                        <?php endif; ?>
                    </dt>
                    <!-- Display mode -->
                    <dd id="sidebar-tags-display" class="flex flex-wrap gap-1 justify-end">
                        <?php if (!empty($ticket_tags)): ?>
                            <?php foreach ($ticket_tags as $tag): ?>
                                <a href="<?php echo e($ticket_tag_filter_url($tag)); ?>" class="ticket-tag-pill"
                                    title="<?php echo e(t('Filter by this tag')); ?>">
                                    #<?php echo e($tag); ?>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-xs" style="color: var(--text-muted);">—</span>
                        <?php endif; ?>
                    </dd>
                    <!-- Edit mode -->
                    <?php if (can_edit_ticket($ticket, $user)): ?>
                    <dd id="sidebar-tags-editor" class="hidden">
                        <div class="chip-select" id="cs-tags-detail">
                            <div class="chip-select__wrap" id="cs-tags-detail-wrap">
                                <div class="chip-select__chips" id="cs-tags-detail-chips"></div>
                                <input type="text" class="chip-select__input" id="cs-tags-detail-input"
                                       placeholder="<?php echo e(t('Type to add tag...')); ?>" autocomplete="off">
                            </div>
                            <div class="chip-select__dropdown hidden" id="cs-tags-detail-dropdown"></div>
                            <div id="cs-tags-detail-hidden"></div>
                        </div>
                        <div class="flex gap-2 mt-2">
                            <button type="button" id="sidebar-tags-save" class="btn btn-primary btn-xs">
                                <?php echo e(t('Save')); ?>
                            </button>
                            <button type="button" id="sidebar-tags-cancel" class="btn btn-ghost btn-xs">
                                <?php echo e(t('Cancel')); ?>
                            </button>
                        </div>
                    </dd>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="flex justify-between">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Created')); ?></dt>
                    <dd class="text-xs" style="color: var(--text-primary);">
                        <?php echo format_date($ticket['created_at']); ?></dd>
                </div>
                <?php if (!empty($ticket['due_date'])): ?>
                        <div class="flex justify-between">
                            <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Due date')); ?></dt>
                            <dd class="text-xs">
                                <?php
                                $due_datetime = new DateTime($ticket['due_date']);
                                $now = new DateTime();
                                $is_overdue = $due_datetime < $now && empty($ticket['is_closed']);
                                ?>
                                <span class="<?php echo $is_overdue ? 'text-red-600 font-bold' : ''; ?>"
                                    style="<?php echo !$is_overdue ? 'color: var(--text-primary);' : ''; ?>">
                                    <?php echo format_date($ticket['due_date']); ?>
                                </span>
                            </dd>
                        </div>
                <?php endif; ?>
                <?php if (!empty($attachment_list)): ?>
                        <div class="flex justify-between">
                            <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Attachments')); ?></dt>
                            <dd class="text-xs" style="color: var(--text-primary);"><?php echo count($attachment_list); ?>
                                <?php echo e(t('files')); ?>
                            </dd>
                        </div>
                <?php endif; ?>
                <?php if ($time_tracking_available && can_view_time($user)): ?>
                        <div class="flex justify-between items-center">
                            <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Logged time')); ?></dt>
                            <dd>
                                <?php if ($total_time_minutes > 0): ?>
                                        <span class="badge-inline bg-blue-50 text-blue-700">
                                            <?php echo get_icon('clock', 'mr-1'); ?>                <?php echo e(format_duration_minutes($total_time_minutes)); ?>
                                        </span>
                                <?php else: ?>
                                        <span class="text-xs" style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <?php if ($time_breakdown['human'] > 0 && $time_breakdown['ai'] > 0): ?>
                                <div class="flex justify-end items-center gap-2 -mt-1">
                                    <span
                                        class="inline-flex items-center text-xs bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded">
                                        <?php echo get_icon('user', 'w-3 h-3 mr-0.5'); ?>                <?php echo e(format_duration_minutes($time_breakdown['human'])); ?>
                                    </span>
                                    <span class="inline-flex items-center text-xs bg-purple-50 text-purple-700 px-1.5 py-0.5 rounded">
                                        <?php echo get_icon('bot', 'w-3 h-3 mr-0.5'); ?>                <?php echo e(format_duration_minutes($time_breakdown['ai'])); ?>
                                    </span>
                                </div>
                        <?php endif; ?>
                <?php endif; ?>
            </dl>
        </div>

        <?php if (function_exists('can_view_timeline') && can_view_timeline($user)): ?>
        <div class="card card-body">
            <button onclick="openTicketTimeline(<?php echo (int)$ticket['id']; ?>)"
                class="w-full flex items-center gap-2 text-sm font-medium rounded-lg px-3 py-2 transition-colors"
                style="color: var(--text-secondary); background: var(--surface-secondary);"
                onmouseover="this.style.background='var(--surface-hover)'"
                onmouseout="this.style.background='var(--surface-secondary)'">
                <?php echo get_icon('history', 'w-4 h-4'); ?>
                <?php echo e(t('Activity Timeline')); ?>
                <span class="ml-auto"><?php echo get_icon('chevron-right', 'w-3 h-3'); ?></span>
            </button>
        </div>
        <?php endif; ?>

        <?php if (!empty($attachment_list)): ?>
                <div class="card card-body">
                    <h3 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">
                        <?php echo e(t('All attachments')); ?></h3>
                    <div class="space-y-1">
                        <?php foreach ($attachment_list as $attachment): ?>
                                <?php
                                $comment_anchor = !empty($attachment['comment_id']) ? ('#comment-' . $attachment['comment_id']) : '';
                                $uploader_name = trim(($attachment['first_name'] ?? '') . ' ' . ($attachment['last_name'] ?? ''));
                                $_att_url = e(upload_url(UPLOAD_DIR . $attachment['filename']));
                                $_is_img = is_image_mime($attachment['mime_type'] ?? '');
                                ?>
                                <div class="flex items-start gap-2 p-1.5 rounded group tr-hover">
                                    <?php if ($_is_img): ?>
                                        <a href="<?php echo $_att_url; ?>" target="_blank"
                                           class="flex-shrink-0 rounded overflow-hidden border cursor-pointer"
                                           style="border-color: var(--border-light);"
                                           onclick="event.preventDefault(); openImagePreview('<?php echo $_att_url; ?>', '<?php echo e($attachment['original_name']); ?>');">
                                            <img src="<?php echo $_att_url; ?>" alt="" class="w-8 h-8 object-cover" loading="lazy">
                                        </a>
                                    <?php else: ?>
                                        <?php echo get_icon(get_file_icon($attachment['mime_type']), 'td-text-muted mt-0.5 w-3 h-3 flex-shrink-0'); ?>
                                    <?php endif; ?>
                                    <div class="min-w-0 flex-1">
                                        <?php if ($_is_img): ?>
                                            <a href="<?php echo $_att_url; ?>"
                                               class="text-xs font-medium truncate hover:text-blue-600 block cursor-pointer"
                                               style="color: var(--text-secondary);"
                                               onclick="event.preventDefault(); openImagePreview('<?php echo $_att_url; ?>', '<?php echo e($attachment['original_name']); ?>');">
                                                <?php echo e($attachment['original_name']); ?>
                                            </a>
                                        <?php else: ?>
                                            <a href="<?php echo $_att_url; ?>" target="_blank"
                                               class="text-xs font-medium truncate hover:text-blue-600 block"
                                               style="color: var(--text-secondary);">
                                                <?php echo e($attachment['original_name']); ?>
                                            </a>
                                        <?php endif; ?>
                                        <div class="text-xs flex items-center gap-1" style="color: var(--text-muted);">
                                            <?php echo format_file_size($attachment['file_size']); ?>
                                            <?php if (!empty($uploader_name)): ?>
                                                    &middot; <?php echo e($uploader_name); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                        <?php endforeach; ?>
                    </div>
                </div>
        <?php endif; ?>

        <?php if (is_agent()): ?>
                <!-- Compact Actions Panel -->
                <div class="card card-body">
                    <!-- Collapsible Options (collapsed by default) -->
                    <details class="group">
                        <summary class="flex items-center justify-between cursor-pointer py-1 text-xs"
                            style="color: var(--text-muted);">
                            <span class="flex items-center gap-1.5">
                                <?php echo get_icon('cog', 'w-3.5 h-3.5'); ?>
                                <?php echo e(t('More options')); ?>
                            </span>
                            <?php echo get_icon('chevron-down', 'w-3.5 h-3.5 group-open:rotate-180 transition-transform'); ?>
                        </summary>

                        <div class="pt-3">
                            <!-- Advanced Fields Grid -->
                            <div class="grid grid-cols-1 gap-3 mb-3">
                                <!-- On Behalf Of -->
                                <div>
                                    <label class="form-label-sm mb-0.5">
                                        <?php echo get_icon('user-shield', 'w-3 h-3 inline mr-1'); ?>        <?php echo e(t('On behalf of')); ?>
                                    </label>
                                    <?php $behalf_users = db_fetch_all("SELECT id, first_name, last_name FROM users WHERE role IN ('user') AND is_active = 1 ORDER BY first_name"); ?>
                                    <select class="form-select text-sm py-1.5 w-full" onchange="quickEditField('quick-behalf', {created_for_user_id: this.value})">
                                        <option value=""><?php echo e(t('-- None --')); ?></option>
                                        <?php foreach ($behalf_users as $behalf_user): ?>
                                                <option value="<?php echo $behalf_user['id']; ?>" <?php echo ($ticket['created_for_user_id'] ?? 0) == $behalf_user['id'] ? 'selected' : ''; ?>>
                                                    <?php echo e($behalf_user['first_name'] . ' ' . $behalf_user['last_name']); ?>
                                                </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Due Date -->
                                <div>
                                    <label class="form-label-sm mb-0.5">
                                        <?php echo get_icon('calendar-alt', 'w-3 h-3 inline mr-1'); ?>        <?php echo e(t('Due date')); ?>
                                    </label>
                                    <?php $quick_due_overdue = !empty($ticket['due_date']) && empty($ticket['is_closed']) && strtotime($ticket['due_date']) < time(); ?>
                                    <input type="datetime-local"
                                        value="<?php echo !empty($ticket['due_date']) ? date('Y-m-d\TH:i', strtotime($ticket['due_date'])) : ''; ?>"
                                        class="form-input text-sm py-1.5 w-full <?php echo $quick_due_overdue ? 'border-red-400 bg-red-50 text-red-700' : ''; ?>"
                                        onchange="quickEditField('quick-due-date', {due_date: this.value})">
                                    <?php if ($quick_due_overdue): ?>
                                            <p class="mt-1 text-xs font-medium text-red-600"><?php echo e(t('Overdue')); ?></p>
                                    <?php endif; ?>
                                </div>

                                <!-- Company -->
                                <div>
                                    <label class="form-label-sm mb-0.5">
                                        <?php echo get_icon('building', 'w-3 h-3 inline mr-1'); ?>        <?php echo e(t('Company')); ?>
                                    </label>
                                    <?php $companies = db_fetch_all("SELECT id, name FROM organizations ORDER BY name"); ?>
                                    <select class="form-select text-sm py-1.5 w-full" onchange="quickEditField('quick-company', {organization_id: this.value})">
                                        <option value=""><?php echo e(t('-- None --')); ?></option>
                                        <?php foreach ($companies as $company): ?>
                                                <option value="<?php echo $company['id']; ?>" <?php echo ($ticket['organization_id'] ?? 0) == $company['id'] ? 'selected' : ''; ?>>
                                                    <?php echo e($company['name']); ?>
                                                </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Additional Fields (Ticket Access, Share Link, etc.) -->
                            <div class="space-y-3">
                                <!-- Ticket Access -->
                            <div>
                                <label class="form-label-sm mb-1.5">
                                    <?php echo get_icon('users', 'w-3 h-3 inline mr-1'); ?>        <?php echo e(t('Ticket access')); ?>
                                    <span style="color: var(--border-light);">(<?php echo count($shared_users); ?>)</span>
                                </label>
                                <?php if (!empty($shared_users)): ?>
                                        <div class="flex flex-wrap gap-1 mb-2">
                                            <?php foreach ($shared_users as $shared_user): ?>
                                                    <span
                                                        class="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded-full bg-blue-50 text-blue-700 group"
                                                        title="<?php echo e($shared_user['first_name'] . ' ' . $shared_user['last_name']); ?>">
                                                        <?php echo e($shared_user['first_name'] . ' ' . substr($shared_user['last_name'], 0, 1) . '.'); ?>
                                                        <form method="post" class="inline">
                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="shared_user_id"
                                                                value="<?php echo $shared_user['id']; ?>">
                                                            <button type="submit" name="remove_shared_user"
                                                                class="text-blue-400 hover:text-red-500 ml-0.5">
                                                                <?php echo get_icon('times', 'w-3 h-3'); ?>
                                                            </button>
                                                        </form>
                                                    </span>
                                            <?php endforeach; ?>
                                        </div>
                                <?php endif; ?>
                                <form method="post" class="flex gap-1">
                                    <?php echo csrf_field(); ?>
                                    <select name="shared_user_id" class="form-select text-xs py-1.5 flex-1">
                                        <option value=""><?php echo e(t('Add user...')); ?></option>
                                        <?php
                                        $shared_lookup = array_flip($shared_user_ids);
                                        foreach ($all_users as $candidate):
                                            if (empty($candidate['is_active']))
                                                continue;
                                            if ((int) $candidate['id'] === (int) $ticket['user_id'])
                                                continue;
                                            if (isset($shared_lookup[$candidate['id']]))
                                                continue;
                                            ?>
                                                <option value="<?php echo $candidate['id']; ?>">
                                                    <?php echo e($candidate['first_name'] . ' ' . $candidate['last_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="add_shared_user" class="btn btn-secondary btn-xs px-2">
                                        <?php echo get_icon('plus', 'w-3 h-3'); ?>
                                    </button>
                                </form>
                            </div>

                            <!-- Share Link -->
                            <div>
                                <label class="form-label-sm mb-1.5">
                                    <?php echo get_icon('link', 'w-3 h-3 inline mr-1'); ?>        <?php echo e(t('Public link')); ?>
                                    <span
                                        class="<?php echo $share_status_class; ?>"><?php echo e($share_status_label); ?></span>
                                </label>
                                <?php if (!empty($share_url)): ?>
                                        <div class="flex gap-1 mb-1.5">
                                            <input type="text" id="share-link-input" readonly value="<?php echo e($share_url); ?>"
                                                class="form-input text-xs py-1.5 flex-1 font-mono"
                                                style="background: var(--surface-secondary); color: var(--text-secondary);">
                                            <button type="button" id="share-copy-btn" class="btn btn-secondary btn-xs px-2"
                                                title="<?php echo e(t('Copy')); ?>">
                                                <?php echo get_icon('copy', 'w-3 h-3'); ?>
                                            </button>
                                        </div>
                                <?php endif; ?>
                                <form method="post" class="flex gap-1">
                                    <?php echo csrf_field(); ?>
                                    <?php if ($share_status !== 'active'): ?>
                                            <button type="submit" name="create_share_link"
                                                class="btn btn-secondary btn-xs flex-1 justify-center">
                                                <?php echo get_icon('link', 'w-3 h-3 mr-1'); ?>                <?php echo e(t('Create')); ?>
                                            </button>
                                    <?php else: ?>
                                            <button type="submit" name="create_share_link"
                                                class="btn btn-secondary btn-xs flex-1 justify-center">
                                                <?php echo e(t('New')); ?>
                                            </button>
                                            <button type="submit" name="revoke_share_link"
                                                class="btn btn-xs border border-red-200 text-red-600 hover:bg-red-50 flex-1 justify-center">
                                                <?php echo e(t('Revoke')); ?>
                                            </button>
                                    <?php endif; ?>
                                </form>
                            </div>


                            <?php if (is_admin() || (is_agent() && can_archive_tickets())): ?>
                                    <!-- Archive -->
                                    <div class="pt-2 border-t" style="border-color: var(--border-light);">
                                        <?php if (empty($ticket['is_archived'])): ?>
                                                <form method="post"
                                                    onsubmit="return confirm('<?php echo e(t('Are you sure you want to move this ticket to the archive?')); ?>')">
                                                    <?php echo csrf_field(); ?>
                                                    <button type="submit" name="archive_ticket"
                                                        class="btn btn-ghost btn-sm w-full justify-center hover:text-orange-600 hover:bg-orange-50"
                                                        style="color: var(--text-muted);">
                                                        <?php echo get_icon('archive', 'w-4 h-4 mr-1.5'); ?>                        <?php echo e(t('Archive')); ?>
                                                    </button>
                                                </form>
                                        <?php else: ?>
                                                <form method="post">
                                                    <?php echo csrf_field(); ?>
                                                    <button type="submit" name="restore_ticket"
                                                        class="btn btn-success btn-sm w-full justify-center">
                                                        <?php echo get_icon('undo', 'w-4 h-4 mr-1.5'); ?>                        <?php echo e(t('Restore')); ?>
                                                    </button>
                                                </form>
                                        <?php endif; ?>
                                    </div>
                            <?php endif; ?>
                        </div>
                    </details>
                </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Ticket Modal -->
<?php if (can_edit_ticket($ticket, $user)): ?>
        <div id="edit-ticket-modal" class="modal-overlay hidden" aria-labelledby="edit-ticket-title" role="dialog"
            aria-modal="true">
            <div class="modal-backdrop" onclick="closeEditTicketModal()"></div>
            <div class="modal-panel max-w-2xl">
                <form method="post" id="edit-ticket-form">
                    <?php echo csrf_field(); ?>
                    <div class="modal-panel-body">
                        <h3 class="text-base font-semibold mb-4 flex items-center gap-2" style="color: var(--text-primary);"
                            id="edit-ticket-title">
                            <?php echo get_icon('edit', 'w-5 h-5 td-text-muted'); ?>
                            <?php echo e(t('Edit ticket')); ?>
                        </h3>

                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-medium mb-1"
                                    style="color: var(--text-muted);"><?php echo e(t('Subject')); ?> *</label>
                                <input type="text" name="edit_title" id="edit-ticket-title-input"
                                    value="<?php echo e($ticket['title']); ?>" class="form-input w-full" required>
                            </div>

                            <div>
                                <label class="block text-xs font-medium mb-1"
                                    style="color: var(--text-muted);"><?php echo e(t('Description')); ?></label>
                                <div class="editor-wrapper">
                                    <div id="edit-description-editor"></div>
                                </div>
                                <input type="hidden" name="edit_description" id="edit-description-input"
                                    value="<?php echo e($ticket['description']); ?>">
                            </div>

                            <?php if ($tags_supported): ?>
                                    <div>
                                        <label class="block text-xs font-medium mb-1"
                                            style="color: var(--text-muted);"><?php echo e(t('Tags')); ?></label>
                                        <input type="text" name="edit_tags" id="edit-ticket-tags-input"
                                            value="<?php echo e($ticket['tags'] ?? ''); ?>" class="form-input w-full"
                                            placeholder="<?php echo e(t('Comma separated tags')); ?>">
                                    </div>
                            <?php endif; ?>

                            <?php if (is_agent()): ?>
                                    <div>
                                        <label class="block text-xs font-medium mb-1"
                                            style="color: var(--text-muted);"><?php echo e(t('Company')); ?></label>
                                        <select name="edit_organization_id" class="form-select w-full">
                                            <option value=""><?php echo e(t('-- No organization --')); ?></option>
                                            <?php foreach ($organizations as $org): ?>
                                                    <option value="<?php echo (int) $org['id']; ?>" <?php echo ((int) ($ticket['organization_id'] ?? 0) === (int) ($org['id'] ?? 0)) ? 'selected' : ''; ?>>
                                                        <?php echo e($org['name']); ?>
                                                    </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="modal-panel-footer">
                        <button type="button" onclick="closeEditTicketModal()"
                            class="btn btn-secondary"><?php echo e(t('Cancel')); ?></button>
                        <button type="submit" name="update_ticket"
                            class="btn btn-primary"><?php echo e(t('Save changes')); ?></button>
                    </div>
                </form>
            </div>
        </div>
<?php endif; ?>

<!-- Edit Comment Modal -->
<?php if (is_admin() || is_agent()): ?>
        <div id="edit-comment-modal" class="modal-overlay hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="modal-backdrop" onclick="closeEditCommentModal()"></div>
            <div class="modal-panel max-w-lg">
                <form id="edit-comment-form" onsubmit="submitEditComment(event)">
                    <div class="modal-panel-body">
                        <h3 class="text-lg font-medium mb-4" id="modal-title" style="color: var(--text-primary);">
                            <?php echo e(t('Edit comment')); ?></h3>
                        <input type="hidden" name="comment_id" id="edit-comment-id">
                        <div class="editor-wrapper">
                            <div id="edit-comment-editor"></div>
                        </div>
                    </div>
                    <div class="modal-panel-footer">
                        <button type="button" onclick="closeEditCommentModal()"
                            class="btn btn-secondary"><?php echo e(t('Cancel')); ?></button>
                        <button type="submit" class="btn btn-primary"><?php echo e(t('Save')); ?></button>
                    </div>
                </form>
            </div>
        </div>
<?php endif; ?>

<!-- Edit Time Entry Modal -->
<?php if (is_admin() && $time_tracking_available): ?>
        <div id="edit-time-modal" class="modal-overlay hidden" aria-labelledby="time-modal-title" role="dialog"
            aria-modal="true">
            <div class="modal-backdrop" onclick="closeEditTimeModal()"></div>
            <div class="modal-panel max-w-md">
                <form method="post" id="edit-time-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="entry_id" id="edit-time-id">
                    <input type="hidden" name="edit_time_date" id="edit-time-date">
                    <div class="modal-panel-body">
                        <h3 class="text-base font-semibold mb-4 flex items-center gap-2" style="color: var(--text-primary);"
                            id="time-modal-title">
                            <?php echo get_icon('clock', 'w-5 h-5 td-text-muted'); ?>
                            <?php echo e(t('Edit time entry')); ?>
                        </h3>

                        <div class="space-y-3">
                            <!-- Date + Start + End on one row -->
                            <div class="grid grid-cols-[1fr_auto_auto] gap-2 items-end">
                                <div>
                                    <label class="block text-xs font-medium mb-1"
                                        style="color: var(--text-muted);"><?php echo e(t('Date')); ?></label>
                                    <input type="date" id="edit-time-date-picker" class="form-input w-full text-sm h-9"
                                        required>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1"
                                        style="color: var(--text-muted);"><?php echo e(t('Start')); ?></label>
                                    <input type="time" id="edit-time-start-time" class="form-input w-full text-sm h-9" required>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1"
                                        style="color: var(--text-muted);"><?php echo e(t('End')); ?></label>
                                    <input type="time" id="edit-time-end-time" class="form-input w-full text-sm h-9" required>
                                </div>
                            </div>
                            <!-- Hidden actual datetime-local inputs for form submission -->
                            <input type="hidden" name="started_at" id="edit-time-start">
                            <input type="hidden" name="ended_at" id="edit-time-end">

                            <div class="flex items-center gap-2">
                                <span class="text-xs font-medium"
                                    style="color: var(--text-muted);"><?php echo e(t('Duration')); ?>:</span>
                                <span id="edit-time-duration" class="text-sm font-semibold text-blue-600">-</span>
                            </div>

                            <div>
                                <label class="block text-xs font-medium mb-1"
                                    style="color: var(--text-muted);"><?php echo e(t('Summary')); ?></label>
                                <textarea name="summary" id="edit-time-summary" rows="2" class="form-textarea w-full text-sm"
                                    placeholder="<?php echo e(t('Optional work description...')); ?>"></textarea>
                            </div>

                            <div>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="is_billable" id="edit-time-billable" value="1"
                                        class="rounded text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm"
                                        style="color: var(--text-secondary);"><?php echo e(t('Billable')); ?></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-panel-footer">
                        <button type="button" onclick="closeEditTimeModal()"
                            class="btn btn-secondary"><?php echo e(t('Cancel')); ?></button>
                        <button type="submit" name="update_time_entry"
                            class="btn btn-primary"><?php echo e(t('Save')); ?></button>
                    </div>
                </form>
            </div>
        </div>
<?php endif; ?>

<script>
    const ICONS = {
        'times': '<line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>',
        'file': '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline>',
        'file-image': '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline>',
        'file-pdf': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
        'file-word': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
        'file-excel': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
        'file-archive': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>'
    };

    function getIcon(name, classes = '') {
        const path = ICONS[name] || ICONS['file'];
        return `<svg xmlns="http://www.w3.org/2000/svg" class="${classes}" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${path}</svg>`;
    }

    // AJAX quick-edit for sidebar dropdowns (no page reload)
    function quickEditField(action, data) {
        var body = new FormData();
        body.append('ticket_id', '<?php echo (int)$ticket['id']; ?>');
        for (var key in data) {
            body.append(key, data[key]);
        }
        fetch(window.appConfig.apiUrl + '&action=' + action, {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': window.csrfToken},
            body: body
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                if (window.showAppToast) window.showAppToast(res.message || '<?php echo e(t('Saved')); ?>', 'success');
            } else {
                if (window.showAppToast) window.showAppToast(res.error || '<?php echo e(t('Error')); ?>', 'error');
            }
        })
        .catch(function() {
            if (window.showAppToast) window.showAppToast('<?php echo e(t('Error')); ?>', 'error');
        });
    }

    // Share link copy
    const shareCopyButton = document.getElementById('share-copy-btn');
    const shareLinkInput = document.getElementById('share-link-input');

    if (shareCopyButton && shareLinkInput) {
        shareCopyButton.addEventListener('click', async () => {
            const value = shareLinkInput.value;
            try {
                if (navigator.clipboard) {
                    await navigator.clipboard.writeText(value);
                } else {
                    shareLinkInput.select();
                    document.execCommand('copy');
                }
                shareCopyButton.textContent = '<?php echo e(t('Copied')); ?>';
                setTimeout(() => {
                    shareCopyButton.textContent = '<?php echo e(t('Copy')); ?>';
                }, 1500);
            } catch (err) {
                shareCopyButton.textContent = '<?php echo e(t('Error')); ?>';
                setTimeout(() => {
                    shareCopyButton.textContent = '<?php echo e(t('Copy')); ?>';
                }, 1500);
            }
        });
    }

    // Drag & Drop for comment attachments
    const commentFileInput = document.getElementById('comment-file-input');
    const commentFilePreview = document.getElementById('comment-file-preview');
    const removeFileLabel = '<?php echo e(t('Remove')); ?>';

    function updateCommentPreview() {
        commentFilePreview.innerHTML = '';

        if (commentFileInput.files.length === 0) {
            commentFilePreview.classList.add('hidden');
            return;
        }

        commentFilePreview.classList.remove('hidden');

        for (let i = 0; i < commentFileInput.files.length; i++) {
            const file = commentFileInput.files[i];
            const size = formatFileSize(file.size);

            const div = document.createElement('div');
            div.className = 'flex items-center justify-between rounded-lg px-4 py-2';
            div.style.background = 'var(--surface-secondary)';
            div.innerHTML = `
            <div class="flex items-center space-x-3 min-w-0">
                ${getIcon(getFileIcon(file.type), 'td-text-muted flex-shrink-0 w-4 h-4')}
                <span class="text-sm truncate" style="color: var(--text-secondary)">${file.name}</span>
                <span class="text-xs flex-shrink-0" style="color: var(--text-muted)">${size}</span>
            </div>
            <button type="button" onclick="removeCommentFile(${i})" class="text-red-400 hover:text-red-500 ml-2 flex-shrink-0" aria-label="${removeFileLabel}" title="${removeFileLabel}">
                ${getIcon('times', 'w-4 h-4')}
            </button>
        `;
            commentFilePreview.appendChild(div);
        }
    }

    function removeCommentFile(index) {
        const dt = new DataTransfer();
        for (let i = 0; i < commentFileInput.files.length; i++) {
            if (i !== index) {
                dt.items.add(commentFileInput.files[i]);
            }
        }
        commentFileInput.files = dt.files;
        updateCommentPreview();
    }

    function formatFileSize(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
        return bytes + ' B';
    }

    function getFileIcon(mimeType) {
        if (mimeType.startsWith('image/')) return 'file-image';
        if (mimeType === 'application/pdf') return 'file-pdf';
        if (mimeType.includes('word')) return 'file-word';
        if (mimeType.includes('excel') || mimeType.includes('spreadsheet')) return 'file-excel';
        if (mimeType.includes('zip') || mimeType.includes('rar')) return 'file-archive';
        return 'file';
    }

    const initTicketDetailDropzones = function () {
        if (window.initFileDropzone && commentFileInput) {
            window.initFileDropzone({
                zoneId: 'comment-upload-zone',
                inputId: 'comment-file-input',
                onFilesChanged: updateCommentPreview
            });
        } else if (commentFileInput) {
            commentFileInput.addEventListener('change', updateCommentPreview);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTicketDetailDropzones);
    } else {
        initTicketDetailDropzones();
    }

    // Manual time entry toggle
    const manualToggle = document.getElementById('manual-toggle');
    const manualEntryRow = document.getElementById('manual-entry-row');
    const manualStartInput = document.querySelector('input[name="manual_start"]');
    const manualEndInput = document.querySelector('input[name="manual_end"]');
    const manualDateInput = document.querySelector('input[name="manual_date"]');
    const manualStartTimeInput = document.querySelector('input[name="manual_start_time"]');
    const manualEndTimeInput = document.querySelector('input[name="manual_end_time"]');

    function pad2(n) {
        return String(n).padStart(2, '0');
    }

    function setManualDefaults() {
        const now = new Date();
        const today = now.getFullYear() + '-' + pad2(now.getMonth() + 1) + '-' + pad2(now.getDate());
        const currentTime = pad2(now.getHours()) + ':' + pad2(now.getMinutes());
        const plusTenMin = new Date(now.getTime() + (10 * 60 * 1000));
        const endTime = pad2(plusTenMin.getHours()) + ':' + pad2(plusTenMin.getMinutes());

        if (manualDateInput && !manualDateInput.value) {
            manualDateInput.value = today;
        }
        if (manualStartTimeInput && !manualStartTimeInput.value) {
            manualStartTimeInput.value = currentTime;
        }
        if (manualEndTimeInput && !manualEndTimeInput.value) {
            manualEndTimeInput.value = endTime;
        }
    }

    function setManualEntryVisible(show) {
        if (!manualEntryRow || !manualToggle) return;
        manualEntryRow.classList.toggle('hidden', !show);
        manualToggle.setAttribute('aria-expanded', show ? 'true' : 'false');
        if (show) {
            setManualDefaults();
        }
    }

    if (manualToggle && manualEntryRow) {
        manualToggle.addEventListener('click', () => {
            setManualEntryVisible(manualEntryRow.classList.contains('hidden'));
        });
    }

    if ((manualStartInput && manualStartInput.value) ||
        (manualEndInput && manualEndInput.value) ||
        (manualStartTimeInput && manualStartTimeInput.value) ||
        (manualEndTimeInput && manualEndTimeInput.value)) {
        setManualEntryVisible(true);
    }

    // CC Autocomplete
    const ccSearchInput = document.getElementById('cc-search-input');
    const ccDropdown = document.getElementById('cc-dropdown');
    const ccSelected = document.getElementById('cc-selected');
    const ccHiddenInputs = document.getElementById('cc-hidden-inputs');

    let selectedUsers = [];
    let searchTimeout = null;

    if (ccSearchInput) {
        ccSearchInput.addEventListener('input', function () {
            const query = this.value.trim();

            if (query.length < 2) {
                ccDropdown.classList.add('hidden');
                return;
            }

            // Debounce
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                searchUsers(query);
            }, 300);
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function (e) {
            if (!ccSearchInput.contains(e.target) && !ccDropdown.contains(e.target)) {
                ccDropdown.classList.add('hidden');
            }
        });
    }

    function searchUsers(query) {
        fetch('index.php?page=api&action=search_users&q=' + encodeURIComponent(query))
            .then(response => response.json())
            .then(users => {
                if (users.length === 0) {
                    ccDropdown.innerHTML = '<div class="px-3 py-2 text-sm" style="color: var(--text-muted)"><?php echo e(t('No users found.')); ?></div>';
                    ccDropdown.classList.remove('hidden');
                    return;
                }

                ccDropdown.innerHTML = '';
                users.forEach(user => {
                    // Skip if already selected
                    if (selectedUsers.find(u => u.id === user.id)) {
                        return;
                    }

                    const div = document.createElement('div');
                    div.className = 'px-3 py-2 cursor-pointer text-sm tr-hover';
                    div.innerHTML = '<strong>' + escapeHtml(user.name) + '</strong><br><span class="text-xs" style="color: var(--text-muted)">' + escapeHtml(user.email) + '</span>';
                    div.onclick = () => addCCUser(user);
                    ccDropdown.appendChild(div);
                });

                ccDropdown.classList.remove('hidden');
            })
            .catch(error => {
                console.error('Error searching users:', error);
            });
    }

    function addCCUser(user) {
        // Add to selected array
        selectedUsers.push(user);

        // Create chip
        const chip = document.createElement('span');
        chip.className = 'inline-flex items-center px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-sm';
        var nameSpan = document.createElement('span');
        nameSpan.textContent = user.name + ' ';
        chip.appendChild(nameSpan);
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'ml-2 hover:text-blue-900';
        removeBtn.setAttribute('aria-label', removeFileLabel);
        removeBtn.title = removeFileLabel;
        removeBtn.onclick = function() { removeCCUser(user.id); };
        removeBtn.innerHTML = getIcon('times', 'w-3 h-3');
        chip.appendChild(removeBtn);
        ccSelected.appendChild(chip);

        // Add hidden input
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'cc_users[]';
        input.value = user.id;
        input.id = 'cc-user-' + user.id;
        ccHiddenInputs.appendChild(input);

        // Clear search and hide dropdown
        ccSearchInput.value = '';
        ccDropdown.classList.add('hidden');
    }

    function removeCCUser(userId) {
        // Remove from array
        selectedUsers = selectedUsers.filter(u => u.id !== userId);

        // Remove chip (find and remove the parent span)
        const chip = event.target.closest('span');
        if (chip) chip.remove();

        // Remove hidden input
        const input = document.getElementById('cc-user-' + userId);
        if (input) input.remove();
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Update submit label when stopping timer or logging manual time
    const commentSubmitBtn = document.getElementById('comment-submit-btn');
    // manualStartInput and manualEndInput already declared above (lines ~1493-1494)

    // Exposed globally so timer IIFE can call it after DOM updates
    window.updateSubmitLabel = function () {
        if (!commentSubmitBtn) return;
        // Get fresh reference each time (checkbox may be recreated by AJAX)
        const stopTimerToggle = document.getElementById('stop-timer-toggle');
        const hasActiveTimer = commentSubmitBtn.dataset.hasActiveTimer === '1';
        const stopRequested = hasActiveTimer && stopTimerToggle && stopTimerToggle.checked;
        const hasManualTime =
            (manualStartInput && manualStartInput.value) ||
            (manualEndInput && manualEndInput.value) ||
            (manualStartTimeInput && manualStartTimeInput.value) ||
            (manualEndTimeInput && manualEndTimeInput.value);

        let label = commentSubmitBtn.dataset.defaultText;
        if (stopRequested) {
            label = commentSubmitBtn.dataset.stopText;
        } else if (hasManualTime) {
            label = commentSubmitBtn.dataset.logTimeText;
        }

        const labelSpan = commentSubmitBtn.querySelector('.btn-text');
        if (labelSpan) {
            labelSpan.textContent = label;
        }
    };

    // Attach listener to stop-timer-toggle (exposed so it can be re-called after AJAX)
    window.attachStopTimerToggleListener = function () {
        const stopTimerToggle = document.getElementById('stop-timer-toggle');
        if (stopTimerToggle) {
            stopTimerToggle.addEventListener('change', window.updateSubmitLabel);
        }
    };

    window.attachStopTimerToggleListener();
    if (manualStartInput) {
        manualStartInput.addEventListener('input', window.updateSubmitLabel);
    }
    if (manualEndInput) {
        manualEndInput.addEventListener('input', window.updateSubmitLabel);
    }
    if (manualStartTimeInput) {
        manualStartTimeInput.addEventListener('input', window.updateSubmitLabel);
    }
    if (manualEndTimeInput) {
        manualEndTimeInput.addEventListener('input', window.updateSubmitLabel);
    }
    window.updateSubmitLabel();

    // Comment mode toggle
    const modeButtons = document.querySelectorAll('.comment-mode-btn');
    const internalToggle = document.getElementById('is_internal_toggle');
    const internalSection = document.getElementById('internal-comment-section');
    const publicSection = document.getElementById('public-comment-section');
    const commentText = document.getElementById('comment-text');
    const internalText = document.getElementById('internal-text');
    const modeHint = document.getElementById('comment-mode-hint');

    function setCommentMode(mode) {
        const isInternal = mode === 'internal';

        if (internalToggle) internalToggle.checked = isInternal;
        if (internalSection) internalSection.classList.toggle('hidden', !isInternal);
        if (publicSection) publicSection.classList.toggle('hidden', isInternal);

        if (commentText) {
            if (isInternal) {
                commentText.removeAttribute('required');
            } else if (commentText.hasAttribute('data-required')) {
                commentText.setAttribute('required', 'required');
            }
        }
        if (internalText) {
            if (isInternal) {
                internalText.setAttribute('required', 'required');
            } else {
                internalText.removeAttribute('required');
            }
        }

        if (modeHint) {
            modeHint.textContent = isInternal ? '<?php echo e(t('Visible to agents only')); ?>' : '<?php echo e(t('Visible to customer')); ?>';
        }

        modeButtons.forEach(btn => {
            const active = btn.dataset.mode === mode;
            btn.classList.toggle('shadow', active);
            btn.classList.toggle('text-blue-600', active);
            if (active) {
                btn.style.background = 'var(--bg-primary)';
                btn.style.color = '';
            } else {
                btn.style.background = '';
                btn.style.color = 'var(--text-muted)';
            }
        });
    }

    if (modeButtons.length > 0) {
        modeButtons.forEach(btn => {
            btn.addEventListener('click', () => setCommentMode(btn.dataset.mode));
        });
        setCommentMode('public');
    }

    // ================================
    // AJAX Timer Controls (unified button approach)
    // ================================
    (function () {
        const timerControls = document.getElementById('timer-controls');
        if (!timerControls) return;

        const ticketId = timerControls.dataset.ticketId;
        const csrfToken = window.csrfToken;

        // SVG icon strings for dynamic updates
        const ICON_PLAY = '<?php echo get_icon('play', 'w-4 h-4'); ?>';
        const ICON_PAUSE = '<?php echo get_icon('pause', 'w-4 h-4'); ?>';
        const ICON_SPINNER = '<?php echo get_icon('spinner', 'w-4 h-4 animate-spin'); ?>';
        const ICON_PLAY_SM = '<?php echo get_icon('play', 'w-3.5 h-3.5'); ?>';
        const ICON_PAUSE_SM = '<?php echo get_icon('pause', 'w-3.5 h-3.5'); ?>';

        // Translated strings
        const STR = {
            start: '<?php echo e(t('Start timer')); ?>',
            starting: '<?php echo e(t('Starting...')); ?>',
            pause: '<?php echo e(t('Pause timer')); ?>',
            resume: '<?php echo e(t('Resume timer')); ?>',
            discard: '<?php echo e(t('Discard timer')); ?>',
            confirmDiscard: '<?php echo e(t('Discard this timer? The tracked time will be lost.')); ?>',
            paused: '<?php echo e(t('Paused')); ?>',
            started: '<?php echo e(t('Timer started.')); ?>',
            pausedMsg: '<?php echo e(t('Timer paused.')); ?>',
            resumedMsg: '<?php echo e(t('Timer resumed.')); ?>',
            discardedMsg: '<?php echo e(t('Timer discarded.')); ?>',
            failStart: '<?php echo e(t('Failed to start timer.')); ?>',
            failPause: '<?php echo e(t('Failed to pause timer.')); ?>',
            failResume: '<?php echo e(t('Failed to resume timer.')); ?>',
            failDiscard: '<?php echo e(t('Failed to discard timer.')); ?>',
            error: '<?php echo e(t('An error occurred.')); ?>'
        };

        // DOM references (stable — never replaced by innerHTML)
        const btnAction = document.getElementById('btn-timer-action');
        const btnIcon = btnAction ? btnAction.querySelector('.btn-timer-icon') : null;
        const btnText = btnAction ? btnAction.querySelector('.btn-timer-text') : null;
        const logToggle = document.getElementById('timer-log-toggle');
        const btnDiscard = document.getElementById('btn-discard-timer');

        // Timer state
        let timerInterval = null;
        let timerStartTime = null;
        let pausedSeconds = 0;
        let currentState = '<?php echo $timer_state; ?>'; // 'stopped' | 'running' | 'paused'
        let busy = false; // prevent double-clicks

        // ---- Initialize from server-rendered data ----
        const elapsedSpan = document.getElementById('timer-elapsed');
        if (elapsedSpan && elapsedSpan.dataset.started) {
            timerStartTime = parseInt(elapsedSpan.dataset.started);
            pausedSeconds = parseInt(elapsedSpan.dataset.pausedSeconds || '0');
        }

        // ---- Format seconds to display string ----
        function formatTime(totalSec) {
            if (totalSec < 0) totalSec = 0;
            const h = Math.floor(totalSec / 3600);
            const m = Math.floor((totalSec % 3600) / 60);
            const s = totalSec % 60;
            if (h > 0) return h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
            return m + ':' + String(s).padStart(2, '0');
        }

        // ---- Update the live counter (called every 1s when running) ----
        function tick() {
            if (currentState !== 'running' || !timerStartTime) return;
            const elapsed = Math.floor(Date.now() / 1000) - timerStartTime - pausedSeconds;
            const timeStr = formatTime(elapsed);

            // Update elapsed span
            const el = document.getElementById('timer-elapsed');
            if (el) el.textContent = timeStr;

            // Update toolbar timer elapsed
            const toolbarEl = document.getElementById('toolbar-timer-elapsed');
            if (toolbarEl) toolbarEl.textContent = timeStr;

            // Update browser tab title + favicon
            const favicon = document.getElementById('favicon');
            const faviconTimer = document.getElementById('favicon-timer');
            if (favicon && faviconTimer) favicon.href = faviconTimer.href;
            document.title = '\u23F1\uFE0F ' + timeStr + ' - ' + (window.originalPageTitle || document.title.replace(/^\u23F1\uFE0F.*? - /, ''));
        }

        // ---- Reset page title and favicon ----
        function resetPageTitle() {
            document.title = window.originalPageTitle || '<?php echo e($page_title ?? t("Dashboard")); ?> - <?php echo e($app_name); ?>';
            const favicon = document.getElementById('favicon');
            const customFavicon = '<?php echo e($settings['favicon'] ?? ''); ?>';
            if (favicon && customFavicon) {
                favicon.href = customFavicon;
            } else if (favicon) {
                const appName = window.appName || 'A';
                favicon.href = "data:image/svg+xml," + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="6" fill="#3b82f6"/><text x="16" y="22" font-family="Arial,sans-serif" font-size="18" font-weight="bold" fill="white" text-anchor="middle">' + appName.charAt(0).toUpperCase() + '</text></svg>');
            }
        }

        // ---- Toolbar timer helper ----
        function updateToolbarTimer(state, timeStr) {
            const toolbarBtn = document.getElementById('toolbar-timer-btn');
            if (!toolbarBtn) return;
            let toolbarElapsed = document.getElementById('toolbar-timer-elapsed');

            if (state === 'running') {
                toolbarBtn.className = 'td-tool-btn td-tool-btn--active-timer';
                toolbarBtn.title = STR.pause;
                toolbarBtn.textContent = '';
                toolbarBtn.insertAdjacentHTML('afterbegin', ICON_PAUSE_SM);
                if (!toolbarElapsed) {
                    toolbarElapsed = document.createElement('span');
                    toolbarElapsed.id = 'toolbar-timer-elapsed';
                    toolbarElapsed.className = 'text-xs tabular-nums';
                    toolbarBtn.parentNode.insertBefore(toolbarElapsed, toolbarBtn.nextSibling);
                }
                toolbarElapsed.style.color = 'var(--warning)';
                toolbarElapsed.textContent = timeStr || '';
            } else if (state === 'paused') {
                toolbarBtn.className = 'td-tool-btn';
                toolbarBtn.title = STR.resume;
                toolbarBtn.textContent = '';
                toolbarBtn.insertAdjacentHTML('afterbegin', ICON_PLAY_SM);
                if (!toolbarElapsed) {
                    toolbarElapsed = document.createElement('span');
                    toolbarElapsed.id = 'toolbar-timer-elapsed';
                    toolbarElapsed.className = 'text-xs tabular-nums';
                    toolbarBtn.parentNode.insertBefore(toolbarElapsed, toolbarBtn.nextSibling);
                }
                toolbarElapsed.style.color = 'var(--success)';
                toolbarElapsed.textContent = timeStr || '';
            } else {
                toolbarBtn.className = 'td-tool-btn';
                toolbarBtn.title = STR.start;
                toolbarBtn.textContent = '';
                toolbarBtn.insertAdjacentHTML('afterbegin', ICON_PLAY_SM);
                if (toolbarElapsed) toolbarElapsed.remove();
            }
        }

        // ---- Central state setter — updates DOM in-place, no innerHTML ----
        function setTimerState(state, opts = {}) {
            currentState = state;

            // Stop any running interval
            if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }

            if (state === 'running') {
                // Button: warning (pause action), pause icon
                btnAction.className = 'btn btn-warning px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors';
                btnAction.title = STR.pause;
                btnAction.dataset.state = 'running';
                btnIcon.innerHTML = ICON_PAUSE;

                // Show elapsed counter inside button text
                const elapsed = Math.floor(Date.now() / 1000) - timerStartTime - pausedSeconds;
                btnText.innerHTML = '<span id="timer-elapsed" class="tabular-nums" data-started="' + timerStartTime + '" data-paused-seconds="' + pausedSeconds + '">' + formatTime(elapsed) + '</span>';

                // Show auxiliary controls & enable stop_timer checkbox
                if (logToggle) logToggle.classList.remove('hidden');
                if (btnDiscard) btnDiscard.classList.remove('hidden');
                const stopCb1 = document.getElementById('stop-timer-toggle');
                if (stopCb1) { stopCb1.disabled = false; stopCb1.checked = true; }

                // Start live ticking
                timerInterval = setInterval(tick, 1000);

                // Submit button integration
                const submitBtn = document.getElementById('comment-submit-btn');
                if (submitBtn) submitBtn.dataset.hasActiveTimer = '1';

                // Toolbar timer
                updateToolbarTimer('running', formatTime(elapsed));

            } else if (state === 'paused') {
                const elapsedSec = opts.elapsedSeconds || 0;
                const elapsedMin = Math.floor(elapsedSec / 60);

                // Button: success (resume action), play icon
                btnAction.className = 'btn btn-success px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors';
                btnAction.title = STR.resume;
                btnAction.dataset.state = 'paused';
                btnIcon.innerHTML = ICON_PLAY;

                // Show frozen time + "Paused" label
                btnText.innerHTML = '<span id="timer-elapsed" class="tabular-nums" data-started="' + timerStartTime + '" data-paused-seconds="' + pausedSeconds + '">' + elapsedMin + ' min</span>'
                    + ' <span class="text-xs uppercase ml-1">' + STR.paused + '</span>';

                // Keep auxiliary controls visible & enable stop_timer checkbox
                if (logToggle) logToggle.classList.remove('hidden');
                if (btnDiscard) btnDiscard.classList.remove('hidden');
                const stopCb2 = document.getElementById('stop-timer-toggle');
                if (stopCb2) { stopCb2.disabled = false; stopCb2.checked = true; }

                // Reset tab title (timer not actively ticking)
                resetPageTitle();

                // Toolbar timer
                updateToolbarTimer('paused', elapsedMin + ' min');

            } else { // stopped
                // Button: success (start action), play icon + text
                btnAction.className = 'btn btn-success px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors';
                btnAction.title = STR.start;
                btnAction.dataset.state = 'stopped';
                btnIcon.innerHTML = ICON_PLAY;
                btnText.textContent = STR.start;

                // Hide auxiliary controls & disable stop_timer checkbox
                if (logToggle) logToggle.classList.add('hidden');
                if (btnDiscard) btnDiscard.classList.add('hidden');
                const stopCb3 = document.getElementById('stop-timer-toggle');
                if (stopCb3) { stopCb3.disabled = true; stopCb3.checked = false; }

                // Reset state
                timerStartTime = null;
                pausedSeconds = 0;
                resetPageTitle();

                // Submit button integration
                const submitBtn = document.getElementById('comment-submit-btn');
                if (submitBtn) submitBtn.dataset.hasActiveTimer = '0';

                // Toolbar timer
                updateToolbarTimer('stopped');
            }

            // Re-wire the checkbox listener & update submit label
            if (window.attachStopTimerToggleListener) window.attachStopTimerToggleListener();
            if (window.updateSubmitLabel) window.updateSubmitLabel();

            // Re-enable button
            btnAction.disabled = false;
            if (btnDiscard) btnDiscard.disabled = false;
        }

        // ---- AJAX helper ----
        async function timerAction(action) {
            const formData = new FormData();
            formData.append('ticket_id', ticketId);
            formData.append('csrf_token', csrfToken);
            const response = await fetch('index.php?page=api&action=' + action, { method: 'POST', body: formData });
            return response.json();
        }

        // ---- Unified button click handler ----
        async function onTimerActionClick() {
            if (busy) return;
            const state = currentState;

            if (state === 'stopped') {
                // Start timer
                busy = true;
                btnAction.disabled = true;
                btnIcon.innerHTML = ICON_SPINNER;
                btnText.textContent = STR.starting;

                try {
                    const data = await timerAction('start-timer');
                    if (data.success) {
                        timerStartTime = Math.floor(Date.now() / 1000);
                        pausedSeconds = 0;
                        setTimerState('running');
                        selfDispatch = true; document.dispatchEvent(new CustomEvent('timerStateChanged')); selfDispatch = false;
                        showToast(data.message || STR.started, 'success');
                    } else {
                        showToast(data.error || STR.failStart, 'error');
                        setTimerState('stopped');
                    }
                } catch (e) {
                    console.error('Timer start error:', e);
                    showToast(STR.error, 'error');
                    setTimerState('stopped');
                }
                busy = false;

            } else if (state === 'running') {
                // Pause timer
                busy = true;
                btnAction.disabled = true;

                try {
                    const data = await timerAction('pause-timer');
                    if (data.success) {
                        setTimerState('paused', { elapsedSeconds: data.elapsed_seconds || 0 });
                        selfDispatch = true; document.dispatchEvent(new CustomEvent('timerStateChanged')); selfDispatch = false;
                        showToast(data.message || STR.pausedMsg, 'success');
                    } else {
                        showToast(data.error || STR.failPause, 'error');
                        btnAction.disabled = false;
                    }
                } catch (e) {
                    console.error('Timer pause error:', e);
                    showToast(STR.error, 'error');
                    btnAction.disabled = false;
                }
                busy = false;

            } else if (state === 'paused') {
                // Resume timer
                busy = true;
                btnAction.disabled = true;

                try {
                    const data = await timerAction('resume-timer');
                    if (data.success) {
                        // Use server-returned paused_seconds (accumulated correctly)
                        pausedSeconds = data.paused_seconds || pausedSeconds;
                        setTimerState('running');
                        selfDispatch = true; document.dispatchEvent(new CustomEvent('timerStateChanged')); selfDispatch = false;
                        showToast(data.message || STR.resumedMsg, 'success');
                    } else {
                        showToast(data.error || STR.failResume, 'error');
                        btnAction.disabled = false;
                    }
                } catch (e) {
                    console.error('Timer resume error:', e);
                    showToast(STR.error, 'error');
                    btnAction.disabled = false;
                }
                busy = false;
            }
        }

        // ---- Discard handler ----
        async function onDiscardClick() {
            if (busy) return;
            if (!confirm(STR.confirmDiscard)) return;

            busy = true;
            if (btnDiscard) btnDiscard.disabled = true;

            try {
                const data = await timerAction('discard-timer');
                if (data.success) {
                    setTimerState('stopped');
                    selfDispatch = true; document.dispatchEvent(new CustomEvent('timerStateChanged')); selfDispatch = false;
                    showToast(data.message || STR.discardedMsg, 'success');
                } else {
                    showToast(data.error || STR.failDiscard, 'error');
                    if (btnDiscard) btnDiscard.disabled = false;
                }
            } catch (e) {
                console.error('Timer discard error:', e);
                showToast(STR.error, 'error');
                if (btnDiscard) btnDiscard.disabled = false;
            }
            busy = false;
        }

        // ---- Attach event listeners (once — no re-attachment needed) ----
        if (btnAction) btnAction.addEventListener('click', onTimerActionClick);
        if (btnDiscard) btnDiscard.addEventListener('click', onDiscardClick);
        const toolbarTimerBtn = document.getElementById('toolbar-timer-btn');
        if (toolbarTimerBtn) toolbarTimerBtn.addEventListener('click', onTimerActionClick);

        // ---- Sync timer state when sidebar changes it ----
        let selfDispatch = false;
        document.addEventListener('timerStateChanged', async function() {
            if (selfDispatch) return; // ignore our own dispatches
            try {
                const r = await fetch('index.php?page=api&action=get_active_timers');
                const data = await r.json();
                if (!data.success) return;
                const mine = (data.timers || []).find(t => t.ticket_id == ticketId);
                if (mine) {
                    if (mine.is_paused) {
                        const elapsed = mine.elapsed_minutes * 60;
                        pausedSeconds = mine.paused_seconds || 0;
                        timerStartTime = mine.started_at;
                        setTimerState('paused', { elapsedSeconds: elapsed });
                    } else {
                        timerStartTime = mine.started_at;
                        pausedSeconds = mine.paused_seconds || 0;
                        setTimerState('running');
                    }
                } else {
                    // Timer for this ticket no longer active (stopped/discarded)
                    if (currentState !== 'stopped') {
                        setTimerState('stopped');
                    }
                }
            } catch (e) {
                // ignore fetch errors
            }
        });

        // ---- Start ticking if timer was running on page load ----
        if (currentState === 'running') {
            timerInterval = setInterval(tick, 1000);
        }

        // ---- Toast notification ----
        function showToast(message, type = 'success') {
            if (typeof window.showAppToast === 'function') {
                if (window.showAppToast(message, type)) return;
            }
            if (window.appNotificationPrefs && window.appNotificationPrefs.inAppEnabled === false) return;

            const toast = document.createElement('div');
            toast.className = `fixed bottom-4 right-4 px-4 py-2 rounded-lg shadow-lg text-sm font-medium z-50 transition-opacity duration-300 ${type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white'}`;
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
        }
    })();

    // Agent CC Dropdown (custom multi-select with translated text)
    (function () {
        const toggle = document.getElementById('agent-cc-toggle');
        const list = document.getElementById('agent-cc-list');
        const display = document.getElementById('agent-cc-display');
        const checkboxes = document.querySelectorAll('.agent-cc-checkbox');

        if (!toggle || !list || !display) return;

        const noneText = toggle.dataset.noneText || 'Select users...';
        const selectedText = toggle.dataset.selectedText || 'Selected';

        function updateDisplay() {
            const checked = document.querySelectorAll('.agent-cc-checkbox:checked');
            if (checked.length === 0) {
                display.textContent = noneText;
            } else {
                display.textContent = selectedText + ': ' + checked.length;
            }
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            list.classList.toggle('hidden');
        });

        checkboxes.forEach(cb => {
            cb.addEventListener('change', updateDisplay);
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#agent-cc-dropdown-container')) {
                list.classList.add('hidden');
            }
        });

        updateDisplay();
    })();

    // ================================
    // Comment Edit/Delete Functions
    // ================================
    let editCommentEditor = null;

    function openEditCommentModal(commentId, content) {
        const modal = document.getElementById('edit-comment-modal');
        const idInput = document.getElementById('edit-comment-id');
        const editorEl = document.getElementById('edit-comment-editor');

        if (modal && idInput && editorEl) {
            idInput.value = commentId;
            modal.classList.remove('hidden');

            // Initialize Quill editor if not already done
            if (!editCommentEditor) {
                editCommentEditor = new Quill('#edit-comment-editor', {
                    theme: 'snow',
                    placeholder: '<?php echo e(t('Edit your comment...')); ?>',
                    modules: {
                        toolbar: [
                            [{ 'header': [1, 2, 3, false] }],
                            ['bold', 'italic', 'underline', 'strike'],
                            [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                            ['link'],
                            ['clean']
                        ]
                    }
                });
            }

            // Set content - handle both HTML and plain text
            if (content.includes('<') && content.includes('>')) {
                editCommentEditor.root.innerHTML = content;
            } else {
                editCommentEditor.setText(content);
            }

            // Focus the editor
            setTimeout(() => editCommentEditor.focus(), 100);
        }
    }

    function closeEditCommentModal() {
        const modal = document.getElementById('edit-comment-modal');
        if (modal) {
            modal.classList.add('hidden');
        }
        // Clear editor content
        if (editCommentEditor) {
            editCommentEditor.setText('');
        }
    }

    async function submitEditComment(event) {
        event.preventDefault();

        const form = event.target;
        const commentId = form.querySelector('#edit-comment-id').value;

        // Get content from Quill editor
        let content = '';
        if (editCommentEditor) {
            const html = editCommentEditor.root.innerHTML;
            content = (html === '<p><br></p>' || html === '<p></p>') ? '' : html;
        }

        if (!content) {
            alert('<?php echo e(t("Comment cannot be empty.")); ?>');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('comment_id', commentId);
            formData.append('content', content);
            formData.append('csrf_token', window.csrfToken);

            const response = await fetch('index.php?page=api&action=edit-comment', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                // Update the comment content in the DOM
                const contentDiv = document.getElementById('comment-content-' + commentId);
                if (contentDiv) {
                    contentDiv.innerHTML = data.content_html;
                }

                <?php if ($can_view_edit_history): ?>
                        // Add edited indicator if not already present
                        const commentEl = document.getElementById('comment-' + commentId);
                        if (commentEl && !commentEl.querySelector('.edited-indicator')) {
                            const timestampSpan = commentEl.querySelector('.text-sm[style*="--text-muted"]');
                            if (timestampSpan) {
                                const editedSpan = document.createElement('span');
                                editedSpan.className = 'text-xs italic edited-indicator ml-1';
                                editedSpan.style.color = 'var(--text-muted)';
                                editedSpan.textContent = '(<?php echo e(t("edited")); ?>)';
                                timestampSpan.parentNode.insertBefore(editedSpan, timestampSpan.nextSibling);
                            }
                        }
                <?php endif; ?>

                closeEditCommentModal();
                showToastGlobal(data.message || '<?php echo e(t("Comment updated.")); ?>', 'success');
            } else {
                alert(data.error || '<?php echo e(t("Failed to update comment.")); ?>');
            }
        } catch (error) {
            console.error('Edit comment error:', error);
            alert('<?php echo e(t("An error occurred.")); ?>');
        }
    }

    async function deleteComment(commentId) {
        if (!confirm('<?php echo e(t("Are you sure you want to delete this comment?")); ?>')) {
            return;
        }

        try {
            const formData = new FormData();
            formData.append('comment_id', commentId);
            formData.append('csrf_token', window.csrfToken);

            const response = await fetch('index.php?page=api&action=delete-comment', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                // Remove the comment from the DOM
                const commentEl = document.getElementById('comment-' + commentId);
                if (commentEl) {
                    commentEl.style.opacity = '0';
                    commentEl.style.transition = 'opacity 0.3s';
                    setTimeout(() => commentEl.remove(), 300);
                }

                showToastGlobal(data.message || '<?php echo e(t("Comment deleted.")); ?>', 'success');
            } else {
                alert(data.error || '<?php echo e(t("Failed to delete comment.")); ?>');
            }
        } catch (error) {
            console.error('Delete comment error:', error);
            alert('<?php echo e(t("An error occurred.")); ?>');
        }
    }

    // Global toast function (reusable)
    function showToastGlobal(message, type = 'success') {
        if (typeof window.showAppToast === 'function') {
            const shown = window.showAppToast(message, type);
            if (shown) {
                return;
            }
        }
        showToast(message, type);
    }

    // Close modal with Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeEditCommentModal();
            closeEditTimeModal();
        }
    });

    // ================================
    // Edit Time Entry Functions
    // ================================
    function openEditTimeEntry(entry) {
        const modal = document.getElementById('edit-time-modal');
        if (!modal) return;

        document.getElementById('edit-time-id').value = entry.id;
        document.getElementById('edit-time-summary').value = entry.summary || '';
        document.getElementById('edit-time-billable').checked = entry.is_billable == 1;

        const pad = n => n.toString().padStart(2, '0');

        // Parse and split into date + time fields
        if (entry.started_at) {
            const start = new Date(entry.started_at.replace(' ', 'T'));
            document.getElementById('edit-time-date-picker').value =
                start.getFullYear() + '-' + pad(start.getMonth() + 1) + '-' + pad(start.getDate());
            document.getElementById('edit-time-start-time').value =
                pad(start.getHours()) + ':' + pad(start.getMinutes());
        }
        if (entry.ended_at) {
            const end = new Date(entry.ended_at.replace(' ', 'T'));
            document.getElementById('edit-time-end-time').value =
                pad(end.getHours()) + ':' + pad(end.getMinutes());
        }

        syncEditTimeHiddenFields();
        updateTimeDuration();
        modal.classList.remove('hidden');
    }

    function closeEditTimeModal() {
        const modal = document.getElementById('edit-time-modal');
        if (modal) {
            modal.classList.add('hidden');
        }
    }

    // Sync split date+time pickers → hidden datetime-local inputs for form submission
    function syncEditTimeHiddenFields() {
        const dateVal = document.getElementById('edit-time-date-picker')?.value || '';
        const startTime = document.getElementById('edit-time-start-time')?.value || '';
        const endTime = document.getElementById('edit-time-end-time')?.value || '';

        if (dateVal && startTime) {
            document.getElementById('edit-time-start').value = dateVal + 'T' + startTime;
        }
        if (dateVal && endTime) {
            document.getElementById('edit-time-end').value = dateVal + 'T' + endTime;
        }
    }

    function updateTimeDuration() {
        syncEditTimeHiddenFields();
        const startInput = document.getElementById('edit-time-start');
        const endInput = document.getElementById('edit-time-end');
        const durationDiv = document.getElementById('edit-time-duration');

        if (!startInput || !endInput || !durationDiv) return;

        const start = new Date(startInput.value);
        const end = new Date(endInput.value);

        if (start && end && end > start) {
            const diffMs = end - start;
            const diffMins = Math.floor(diffMs / 60000);
            const hours = Math.floor(diffMins / 60);
            const mins = diffMins % 60;

            if (hours > 0) {
                durationDiv.textContent = hours + 'h ' + mins + 'min';
            } else {
                durationDiv.textContent = mins + ' min';
            }
            durationDiv.classList.remove('text-red-600');
            durationDiv.classList.add('text-blue-600');
        } else {
            durationDiv.textContent = '<?php echo e(t("Invalid range")); ?>';
            durationDiv.classList.remove('text-blue-600');
            durationDiv.classList.add('text-red-600');
        }
    }

    // Attach listeners for duration calculation on all three split inputs
    const editTimeDatePicker = document.getElementById('edit-time-date-picker');
    const editTimeStartTime = document.getElementById('edit-time-start-time');
    const editTimeEndTime = document.getElementById('edit-time-end-time');
    if (editTimeDatePicker) editTimeDatePicker.addEventListener('change', updateTimeDuration);
    if (editTimeStartTime) editTimeStartTime.addEventListener('change', updateTimeDuration);
    if (editTimeEndTime) editTimeEndTime.addEventListener('change', updateTimeDuration);

    // Ensure hidden fields are synced before form submission
    const editTimeForm = document.getElementById('edit-time-form');
    if (editTimeForm) {
        editTimeForm.addEventListener('submit', function () {
            syncEditTimeHiddenFields();
        });
    }
</script>

<!-- Tag inline editing -->
<?php if ($tags_supported && can_edit_ticket($ticket, $user)): ?>
<script src="assets/js/chip-select.js"></script>
<script>
(function () {
    var editBtn   = document.getElementById('sidebar-tags-edit-btn');
    var display   = document.getElementById('sidebar-tags-display');
    var editor    = document.getElementById('sidebar-tags-editor');
    var saveBtn   = document.getElementById('sidebar-tags-save');
    var cancelBtn = document.getElementById('sidebar-tags-cancel');
    if (!editBtn || !editor) return;

    var csTagsDetail    = null;
    var tagItemsLoaded  = false;
    var currentTags     = <?php echo json_encode($ticket_tags); ?>;
    var ticketId        = <?php echo json_encode($ticket_id); ?>;
    var csrfToken       = document.querySelector('input[name="csrf_token"]')
                          ? document.querySelector('input[name="csrf_token"]').value : '';
    var filterUrlBase   = <?php echo json_encode(url('tickets', !empty($ticket['is_archived']) ? ['archived' => '1'] : [])); ?>;

    function initChipSelect(allTags) {
        csTagsDetail = new ChipSelect({
            wrapId:      'cs-tags-detail-wrap',
            chipsId:     'cs-tags-detail-chips',
            inputId:     'cs-tags-detail-input',
            dropdownId:  'cs-tags-detail-dropdown',
            hiddenId:    'cs-tags-detail-hidden',
            items:       allTags,
            selected:    currentTags.slice(),
            name:        'tags[]',
            allowCreate: true,
            noMatchText: <?php echo json_encode(t('No matches')); ?>
        });
    }

    function rebuildChipSelect(allTags) {
        // Clear existing chips
        document.getElementById('cs-tags-detail-chips').innerHTML = '';
        document.getElementById('cs-tags-detail-hidden').innerHTML = '';
        initChipSelect(allTags);
    }

    editBtn.addEventListener('click', function () {
        if (!tagItemsLoaded) {
            fetch('index.php?page=api&action=get-tags')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    tagItemsLoaded = true;
                    var allTags = (data && data.tags) ? data.tags : [];
                    initChipSelect(allTags);
                    showEditor();
                });
        } else {
            rebuildChipSelect(csTagsDetail ? csTagsDetail.items : []);
            showEditor();
        }
    });

    function showEditor() {
        display.classList.add('hidden');
        editBtn.classList.add('hidden');
        editor.classList.remove('hidden');
    }

    function hideEditor() {
        editor.classList.add('hidden');
        display.classList.remove('hidden');
        editBtn.classList.remove('hidden');
    }

    cancelBtn.addEventListener('click', hideEditor);

    saveBtn.addEventListener('click', function () {
        if (!csTagsDetail) return;
        var tags = csTagsDetail.getSelectedValues().join(', ');
        saveBtn.disabled = true;

        var formData = new FormData();
        formData.append('ticket_id', ticketId);
        formData.append('tags', tags);
        formData.append('csrf_token', csrfToken);

        fetch('index.php?page=api&action=update-tags', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            saveBtn.disabled = false;
            if (data && data.success) {
                currentTags = data.tags || [];
                // Rebuild display pills
                var html = '';
                currentTags.forEach(function (tag) {
                    html += '<a href="' + filterUrlBase + '&tags=' + encodeURIComponent(tag)
                          + '" class="ticket-tag-pill" title="' + _escHtml(<?php echo json_encode(t('Filter by this tag')); ?>) + '">'
                          + '#' + _escHtml(tag) + '</a>';
                });
                if (!html) html = '<span class="text-xs" style="color: var(--text-muted);">—</span>';
                display.innerHTML = html;
                hideEditor();
            }
        })
        .catch(function () { saveBtn.disabled = false; });
    });
})();
</script>
<?php endif; ?>

<!-- Quill Editor JS (1.3.7 stable) -->
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script src="assets/js/quill-image-upload.js?v=<?php echo APP_VERSION; ?>"></script>
<script>
    // Quill toolbar configuration
    const quillToolbar = [
        [{ 'header': [1, 2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
        ['link', 'image'],
        ['clean']
    ];

    // Initialize Comment Editor
    let commentEditor = null;
    const commentEditorEl = document.getElementById('comment-editor');
    var _quillUploadOpts = {
        uploadUrl: window.appConfig ? window.appConfig.apiUrl + '&action=upload' : 'index.php?page=api&action=upload',
        csrfToken: window.csrfToken || '',
        ticketId: <?php echo (int) $ticket_id; ?>
    };

    if (commentEditorEl) {
        commentEditor = new Quill('#comment-editor', {
            theme: 'snow',
            placeholder: '<?php echo e(t('Write a reply...')); ?>',
            modules: { toolbar: quillToolbar }
        });
        if (window.initQuillImageUpload) initQuillImageUpload(commentEditor, _quillUploadOpts);
    }

    // Initialize Internal Note Editor (for agents)
    let internalEditor = null;
    const internalEditorEl = document.getElementById('internal-editor');
    if (internalEditorEl) {
        internalEditor = new Quill('#internal-editor', {
            theme: 'snow',
            placeholder: '<?php echo e(t('Internal note for agents...')); ?>',
            modules: { toolbar: quillToolbar }
        });
        if (window.initQuillImageUpload) initQuillImageUpload(internalEditor, _quillUploadOpts);
    }

    // Sync Quill content to hidden inputs on form submit
    const commentForm = document.getElementById('comment-form');
    if (commentForm) {
        commentForm.addEventListener('submit', function (e) {
            // Get the active editor based on comment mode
            const isInternal = document.getElementById('is_internal_toggle')?.checked;

            if (isInternal && internalEditor) {
                const html = internalEditor.root.innerHTML;
                document.getElementById('internal-text').value = (html === '<p><br></p>' || html === '<p></p>') ? '' : html;
                document.getElementById('comment-text').value = '';
            } else if (commentEditor) {
                const html = commentEditor.root.innerHTML;
                document.getElementById('comment-text').value = (html === '<p><br></p>' || html === '<p></p>') ? '' : html;
                if (internalEditor) {
                    document.getElementById('internal-text').value = '';
                }
            }

            // Prevent duplicate time entries: if stopping timer, clear manual fields
            const stopCb = document.getElementById('stop-timer-toggle');
            if (stopCb && stopCb.checked && !stopCb.disabled) {
                const mst = document.querySelector('input[name="manual_start_time"]');
                const met = document.querySelector('input[name="manual_end_time"]');
                if (mst) mst.value = '';
                if (met) met.value = '';
            }
        });
    }

    // Clear editors when switching between public/internal modes
    const commentModeButtons = document.querySelectorAll('.comment-mode-btn');
    commentModeButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const mode = this.dataset.mode;
            // Clear both editors when switching
            if (commentEditor) commentEditor.setText('');
            if (internalEditor) internalEditor.setText('');
        });
    });

    // ================================
    // Edit Ticket Modal Functions
    // ================================
    let editDescriptionEditor = null;

    var _editTicketReturnFocus = null;

    function openEditTicketModal() {
        _editTicketReturnFocus = document.activeElement;
        const modal = document.getElementById('edit-ticket-modal');
        if (!modal) return;

        modal.classList.remove('hidden');

        // Initialize Quill editor for description if not already done
        if (!editDescriptionEditor) {
            const editorEl = document.getElementById('edit-description-editor');
            if (editorEl) {
                editDescriptionEditor = new Quill('#edit-description-editor', {
                    theme: 'snow',
                    placeholder: '<?php echo e(t('Description...')); ?>',
                    modules: { toolbar: quillToolbar }
                });
                if (window.initQuillImageUpload) initQuillImageUpload(editDescriptionEditor, _quillUploadOpts);

                // Load existing content
                const existingContent = document.getElementById('edit-description-input').value;
                if (existingContent) {
                    editDescriptionEditor.clipboard.dangerouslyPasteHTML(existingContent);
                }
            }
        }

        // Focus first input inside modal
        var firstInput = modal.querySelector('input:not([type="hidden"]), select, textarea');
        if (firstInput) firstInput.focus();
        if (typeof trapFocus === 'function') trapFocus(modal);
    }

    function closeEditTicketModal() {
        const modal = document.getElementById('edit-ticket-modal');
        if (modal) {
            if (typeof releaseFocus === 'function') releaseFocus(modal);
            modal.classList.add('hidden');
        }
        if (_editTicketReturnFocus) { _editTicketReturnFocus.focus(); _editTicketReturnFocus = null; }
    }

    // Sync edit description editor to hidden input on form submit
    const editTicketForm = document.getElementById('edit-ticket-form');
    if (editTicketForm) {
        editTicketForm.addEventListener('submit', function (e) {
            if (editDescriptionEditor) {
                const html = editDescriptionEditor.root.innerHTML;
                document.getElementById('edit-description-input').value =
                    (html === '<p><br></p>' || html === '<p></p>') ? '' : html;
            }
        });
    }
</script>

<!-- Autosave for comment editor -->
<script src="assets/js/autosave.js"></script>
<script>
(function() {
    var ticketId = <?php echo (int)$ticket['id']; ?>;

    // Autosave comment editor draft
    if (typeof FoxDeskAutosave !== 'undefined' && typeof commentEditor !== 'undefined' && commentEditor) {
        var commentDraft = FoxDeskAutosave.create({
            key: 'foxdesk_draft_comment_' + ticketId,
            formSelector: '#comment-form',
            quillEditors: {comment: commentEditor},
            fields: [
                {name: 'comment', type: 'quill', editorKey: 'comment', selector: '#comment-text'}
            ],
            onRestore: function(relTime) {
                if (window.showAppToast) window.showAppToast('<?php echo e(t('Draft restored')); ?> (' + relTime + ')', 'info');
            }
        });
        commentDraft.init();
    }
})();
</script>

<?php if (function_exists('can_view_timeline') && can_view_timeline($user)): ?>
<!-- Timeline Modal -->
<div id="timeline-overlay" onclick="closeTimeline()" style="display:none; position:fixed; inset:0; z-index:50; background:rgba(0,0,0,0.5); backdrop-filter:blur(2px);">
    <div onclick="event.stopPropagation()" style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:100%; max-width:640px; max-height:85vh; border-radius:12px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); display:flex; flex-direction:column; background:var(--surface-primary); color:var(--text-primary);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--border-light);">
            <h2 style="font-size:16px; font-weight:600; display:flex; align-items:center; gap:8px;">
                <?php echo get_icon('history', 'w-5 h-5'); ?>
                <?php echo e(t('Activity Timeline')); ?>
            </h2>
            <button onclick="closeTimeline()" style="width:28px; height:28px; display:flex; align-items:center; justify-content:center; border-radius:6px; border:none; cursor:pointer; background:var(--surface-secondary); color:var(--text-muted);">
                &times;
            </button>
        </div>
        <div id="timeline-content" style="overflow-y:auto; padding:20px; flex:1; min-height:200px;">
            <div style="text-align:center; padding:40px 0; color:var(--text-muted);"><?php echo e(t('Loading...')); ?></div>
        </div>
    </div>
</div>

<style>
.tl-event { position:relative; padding-left:32px; padding-bottom:20px; }
.tl-event:last-child { padding-bottom:0; }
.tl-event::before { content:''; position:absolute; left:11px; top:22px; bottom:0; width:1px; background:var(--border-light); }
.tl-event:last-child::before { display:none; }
.tl-dot { position:absolute; left:6px; top:6px; width:12px; height:12px; border-radius:50%; border:2px solid; background:var(--surface-primary); }
.tl-time { font-size:11px; color:var(--text-muted); }
.tl-user { font-size:12px; font-weight:600; }
.tl-label { font-size:13px; }
.tl-detail { font-size:12px; color:var(--text-muted); margin-top:2px; }
.tl-change { font-size:12px; margin-top:4px; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.tl-old { text-decoration:line-through; color:var(--text-muted); opacity:0.7; }
.tl-new { font-weight:600; }
.tl-arrow { color:var(--text-muted); font-size:10px; }
</style>

<script>
function openTicketTimeline(ticketId) {
    var overlay = document.getElementById('timeline-overlay');
    var content = document.getElementById('timeline-content');
    overlay.style.display = '';
    content.innerHTML = '<div style="text-align:center;padding:40px 0;color:var(--text-muted);"><?php echo e(t('Loading...')); ?></div>';
    document.body.style.overflow = 'hidden';

    fetch('index.php?page=api&action=get-timeline&ticket_id=' + ticketId, {
        headers: {'X-CSRF-TOKEN': window.csrfToken}
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success || !data.events || !data.events.length) {
            content.innerHTML = '<div style="text-align:center;padding:40px 0;color:var(--text-muted);"><?php echo e(t('No activity found')); ?></div>';
            return;
        }
        var html = '';
        data.events.forEach(function(ev) {
            html += '<div class="tl-event">';
            html += '<div class="tl-dot" style="border-color:' + escTlHtml(ev.color) + ';"></div>';
            html += '<div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;">';
            html += '<div><span class="tl-user">' + escTlHtml(ev.user_name) + '</span> ';
            html += '<span class="tl-label">' + escTlHtml(ev.label) + '</span></div>';
            html += '<span class="tl-time">' + formatTlDate(ev.timestamp) + '</span>';
            html += '</div>';
            if (ev.type === 'change' && (ev.old_value || ev.new_value)) {
                html += '<div class="tl-change">';
                if (ev.old_value) html += '<span class="tl-old">' + ev.old_value + '</span>';
                html += '<span class="tl-arrow">→</span>';
                if (ev.new_value) html += '<span class="tl-new">' + ev.new_value + '</span>';
                html += '</div>';
            }
            if (ev.detail) {
                html += '<div class="tl-detail">' + escTlHtml(ev.detail) + '</div>';
            }
            html += '</div>';
        });
        content.innerHTML = html;
    })
    .catch(function() {
        content.innerHTML = '<div style="text-align:center;padding:40px 0;color:var(--text-muted);"><?php echo e(t('Error loading timeline')); ?></div>';
    });
}

function closeTimeline() {
    document.getElementById('timeline-overlay').style.display = 'none';
    document.body.style.overflow = '';
}

function escTlHtml(str) {
    if (!str) return '';
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function formatTlDate(ts) {
    if (!ts) return '';
    var d = new Date(ts.replace(' ', 'T'));
    var now = new Date();
    var pad = function(n) { return n < 10 ? '0'+n : n; };
    var time = pad(d.getHours()) + ':' + pad(d.getMinutes());
    if (d.toDateString() === now.toDateString()) return time;
    return pad(d.getDate()) + '.' + pad(d.getMonth()+1) + '. ' + time;
}

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('timeline-overlay').style.display !== 'none') {
        closeTimeline();
    }
});
</script>
<?php endif; ?>

<?php require_once BASE_PATH . '/includes/footer.php';
