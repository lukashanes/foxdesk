<?php
/**
 * Notifications Page — Full-page notification list
 * Shows all notifications with filters, ticket grouping, deep links, and mark-as-read.
 */

$page_title = t('Notifications');
$page = 'notifications';
$user = current_user();

// Load notification functions
if (file_exists(BASE_PATH . '/includes/notification-functions.php')) {
    require_once BASE_PATH . '/includes/notification-functions.php';
}

// Filter: all | action | info | resolved
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'action', 'info', 'resolved'])) {
    $filter = 'all';
}

// Fetch notifications (server-side, first 50)
$all_notifications = [];
$unread_count = 0;
if (function_exists('notifications_table_exists') && notifications_table_exists()) {
    // For "resolved" filter, include resolved notifications; otherwise exclude them
    $exclude_resolved = ($filter !== 'resolved');
    $result = get_user_notifications((int) $user['id'], 50, 0, $exclude_resolved);
    $all_notifications = $result['notifications'];
    $unread_count = $result['unread_count'];
}

// Apply filter
$notifications = [];
foreach ($all_notifications as $n) {
    $data = $n['data'] ?? [];
    $is_action = is_action_required_notification($n['type'], $data);
    $is_resolved = !empty($n['is_resolved']);
    if ($filter === 'action' && !$is_action) continue;
    if ($filter === 'info' && $is_action) continue;
    if ($filter === 'resolved' && !$is_resolved) continue;
    $notifications[] = $n;
}

// Group by date, then sub-group each date bucket by ticket
$grouped = group_notifications($notifications);
foreach (['today', 'yesterday', 'earlier'] as $grp) {
    $grouped[$grp] = group_by_ticket($grouped[$grp]);
}

require_once BASE_PATH . '/includes/header.php';

/**
 * Helper: render a single notification card (primary or standalone).
 */
