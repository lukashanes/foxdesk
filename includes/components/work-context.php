<?php
$work_notifications = get_user_notifications((int) $user['id'], 30, 0, false);
$work_events = $work_notifications['notifications'] ?? [];
$work_attention = array_values(array_filter($work_events, static fn($event) =>
    is_action_required_notification((string) ($event['type'] ?? ''), $event['data'] ?? [])
    && empty($event['is_resolved'])
));
$work_activity = array_values(array_filter($work_events, static fn($event) =>
    !is_action_required_notification((string) ($event['type'] ?? ''), $event['data'] ?? [])
));
$work_attention_count = count($work_attention);
$work_activity_count = count(array_filter($work_activity, static fn($event) => empty($event['is_read'])));
$work_default_context = $work_attention_count > 0 ? 'attention' : 'activity';
$work_context_labels = [
    'attention' => t('Action required'),
    'activity' => t('Notifications'),
    'reports' => t('Reports'),
];
?>
<div class="fd-segmented work-context-tabs" role="tablist" aria-label="<?php echo e(t('Notifications')); ?>">
    <?php foreach ($work_context_labels as $context_key => $context_label): ?>
        <?php if ($context_key === 'reports' && !is_agent()) continue; ?>
        <?php $context_selected = $context_key === $work_default_context; ?>
        <button type="button" role="tab" id="work-tab-<?php echo e($context_key); ?>" aria-controls="work-panel-<?php echo e($context_key); ?>" aria-selected="<?php echo $context_selected ? 'true' : 'false'; ?>" tabindex="<?php echo $context_selected ? '0' : '-1'; ?>" class="fd-segmented__item <?php echo $context_selected ? 'is-active' : ''; ?>" data-work-context-tab="<?php echo e($context_key); ?>">
            <span class="work-context-tabs__label"><?php echo e($context_label); ?></span>
            <?php if ($context_key !== 'reports'): ?>
                <span class="work-context-tabs__count" aria-label="<?php echo e((string) ($context_key === 'attention' ? $work_attention_count : $work_activity_count)); ?>"><?php echo $context_key === 'attention' ? $work_attention_count : $work_activity_count; ?></span>
            <?php endif; ?>
        </button>
    <?php endforeach; ?>
