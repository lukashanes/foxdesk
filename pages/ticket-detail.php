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
// Audience preview exits before notification writes, form handlers and timer migrations.
if (isset($_GET['preview_user'])) {
    require_once BASE_PATH . '/includes/modules/tickets/ticket-audience-preview.php';
    require BASE_PATH . '/includes/components/ticket-audience-preview-page.php';
    return;
}
// Auto mark ALL notifications for this ticket as read when viewing it
if (function_exists('mark_ticket_notifications_read')) {
    mark_ticket_notifications_read($ticket_id, (int) $user['id']);
}
$page_title = $ticket['title'];
$page = 'ticket';
$ticket_detail_asset_version = static function (string $path): string {
    return (defined('APP_VERSION') ? (string) APP_VERSION : '1') . '-' . (string) (@filemtime(BASE_PATH . '/' . $path) ?: '0');
};
$ticket_detail_context = ticket_detail_context($ticket_id, $ticket, $user, $_SESSION);
$all_comments = $ticket_detail_context['all_comments'];
$attachments = $ticket_detail_context['attachments'];
$statuses = $ticket_detail_context['statuses'];
$composer_statuses = function_exists('ticket_status_group_visible_workflow_statuses')
    ? ticket_status_group_visible_workflow_statuses($statuses)
    : $statuses;
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
$time_breakdown = $time_tracking_available ? $ticket_detail_context['time_breakdown'] : ['total' => 0, 'human' => 0, 'ai' => 0];
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
$workflow_metadata = ticket_workflow_metadata($ticket, $user);
$workflow_metadata['user_id'] = (int) $user['id'];
$workflow_metadata['tenant_id'] = (int) ($user['tenant_id'] ?? 0);
$workflow_metadata['draft_ack'] = (string) ($_SESSION['ticket_workflow_ack'][$ticket_id] ?? '');
$workflow_metadata['recipient_emails'] = [];
$workflow_metadata['email_enabled'] = false;
if (is_agent()) {
    require_once BASE_PATH . '/includes/mailer.php';
    $notification_settings = (array) get_settings();
    $workflow_metadata['email_enabled'] = ($notification_settings['notify_on_new_comment'] ?? null) === '1';
    foreach (ticket_comment_notification_recipients($ticket, $user) as $recipient_id => $recipient_meta) {
        $recipient = get_user((int) $recipient_id);
        if (!empty($recipient['email'])) $workflow_metadata['recipient_emails'][] = $recipient['email'];
    }
}
$workflow_metadata['copy'] = ['stop_timer' => t('Stop timer'), 'assign' => t('Assign'), 'cancel' => t('Cancel'), 'internal_note' => t('Internal note')];
foreach (['reopen', 'claim', 'send', 'send_done', 'send_waiting', 'save_note', 'more', 'add_time', 'exact_time', 'next', 'previous', 'complete_next', 'conflict', 'save_failed', 'saved', 'undo_status', 'draft_restored', 'attachments_reselect', 'saving', 'triage', 'recipients', 'keyboard', 'draft_saved', 'reload'] as $key) $workflow_metadata['copy'][$key] = t('workflow.' . $key);
require_once BASE_PATH . '/includes/header.php';
?>
<!-- Quill Editor CSS -->
<link href="assets/vendor/quill/2.0.2/quill.snow.css?v=<?php echo APP_VERSION; ?>" rel="stylesheet">
<div class="workflow-surface workflow-surface--ticket-detail ticket-detail-page"
    data-core-workflow-surface="ticket-detail"
    data-workflow="<?php echo e(json_encode($workflow_metadata)); ?>"
    data-ticket-detail-surface
    data-ticket-id="<?php echo (int) $ticket_id; ?>">
    <!-- Full-width heading followed by the working content and properties. -->
        <!-- Ticket Work Panel -->
        <div class="ticket-work-panel">
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
                    <a href="<?php echo $back_url; ?>" class="ticket-back-link">
                        <?php echo get_icon('arrow-left', 'w-3.5 h-3.5'); ?>
                        <?php echo e(t('workflow.back')); ?>
                    </a>
                    <span><?php echo get_ticket_code($ticket_id); ?></span>
                    <?php ticket_detail_render_status_pill($ticket, $statuses); ?>
                    <?php if (!empty($ticket['is_archived'])): ?>
                        <span class="ticket-status-pill ticket-status-pill--archived"><?php echo e(t('Archived')); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($ticket['organization_name'])): ?>
                        <span><?php echo e($ticket['organization_name']); ?></span>
                    <?php endif; ?>
                </div>
                <h1 class="ticket-work-panel__title" title="<?php echo e($ticket['title']); ?>"><bdi dir="auto"><?php echo e($ticket['title']); ?></bdi></h1>
            </div>
        </div>
    <div class="ticket-detail-main card">
        <!-- Description Card -->
        <?php $initial_attachments = ticket_detail_initial_attachments($attachments); ?>
        <?php if (!empty($ticket['description']) || !empty($initial_attachments)): ?>
                <section class="ticket-description">
                    <h2><?php echo e(t('Description')); ?></h2>
                    <?php if (!empty($ticket['description'])): ?>
                            <div class="prose max-w-none rich-content text-theme-secondary" dir="auto">
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
                    <div class="mt-3 pt-2.5 border-t flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 text-xs ticket-detail-muted">
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
                                <summary class="flex items-center gap-2 cursor-pointer text-sm ticket-detail-muted">
                                    <?php echo get_icon('history', 'w-4 h-4'); ?>
                                    <?php echo e(t('Edit history')); ?> (<?php echo count($ticket_history); ?>)
                                </summary>
                                <div class="mt-3 space-y-2">
                                    <?php foreach ($ticket_history as $history): ?>
                                            <?php
                                            $is_long_text_change = in_array($history['field_name'], ['description', 'comment_content', 'comment_deleted'], true);
                                            $is_attachment_event = in_array($history['field_name'], ['attachment_added', 'attachment_unlinked'], true);
                                            ?>
                                            <div class="flex items-start gap-3 text-xs p-2 fd-rounded-card bg-theme-secondary">
                                                <div class="ticket-history-avatar flex-shrink-0 w-6 h-6 fd-rounded-pill flex items-center justify-center">
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
                                                                <div class="fd-rounded-control border border-red-200 bg-red-50 px-2 py-1.5">
                                                                    <div class="text-xs uppercase tracking-wide text-red-700 mb-1">
                                                                        <?php echo e(t('Previous')); ?></div>
                                                                    <div class="text-xs text-red-800 whitespace-pre-wrap break-words">
                                                                        <?php echo format_history_value($history['field_name'], $history['old_value']); ?>
                                                                    </div>
                                                                </div>
                                                                <div class="fd-rounded-control border border-green-200 bg-green-50 px-2 py-1.5">
                                                                    <div class="text-xs uppercase tracking-wide text-green-700 mb-1">
                                                                        <?php echo e(t('New')); ?></div>
                                                                    <div class="text-xs text-green-800 whitespace-pre-wrap break-words">
                                                                        <?php echo format_history_value($history['field_name'], $history['new_value']); ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                    <?php elseif ($is_attachment_event): ?>
                                                            <div class="mt-1 flex flex-wrap items-center gap-2 ticket-detail-muted">
                                                                <?php if ($history['field_name'] === 'attachment_added'): ?>
                                                                        <span
                                                                            class="inline-flex items-center px-1.5 py-0.5 fd-rounded-control bg-green-100 text-green-700 font-medium">+
                                                                            <?php echo format_history_value($history['field_name'], $history['new_value']); ?></span>
                                                                <?php else: ?>
                                                                        <span
                                                                            class="inline-flex items-center px-1.5 py-0.5 fd-rounded-control bg-red-100 text-red-700 font-medium">-
                                                                            <?php echo format_history_value($history['field_name'], $history['old_value']); ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                    <?php else: ?>
                                                            <div class="mt-1 flex flex-wrap items-center gap-2 ticket-detail-muted">
                                                                <span
                                                                    class="line-through"><?php echo format_history_value($history['field_name'], $history['old_value']); ?></span>
                                                                <span>→</span>
                                                                <span class="font-medium text-theme-secondary"><?php echo format_history_value($history['field_name'], $history['new_value']); ?></span>
                                                            </div>
                                                    <?php endif; ?>
                                                    <div class="mt-1 ticket-detail-muted">
                                                        <?php echo format_date($history['created_at']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                    <?php endif; ?>
                </section>
        <?php endif; ?>
        <?php
        $time_entries = ($time_tracking_available && can_view_time($user)) ? $ticket_detail_context['time_entries'] : [];
        $ticket_timeline = ticket_detail_build_timeline($comments, $time_entries);
        $time_entries_by_comment = $ticket_timeline['time_entries_by_comment'];
        $timeline_items = $ticket_timeline['timeline_items'];

        ?>
        <!-- Comments & Time Log Combined -->
        <div class="ticket-activity-card" data-ticket-activity-surface>
            <div class="card-header ticket-activity-header">
                <h3 class="ticket-activity-title"><?php echo e(t('ticket.workspace.conversation')); ?></h3>
                <span class="ticket-activity-count"><?php echo e(t('{count} comments', ['count' => count($comments)])); ?></span>
                <?php if ($time_tracking_available && $total_time_minutes > 0 && can_view_time($user)): ?>
                        <span class="ticket-activity-time">
                            <?php echo get_icon('clock', 'w-3 h-3'); ?>
                            <?php echo format_duration_minutes($total_time_minutes); ?>
                        </span>
                <?php endif; ?>
            </div>
            <?php if (empty($timeline_items)): ?>
                    <div class="ticket-activity-empty">
                        <?php echo e(t('No comments yet.')); ?>
                    </div>
            <?php else: ?>
                    <div class="ticket-activity-list">
                        <?php foreach ($timeline_items as $timeline_item): ?>
                            <?php if ($timeline_item['type'] === 'time_entry'): ?>
                                <div class="ticket-conversation-work ticket-time-entry-list" data-timeline-item="time_entry">
                                    <?php $entry = $timeline_item['data']; include BASE_PATH . '/includes/components/ticket-workspace-time-row.php'; ?>
                                </div>
                                <?php continue; ?>
                            <?php endif; ?>
                                        <?php $comment = $timeline_item['data']; ?>
                                        <?php
                                        $comment_attachments = ticket_detail_comment_attachments($attachments, (int) $comment['id']);
                                        $is_own_comment = ((int) $comment['user_id'] === (int) $user['id']);
                                        ?>
                                        <div id="comment-<?php echo $comment['id']; ?>"
                                            class="comment-item ticket-comment <?php echo $comment['is_internal'] ? 'comment-internal ticket-comment--internal' : ''; ?>">
                                            <div class="ticket-comment__inner">
                                                <!-- Avatar -->
                                                <?php echo render_user_avatar($comment, 'md', 'ticket-comment__avatar ' . ($is_own_comment ? 'ticket-comment__avatar--own' : '')); ?>
                                                <!-- Content -->
                                                <div class="ticket-comment__content">
                                                    <!-- Header: name + badges + timestamp + actions -->
                                                    <div class="ticket-comment__header">
                                                        <span class="ticket-comment__author">
                                                            <?php echo e($comment['first_name'] . ' ' . $comment['last_name']); ?>
                                                        </span>
                                                        <?php if ($is_own_comment): ?>
                                                                <span class="ticket-comment__badge ticket-comment__badge--you"><?php echo e(t('You')); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($comment['is_internal']): ?>
                                                                <span class="ticket-comment__badge ticket-comment__badge--internal"><?php echo e(t('Internal')); ?></span>
                                                        <?php endif; ?>
                                                        <span class="ticket-comment__date"><?php
                                                        echo !empty($comment['work_date'])
                                                            ? e(format_date($comment['work_date'], 'd.m.Y'))
                                                            : e(format_date($comment['created_at']));
                                                        ?></span>
                                                        <?php if ($can_view_edit_history && !empty($comment['updated_at']) && $comment['updated_at'] !== $comment['created_at']): ?>
                                                                <span class="ticket-comment__edited">(<?php echo e(t('edited')); ?>)</span>
                                                        <?php endif; ?>
                                                        <!-- Edit/Delete actions (visible on hover) -->
                                                        <?php if (can_manage_comment($comment, $user)): ?>
                                                                <div class="comment-actions">
                                                                    <button type="button"
                                                                        onclick="openEditCommentModal(<?php echo $comment['id']; ?>, <?php echo htmlspecialchars(json_encode($comment['content']), ENT_QUOTES, 'UTF-8'); ?>)"
                                                                        class="ticket-inline-icon-button" title="<?php echo e(t('Edit comment')); ?>">
                                                                        <?php echo get_icon('pencil', 'w-3.5 h-3.5'); ?>
                                                                    </button>
                                                                    <button type="button" onclick="deleteComment(<?php echo $comment['id']; ?>)"
                                                                        class="ticket-inline-icon-button ticket-inline-icon-button--danger" title="<?php echo e(t('Delete comment')); ?>">
                                                                        <?php echo get_icon('trash', 'w-3.5 h-3.5'); ?>
                                                                    </button>
                                                                </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <!-- Comment body -->
                                                    <div class="ticket-comment__body rich-content" dir="auto"
                                                        id="comment-content-<?php echo $comment['id']; ?>">
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
                                                            <div class="ticket-time-badge">
                                                                <?php echo get_icon('clock', 'w-3 h-3'); ?>
                                                                <span><?php echo e(format_duration_minutes($display_time)); ?></span>
                                                            </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($comment_time_entries) && can_view_time($user)): ?>
                                                            <div class="ticket-time-entry-list">
                                                                <?php foreach ($comment_time_entries as $entry): ?>
                                                                        <?php include BASE_PATH . '/includes/components/ticket-workspace-time-row.php'; ?>
                                                                <?php endforeach; ?>
                                                            </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                        <?php endforeach; ?>
                    </div>
            <?php endif; ?>
            <?php include BASE_PATH . '/includes/components/ticket-detail-composer.php'; ?>
        </div>
    </div>
    <?php include BASE_PATH . '/includes/components/ticket-detail-sidebar.php'; ?>