function render_notif_card(array $notif, int $group_count = 1): void
{
    $n_is_read = (bool) $notif['is_read'];
    $n_time = notification_time_ago($notif['created_at']);
    $n_ticket_id = $notif['ticket_id'] ? (int) $notif['ticket_id'] : null;
    $n_data = $notif['data'] ?? [];
    $n_comment_id = $n_data['comment_id'] ?? null;
    $n_snippet = get_notification_snippet($notif);
    $n_is_action = is_action_required_notification($notif['type'], $n_data);

    // Ticket subject (primary line)
    $n_subject = $n_data['ticket_subject'] ?? '';
    if (mb_strlen($n_subject) > 60) {
        $n_subject = mb_substr($n_subject, 0, 57) . '...';
    }
    // Action text (secondary line — no ticket subject)
    $n_action = format_notification_action($notif);

    // Deep link URL
    $n_href = '#';
    if ($n_ticket_id) {
        $n_href = 'index.php?page=ticket&id=' . $n_ticket_id . '&ref=notifications&nid=' . (int)$notif['id'];
        if ($n_comment_id) {
            $n_href .= '#comment-' . $n_comment_id;
        }
    }

    $n_actor_name = trim(($notif['actor_first_name'] ?? '') . ' ' . ($notif['actor_last_name'] ?? ''));
    $n_actor_avatar = $notif['actor_avatar'] ?? null;
    $n_initials = mb_strtoupper(mb_substr($notif['actor_first_name'] ?? '?', 0, 1));
    $avatar_bg = 'hsl(' . abs(crc32($n_actor_name)) % 360 . ', 55%, 60%)';

    // Type icon + color
    $type_icon = 'bell';
    $type_color = '#6b7280';
    switch ($notif['type']) {
        case 'new_ticket':       $type_icon = 'plus';                  $type_color = '#10b981'; break;
        case 'new_comment':      $type_icon = 'comment';               $type_color = '#3b82f6'; break;
        case 'status_changed':   $type_icon = 'refresh-cw';            $type_color = '#8b5cf6'; break;
        case 'assigned_to_you':  $type_icon = 'user-plus';             $type_color = '#f59e0b'; break;
        case 'priority_changed': $type_icon = 'exclamation-triangle';  $type_color = '#ef4444'; break;
        case 'ticket_updated':   $type_icon = 'edit';                  $type_color = '#6366f1'; break;
        case 'due_date_reminder': $type_icon = 'clock';                $type_color = '#ef4444'; break;
    }
    ?>
    <div class="notif-card <?php echo $n_is_read ? '' : 'unread'; ?>"
         id="notif-item-<?php echo (int)$notif['id']; ?>"
         data-id="<?php echo (int)$notif['id']; ?>">
        <!-- Avatar -->
        <a href="<?php echo $n_href; ?>" class="notif-avatar" style="background: <?php echo $avatar_bg; ?>;">
            <?php if ($n_actor_avatar && !str_starts_with($n_actor_avatar, 'data:')): ?>
                <img src="<?php echo e(upload_url($n_actor_avatar)); ?>" alt=""
                     onerror="this.style.display='none';this.parentElement.textContent='<?php echo e($n_initials); ?>'">
            <?php elseif ($n_actor_avatar && str_starts_with($n_actor_avatar, 'data:')): ?>
                <img src="<?php echo e($n_actor_avatar); ?>" alt="">
            <?php else: ?>
                <?php echo e($n_initials); ?>
            <?php endif; ?>
        </a>
        <!-- Content — ticket subject first, action second -->
        <a href="<?php echo $n_href; ?>" class="notif-card-content" style="text-decoration: none;">
            <?php if ($n_subject): ?>
                <div class="notif-card-subject"><?php echo e($n_subject); ?></div>
            <?php endif; ?>
            <div class="notif-card-action"><?php echo e($n_action); ?></div>
            <?php if ($n_snippet): ?>
                <div class="notif-card-snippet"><?php echo e($n_snippet); ?></div>
            <?php endif; ?>
            <div class="notif-card-meta">
                <span class="notif-type-icon" style="color: <?php echo e($type_color); ?>;">
                    <?php echo get_icon($type_icon, 'w-3 h-3'); ?>
                </span>
                <span class="notif-card-time"><?php echo e($n_time); ?></span>
                <?php if ($n_is_action): ?>
                    <span class="notif-action-badge"><?php echo e(t('Action required')); ?></span>
                <?php endif; ?>
            </div>
        </a>
        <!-- Actions -->
        <div class="notif-card-actions">
            <?php if ($group_count > 1): ?>
                <button type="button" class="notif-group-toggle" title="<?php echo e(t('Show all')); ?>">
                    <span class="notif-group-count">+<?php echo $group_count - 1; ?></span>
                </button>
            <?php endif; ?>
            <?php if ($n_ticket_id && $group_count > 1 && !$n_is_read): ?>
                <button type="button" class="notif-mark-read-btn"
                        onclick="event.stopPropagation(); markTicketNotifsRead(<?php echo $n_ticket_id; ?>)"
                        title="<?php echo e(t('Mark all for this ticket as read')); ?>">
                    <?php echo get_icon('check-circle', 'w-4 h-4'); ?>
                </button>
            <?php endif; ?>
            <?php if (!$n_is_read): ?>
                <button type="button" class="notif-mark-read-btn"
                        onclick="event.stopPropagation(); markNotifRead(<?php echo (int)$notif['id']; ?>)"
                        title="<?php echo e(t('Mark as read')); ?>">
                    <?php echo get_icon('check', 'w-4 h-4'); ?>
                </button>
            <?php endif; ?>
            <a href="<?php echo $n_href; ?>" class="notif-mark-read-btn"
               title="<?php echo e(t('Open')); ?>">
                <?php echo get_icon('chevron-right', 'w-4 h-4'); ?>
            </a>
        </div>
    </div>
    <?php
}

/**
 * Helper: render a compact child notification card.
 * Shows action text only (ticket subject is in the parent card).
 */
