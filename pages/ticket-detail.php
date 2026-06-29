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
$ticket_detail_context = ticket_detail_context($ticket_id, $ticket, $user, $_SESSION);
$all_comments = $ticket_detail_context['all_comments'];
$attachments = $ticket_detail_context['attachments'];
$statuses = $ticket_detail_context['statuses'];
$tags_supported = $ticket_detail_context['tags_supported'];
$organizations = $ticket_detail_context['organizations'];
$ticket_tags = $ticket_detail_context['ticket_tags'];
$ticket_tag_filter_url = static function ($tag_value) use ($ticket) {
    return ticket_detail_tag_filter_url($ticket, (string) $tag_value);
};
$all_users = $ticket_detail_context['all_users']; // For CC selection
$ticket_share_state = $ticket_detail_context['share_state'];
$shared_users = $ticket_share_state['shared_users'];
$shared_user_ids = $ticket_share_state['shared_user_ids'];
$share_status = $ticket_share_state['share_status'];
$share_url = $ticket_share_state['share_url'];
$share_status_label = $ticket_share_state['share_status_label'];
$share_status_class = $ticket_share_state['share_status_class'];
$ticket_creator_name = trim((string) (($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? '')));
if ($ticket_creator_name === '') {
    $ticket_creator_name = (string) ($ticket['email'] ?? t('User'));
}
$ticket_creator_initial = mb_strtoupper(mb_substr($ticket_creator_name, 0, 1));
if ($ticket_creator_initial === '') {
    $ticket_creator_initial = '?';
}

// Time tracking state
$time_tracking_available = ticket_time_table_exists();
$active_timer = null;
$active_timer_elapsed = 0;
$timer_is_paused = false;
$time_breakdown = $time_tracking_available ? get_ticket_time_breakdown($ticket_id) : ['total' => 0, 'human' => 0, 'ai' => 0];
$total_time_minutes = $time_breakdown['total'];
$org_billable_rate = 0.0;
$ticket_custom_billable_rate = function_exists('get_ticket_custom_billable_rate') ? get_ticket_custom_billable_rate($ticket) : null;
$ticket_effective_billable_rate = function_exists('get_ticket_effective_billable_rate') ? get_ticket_effective_billable_rate($ticket) : 0.0;
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
$ticket_primary_actions = ticket_detail_primary_actions($ticket, $user, $statuses, [
    'time_tracking_available' => $time_tracking_available,
    'timer_state' => $timer_state,
]);

