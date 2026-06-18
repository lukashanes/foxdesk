<?php
/**
 * Ticket detail composer surface.
 *
 * Included from pages/ticket-detail.php with ticket, timer, status, user,
 * and attachment limit view-model variables already prepared by the route.
 */
?>
            <!-- Add Comment Form -->
            <form method="post" enctype="multipart/form-data" class="p-3 lg:p-4 border-t"
                style="background: var(--surface-secondary);"
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
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <div class="inline-flex items-center gap-0.5 rounded-lg p-1"
                                style="background: var(--surface-secondary);">
                                <button type="button"
                                    class="comment-mode-btn flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-all"
                                    data-mode="public" title="<?php echo e(t('Public reply')); ?>">
                                    <?php echo get_icon('eye', 'w-4 h-4'); ?>
                                    <span><?php echo e(t('Public')); ?></span>
                                </button>
                                <button type="button"
                                    class="comment-mode-btn flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-all"
                                    data-mode="internal" title="<?php echo e(t('Internal note')); ?>">
                                    <?php echo get_icon('lock', 'w-4 h-4'); ?>
                                    <span><?php echo e(t('Internal')); ?></span>
                                </button>
                            </div>
                            <input type="checkbox" id="is_internal_toggle" name="is_internal" class="hidden">
                            <p class="text-xs" style="color: var(--text-muted);" id="comment-mode-hint">
                                <?php echo e(t('Visible to customer')); ?></p>
                        </div>
                <?php endif; ?>

                <!-- Public Reply Section -->
                <div id="public-comment-section">
                    <?php if (!is_agent()): ?>
                            <label class="block text-sm mb-2"
                                style="color: var(--text-secondary);"><?php echo e(t('Your reply')); ?>
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
                <div class="mt-3">
                    <?php if (is_agent()): ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <select name="status_id" class="form-select text-sm w-full" style="height: 42px;">
                                        <?php foreach ($statuses as $status): ?>
                                                <option value="<?php echo $status['id']; ?>" <?php echo $status['id'] == $ticket['status_id'] ? 'selected' : ''; ?>>
                                                    <?php echo e(t('Status')); ?>: <?php echo e($status['name']); ?>
                                                </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <div id="comment-upload-zone"
                                        class="upload-zone rounded-lg text-center cursor-pointer border-2 border-dashed hover:border-blue-300 transition-colors flex items-center justify-center"
                                        style="border-color: var(--border-light); height: 42px;">
                                        <input type="file" name="comment_attachments[]" id="comment-file-input" multiple
                                            class="hidden"
                                            accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                                        <div class="flex items-center justify-center gap-2" style="color: var(--text-muted);">
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
                                    class="upload-zone rounded-lg p-2.5 text-center cursor-pointer border-2 border-dashed hover:border-blue-300 transition-colors"
                                    style="border-color: var(--border-light);">
                                    <input type="file" name="comment_attachments[]" id="comment-file-input" multiple
                                        class="hidden"
                                        accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                                    <div class="flex items-center justify-center gap-2" style="color: var(--text-muted);">
                                        <?php echo get_icon('paperclip', 'w-4 h-4'); ?>
                                        <span class="text-sm">
                                            <span
                                                class="text-blue-500 font-medium"><?php echo e(t('Add attachments')); ?></span>
                                            <span class="text-xs ml-1"
                                                style="color: var(--text-muted);">(<?php echo e(t('or drag files')); ?>)</span>
                                        </span>
                                    </div>
                                </div>
                                <div id="comment-file-preview" class="mt-2 space-y-1 hidden"></div>
                            </div>
                    <?php endif; ?>
                </div>
                <?php if (get_request_upload_limit() > 0): ?>
                <p class="mt-2 text-xs" style="color: var(--text-muted);">
                    <?php echo e(t('Total upload per request is limited to {size}.', ['size' => format_file_size(get_request_upload_limit())])); ?>
                </p>
                <?php endif; ?>

                <?php if (is_agent() && $time_tracking_available): ?>
                        <!-- Manual Time Entry (expandable, between attachments and submit row) -->
                        <div id="manual-entry-row" class="hidden mt-2 pt-2 border-t" style="border-color: var(--border-light);">
                            <input type="hidden" name="manual_start_at" id="manual-start-at">
                            <input type="hidden" name="manual_end_at" id="manual-end-at">
                            <div class="grid grid-cols-2 lg:grid-cols-4 gap-2">
                                <div>
                                    <label class="form-label-sm mb-1"><?php echo e(t('Time (min)')); ?></label>
                                    <input type="number" name="manual_duration_minutes" id="manual-duration-minutes"
                                        min="1" max="1440" step="1" placeholder="15"
                                        class="form-input text-sm h-9">
                                </div>
                                <div>
                                    <label class="form-label-sm mb-1"><?php echo e(t('Date')); ?></label>
                                    <input type="date" name="manual_date" value="<?php echo e(date('Y-m-d')); ?>"
                                        class="form-input text-sm h-9">
                                </div>
                                <div>
                                    <label class="form-label-sm mb-1"><?php echo e(t('Start')); ?></label>
                                    <input type="time" name="manual_start_time" class="form-input text-sm h-9">
                                </div>
                                <div>
                                    <label class="form-label-sm mb-1"><?php echo e(t('End')); ?></label>
                                    <input type="time" name="manual_end_time" class="form-input text-sm h-9">
                                </div>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="button" class="manual-duration-chip btn btn-ghost px-2 py-1 text-xs" data-minutes="5">+5</button>
                                <button type="button" class="manual-duration-chip btn btn-ghost px-2 py-1 text-xs" data-minutes="10">+10</button>
                                <button type="button" class="manual-duration-chip btn btn-ghost px-2 py-1 text-xs" data-minutes="15">+15</button>
                                <button type="button" class="manual-duration-chip btn btn-ghost px-2 py-1 text-xs" data-minutes="30">+30</button>
                                <button type="button" class="manual-duration-chip btn btn-ghost px-2 py-1 text-xs" data-minutes="60">+60</button>
                            </div>
                        </div>
                <?php endif; ?>

                <!-- Submit row: timer + notification on LEFT, CC + send on RIGHT -->
                <div class="mt-3 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-3">
                    <div class="flex items-center gap-2 flex-wrap min-w-0">
                        <?php if (is_agent() && $time_tracking_available): ?>
                                <!-- Unified timer control — single button that changes state -->
                                <div id="timer-controls" data-ticket-id="<?php echo $ticket_id; ?>"
                                    data-paused="<?php echo $timer_is_paused ? '1' : '0'; ?>" class="flex items-center gap-2">
                                    <button type="button" id="btn-timer-action"
                                        class="btn <?php echo $timer_state === 'running' ? 'btn-warning' : 'btn-success'; ?> px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors"
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
                                    <label id="timer-log-toggle"
                                        class="<?php echo $timer_state === 'stopped' ? 'hidden' : ''; ?> inline-flex items-center gap-1.5 text-xs cursor-pointer select-none whitespace-nowrap"
                                        style="color: var(--text-secondary);">
                                        <input type="checkbox" name="stop_timer" id="stop-timer-toggle" value="1" <?php echo $timer_state !== 'stopped' ? 'checked' : 'disabled'; ?>
                                            class="rounded text-blue-600 focus:ring-blue-500 w-4 h-4">
                                        <span><?php echo e(t('Log on submit')); ?></span>
                                    </label>
                                    <!-- Discard button (visible when timer active) -->
                                    <button type="button" id="btn-discard-timer"
                                        class="<?php echo $timer_state === 'stopped' ? 'hidden' : ''; ?> btn btn-ghost px-2 py-1.5 hover:text-red-500 transition-colors"
                                        style="color: var(--text-muted);" title="<?php echo e(t('Discard timer')); ?>">
                                        <?php echo get_icon('trash', 'w-4 h-4'); ?>
                                    </button>
                                </div>
                                <!-- Manual entry toggle -->
                                <button type="button" id="manual-toggle" class="btn btn-ghost px-2 py-1.5"
                                    style="color: var(--text-muted);" aria-expanded="false"
                                    title="<?php echo e(t('Manual entry')); ?>">
                                    <?php echo get_icon('pen', 'w-4 h-4'); ?>
                                </button>
                        <?php endif; ?>
                        <label class="flex items-center text-sm cursor-pointer whitespace-nowrap"
                            style="color: var(--text-secondary);">
                            <input type="checkbox" name="skip_notification" value="1" class="mr-2 rounded">
                            <span><?php echo e(t('Do not send email notification')); ?></span>
                        </label>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <?php if (is_agent()): ?>
                                <!-- CC compact -->
                                <div class="relative" id="agent-cc-dropdown-container">
                                    <button type="button" id="agent-cc-toggle"
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-sm border rounded-lg transition-colors"
                                        style="color: var(--text-secondary); background: var(--bg-primary); border-color: var(--border-light);"
                                        data-none-text="<?php echo e(t('CC')); ?>"
                                        data-selected-text="<?php echo e(t('CC')); ?>">
                                        <?php echo get_icon('users', 'w-3.5 h-3.5 td-text-muted'); ?>
                                        <span id="agent-cc-display" class="text-sm"><?php echo e(t('CC')); ?></span>
                                        <?php echo get_icon('chevron-down', 'w-3 h-3 td-text-muted flex-shrink-0'); ?>
                                    </button>
                                    <div id="agent-cc-list"
                                        class="hidden absolute z-50 bottom-full mb-1 right-0 w-64 border rounded-lg shadow-lg max-h-48 overflow-y-auto"
                                        style="background: var(--bg-primary); border-color: var(--border-light);">
                                        <?php foreach ($all_users as $u): ?>
                                                <?php if ($u['id'] !== $user['id'] && $u['id'] !== $ticket['user_id']): ?>
                                                        <label class="flex items-center px-3 py-2 cursor-pointer tr-hover">
                                                            <input type="checkbox" name="cc_users[]" value="<?php echo $u['id']; ?>"
                                                                class="agent-cc-checkbox rounded text-blue-600 mr-2">
                                                            <span
                                                                class="text-sm truncate"><?php echo e($u['first_name'] . ' ' . $u['last_name']); ?></span>
                                                        </label>
                                                <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                        <?php endif; ?>
                        <button type="submit" name="add_comment" id="comment-submit-btn"
                            class="btn btn-primary whitespace-nowrap"
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