</div>
<?php foreach (['attention' => $work_attention, 'activity' => $work_activity] as $context_key => $events): ?>
    <div id="work-panel-<?php echo e($context_key); ?>" class="<?php echo count($events) >= 6 ? 'work-context-panel--filled' : ''; ?>" role="tabpanel" aria-labelledby="work-tab-<?php echo e($context_key); ?>" data-work-context-panel="<?php echo e($context_key); ?>" <?php echo $context_key === $work_default_context ? '' : 'hidden'; ?>>
        <p class="work-context-empty" data-work-event-empty <?php echo $events ? 'hidden' : ''; ?>><?php echo e(t('All clear')); ?></p>
        <div class="work-event-list">
        <?php foreach ($events as $event_index => $event): ?>
            <?php
            if (empty($event['data']['actor_name'])) {
                $event['data']['actor_name'] = trim(($event['actor_first_name'] ?? '') . ' ' . ($event['actor_last_name'] ?? '')) ?: t('System');
            }
            $event_anchor = !empty($event['data']['time_entry_id']) ? '#time-entry-' . (int) $event['data']['time_entry_id']
                : (!empty($event['data']['comment_id']) ? '#comment-' . (int) $event['data']['comment_id'] : '');
            $event_url = '';
            if (!empty($event['ticket_id'])) {
                $event_params = !empty($event['ticket_hash'])
                    ? ['t' => $event['ticket_hash']]
                    : ['id' => (int) $event['ticket_id']];
                $event_params['ref'] = 'work';
                $event_params['nid'] = (int) $event['id'];
                $event_url = url('ticket', $event_params) . $event_anchor;
            }
            $event_type = notification_type_meta((string) ($event['type'] ?? ''));
            $event_title = format_notification_action($event) ?: format_notification_text($event);
            ?>
            <article class="work-event <?php echo empty($event['is_read']) ? 'is-unread' : ''; ?>" data-work-event data-notification-id="<?php echo (int) $event['id']; ?>" data-work-context-key="<?php echo e($context_key); ?>" <?php echo $event_index >= 6 ? 'hidden' : ''; ?>>
                <?php if ($event_url !== ''): ?>
                <a class="work-event__link" data-event-open href="<?php echo e($event_url); ?>" aria-label="<?php echo e($event_title . ': ' . ($event['data']['ticket_subject'] ?? '')); ?>">
                <?php else: ?>
                <div class="work-event__link">
                <?php endif; ?>
                    <span class="work-event__type" style="--work-event-color: <?php echo e($event_type['color']); ?>" aria-hidden="true"><?php echo get_icon($event_type['icon'], 'w-4 h-4'); ?></span>
                    <span class="work-event__copy">
                        <strong><bdi dir="auto"><?php echo e($event_title); ?></bdi></strong>
                        <small><bdi dir="auto"><?php echo e($event['data']['ticket_subject'] ?? ''); ?></bdi></small>
                    </span>
                    <time datetime="<?php echo e($event['created_at']); ?>"><?php echo e(notification_time_ago($event['created_at'])); ?></time>
                <?php echo $event_url !== '' ? '</a>' : '</div>'; ?>
                <div class="work-event__actions">
                    <?php if (!empty($event['ticket_id'])): ?>
                    <button class="work-event__action" type="button" data-event-reply-toggle aria-expanded="false" aria-controls="event-reply-<?php echo e($context_key); ?>-<?php echo (int) $event['id']; ?>" title="<?php echo e(t('Reply')); ?>">
                        <?php echo get_icon('comment', 'w-4 h-4'); ?><span><?php echo e(t('Reply')); ?></span>
                    </button>
                    <?php endif; ?>
                    <?php if (empty($event['is_read'])): ?>
                    <button class="work-event__action work-event__action--icon" type="button" data-event-read aria-label="<?php echo e(t('Mark as read')); ?>" title="<?php echo e(t('Mark as read')); ?>" <?php echo $context_key === 'attention' ? 'hidden' : ''; ?>>
                        <?php echo get_icon('check', 'w-4 h-4'); ?>
                    </button>
                    <?php endif; ?>
                    <?php if ($context_key === 'attention'): ?>
                    <button class="work-event__action work-event__action--resolve" type="button" data-event-reviewed aria-pressed="false">
                        <?php echo get_icon('check', 'w-4 h-4'); ?><span><?php echo e(t('Resolved')); ?></span>
                    </button>
                    <?php endif; ?>
                </div>
                <?php if (!empty($event['ticket_id'])): ?>
                <form hidden id="event-reply-<?php echo e($context_key); ?>-<?php echo (int) $event['id']; ?>" data-event-reply data-ticket-id="<?php echo (int) $event['ticket_id']; ?>">
                    <label><?php echo e(t('Reply')); ?><textarea class="form-input" name="content" rows="2" required maxlength="10000"></textarea></label>
                    <?php if (is_agent()): ?><label class="work-event__internal"><input type="checkbox" name="is_internal" checked><?php echo e(t('Internal note')); ?></label><?php endif; ?>
                    <button class="btn btn-primary btn-sm" type="submit"><?php echo e(t('workflow.send')); ?></button>
                </form>
                <?php endif; ?>
                <p role="status" data-event-status data-saved="<?php echo e(t('Saved')); ?>" hidden></p>
            </article>
        <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
<a class="work-context-all" href="<?php echo e(url('notifications')); ?>"><span><?php echo e(t('View all notifications')); ?></span><?php echo get_icon('chevron-right', 'w-4 h-4'); ?></a>