$comments = ticket_detail_visible_comments($all_comments, is_agent());
$visible_comment_ids = ticket_detail_visible_comment_ids($comments);
$attachment_list = ticket_detail_visible_attachments($attachments, $visible_comment_ids, is_agent());

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
        border-radius: var(--fd-radius-control);
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

    #comment-editor .ql-editor img,
    #internal-editor .ql-editor img,
    #edit-description-editor .ql-editor img,
    #edit-comment-editor .ql-editor img {
        display: block;
        max-width: min(100%, 18rem);
        max-height: 14rem;
        width: auto;
        height: auto;
        object-fit: contain;
        margin: 0.75rem 0;
        border-radius: 0.875rem;
        border: 1px solid var(--border-light);
        background: var(--surface-secondary);
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
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

    .rich-content img.rich-inline-image {
        display: block;
        max-width: min(100%, 22rem);
        max-height: 18rem;
        width: auto;
        height: auto;
        object-fit: contain;
        margin: 0.75rem 0;
        border-radius: 0.875rem;
        border: 1px solid var(--border-light);
        background: var(--surface-secondary);
        box-shadow: 0 10px 26px rgba(15, 23, 42, 0.10);
        cursor: zoom-in;
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    }

    .rich-content img.rich-inline-image:hover {
        transform: translateY(-1px);
        border-color: var(--primary);
        box-shadow: 0 16px 34px rgba(15, 23, 42, 0.14);
    }

    .rich-content img.rich-inline-image:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 3px;
    }

    /* ── Link preview cards ──────────────────────────────────────────────── */
    .link-preview-card {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 14px;
        margin: 8px 0;
        border: 1px solid var(--border-light, #e2e8f0);
        border-radius: var(--fd-radius-control);
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
        border-radius: var(--fd-radius-control);
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
    [data-theme="dark"] #internal-editor .ql-toolbar,
    [data-theme="dark"] #edit-description-editor .ql-toolbar,
    [data-theme="dark"] #edit-comment-editor .ql-toolbar {
        background: var(--corp-slate-800) !important;
        border-bottom: 1px solid var(--corp-slate-600) !important;
    }

    [data-theme="dark"] #comment-editor .ql-container,
    [data-theme="dark"] #internal-editor .ql-container,
    [data-theme="dark"] #edit-description-editor .ql-container,
    [data-theme="dark"] #edit-comment-editor .ql-container {
        background: var(--corp-slate-800) !important;
    }

    [data-theme="dark"] #comment-editor .ql-editor,
    [data-theme="dark"] #internal-editor .ql-editor,
    [data-theme="dark"] #edit-description-editor .ql-editor,
    [data-theme="dark"] #edit-comment-editor .ql-editor {
        color: var(--corp-slate-100) !important;
        background: var(--corp-slate-800) !important;
    }

    [data-theme="dark"] .editor-wrapper--internal {
        border-color: var(--corp-slate-600) !important;
        background: var(--corp-slate-800) !important;
    }

    [data-theme="dark"] #internal-editor .ql-toolbar {
        background: var(--corp-slate-700) !important;
        border-bottom-color: var(--corp-slate-600) !important;
    }

    [data-theme="dark"] #internal-editor .ql-container {
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
        border-radius: var(--fd-radius-control);
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
    [data-theme="dark"] .rich-content a:hover {
        color: var(--accent-primary);
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

    .ticket-work-panel {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 0.875rem;
        padding: 1rem;
    }

    .ticket-work-panel__summary {
        min-width: 0;
        max-width: min(100%, 64rem);
    }

    .ticket-work-panel__title {
        display: -webkit-box;
        margin: 0.3rem 0 0;
        color: var(--text-primary);
        font-size: var(--type-2xl);
        line-height: 1.12;
        font-weight: 750;
        letter-spacing: 0;
        overflow: hidden;
        overflow-wrap: anywhere;
        text-wrap: balance;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 3;
    }

    .ticket-work-panel__meta {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
        color: var(--text-muted);
        font-size: 0.75rem;
    }

    .ticket-work-panel__actions {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 0.5rem;
        flex-wrap: wrap;
        width: 100%;
        min-width: 0;
        padding-top: 0.75rem;
        border-top: 1px solid var(--border-light);
    }

    .ticket-primary-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        min-height: 2.25rem;
        padding: 0.5rem 0.8rem;
        border: 1px solid var(--border-light);
        border-radius: var(--fd-radius-control);
        color: var(--text-primary);
        background: var(--surface-primary);
        font-size: 0.875rem;
        font-weight: 700;
        line-height: 1;
        white-space: nowrap;
        transition: transform 0.12s ease, border-color 0.12s ease, background 0.12s ease;
    }

    .ticket-primary-action:hover {
        transform: translateY(-1px);
        border-color: var(--primary);
        text-decoration: none;
    }

    .ticket-primary-action--primary {
        border-color: var(--primary);
        background: var(--primary);
        color: #fff;
    }

    .ticket-primary-action--success {
        border-color: rgba(16, 185, 129, 0.35);
        background: rgba(16, 185, 129, 0.12);
        color: #047857;
    }

    .ticket-primary-action--warning {
        border-color: rgba(245, 158, 11, 0.35);
        background: rgba(245, 158, 11, 0.12);
        color: #92400e;
    }

    .ticket-primary-action--ghost {
        color: var(--text-secondary);
        background: transparent;
    }

    @media (max-width: 768px) {
        .ticket-work-panel__actions {
            justify-content: flex-start;
        }

        .ticket-primary-action {
            flex: 1 1 auto;
        }
    }
</style>

