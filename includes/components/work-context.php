<?php
$work_notifications = get_user_notifications((int) $user['id'], 30, 0, false);
$work_events = $work_notifications['notifications'] ?? [];
$work_attention = array_values(array_filter($work_events, static fn($event) => is_action_required_notification((string) ($event['type'] ?? ''), $event['data'] ?? [])));
$work_attention_count = count(array_filter($work_attention, static fn($event) => empty($event['is_resolved'])));
?>
<div class="fd-segmented work-context-tabs" role="tablist" aria-label="<?php echo e(t('Notifications')); ?>">
    <?php foreach (['activity' => t('Notifications'), 'attention' => t('Action required'), 'reports' => t('Reports')] as $context_key => $context_label): ?>
        <?php if ($context_key === 'reports' && !is_agent()) continue; ?>
        <button type="button" role="tab" id="work-tab-<?php echo e($context_key); ?>" aria-controls="work-panel-<?php echo e($context_key); ?>" aria-selected="<?php echo $context_key === 'activity' ? 'true' : 'false'; ?>" tabindex="<?php echo $context_key === 'activity' ? '0' : '-1'; ?>" class="fd-segmented__item <?php echo $context_key === 'activity' ? 'is-active' : ''; ?>" data-work-context-tab="<?php echo e($context_key); ?>"><?php echo e($context_label); ?><?php if ($context_key !== 'reports'): ?> <span><?php echo $context_key === 'activity' ? (int) $work_notifications['unread_count'] : $work_attention_count; ?></span><?php endif; ?></button>
    <?php endforeach; ?>
</div>
<?php foreach (['activity' => $work_events, 'attention' => $work_attention] as $context_key => $events): ?>
    <div id="work-panel-<?php echo e($context_key); ?>" role="tabpanel" aria-labelledby="work-tab-<?php echo e($context_key); ?>" data-work-context-panel="<?php echo e($context_key); ?>" <?php echo $context_key === 'activity' ? '' : 'hidden'; ?>>
        <p class="work-context-empty" data-work-event-empty <?php echo $events ? 'hidden' : ''; ?>><?php echo e(t('All clear')); ?></p>
        <?php foreach ($events as $event_index => $event): ?>
            <?php
            if (empty($event['data']['actor_name'])) $event['data']['actor_name'] = trim(($event['actor_first_name'] ?? '') . ' ' . ($event['actor_last_name'] ?? '')) ?: t('System');
            $event_anchor = !empty($event['data']['time_entry_id']) ? '#time-entry-' . (int) $event['data']['time_entry_id']
                : (!empty($event['data']['comment_id']) ? '#comment-' . (int) $event['data']['comment_id'] : '');
            ?>
            <article class="work-event <?php echo empty($event['is_read']) ? 'is-unread' : ''; ?> <?php echo !empty($event['is_resolved']) ? 'is-reviewed' : ''; ?>" data-work-event data-notification-id="<?php echo (int) $event['id']; ?>" <?php echo $event_index >= 3 || ($context_key === 'attention' && !empty($event['is_resolved'])) ? 'hidden' : ''; ?>>
                <div class="work-event__meta"><span class="work-event__avatar" aria-hidden="true"><?php echo e(mb_strtoupper(mb_substr((string) $event['data']['actor_name'], 0, 1))); ?></span><bdi dir="auto"><?php echo e(trim(($event['actor_first_name'] ?? '') . ' ' . ($event['actor_last_name'] ?? '')) ?: t('System')); ?></bdi><time datetime="<?php echo e($event['created_at']); ?>"><?php echo e(notification_time_ago($event['created_at'])); ?></time></div>
                <strong><bdi dir="auto"><?php echo e(format_notification_action($event) ?: format_notification_text($event)); ?></bdi></strong>
                <p><bdi dir="auto"><?php echo e($event['data']['ticket_subject'] ?? ''); ?></bdi></p>
                <div class="work-event__actions">
                <?php if (!empty($event['ticket_id'])): ?><a class="btn btn-secondary btn-sm" href="<?php echo e((!empty($event['ticket_hash']) ? url('ticket', ['t' => $event['ticket_hash']]) : url('ticket', ['id' => (int) $event['ticket_id']])) . $event_anchor); ?>"><?php echo e(t('View ticket')); ?></a>
                <button class="btn btn-secondary btn-sm" type="button" data-event-reply-toggle aria-expanded="false" aria-controls="event-reply-<?php echo e($context_key); ?>-<?php echo (int) $event['id']; ?>"><?php echo e(t('Reply')); ?></button><?php endif; ?>
                <?php if (empty($event['is_read'])): ?><button class="btn btn-secondary btn-sm" type="button" data-event-read><?php echo e(t('Mark as read')); ?></button><?php endif; ?>
                <?php if (is_action_required_notification((string) $event['type'], $event['data'] ?? [])): ?><label class="work-event__review"><input type="checkbox" data-event-reviewed <?php echo !empty($event['is_resolved']) ? 'checked' : ''; ?>><?php echo e(t('Resolved')); ?></label><?php endif; ?>
                </div>
                <?php if (!empty($event['ticket_id'])): ?><form hidden id="event-reply-<?php echo e($context_key); ?>-<?php echo (int) $event['id']; ?>" data-event-reply data-ticket-id="<?php echo (int) $event['ticket_id']; ?>">
                    <label><?php echo e(t('Reply')); ?><textarea class="form-input" name="content" rows="2" required maxlength="10000"></textarea></label>
                    <?php if (is_agent()): ?><label><input type="checkbox" name="is_internal" checked><?php echo e(t('Internal note')); ?></label><?php endif; ?>
                    <button class="btn btn-primary btn-sm" type="submit"><?php echo e(t('workflow.send')); ?></button>
                </form><?php endif; ?>
                <p role="status" data-event-status data-saved="<?php echo e(t('Saved')); ?>" hidden></p>
            </article>
        <?php endforeach; ?>
        <div class="work-queue-pagination" data-work-event-pagination <?php echo count($events) <= 3 ? 'hidden' : ''; ?>><span data-work-event-page>1 / <?php echo max(1, (int) ceil(count($events) / 3)); ?></span><button class="btn btn-secondary btn-sm" data-work-event-step="-1" type="button" disabled><?php echo e(t('Previous')); ?></button><button class="btn btn-secondary btn-sm" data-work-event-step="1" type="button"><?php echo e(t('Next')); ?></button></div>
    </div>
<?php endforeach; ?>
<a class="work-context-all" href="<?php echo e(url('notifications')); ?>"><?php echo e(t('View all notifications')); ?></a>