</div>
<?php include BASE_PATH . '/includes/components/ticket-detail-modals.php'; ?>
<?php if (is_agent() && $time_tracking_available) include BASE_PATH . '/includes/components/ticket-workspace-time-editor.php'; ?>
<?php
$ticket_detail_js_config = [
    'quickStart' => isset($_GET['quick_start']) && $_GET['quick_start'] === '1' && can_edit_ticket($ticket, $user),
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
        'pauseTimer' => t('Pause timer'),
        'resumeTimer' => t('Resume timer'),
        'startingTimer' => t('Starting...'),
        'pauseTimer' => t('Pause timer'),
        'pauseTimerHelp' => t('Pause this timer without logging time yet.'),
        'resumeTimer' => t('Resume timer'),
        'resumeTimerHelp' => t('Resume the paused timer.'),
        'completeHelp' => t('Mark this ticket as done.'),
        'completeTimerHelp' => t('Mark this ticket as done and stop the active timer.'),
        'completeLabel' => t('Complete'),
        'completeTimerLabel' => t('Complete & stop timer'),
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
        'commentDeleted' => t('Comment deleted.'),
        'commentDeleteFailed' => t('Failed to delete comment.'),
        'timeEntryDeleted' => t('Time entry deleted.'),
        'timeEntryDeleteFailed' => t('Failed to delete time entry.'),
        'undo' => t('Undo'),
        'undoFailed' => t('Undo is no longer available.'),
        'restored' => t('Restored.'),
        'confirmDeleteAttachment' => t('Delete this attachment?'),
        'attachmentDeleted' => t('Attachment deleted.'),
        'attachmentDeleteFailed' => t('Failed to delete attachment.'),
        'invalidRange' => t('Invalid range'),
        'noMatches' => t('No matches'),
        'remove' => t('Remove'),
        'filterByTag' => t('Filter by this tag'),
        'replyPlaceholder' => t('Write a reply...'),
        'internalPlaceholder' => t('Internal note for agents...'),
        'descriptionPlaceholder' => t('Description...'),
        'draftRestored' => t('Draft restored'),
        'loading' => t('Loading...'),
        'noActivity' => t('No activity found'),
        'timelineError' => t('Error loading timeline'),
        'ticket' => t('Ticket'),
        'comments' => t('Comments'),
        'timeEntries' => t('Time entries'),
        'totalTime' => t('Total time'),
        'attachments' => t('Attachments'),
        'loadingDeletionSummary' => t('Loading deletion summary...'),
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
<script src="assets/js/chip-select.js?v=<?php echo e($ticket_detail_asset_version('assets/js/chip-select.js')); ?>"></script>
<?php endif; ?>
<!-- Quill Editor JS -->
<script src="assets/vendor/quill/2.0.2/quill.js?v=<?php echo APP_VERSION; ?>"></script>
<script src="assets/js/rich-text-editor.js?v=<?php echo e($ticket_detail_asset_version('assets/js/rich-text-editor.js')); ?>"></script>
<script src="assets/js/quill-image-upload.js?v=<?php echo e($ticket_detail_asset_version('assets/js/quill-image-upload.js')); ?>"></script>
<script src="assets/js/attachment-paste-drop.js?v=<?php echo e($ticket_detail_asset_version('assets/js/attachment-paste-drop.js')); ?>"></script>
<!-- Autosave for comment editor -->
<script src="assets/js/autosave.js?v=<?php echo e($ticket_detail_asset_version('assets/js/autosave.js')); ?>"></script>
<?php if (function_exists('can_view_timeline') && can_view_timeline($user)): ?>
<!-- Timeline Modal -->
<div id="timeline-overlay" class="ticket-timeline-overlay" onclick="closeTimeline()" aria-hidden="true">
    <div class="ticket-timeline-modal" onclick="event.stopPropagation()" role="dialog" aria-modal="true" aria-labelledby="timeline-title">
        <div class="ticket-timeline-header">
            <h2 id="timeline-title" class="ticket-timeline-title">
                <?php echo get_icon('history', 'w-5 h-5'); ?>
                <?php echo e(t('Activity Timeline')); ?>
            </h2>
            <button type="button" onclick="closeTimeline()" class="ticket-timeline-close" aria-label="<?php echo e(t('Close')); ?>">
                &times;
            </button>
        </div>
        <div id="timeline-content" class="ticket-timeline-content">
            <div class="ticket-timeline-empty"><?php echo e(t('Loading...')); ?></div>
        </div>
    </div>
</div>
<?php endif; ?>
<script src="assets/js/ticket-detail-timer.js?v=<?php echo e($ticket_detail_asset_version('assets/js/ticket-detail-timer.js')); ?>"></script>
<script src="assets/js/ticket-detail-records.js?v=<?php echo e($ticket_detail_asset_version('assets/js/ticket-detail-records.js')); ?>"></script>
<script src="assets/js/ticket-detail-admin.js?v=<?php echo e($ticket_detail_asset_version('assets/js/ticket-detail-admin.js')); ?>"></script>
<script src="assets/js/ticket-detail.js?v=<?php echo e($ticket_detail_asset_version('assets/js/ticket-detail.js')); ?>"></script>
<?php require_once BASE_PATH . '/includes/footer.php';