<div class="workflow-surface workflow-surface--ticket-detail ticket-detail-page"
    data-core-workflow-surface="ticket-detail"
    data-ticket-detail-surface
    data-ticket-id="<?php echo (int) $ticket_id; ?>">
    <!-- Main Content -->
    <div class="ticket-detail-main">
        <!-- Ticket Work Panel -->
        <div class="card ticket-work-panel">
            <div class="ticket-work-panel__summary min-w-0">
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
                <div class="ticket-work-panel__meta">
                    <a href="<?php echo $back_url; ?>" class="inline-flex items-center gap-1 hover:underline text-theme-muted">
                        <?php echo get_icon('arrow-left', 'w-3.5 h-3.5'); ?>
                        <?php echo e(t('Back')); ?>
                    </a>
                    <span><?php echo get_ticket_code($ticket_id); ?></span>
                    <?php ticket_detail_render_status_pill($ticket, $statuses); ?>
                    <?php if (!empty($ticket['is_archived'])): ?>
                        <span class="px-1.5 py-0.5 fd-rounded-pill text-[11px] font-medium bg-theme-tertiary text-theme-secondary"><?php echo e(t('Archived')); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($ticket['organization_name'])): ?>
                        <span><?php echo e($ticket['organization_name']); ?></span>
                    <?php endif; ?>
                </div>
                <h1 class="ticket-work-panel__title" title="<?php echo e($ticket['title']); ?>"><?php echo e($ticket['title']); ?></h1>
            </div>
            <div class="ticket-work-panel__actions" aria-label="<?php echo e(t('Primary actions')); ?>">
                <?php foreach ($ticket_primary_actions as $action): ?>
                    <?php $action_class = ticket_detail_primary_action_class($action); ?>
                    <?php $action_title = t($action['title'] ?? $action['label']); ?>
                    <?php if ($action['type'] === 'anchor'): ?>
                        <a href="<?php echo e($action['href']); ?>" class="<?php echo e($action_class); ?>"
                           title="<?php echo e($action_title); ?>" aria-label="<?php echo e($action_title); ?>">
                            <?php echo get_icon($action['icon'], 'w-4 h-4'); ?>
                            <span><?php echo e(t($action['label'])); ?></span>
                        </a>
                    <?php elseif ($action['type'] === 'submit'): ?>
                        <form method="post" class="ticket-primary-action-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="status_id" value="<?php echo (int) $action['status_id']; ?>">
                            <button type="submit" name="<?php echo e($action['name']); ?>" class="<?php echo e($action_class); ?>"
                                    title="<?php echo e($action_title); ?>" aria-label="<?php echo e($action_title); ?>">
                                <?php echo get_icon($action['icon'], 'w-4 h-4'); ?>
                                <span><?php echo e(t($action['label'])); ?></span>
                            </button>
                        </form>
                    <?php else: ?>
                        <button type="button"
                            <?php if (!empty($action['id'])): ?>id="<?php echo e($action['id']); ?>"<?php endif; ?>
                            <?php if (!empty($action['onclick'])): ?>onclick="<?php echo e($action['onclick']); ?>"<?php endif; ?>
                            title="<?php echo e($action_title); ?>" aria-label="<?php echo e($action_title); ?>"
                            class="<?php echo e($action_class); ?>">
                            <?php echo get_icon($action['icon'], 'w-4 h-4'); ?>
                            <span><?php echo e(t($action['label'])); ?></span>
                            <?php if (($action['key'] ?? '') === 'start_work' && $timer_state !== 'stopped'): ?>
                                <span id="toolbar-timer-elapsed" class="ticket-primary-action__timer"><?php echo format_duration_minutes($active_timer_elapsed); ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Description Card -->
        <?php $initial_attachments = ticket_detail_initial_attachments($attachments); ?>
        <?php if (!empty($ticket['description']) || !empty($initial_attachments)): ?>
                <div class="card card-body">
                    <?php if (!empty($ticket['description'])): ?>
                            <div class="prose max-w-none rich-content text-theme-secondary">
                                <?php echo render_content($ticket['description']); ?>
                            </div>
                    <?php endif; ?>

                    <?php if (!empty($initial_attachments)): ?>
                            <div class="<?php echo !empty($ticket['description']) ? 'mt-4 pt-4 border-t' : ''; ?>">
                                <h4 class="text-sm font-medium mb-1 text-theme-secondary">
                                    <?php echo e(t('Attachments')); ?></h4>
                                <?php $component_attachments = $initial_attachments; $component_layout = 'grid'; include BASE_PATH . '/includes/components/attachment-grid.php'; ?>
                            </div>
                    <?php endif; ?>

                    <div class="mt-3 pt-2.5 border-t flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 text-xs text-theme-muted">
                        <div class="flex items-center space-x-3">
                            <span class="ticket-meta-avatar" aria-hidden="true">
                                <span class="ticket-meta-avatar__initial"><?php echo e($ticket_creator_initial); ?></span>
                            </span>
                            <span><?php echo e(t('Created by')); ?>:
                                <?php if (is_agent()): ?>
                                        <a href="<?php echo url('user-profile', ['id' => $ticket['user_id']]); ?>"
                                            class="font-medium text-blue-600 hover:text-blue-700 hover:underline">
                                            <?php echo e($ticket_creator_name); ?>
                                        </a>
                                <?php else: ?>
                                        <strong><?php echo e($ticket_creator_name); ?></strong>
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
                                <summary class="flex items-center gap-2 cursor-pointer text-sm text-theme-muted">
                                    <?php echo get_icon('history', 'w-4 h-4'); ?>
                                    <?php echo e(t('Edit history')); ?> (<?php echo count($ticket_history); ?>)
                                </summary>
                                <div class="mt-3 space-y-2">
                                    <?php foreach ($ticket_history as $history): ?>
                                            <?php
                                            $is_long_text_change = in_array($history['field_name'], ['description', 'comment_content', 'comment_deleted'], true);
                                            $is_attachment_event = in_array($history['field_name'], ['attachment_added', 'attachment_unlinked'], true);
                                            ?>
                                            <div class="flex items-start gap-3 text-xs p-2 rounded-lg bg-theme-secondary">
                                                <div class="flex-shrink-0 w-6 h-6 fd-rounded-pill flex items-center justify-center bg-theme-tertiary">
                                                    <span class="font-medium text-xs text-theme-secondary">
                                                        <?php echo strtoupper(substr($history['first_name'] ?? 'U', 0, 1)); ?>
                                                    </span>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex flex-wrap items-center gap-1 text-theme-secondary">
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
                                                            <div class="mt-1 flex flex-wrap items-center gap-2 text-theme-muted">
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
                                                            <div class="mt-1 flex flex-wrap items-center gap-2 text-theme-muted">
                                                                <span
                                                                    class="line-through"><?php echo format_history_value($history['field_name'], $history['old_value']); ?></span>
                                                                <span>→</span>
                                                                <span class="font-medium text-theme-secondary"><?php echo format_history_value($history['field_name'], $history['new_value']); ?></span>
                                                            </div>
                                                    <?php endif; ?>
                                                    <div class="mt-1 text-theme-muted">
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
        $time_entries = ($time_tracking_available && can_view_time($user)) ? get_ticket_time_entries($ticket_id) : [];
        $ticket_timeline = ticket_detail_build_timeline($comments, $time_entries);
        $time_entries_by_comment = $ticket_timeline['time_entries_by_comment'];
        $timeline_items = $ticket_timeline['timeline_items'];
        ?>

        <!-- Comments & Time Log Combined -->
        <div class="card">
            <div class="card-header">
                <h3 class="font-semibold text-theme-primary"><?php echo e(t('Activity')); ?>
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
                    <div class="p-4 text-center text-theme-muted">
                        <?php echo e(t('No comments yet.')); ?>
                    </div>
            <?php else: ?>
                    <div class="divide-y border-theme-light">
                        <?php foreach ($timeline_items as $timeline_item): ?>
                                <?php if ($timeline_item['type'] === 'time_entry'): ?>
                                        <?php $entry = $timeline_item['data']; ?>
                                        <?php if (can_view_time($user)): ?>
                                                <div class="flex justify-center py-2.5">
                                                    <div class="time-entry-row inline-flex flex-wrap items-center gap-1.5 text-xs px-3 py-1.5 rounded-full"
                                                        style="background: var(--surface-secondary); color: var(--text-muted);">
                                                        <?php echo get_icon('clock', 'w-3.5 h-3.5 flex-shrink-0'); ?>
                                                        <span class="font-medium text-theme-secondary"><?php
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
                                                                                class="p-0.5 hover:text-blue-600 transition text-theme-muted"
                                                                                title="<?php echo e(t('Edit')); ?>">
                                                                                <?php echo get_icon('pencil', 'w-3 h-3'); ?>
                                                                            </button>
                                                                    <?php endif; ?>
                                                                    <form method="post" class="inline">
                                                                        <?php echo csrf_field(); ?>
                                                                        <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                                                        <button type="submit" name="delete_time_entry"
                                                                            class="p-0.5 hover:text-red-500 transition text-theme-muted"
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
                                        $comment_attachments = ticket_detail_comment_attachments($attachments, (int) $comment['id']);
                                        $is_own_comment = ((int) $comment['user_id'] === (int) $user['id']);
                                        ?>
                                        <div id="comment-<?php echo $comment['id']; ?>"
                                            class="comment-item group px-4 lg:px-5 py-4 transition-colors hover:bg-[var(--surface-secondary)]/40 <?php echo $comment['is_internal'] ? 'comment-internal' : ''; ?>">
                                            <div class="flex gap-3">
                                                <!-- Avatar -->
                                                <?php echo render_user_avatar($comment, 'md', 'mt-0.5 ' . ($is_own_comment ? 'ticket-comment__avatar--own' : '')); ?>

                                                <!-- Content -->
                                                <div class="flex-1 min-w-0">
                                                    <!-- Header: name + badges + timestamp + actions -->
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <span class="font-semibold text-sm text-theme-primary">
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
                                                        <span class="text-xs text-theme-muted"><?php echo format_date($comment['created_at']); ?></span>
                                                        <?php if ($can_view_edit_history && !empty($comment['updated_at']) && $comment['updated_at'] !== $comment['created_at']): ?>
                                                                <span class="text-xs italic text-theme-muted">(<?php echo e(t('edited')); ?>)</span>
                                                        <?php endif; ?>

                                                        <!-- Edit/Delete actions (visible on hover) -->
                                                        <?php if (is_admin() || (is_agent() && (int) $comment['user_id'] === (int) $user['id'])): ?>
                                                                <div class="comment-actions">
                                                                    <button type="button"
                                                                        onclick="openEditCommentModal(<?php echo $comment['id']; ?>, <?php echo htmlspecialchars(json_encode($comment['content']), ENT_QUOTES, 'UTF-8'); ?>)"
                                                                        class="hover:text-blue-600 p-1 rounded transition text-theme-muted" title="<?php echo e(t('Edit comment')); ?>">
                                                                        <?php echo get_icon('pencil', 'w-3.5 h-3.5'); ?>
                                                                    </button>
                                                                    <button type="button" onclick="deleteComment(<?php echo $comment['id']; ?>)"
                                                                        class="hover:text-red-600 p-1 rounded transition text-theme-muted" title="<?php echo e(t('Delete comment')); ?>">
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
                                                                            <span class="font-medium text-theme-secondary"><?php
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
                                                                                                    class="p-0.5 hover:text-blue-600 transition text-theme-muted" title="<?php echo e(t('Edit time')); ?>">
                                                                                                    <?php echo get_icon('pencil', 'w-3 h-3'); ?>
                                                                                                </button>
                                                                                        <?php endif; ?>
                                                                                        <form method="post" class="inline">
                                                                                            <?php echo csrf_field(); ?>
                                                                                            <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                                                                            <button type="submit" name="delete_time_entry"
                                                                                                class="p-0.5 hover:text-red-500 transition text-theme-muted"
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

            <?php include BASE_PATH . '/includes/components/ticket-detail-composer.php'; ?>

        </div>
    </div>

    <?php include BASE_PATH . '/includes/components/ticket-detail-sidebar.php'; ?>
