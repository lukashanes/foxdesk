<?php if (is_agent() && $time_tracking_available): ?>
                                <!-- Unified timer control — single button that changes state -->
                                <div id="timer-controls" data-ticket-id="<?php echo $ticket_id; ?>"
                                    data-paused="<?php echo $timer_is_paused ? '1' : '0'; ?>" class="ticket-composer-timer-controls">
                                    <button type="button" id="btn-timer-action"
                                        class="btn <?php echo $timer_state === 'running' ? 'btn-warning' : 'btn-primary'; ?> ticket-composer-timer-button"
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
                                    <button type="button" id="workspace-stop-timer" class="btn btn-secondary" <?php echo $timer_state === 'stopped' ? 'hidden' : ''; ?>><?php echo get_icon('stop', 'w-4 h-4'); ?><?php echo e(t('Stop timer')); ?></button>
                                    <label id="timer-log-toggle"
                                        class="ticket-composer-timer-log <?php echo $timer_state === 'stopped' ? 'hidden' : ''; ?>">
                                        <input type="checkbox" form="comment-form" name="stop_timer" id="stop-timer-toggle" value="1" <?php echo $timer_state !== 'stopped' ? 'checked' : 'disabled'; ?>
                                            class="fd-rounded-control text-blue-600 focus:ring-blue-500 w-4 h-4">
                                        <span><?php echo e(t('Log on submit')); ?></span>
                                    </label>
                                    <!-- Discard button (visible when timer active) -->
                                    <button type="button" id="btn-discard-timer"
                                        class="ticket-composer-icon-button <?php echo $timer_state === 'stopped' ? 'hidden' : ''; ?>"
                                        title="<?php echo e(t('Discard timer')); ?>">
                                        <?php echo get_icon('trash', 'w-4 h-4'); ?>
                                    </button>
                                </div>

<?php endif; ?>
