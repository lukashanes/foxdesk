            <div class="ticket-work-panel__actions" aria-label="<?php echo e(t('Primary actions')); ?>">
                <?php foreach (array_filter($ticket_primary_actions, static fn($item) => $item['type'] !== 'anchor' && !in_array($item['key'] ?? '', ['start_work', 'complete', 'reopen'], true)) as $action): ?>
                    <?php $action_class = ticket_detail_primary_action_class($action); ?>
                    <?php $action_title = t($action['title'] ?? $action['label']); ?>
                    <?php if ($action['type'] === 'anchor'): ?>
                        <a href="<?php echo e($action['href']); ?>" class="<?php echo e($action_class); ?>"
                           title="<?php echo e($action_title); ?>" aria-label="<?php echo e($action_title); ?>">
                            <?php echo get_icon($action['icon'], 'w-4 h-4'); ?>
                            <span><?php echo e(t($action['label'])); ?></span>
                        </a>
                    <?php elseif ($action['type'] === 'submit'): ?>
                        <form method="post" class="ticket-primary-action-form" data-workflow-operation="<?php echo e($action['key']); ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="status_id" value="<?php echo (int) $action['status_id']; ?>">
                            <?php if (($action['key'] ?? '') === 'complete'): ?>
                                <input type="hidden" name="stop_timer_on_complete" value="<?php echo !empty($action['stops_timer']) ? '1' : '0'; ?>">
                            <?php endif; ?>
                            <button type="submit" name="<?php echo e($action['name']); ?>" class="<?php echo e($action_class); ?>"
                                    title="<?php echo e($action_title); ?>" aria-label="<?php echo e($action_title); ?>">
                                <?php echo get_icon($action['icon'], 'w-4 h-4'); ?>
                                <span data-action-label="<?php echo e($action['key'] ?? ''); ?>"><?php echo e(t($action['label'])); ?></span>
                            </button>
                        </form>
                    <?php else: ?>
                        <button type="button" data-workflow-operation="<?php echo e($action['key']); ?>"
                            <?php if (!empty($action['id'])): ?>id="<?php echo e($action['id']); ?>"<?php endif; ?>
                            <?php if (!empty($action['onclick'])): ?>onclick="<?php echo e($action['onclick']); ?>"<?php endif; ?>
                            <?php if (($action['key'] ?? '') === 'edit'): ?>data-ticket-edit-open<?php endif; ?>
                            title="<?php echo e($action_title); ?>" aria-label="<?php echo e($action_title); ?>"
                            class="<?php echo e($action_class); ?>">
                            <?php echo get_icon($action['icon'], 'w-4 h-4'); ?>
                            <span><?php echo e(t($action['label'])); ?></span>
                            <?php if (($action['key'] ?? '') === 'start_work' && $timer_state !== 'stopped'): ?>
                                <span id="toolbar-timer-elapsed" class="ticket-primary-action__timer tabular-nums"><?php echo format_duration_minutes($active_timer_elapsed); ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