</div>

<?php include BASE_PATH . '/includes/components/ticket-detail-modals.php'; ?>

<?php
$ticket_detail_js_config = [
    'ticketId' => (int) $ticket_id,
    'timerState' => (string) $timer_state,
    'csrfToken' => csrf_token(),
    'pageTitle' => ($page_title ?? t('Dashboard')) . ' - ' . $app_name,
    'appName' => $app_name,
    'favicon' => $settings['favicon'] ?? '',
    'canViewEditHistory' => (bool) $can_view_edit_history,
    'labels' => [
        'saved' => t('Saved'),
        'error' => t('Error'),
        'copied' => t('Copied'),
        'copy' => t('Copy'),
        'remove' => t('Remove'),
        'noUsersFound' => t('No users found.'),
        'visibleAgents' => t('Visible to agents only'),
        'visibleCustomer' => t('Visible to customer'),
        'startTimer' => t('Start timer'),
        'startTimerHelp' => t('Start a timer for this ticket.'),
        'startingTimer' => t('Starting...'),
        'pauseTimer' => t('Pause timer'),
        'pauseTimerHelp' => t('Pause this timer without logging time yet.'),
        'resumeTimer' => t('Resume timer'),
        'resumeTimerHelp' => t('Resume the paused timer.'),
        'completeHelp' => t('Mark this ticket as done.'),
        'completeTimerHelp' => t('Mark this ticket as done and stop the active timer.'),
        'confirmDiscardTimer' => t('Discard this timer? The tracked time will be lost.'),
        'paused' => t('Paused'),
        'timerStarted' => t('Timer started.'),
        'timerPaused' => t('Timer paused.'),
        'timerResumed' => t('Timer resumed.'),
        'timerDiscarded' => t('Timer discarded.'),
        'failStartTimer' => t('Failed to start timer.'),
        'failPauseTimer' => t('Failed to pause timer.'),
        'failResumeTimer' => t('Failed to resume timer.'),
        'failDiscardTimer' => t('Failed to discard timer.'),
        'genericError' => t('An error occurred.'),
        'editCommentPlaceholder' => t('Edit your comment...'),
        'commentEmpty' => t('Comment cannot be empty.'),
        'edited' => t('edited'),
        'commentUpdated' => t('Comment updated.'),
        'commentUpdateFailed' => t('Failed to update comment.'),
        'confirmDeleteComment' => t('Are you sure you want to delete this comment?'),
        'commentDeleted' => t('Comment deleted.'),
        'commentDeleteFailed' => t('Failed to delete comment.'),
        'invalidRange' => t('Invalid range'),
        'noMatches' => t('No matches'),
        'filterByTag' => t('Filter by this tag'),
        'replyPlaceholder' => t('Write a reply...'),
        'internalPlaceholder' => t('Internal note for agents...'),
        'descriptionPlaceholder' => t('Description...'),
        'draftRestored' => t('Draft restored'),
        'loading' => t('Loading...'),
        'noActivity' => t('No activity found'),
        'timelineError' => t('Error loading timeline'),
    ],
    'icons' => [
        'play' => get_icon('play', 'w-4 h-4'),
        'pause' => get_icon('pause', 'w-4 h-4'),
        'spinner' => get_icon('spinner', 'w-4 h-4 animate-spin'),
        'playSm' => get_icon('play', 'w-3.5 h-3.5'),
        'pauseSm' => get_icon('pause', 'w-3.5 h-3.5'),
    ],
    'upload' => [
        'single' => (int) get_max_upload_size(),
        'total' => (int) get_request_upload_limit(),
        'singleTemplate' => t('File "{name}" exceeds the maximum allowed size of {size}.'),
        'totalTemplate' => t('Selected attachments exceed the server request limit of {size}.'),
    ],
    'quillUpload' => [
        'uploadUrl' => 'index.php?page=api&action=upload',
        'csrfToken' => csrf_token(),
        'ticketId' => (int) $ticket_id,
    ],
    'tags' => [
        'enabled' => (bool) ($tags_supported && can_edit_ticket($ticket, $user)),
        'current' => $ticket_tags,
        'filterUrlBase' => url('tickets', !empty($ticket['is_archived']) ? ['archived' => '1'] : []),
    ],
];
?>
<script>
window.FoxDeskTicketDetailConfig = <?php echo json_encode($ticket_detail_js_config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>

<!-- Tag inline editing -->
<?php if ($tags_supported && can_edit_ticket($ticket, $user)): ?>
<script src="assets/js/chip-select.js?v=<?php echo APP_VERSION; ?>"></script>
<?php endif; ?>

<!-- Quill Editor JS (1.3.7 stable) -->
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script src="assets/js/quill-image-upload.js?v=<?php echo APP_VERSION; ?>"></script>
<script src="assets/js/attachment-paste-drop.js?v=<?php echo APP_VERSION; ?>"></script>

<!-- Autosave for comment editor -->
<script src="assets/js/autosave.js?v=<?php echo APP_VERSION; ?>"></script>

<?php if (function_exists('can_view_timeline') && can_view_timeline($user)): ?>
<!-- Timeline Modal -->
<div id="timeline-overlay" onclick="closeTimeline()" style="display:none; position:fixed; inset:0; z-index:50; background:rgba(0,0,0,0.5);">
    <div onclick="event.stopPropagation()" style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:100%; max-width:640px; max-height:85vh; border-radius: var(--fd-radius-card); box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); display:flex; flex-direction:column; background:var(--surface-primary); color:var(--text-primary);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--border-light);">
            <h2 style="font-size:16px; font-weight:600; display:flex; align-items:center; gap:8px;">
                <?php echo get_icon('history', 'w-5 h-5'); ?>
                <?php echo e(t('Activity Timeline')); ?>
            </h2>
            <button onclick="closeTimeline()" style="width:28px; height:28px; display:flex; align-items:center; justify-content:center; border-radius: var(--fd-radius-control); border:none; cursor:pointer; background:var(--surface-secondary); color:var(--text-muted);">
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

<?php endif; ?>

<script src="assets/js/ticket-detail.js?v=<?php echo APP_VERSION; ?>"></script>

<?php require_once BASE_PATH . '/includes/footer.php';
