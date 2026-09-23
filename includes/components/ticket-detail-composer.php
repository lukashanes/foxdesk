<?php
/**
 * Ticket detail composer surface.
 *
 * Included from pages/ticket-detail.php with ticket, timer, status, user,
 * and attachment limit view-model variables already prepared by the route.
 */
?>
            <!-- Add Comment Form -->
            <form method="post" enctype="multipart/form-data" class="ticket-composer"
                data-ticket-composer-surface
                id="comment-form">
                <?php echo csrf_field(); ?>
                <?php
                // Capture referrer for redirect after status change (back to tickets list or dashboard)
                $referrer = $_SERVER['HTTP_REFERER'] ?? '';
                if (preg_match('/page=(tickets|dashboard)/', $referrer)) {
                    echo '<input type="hidden" name="redirect_to" value="' . e($referrer) . '">';
                }
                ?>
                <?php if (is_agent()): ?>
                        <input type="hidden" name="change_status_with_comment" value="1">
                <?php endif; ?>

                <?php if (is_agent()): ?>
                        <!-- Comment Mode Toggle - Primary Choice -->
                        <div class="ticket-composer-mode-row">
                            <div class="ticket-composer-mode-tabs">
                                <button type="button"
                                    class="comment-mode-btn ticket-composer-mode-button"
                                    data-mode="public" title="<?php echo e(t('Public reply')); ?>">
                                    <?php echo get_icon('eye', 'w-4 h-4'); ?>
                                    <span><?php echo e(t('Public')); ?></span>
                                </button>
                                <button type="button"
                                    class="comment-mode-btn ticket-composer-mode-button"
                                    data-mode="internal" title="<?php echo e(t('Internal note')); ?>">
                                    <?php echo get_icon('lock', 'w-4 h-4'); ?>
                                    <span><?php echo e(t('Internal')); ?></span>
                                </button>
                            </div>
                            <input type="checkbox" id="is_internal_toggle" name="is_internal" class="hidden">
                            <p class="ticket-composer-mode-hint" id="comment-mode-hint">
                                <?php echo e(t('Visible to customer')); ?></p>
                        </div>
                <?php endif; ?>

                <!-- Public Reply Section -->
                <div id="public-comment-section">
                    <?php if (!is_agent()): ?>
                            <label class="ticket-composer-label"><?php echo e(t('Your reply')); ?>
                                <span class="text-red-500">*</span></label>
                    <?php endif; ?>
                    <div class="editor-wrapper">
                        <div id="comment-editor"></div>
                    </div>
                    <input type="hidden" name="comment" id="comment-text">
                </div>

                <?php if (is_agent()): ?>
                        <!-- Internal Note Section (hidden by default) -->
                        <div id="internal-comment-section" class="hidden">
                            <div class="editor-wrapper editor-wrapper--internal">
                                <div id="internal-editor"></div>
                            </div>
                            <input type="hidden" name="internal_text" id="internal-text">
                        </div>
                <?php endif; ?>

                <!-- Status + Attachments -->
                <div class="ticket-composer-tools">
                    <?php if (is_agent()): ?>
                            <div class="ticket-composer-tool-grid">
                                <div>
                                    <label class="form-label-sm"><?php echo e(t('Created at')); ?></label>
                                    <input type="datetime-local"
                                        name="comment_created_at"
                                        max="<?php echo e(date('Y-m-d\TH:i')); ?>"
                                        class="form-input ticket-composer-status-select"
                                        aria-label="<?php echo e(t('Created at')); ?>">
                                </div>
                                <div>
                                    <input type="hidden" name="status_id" value="<?php echo (int) $ticket['status_id']; ?>">
                                </div>
                                <div>
                                    <div id="comment-upload-zone"
                                        class="upload-zone ticket-composer-upload-zone">
                                        <input type="file" name="comment_attachments[]" id="comment-file-input" multiple
                                            class="hidden"
                                            accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                                        <div class="flex items-center justify-center gap-2 text-theme-muted">
                                            <?php echo get_icon('paperclip', 'w-4 h-4'); ?>
                                            <span class="text-sm">
                                                <span
                                                    class="text-blue-500 font-medium"><?php echo e(t('Add attachments')); ?></span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div id="comment-file-preview" class="mt-2 space-y-1 hidden"></div>
                    <?php else: ?>
                            <!-- Non-agent: attachments only -->
                            <div>
                                <div id="comment-upload-zone"
                                    class="upload-zone ticket-composer-upload-zone ticket-composer-upload-zone--standalone">
                                    <input type="file" name="comment_attachments[]" id="comment-file-input" multiple
                                        class="hidden"
                                        accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                                    <div class="flex items-center justify-center gap-2 text-theme-muted">
                                        <?php echo get_icon('paperclip', 'w-4 h-4'); ?>
                                        <span class="text-sm">
                                            <span
                                                class="text-blue-500 font-medium"><?php echo e(t('Add attachments')); ?></span>
                                            <span class="text-xs ml-1 text-theme-muted">(<?php echo e(t('or drag files')); ?>)</span>
                                        </span>
                                    </div>
                                </div>
                                <div id="comment-file-preview" class="mt-2 space-y-1 hidden"></div>
                            </div>
                    <?php endif; ?>
                </div>
                <?php if (is_agent() && $time_tracking_available): ?>
                    <fieldset id="reply-time-fields" class="ticket-reply-time" data-reply-time-fields hidden disabled aria-labelledby="reply-time-heading"
                        data-range-error="<?php echo e(t('Start and end time are required.')); ?>"
                        data-conflict-error="<?php echo e(t('workflow.timer_manual_conflict')); ?>">
                        <div id="reply-time-heading" class="ticket-reply-time__heading"><?php echo get_icon('clock', 'w-4 h-4'); ?><?php echo e(t('workflow.add_time')); ?></div>
                        <input type="hidden" name="reply_manual_time" value="1">
                        <div class="ticket-reply-time__fields">
                            <label for="reply-work-date"><?php echo e(t('Date')); ?><input type="date" id="reply-work-date" name="manual_date" class="form-input" required value="<?php echo date('Y-m-d'); ?>"></label>
                            <label for="reply-work-minutes"><?php echo e(t('Time (min)')); ?><input type="number" id="reply-work-minutes" name="manual_duration_minutes" class="form-input" min="1" max="1440" step="1" required></label>
                        </div>
                        <details class="ticket-reply-time__exact">
                            <summary><?php echo e(t('workflow.exact_time')); ?></summary>
                            <div class="ticket-reply-time__fields">
                                <label for="reply-work-start"><?php echo e(t('Start')); ?><input type="time" id="reply-work-start" name="manual_start_time" class="form-input"></label>
                                <label for="reply-work-end"><?php echo e(t('End')); ?><input type="time" id="reply-work-end" name="manual_end_time" class="form-input"></label>
                            </div>
                        </details>
                        <div class="ticket-reply-time__actions">
                            <button type="button" class="btn btn-ghost btn-sm" data-workspace-time-add="0"><?php echo e(t('ticket.workspace.standalone')); ?></button>
                            <button type="button" class="btn btn-ghost btn-sm" data-reply-time-remove><?php echo e(t('Remove')); ?></button>
                        </div>
                    </fieldset>
                <?php endif; ?>
                <!-- Reply actions -->
                <div class="ticket-composer-submit-row">
                    <div class="ticket-composer-submit-left">
                        <?php include BASE_PATH . '/includes/components/ticket-workspace-timer.php'; ?>
                        <label class="ticket-composer-skip-notification">
                            <input type="checkbox" name="skip_notification" value="1" class="ticket-composer-checkbox">
                            <span><?php echo e(t('Do not send email notification')); ?></span>
                        </label>
                    </div>
                    <div class="ticket-composer-submit-right">
                        <?php if (is_agent()): ?>
                                <!-- CC compact -->
                                <div class="ticket-composer-cc-dropdown" id="agent-cc-dropdown-container">
                                    <button type="button" id="agent-cc-toggle"
                                        class="ticket-composer-cc-toggle"
                                        data-none-text="<?php echo e(t('CC')); ?>"
                                        data-selected-text="<?php echo e(t('CC')); ?>">
                                        <?php echo get_icon('users', 'w-3.5 h-3.5 td-text-muted'); ?>
                                        <span id="agent-cc-display" class="text-sm"><?php echo e(t('CC')); ?></span>
                                        <?php echo get_icon('chevron-down', 'w-3 h-3 td-text-muted flex-shrink-0'); ?>
                                    </button>
                                    <div id="agent-cc-list"
                                        class="ticket-composer-cc-list hidden">
                                        <?php foreach ($all_users as $u): ?>
                                                <?php if ($u['id'] !== $user['id'] && $u['id'] !== $ticket['user_id']): ?>
                                                        <label class="ticket-composer-cc-option">
                                                            <input type="checkbox" name="cc_users[]" value="<?php echo $u['id']; ?>"
                                                                data-email="<?php echo e($u['email'] ?? ''); ?>" class="agent-cc-checkbox ticket-composer-checkbox">
                                                            <span
                                                                class="ticket-composer-cc-name"><?php echo e($u['first_name'] . ' ' . $u['last_name']); ?></span>
                                                        </label>
                                                <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                        <?php endif; ?>
                        <?php if (is_agent() && $time_tracking_available): ?>
                            <button type="button" class="btn btn-secondary ticket-composer-log-time" data-reply-time-toggle aria-expanded="false" aria-controls="reply-time-fields"><?php echo get_icon('clock', 'w-3.5 h-3.5'); ?><?php echo e(t('Log time')); ?></button>
                        <?php endif; ?>
                        <button type="submit" name="add_comment" id="comment-submit-btn"
                            class="btn btn-primary ticket-composer-submit-button"
                            data-default-text="<?php echo e(t('Send update')); ?>"
                            data-log-time-text="<?php echo e(t('Log time & send update')); ?>"
                            data-stop-text="<?php echo e(t('Stop timer & send update')); ?>"
                            data-has-active-timer="<?php echo $active_timer ? '1' : '0'; ?>">
                            <?php echo get_icon('paper-plane'); ?><span
                                class="btn-text"><?php echo e(t('Send update')); ?></span>
                        </button>
                    </div>
                </div>
            </form>
