<?php
/**
 * Ticket detail surface: work panel, description, conversation, composer,
 * sidebar and modals. Variables are prepared by pages/ticket-detail.php.
 */
?>
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
                    <?php ticket_detail_render_status_pipeline($ticket, $statuses); ?>
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
