<?php
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo e(t('Read only'));
    return;
}
$preview_target = get_user((int) $_GET['preview_user']);
$preview_allowed = ticket_audience_preview_allowed($ticket, $user, $preview_target ?: null);
$page_title = t('media.preview');
if (!$preview_allowed) http_response_code(403);
require BASE_PATH . '/includes/header.php';
?>
<section class="ticket-detail-page ticket-audience-preview">
    <div class="card ticket-preview-banner" role="status">
        <div><strong><?php echo e(t('media.preview')); ?><?php if ($preview_allowed): ?>: <bdi dir="auto"><?php echo e(user_avatar_display_name($preview_target)); ?></bdi><?php endif; ?></strong><p><?php echo e(t('Read only')); ?> · <?php echo e(t('ticket.preview.explanation')); ?></p></div>
        <a class="btn btn-secondary btn-sm" href="<?php echo e(ticket_url($ticket)); ?>"><?php echo e(t('Back')); ?></a>
    </div>
    <?php if (!$preview_allowed): ?>
        <div class="card p-6"><?php echo e(t('You do not have permission to view this ticket.')); ?></div>
    <?php else:
        $preview = ticket_audience_preview_filter($preview_target, get_ticket_comments($ticket_id), get_ticket_attachments($ticket_id), get_ticket_time_entries($ticket_id));
        $attachments = $preview['attachments'];
    ?>
    <header class="ticket-work-panel"><span><?php echo e(get_ticket_code($ticket_id)); ?> · <bdi dir="auto"><?php echo e($ticket['organization_name'] ?? ''); ?></bdi></span><h1 class="ticket-work-panel__title"><bdi dir="auto"><?php echo e($ticket['title']); ?></bdi></h1></header>
    <div class="ticket-detail-layout">
        <div class="ticket-detail-main card">
            <section class="ticket-description"><h2><?php echo e(t('Description')); ?></h2><div class="rich-content" dir="auto"><?php echo render_content($ticket['description']); ?></div>
                <?php $component_attachments = ticket_detail_initial_attachments($attachments); $component_layout = 'grid'; include BASE_PATH . '/includes/components/attachment-grid.php'; ?>
            </section>
            <section class="ticket-activity-list">
            <?php foreach ($preview['comments'] as $comment): ?>
                <article class="ticket-comment <?php echo !empty($comment['is_internal']) ? 'ticket-comment--internal' : ''; ?>">
                    <div class="ticket-comment__inner"><?php echo render_user_avatar($comment, 'md', 'ticket-comment__avatar'); ?><div class="ticket-comment__content">
                        <div class="ticket-comment__header"><strong><bdi dir="auto"><?php echo e($comment['first_name'].' '.$comment['last_name']); ?></bdi></strong><span class="ticket-comment__date"><?php echo e(format_date($comment['created_at'])); ?></span><?php if (!empty($comment['is_internal'])): ?><span class="ticket-comment__badge"><?php echo e(t('Internal')); ?></span><?php endif; ?></div>
                        <div class="rich-content" dir="auto"><?php echo render_content($comment['content']); ?></div>
                        <?php $component_attachments = ticket_detail_comment_attachments($attachments, (int) $comment['id']); $component_layout = 'inline'; include BASE_PATH . '/includes/components/attachment-grid.php'; ?>
                        <?php foreach ($preview['time_entries'] as $entry): if ((int) ($entry['comment_id'] ?? 0) !== (int) $comment['id']) continue; $ticket_time_read_only = true; include BASE_PATH . '/includes/components/ticket-workspace-time-row.php'; endforeach; ?>
                    </div></div>
                </article>
            <?php endforeach; ?>
            <?php if (!$preview['comments']): ?><p class="p-6 text-theme-muted"><?php echo e(t('No comments yet.')); ?></p><?php endif; ?>
            <?php foreach ($preview['time_entries'] as $entry): if (!empty($entry['comment_id'])) continue; $ticket_time_read_only = true; include BASE_PATH . '/includes/components/ticket-workspace-time-row.php'; endforeach; ?>
            </section>
        </div>
        <aside class="card p-5"><h2><?php echo e(t('Ticket properties')); ?></h2><dl class="ticket-side-list">
            <?php
            $preview_properties = [t('Status') => $ticket['status_name'] ?? '', t('Client') => $ticket['organization_name'] ?? ''];
            if (in_array($preview_target['role'], ['admin', 'agent'], true)) {
                $preview_properties[t('workflow.assigned')] = trim(($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? '')) ?: '—';
            }
            $preview_properties[t('Priority')] = $ticket['priority_name'] ?? '—';
            $preview_properties[t('Type')] = get_type_label($ticket['type']);
            $preview_properties[t('Due date')] = !empty($ticket['due_date']) ? format_date($ticket['due_date']) : '—';
            foreach ($preview_properties as $label => $value): ?>
            <div class="ticket-side-row"><dt><?php echo e($label); ?></dt><dd><bdi dir="auto"><?php echo e($value); ?></bdi></dd></div>
            <?php endforeach; ?>
        </dl></aside>
    </div>
    <?php endif; ?>
</section>
<?php require BASE_PATH . '/includes/footer.php'; ?>