function render_child_card(array $notif): void
{
    $n_is_read = (bool) $notif['is_read'];
    $n_action = format_notification_action($notif);
    $n_time = notification_time_ago($notif['created_at']);
    $n_ticket_id = $notif['ticket_id'] ? (int) $notif['ticket_id'] : null;
    $n_data = $notif['data'] ?? [];
    $n_comment_id = $n_data['comment_id'] ?? null;
    $n_is_action = is_action_required_notification($notif['type'], $n_data);

    $n_href = '#';
    if ($n_ticket_id) {
        $n_href = 'index.php?page=ticket&id=' . $n_ticket_id . '&ref=notifications&nid=' . (int)$notif['id'];
        if ($n_comment_id) $n_href .= '#comment-' . $n_comment_id;
    }

    $type_icon = 'bell';
    $type_color = '#6b7280';
    switch ($notif['type']) {
        case 'new_ticket':       $type_icon = 'plus';                  $type_color = '#10b981'; break;
        case 'new_comment':      $type_icon = 'comment';               $type_color = '#3b82f6'; break;
        case 'status_changed':   $type_icon = 'refresh-cw';            $type_color = '#8b5cf6'; break;
        case 'assigned_to_you':  $type_icon = 'user-plus';             $type_color = '#f59e0b'; break;
        case 'priority_changed': $type_icon = 'exclamation-triangle';  $type_color = '#ef4444'; break;
        case 'ticket_updated':   $type_icon = 'edit';                  $type_color = '#6366f1'; break;
        case 'due_date_reminder': $type_icon = 'clock';                $type_color = '#ef4444'; break;
    }
    ?>
    <a href="<?php echo $n_href; ?>" class="notif-child-card <?php echo $n_is_read ? '' : 'unread'; ?>"
       id="notif-item-<?php echo (int)$notif['id']; ?>" data-id="<?php echo (int)$notif['id']; ?>">
        <span class="notif-type-icon" style="color: <?php echo e($type_color); ?>;">
            <?php echo get_icon($type_icon, 'w-3.5 h-3.5'); ?>
        </span>
        <span class="notif-child-text"><?php echo e($n_action); ?></span>
        <span class="notif-child-time"><?php echo e($n_time); ?></span>
        <?php if ($n_is_action): ?>
            <span class="notif-action-badge"><?php echo e(t('Action required')); ?></span>
        <?php endif; ?>
    </a>
    <?php
}
?>

