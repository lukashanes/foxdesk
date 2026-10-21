<?php
/**
 * Ticket Detail Page
 */

// Support hash-based URLs (t=hash), previously issued agent links (hash=) and
// legacy ID-based URLs (id=123); canonical t= takes precedence.
$ticket_hash_input = $_GET['t'] ?? $_GET['hash'] ?? null;
$ticket_hash = is_string($ticket_hash_input) ? trim($ticket_hash_input) : null;
$ticket_hash_requested = array_key_exists('t', $_GET) || array_key_exists('hash', $_GET);
$ticket_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Migrate ticket hashes on first access (one-time operation)
if (function_exists('migrate_ticket_hashes')) {
    migrate_ticket_hashes();
}

// Get ticket by hash or ID
if ($ticket_hash_requested) {
    $ticket = !empty($ticket_hash) ? get_ticket_by_hash($ticket_hash) : null;
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
    $workflow_metadata['email_enabled'] = (get_settings()['notify_on_new_comment'] ?? '') === '1';
    foreach (ticket_comment_notification_recipients($ticket, $user) as $recipient_id => $recipient_meta) {
        $recipient = get_user((int) $recipient_id);
        if (!empty($recipient['email'])) $workflow_metadata['recipient_emails'][] = $recipient['email'];
    }
}
$workflow_metadata['copy'] = ['stop_timer' => t('Stop timer'), 'assign' => t('Assign'), 'cancel' => t('Cancel'), 'internal_note' => t('Internal note')];
foreach (['reopen', 'claim', 'send', 'send_done', 'send_waiting', 'save_note', 'more', 'add_time', 'exact_time', 'next', 'previous', 'complete_next', 'conflict', 'save_failed', 'saved', 'undo_status', 'draft_restored', 'attachments_reselect', 'saving', 'triage', 'recipients', 'keyboard', 'draft_saved', 'reload'] as $key) $workflow_metadata['copy'][$key] = t('workflow.' . $key);
require_once BASE_PATH . '/includes/header.php';
?>

<!-- Quill Editor CSS (self-hosted) -->
<link href="assets/vendor/quill/2.0.2/quill.snow.css?v=<?php echo APP_VERSION; ?>" rel="stylesheet">
<link href="assets/css/ticket-detail.css?v=<?php echo APP_VERSION; ?>" rel="stylesheet">

<?php include BASE_PATH . '/includes/components/ticket-detail-content.php'; ?>

<?php
$ticket_detail_js_config = [
    'ticketId' => (int) $ticket_id,
    'quickStart' => isset($_GET['quick_start']) && $_GET['quick_start'] === '1',
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
        'timeEntryDeleted' => t('Time entry deleted.'),
        'timeEntryDeleteFailed' => t('Failed to delete time entry.'),
        'attachmentDeleted' => t('Attachment deleted.'),
        'attachmentDeleteFailed' => t('Failed to delete attachment.'),
        'undo' => t('Undo'),
        'undoFailed' => t('Undo is no longer available.'),
        'restored' => t('Restored.'),
        'invalidRange' => t('Invalid range'),
        'noMatches' => t('No matches'),
        'filterByTag' => t('Filter by this tag'),
        'replyPlaceholder' => t('Write a reply...'),
        'internalPlaceholder' => t('Internal note for agents...'),
        'descriptionPlaceholder' => t('Description...'),
        'quickStartDetails' => t('Name this work'),
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
<script src="assets/js/chip-select.js?v=<?php echo APP_VERSION; ?>"></script>
<?php endif; ?>

<!-- Quill Editor JS (self-hosted) -->
<script src="assets/vendor/quill/2.0.2/quill.js?v=<?php echo APP_VERSION; ?>"></script>
<script src="assets/js/quill-image-upload.js?v=<?php echo APP_VERSION; ?>"></script>
<script src="assets/js/rich-text-editor.js?v=<?php echo APP_VERSION; ?>"></script>
<script src="assets/js/attachment-paste-drop.js?v=<?php echo APP_VERSION; ?>"></script>

<!-- Autosave for comment editor -->
<script src="assets/js/autosave.js?v=<?php echo APP_VERSION; ?>"></script>

<?php if (function_exists('can_view_timeline') && can_view_timeline($user)): ?>
<!-- Timeline Modal -->
<div id="timeline-overlay" onclick="closeTimeline()" style="display:none; position:fixed; inset:0; z-index:50; background:rgba(0,0,0,0.5);">
    <div onclick="event.stopPropagation()" style="position:absolute; top:50%; inset-inline-start:50%; transform:translate(-50%,-50%); width:100%; max-width:640px; max-height:85vh; border-radius: var(--fd-radius-card); box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); display:flex; flex-direction:column; background:var(--surface-primary); color:var(--text-primary);">
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

<script src="assets/js/ticket-detail-core.js?v=<?php echo APP_VERSION; ?>"></script>
<script src="assets/js/ticket-detail-workflow.js?v=<?php echo APP_VERSION; ?>"></script>
<script src="assets/js/ticket-detail-records.js?v=<?php echo APP_VERSION; ?>"></script>
<script src="assets/js/ticket-detail-admin.js?v=<?php echo APP_VERSION; ?>"></script>
<script src="assets/js/ticket-detail.js?v=<?php echo APP_VERSION; ?>"></script>

<?php require_once BASE_PATH . '/includes/footer.php';