<style>
    .notif-page-wrap {
        max-width: 800px;
        margin: 0 auto;
        padding: 24px 16px;
    }
    .notif-page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }
    .notif-page-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .notif-page-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 22px;
        height: 22px;
        padding: 0 6px;
        font-size: 12px;
        font-weight: 700;
        color: #fff;
        background: #ef4444;
        border-radius: 11px;
    }
    .notif-filter-tabs {
        display: flex;
        gap: 4px;
        padding: 3px;
        border-radius: 10px;
        background: var(--surface-secondary, #f1f5f9);
    }
    .notif-filter-tab {
        padding: 6px 14px;
        font-size: 0.8125rem;
        font-weight: 500;
        border-radius: 8px;
        color: var(--text-secondary);
        text-decoration: none;
        transition: all 0.15s;
        white-space: nowrap;
    }
    .notif-filter-tab:hover {
        background: var(--surface-primary, #fff);
        color: var(--text-primary);
    }
    .notif-filter-tab.active {
        background: var(--surface-primary, #fff);
        color: var(--primary, #3b82f6);
        font-weight: 600;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }
    .notif-date-label {
        font-size: 0.6875rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-muted);
        padding: 12px 4px 6px;
    }

    /* ── Primary notification card ──────────────────────────────────────────── */
    .notif-card {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 12px 14px;
        border-radius: 0 8px 8px 0;
        text-decoration: none;
        transition: background 0.12s, border-color 0.12s;
        border-left: 4px solid transparent;
        position: relative;
    }
    .notif-card:hover {
        background: var(--primary-soft, rgba(59,130,246,0.04));
    }
    .notif-card.unread {
        border-left-color: var(--accent-primary, #3b82f6);
        background: var(--primary-soft, rgba(59,130,246,0.04));
    }
    .notif-card .notif-avatar {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        font-weight: 600;
        color: #fff;
        overflow: hidden;
    }
    .notif-card .notif-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .notif-card-content {
        flex: 1;
        min-width: 0;
    }
    .notif-card-subject {
        font-size: 0.875rem;
        font-weight: 600;
        line-height: 1.4;
        color: var(--text-primary);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .notif-card-action {
        font-size: 0.8125rem;
        color: var(--text-secondary);
        margin-top: 2px;
        line-height: 1.4;
    }
    .notif-card-snippet {
        font-size: 0.8125rem;
        color: var(--text-muted);
        margin-top: 3px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 500px;
    }
    .notif-card-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 4px;
    }
    .notif-type-icon {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        font-size: 0.6875rem;
    }
    .notif-card-time {
        font-size: 0.75rem;
        color: var(--text-muted);
    }
    .notif-action-badge {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        font-size: 0.625rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        padding: 2px 6px;
        border-radius: 99px;
        background: #fff7ed;
        color: #ea580c;
    }
    [data-theme="dark"] .notif-action-badge {
        background: rgba(234, 88, 12, 0.15);
        color: #fb923c;
    }
    .notif-card-actions {
        flex-shrink: 0;
        display: flex;
        align-items: center;
        gap: 4px;
        opacity: 0;
        transition: opacity 0.15s;
    }
    .notif-card:hover .notif-card-actions {
        opacity: 1;
    }
    /* N5: Always show mark-as-read on mobile */
    @media (max-width: 640px) {
        .notif-card-actions { opacity: 1; }
    }
    /* Always show group count badge */
    .notif-group-toggle {
        opacity: 1 !important;
        border: none;
        background: none;
        cursor: pointer;
        padding: 0;
    }
    .notif-card .notif-group-toggle {
        opacity: 1;
    }
    .notif-mark-read-btn {
        padding: 4px;
        border-radius: 6px;
        color: var(--text-muted);
        cursor: pointer;
        transition: color 0.12s, background 0.12s;
        border: none;
        background: none;
    }
    .notif-mark-read-btn:hover {
        color: var(--primary, #3b82f6);
        background: var(--primary-soft, rgba(59,130,246,0.08));
    }

    /* ── Ticket group wrapper ───────────────────────────────────────────────── */
    .notif-ticket-group {
        border-radius: 12px;
        transition: background 0.15s;
    }

    /* Group count badge — always visible */
    .notif-group-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 20px;
        height: 20px;
        padding: 0 6px;
        font-size: 0.6875rem;
        font-weight: 600;
        border-radius: 10px;
        background: var(--surface-secondary, #e2e8f0);
        color: var(--text-secondary);
        flex-shrink: 0;
        transition: background 0.15s, color 0.15s;
    }
    .notif-group-toggle:hover .notif-group-count {
        background: var(--primary, #3b82f6);
        color: #fff;
    }

    /* Collapsed children — hidden by default */
    .notif-group-children {
        max-height: 0;
        overflow: hidden;
        opacity: 0;
        transition: max-height 0.3s ease, opacity 0.2s ease, padding 0.2s ease;
        padding: 0 0 0 48px;
        margin-left: 18px;
        border-left: 2px solid transparent;
    }

    /* Expanded on hover (desktop) or toggle click */
    .notif-ticket-group:hover .notif-group-children,
    .notif-ticket-group.expanded .notif-group-children {
        max-height: 600px;
        opacity: 1;
        padding: 4px 0 8px 48px;
        border-left-color: var(--border-light, #e2e8f0);
    }

    /* Child notification — compact row */
    .notif-child-card {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 10px;
        border-radius: 8px;
        font-size: 0.8125rem;
        color: var(--text-secondary);
        text-decoration: none;
        transition: background 0.12s, color 0.12s;
    }
    .notif-child-card:hover {
        background: var(--primary-soft, rgba(59,130,246,0.06));
        color: var(--text-primary);
    }
    .notif-child-card.unread {
        color: var(--text-primary);
        font-weight: 600;
    }
    .notif-child-text {
        flex: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .notif-child-time {
        font-size: 0.6875rem;
        color: var(--text-muted);
        flex-shrink: 0;
        white-space: nowrap;
    }

    /* ── Empty state & actions ──────────────────────────────────────────────── */
    .notif-empty {
        text-align: center;
        padding: 48px 16px;
        color: var(--text-muted);
    }
    .notif-empty-icon {
        opacity: 0.25;
        margin-bottom: 12px;
    }
    .notif-mark-all-btn {
        padding: 6px 14px;
        font-size: 0.8125rem;
        font-weight: 500;
        border-radius: 8px;
        border: 1px solid var(--border-light);
        background: var(--surface-primary, #fff);
        color: var(--text-secondary);
        cursor: pointer;
        transition: all 0.15s;
    }
    .notif-mark-all-btn:hover {
        border-color: var(--primary, #3b82f6);
        color: var(--primary, #3b82f6);
    }
    .notif-mark-all-btn:disabled {
        opacity: 0.4;
        cursor: default;
    }
    .notif-mark-all-btn:disabled:hover {
        border-color: var(--border-light);
        color: var(--text-secondary);
    }
    .notif-load-more {
        display: block;
        width: 100%;
        padding: 12px;
        text-align: center;
        font-size: 0.8125rem;
        font-weight: 600;
        color: var(--primary, #3b82f6);
        background: none;
        border: 1px dashed var(--border-light);
        border-radius: 10px;
        cursor: pointer;
        transition: background 0.15s;
        margin-top: 12px;
    }
    .notif-load-more:hover {
        background: var(--primary-soft, rgba(59,130,246,0.04));
    }
    @media (max-width: 640px) {
        .notif-page-header { flex-direction: column; align-items: flex-start; }
        .notif-card-snippet { max-width: 250px; }
        /* On mobile, don't expand on hover — only on click/toggle */
        .notif-ticket-group:hover .notif-group-children {
            max-height: 0;
            opacity: 0;
            padding: 0 0 0 48px;
            border-left-color: transparent;
        }
        .notif-ticket-group.expanded .notif-group-children {
            max-height: 600px;
            opacity: 1;
            padding: 4px 0 8px 48px;
            border-left-color: var(--border-light, #e2e8f0);
        }
    }

    /* ── Compact mode ───────────────────────────────────────────────────── */
    .notif-view-toggle {
        display: inline-flex;
        gap: 2px;
        padding: 3px;
        border-radius: 8px;
        background: var(--surface-secondary, #f1f5f9);
    }
    .notif-view-btn {
        padding: 5px 8px;
        border: none;
        background: none;
        border-radius: 6px;
        color: var(--text-muted);
        cursor: pointer;
        transition: all 0.15s;
        display: flex;
        align-items: center;
    }
    .notif-view-btn:hover {
        color: var(--text-primary);
    }
    .notif-view-btn.active {
        background: var(--surface-primary, #fff);
        color: var(--primary, #3b82f6);
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }

    .notif-page-wrap.compact .notif-avatar { display: none; }
    .notif-page-wrap.compact .notif-card { padding: 8px 14px; gap: 8px; }
    .notif-page-wrap.compact .notif-card-snippet { display: none; }
    .notif-page-wrap.compact .notif-card-subject { font-size: 0.8125rem; }
    .notif-page-wrap.compact .notif-card-action { font-size: 0.75rem; margin-top: 1px; }
    .notif-page-wrap.compact .notif-card-meta { margin-top: 2px; }
</style>

<div class="notif-page-wrap" id="notifPageWrap">
    <!-- Header -->
    <div class="notif-page-header">
        <div class="notif-page-title">
            <?php echo get_icon('bell', 'w-6 h-6'); ?>
            <?php echo e(t('Notifications')); ?>
            <?php if ($unread_count > 0): ?>
                <span class="notif-page-badge"><?php echo $unread_count > 99 ? '99+' : $unread_count; ?></span>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-3">
            <button type="button" class="notif-mark-all-btn" onclick="markAllNotifRead()"
                    <?php echo $unread_count <= 0 ? 'disabled' : ''; ?>>
                <?php echo get_icon('check', 'w-4 h-4 inline-block'); ?>
                <?php echo e(t('Mark all as read')); ?>
            </button>
            <div class="notif-view-toggle">
                <button type="button" class="notif-view-btn active" data-view="normal"
                        onclick="setNotifView('normal')" title="<?php echo e(t('Normal')); ?>">
                    <?php echo get_icon('grid', 'w-4 h-4'); ?>
                </button>
                <button type="button" class="notif-view-btn" data-view="compact"
                        onclick="setNotifView('compact')" title="<?php echo e(t('Compact')); ?>">
                    <?php echo get_icon('list', 'w-4 h-4'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Filter tabs -->
    <div class="notif-filter-tabs" style="margin-bottom: 16px;">
        <a href="<?php echo url('notifications'); ?>"
           class="notif-filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
            <?php echo e(t('All')); ?>
        </a>
        <a href="<?php echo url('notifications', ['filter' => 'action']); ?>"
           class="notif-filter-tab <?php echo $filter === 'action' ? 'active' : ''; ?>">
            <?php echo e(t('Action required')); ?>
        </a>
        <a href="<?php echo url('notifications', ['filter' => 'info']); ?>"
           class="notif-filter-tab <?php echo $filter === 'info' ? 'active' : ''; ?>">
            <?php echo e(t('Informational')); ?>
        </a>
        <a href="<?php echo url('notifications', ['filter' => 'resolved']); ?>"
           class="notif-filter-tab <?php echo $filter === 'resolved' ? 'active' : ''; ?>">
            <?php echo e(t('Resolved')); ?>
        </a>
    </div>

    <!-- Notification list -->
    <div id="notif-list">
        <?php if (empty($notifications)): ?>
            <div class="notif-empty">
                <?php echo get_icon('bell', 'w-12 h-12 notif-empty-icon'); ?>
                <p class="text-base font-medium" style="color: var(--text-secondary);"><?php echo e(t('No notifications')); ?></p>
                <p class="text-sm mt-1"><?php echo e(t('Activity on your tickets will appear here')); ?></p>
            </div>
        <?php else: ?>
            <?php
            $group_labels = [
                'today' => t('Today'),
                'yesterday' => t('Yesterday'),
                'earlier' => t('Earlier'),
            ];
            foreach (['today', 'yesterday', 'earlier'] as $grp):
                if (empty($grouped[$grp])) continue;
            ?>
                <div class="notif-date-label"><?php echo e($group_labels[$grp]); ?></div>
                <div class="space-y-1">
                    <?php foreach ($grouped[$grp] as $tg):
                        $primary = $tg['primary'];
                        $others = $tg['others'];
                        $group_count = $tg['count'];
                    ?>
                        <?php if ($group_count > 1): ?>
                            <!-- Ticket group: primary + collapsed children -->
                            <div class="notif-ticket-group">
                                <?php render_notif_card($primary, $group_count); ?>
                                <div class="notif-group-children">
                                    <?php foreach ($others as $child): ?>
                                        <?php render_child_card($child); ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Single notification (no group) -->
                            <?php render_notif_card($primary); ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <?php if (count($all_notifications) >= 50): ?>
                <button type="button" class="notif-load-more" id="notif-load-more-btn"
                        onclick="loadMoreNotifs()">
                    <?php echo e(t('Load more')); ?>
                </button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    var _offset = <?php echo count($all_notifications); ?>;
    var _filter = <?php echo json_encode($filter); ?>;
    var _loading = false;

    // ── Toggle group expand on click (mobile + desktop fallback) ─────────
    document.addEventListener('click', function(e) {
        var toggle = e.target.closest('.notif-group-toggle');
        if (!toggle) return;
        e.preventDefault();
        e.stopPropagation();
        var group = toggle.closest('.notif-ticket-group');
        if (group) group.classList.toggle('expanded');
    });

    // ── Mark single notification as read ─────────────────────────────────
    window.markNotifRead = function(id) {
        fetch('index.php?page=api&action=mark-notification-read', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'notification_id=' + id + '&csrf_token=' + encodeURIComponent(window.csrfToken)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                var el = document.getElementById('notif-item-' + id);
                if (el) {
                    el.classList.remove('unread');
                    el.querySelectorAll('button.notif-mark-read-btn').forEach(function(btn) { btn.remove(); });
                }
                var badge = document.querySelector('.notif-page-badge');
                var count = res.unread_count ?? 0;
                if (badge) {
                    if (count <= 0) badge.remove();
                    else badge.textContent = count > 99 ? '99+' : count;
                }
                if (typeof updateBadge === 'function') updateBadge(count);
            }
        });
    };

    // ── Mark all for a ticket as read (group dismiss) ──────────────────
    window.markTicketNotifsRead = function(ticketId) {
        fetch('index.php?page=api&action=mark-ticket-notifications-read', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'ticket_id=' + ticketId + '&csrf_token=' + encodeURIComponent(window.csrfToken)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                // Find the ticket group and mark all items read
                document.querySelectorAll('.notif-card[data-id], .notif-child-card[data-id]').forEach(function(el) {
                    // Check if this notification's link contains the ticket ID
                    var link = el.querySelector('a[href*="id=' + ticketId + '"]') || el;
                    var href = link.getAttribute('href') || '';
                    if (href.indexOf('id=' + ticketId + '&') !== -1 || href.indexOf('id=' + ticketId + '#') !== -1) {
                        el.classList.remove('unread');
                        var btn = el.querySelector('button.notif-mark-read-btn');
                        if (btn) btn.remove();
                    }
                });
                // Update badge with server count
                var count = res.unread_count ?? 0;
                var badge = document.querySelector('.notif-page-badge');
                if (badge) {
                    if (count <= 0) badge.remove();
                    else badge.textContent = count > 99 ? '99+' : count;
                }
                if (typeof updateBadge === 'function') updateBadge(count);
            }
        });
    };

    // ── Mark all as read ─────────────────────────────────────────────────
    window.markAllNotifRead = function() {
        fetch('index.php?page=api&action=mark-all-notifications-read', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'csrf_token=' + encodeURIComponent(window.csrfToken)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                document.querySelectorAll('.notif-card.unread').forEach(function(el) {
                    el.classList.remove('unread');
                });
                document.querySelectorAll('.notif-child-card.unread').forEach(function(el) {
                    el.classList.remove('unread');
                });
                document.querySelectorAll('.notif-card-actions .notif-mark-read-btn').forEach(function(btn) {
                    if (btn.tagName === 'BUTTON') btn.remove();
                });
                var badge = document.querySelector('.notif-page-badge');
                if (badge) badge.remove();
                var markAllBtn = document.querySelector('.notif-mark-all-btn');
                if (markAllBtn) markAllBtn.disabled = true;
                if (typeof updateBadge === 'function') updateBadge(res.unread_count ?? 0);
            }
        });
    };

    // ── Load more ────────────────────────────────────────────────────────
    window.loadMoreNotifs = function() {
        if (_loading) return;
        _loading = true;
        var btn = document.getElementById('notif-load-more-btn');
        if (btn) btn.textContent = '...';

        fetch('index.php?page=api&action=get-notifications&limit=50&offset=' + _offset)
        .then(function(r) { return r.json(); })
        .then(function(res) {
            _loading = false;
            if (!res.success) return;

            var groups = res.groups || {};
            var hasItems = false;
            var container = document.getElementById('notif-list');

            ['today', 'yesterday', 'earlier'].forEach(function(grp) {
                var ticketGroups = groups[grp] || [];
                ticketGroups.forEach(function(tg) {
                    var primary = tg.primary;
                    if (!primary) return;

                    // Apply filter on primary
                    var isAction = isActionRequired(primary);
                    if (_filter === 'action' && !isAction) return;
                    if (_filter === 'info' && isAction) return;

                    hasItems = true;
                    _offset += tg.count || 1;

                    var html = buildNotifGroup(tg);
                    var lastDiv = container.querySelector('.space-y-1:last-of-type');
                    if (lastDiv) lastDiv.insertAdjacentHTML('beforeend', html);
                    else container.insertAdjacentHTML('beforeend', '<div class="space-y-1">' + html + '</div>');
                });
            });

            if (!hasItems || _offset >= 200) {
                if (btn) btn.remove();
            } else {
                if (btn) btn.textContent = <?php echo json_encode(t('Load more')); ?>;
            }
        })
        .catch(function() {
            _loading = false;
            if (btn) btn.textContent = <?php echo json_encode(t('Load more')); ?>;
        });
    };

    // ── Helper: check if action required ─────────────────────────────────
    function isActionRequired(n) {
        var data = n.data || {};
        return n.type === 'assigned_to_you' || n.type === 'due_date_reminder' ||
               (n.type === 'new_comment' && data.action_required);
    }

    // ── Helper: escape HTML ──────────────────────────────────────────────
    function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    function avatarColor(name) {
        var h = 0;
        for (var i = 0; i < name.length; i++) h = (name.charCodeAt(i) + ((h << 5) - h));
        return 'hsl(' + (Math.abs(h) % 360) + ', 55%, 60%)';
    }

    function typeInfo(type) {
        switch(type) {
            case 'new_ticket':       return { icon: 'plus',                 color: '#10b981' };
            case 'new_comment':      return { icon: 'comment',              color: '#3b82f6' };
            case 'status_changed':   return { icon: 'refresh-cw',           color: '#8b5cf6' };
            case 'assigned_to_you':  return { icon: 'user-plus',            color: '#f59e0b' };
            case 'priority_changed': return { icon: 'exclamation-triangle', color: '#ef4444' };
            case 'ticket_updated':   return { icon: 'edit',                 color: '#6366f1' };
            case 'due_date_reminder': return { icon: 'clock',               color: '#ef4444' };
            default:                 return { icon: 'bell',                 color: '#6b7280' };
        }
    }

    // ── Build a full ticket group (primary + children) ───────────────────
    function buildNotifGroup(tg) {
        var primary = tg.primary;
        var others = tg.others || [];
        var count = tg.count || 1;

        if (count <= 1) {
            return buildNotifCard(primary, 1);
        }

        var html = '<div class="notif-ticket-group">';
        html += buildNotifCard(primary, count);
        html += '<div class="notif-group-children">';
        others.forEach(function(child) {
            html += buildChildCard(child);
        });
        html += '</div></div>';
        return html;
    }

    // ── Build primary notification card ──────────────────────────────────
    function buildNotifCard(n, groupCount) {
        groupCount = groupCount || 1;
        var data = n.data || {};
        var isRead = !!n.is_read;
        var isAction = isActionRequired(n);
        var actorName = ((n.actor_first_name || '') + ' ' + (n.actor_last_name || '')).trim();
        var initials = (n.actor_first_name || '?').charAt(0).toUpperCase();
        var snippet = data.comment_preview || '';
        var commentId = data.comment_id || null;
        var ticketId = n.ticket_id || null;
        var href = '#';
        if (ticketId) {
            href = 'index.php?page=ticket&id=' + ticketId + '&ref=notifications&nid=' + n.id;
            if (commentId) href += '#comment-' + commentId;
        }
        var ti = typeInfo(n.type);
        var text = n.formatted_text || n.text || esc(data.actor_name || actorName) + ' — ' + esc(n.type);

        var html = '<div class="notif-card ' + (isRead ? '' : 'unread') + '" id="notif-item-' + n.id + '" data-id="' + n.id + '">';
        html += '<a href="' + esc(href) + '" class="notif-avatar" style="background:' + avatarColor(actorName) + '">';
        if (n.actor_avatar) {
            html += '<img src="' + esc(n.actor_avatar) + '" alt="" onerror="this.style.display=\'none\';this.parentElement.textContent=\'' + esc(initials) + '\'">';
        } else {
            html += esc(initials);
        }
        html += '</a>';
        html += '<a href="' + esc(href) + '" class="notif-card-content" style="text-decoration:none">';
        html += '<div class="notif-card-text">' + esc(text) + '</div>';
        if (snippet) html += '<div class="notif-card-snippet">' + esc(snippet) + '</div>';
        html += '<div class="notif-card-meta">';
        html += '<span class="notif-type-icon" style="color:' + ti.color + '"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/></svg></span>';
        html += '<span class="notif-card-time">' + esc(n.time_ago || '') + '</span>';
        if (isAction) html += '<span class="notif-action-badge">' + esc(<?php echo json_encode(t('Action required')); ?>) + '</span>';
        html += '</div></a>';
        html += '<div class="notif-card-actions">';
        if (groupCount > 1) {
            html += '<button type="button" class="notif-group-toggle"><span class="notif-group-count">+' + (groupCount - 1) + '</span></button>';
        }
        if (!isRead) {
            html += '<button type="button" class="notif-mark-read-btn" onclick="event.stopPropagation();markNotifRead(' + n.id + ')" title="' + esc(<?php echo json_encode(t('Mark as read')); ?>) + '">';
            html += '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
            html += '</button>';
        }
        html += '<a href="' + esc(href) + '" class="notif-mark-read-btn">';
        html += '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>';
        html += '</a></div></div>';
        return html;
    }

    // ── Build compact child card ─────────────────────────────────────────
    function buildChildCard(n) {
        var data = n.data || {};
        var isRead = !!n.is_read;
        var isAction = isActionRequired(n);
        var ticketId = n.ticket_id || null;
        var commentId = data.comment_id || null;
        var href = '#';
        if (ticketId) {
            href = 'index.php?page=ticket&id=' + ticketId + '&ref=notifications&nid=' + n.id;
            if (commentId) href += '#comment-' + commentId;
        }
        var ti = typeInfo(n.type);
        var text = n.formatted_text || n.text || n.type;

        var html = '<a href="' + esc(href) + '" class="notif-child-card ' + (isRead ? '' : 'unread') + '" id="notif-item-' + n.id + '" data-id="' + n.id + '">';
        html += '<span class="notif-type-icon" style="color:' + ti.color + '"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/></svg></span>';
        html += '<span class="notif-child-text">' + esc(text) + '</span>';
        html += '<span class="notif-child-time">' + esc(n.time_ago || '') + '</span>';
        if (isAction) html += '<span class="notif-action-badge">' + esc(<?php echo json_encode(t('Action required')); ?>) + '</span>';
        html += '</a>';
        return html;
    }
})();

    // ── Compact / Normal view toggle ─────────────────────────────────────
    var savedView = localStorage.getItem('notif_view') || 'normal';
    var wrap = document.getElementById('notifPageWrap');
    if (savedView === 'compact' && wrap) {
        wrap.classList.add('compact');
        var btns = document.querySelectorAll('.notif-view-btn');
        btns.forEach(function(b) {
            b.classList.toggle('active', b.getAttribute('data-view') === 'compact');
        });
    }

    window.setNotifView = function(view) {
        var w = document.getElementById('notifPageWrap');
        if (!w) return;
        if (view === 'compact') {
            w.classList.add('compact');
        } else {
            w.classList.remove('compact');
        }
        localStorage.setItem('notif_view', view);
        document.querySelectorAll('.notif-view-btn').forEach(function(b) {
            b.classList.toggle('active', b.getAttribute('data-view') === view);
        });
    };
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
