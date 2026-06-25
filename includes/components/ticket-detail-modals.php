<?php
/**
 * Ticket detail modal surfaces.
 *
 * Included from pages/ticket-detail.php with ticket, permissions, status,
 * organization, and time-tracking variables already prepared by the route.
 */
?>
<!-- Edit Ticket Modal -->
<?php if (can_edit_ticket($ticket, $user)): ?>
        <div id="edit-ticket-modal" class="modal-overlay hidden" aria-labelledby="edit-ticket-title" role="dialog"
            aria-modal="true">
            <div class="modal-backdrop" onclick="closeEditTicketModal()"></div>
            <div class="modal-panel max-w-2xl">
                <form method="post" id="edit-ticket-form">
                    <?php echo csrf_field(); ?>
                    <div class="modal-panel-body">
                        <h3 class="text-base font-semibold mb-4 flex items-center gap-2 text-theme-primary"
                            id="edit-ticket-title">
                            <?php echo get_icon('edit', 'w-5 h-5 td-text-muted'); ?>
                            <?php echo e(t('Edit ticket')); ?>
                        </h3>

                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-medium mb-1 text-theme-muted"><?php echo e(t('Subject')); ?> *</label>
                                <input type="text" name="edit_title" id="edit-ticket-title-input"
                                    value="<?php echo e($ticket['title']); ?>" class="form-input w-full" required>
                            </div>

                            <div>
                                <label class="block text-xs font-medium mb-1 text-theme-muted"><?php echo e(t('Description')); ?></label>
                                <div class="editor-wrapper">
                                    <div id="edit-description-editor"></div>
                                </div>
                                <input type="hidden" name="edit_description" id="edit-description-input"
                                    value="<?php echo e($ticket['description']); ?>">
                            </div>

                            <?php if ($tags_supported): ?>
                                    <div>
                                        <label class="block text-xs font-medium mb-1 text-theme-muted"><?php echo e(t('Tags')); ?></label>
                                        <input type="text" name="edit_tags" id="edit-ticket-tags-input"
                                            value="<?php echo e($ticket['tags'] ?? ''); ?>" class="form-input w-full"
                                            placeholder="<?php echo e(t('Comma separated tags')); ?>">
                                    </div>
                            <?php endif; ?>

                            <?php if (is_agent()): ?>
                                    <div>
                                        <label class="block text-xs font-medium mb-1 text-theme-muted"><?php echo e(t('Company')); ?></label>
                                        <select name="edit_organization_id" class="form-select w-full">
                                            <option value=""><?php echo e(t('-- No organization --')); ?></option>
                                            <?php foreach ($organizations as $org): ?>
                                                    <option value="<?php echo (int) $org['id']; ?>" <?php echo ((int) ($ticket['organization_id'] ?? 0) === (int) ($org['id'] ?? 0)) ? 'selected' : ''; ?>>
                                                        <?php echo e($org['name']); ?>
                                                    </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                            <?php endif; ?>

                            <?php if (is_admin()): ?>
                                    <div>
                                        <label class="block text-xs font-medium mb-1 text-theme-muted"><?php echo e(t('Custom billable rate (per hour)')); ?></label>
                                        <input type="number" name="edit_custom_billable_rate" step="0.01" min="0"
                                            value="<?php echo e($ticket_custom_billable_rate !== null ? number_format((float) $ticket_custom_billable_rate, 2, '.', '') : ''); ?>"
                                            class="form-input w-full"
                                            placeholder="<?php echo e(t('Leave empty to use the company default')); ?>">
                                        <p class="mt-1 text-xs text-theme-muted">
                                            <?php echo e(t('Company default rate: {rate}', ['rate' => format_money($org_billable_rate)])); ?>
                                        </p>
                                    </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="modal-panel-footer">
                        <button type="button" onclick="closeEditTicketModal()"
                            class="btn btn-secondary"><?php echo e(t('Cancel')); ?></button>
                        <button type="submit" name="update_ticket"
                            class="btn btn-primary"><?php echo e(t('Save changes')); ?></button>
                    </div>
                </form>
            </div>
        </div>
<?php endif; ?>

<!-- Edit Comment Modal -->
<?php if (is_admin() || is_agent()): ?>
        <div id="edit-comment-modal" class="modal-overlay hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="modal-backdrop" onclick="closeEditCommentModal()"></div>
            <div class="modal-panel max-w-lg">
                <form id="edit-comment-form" onsubmit="submitEditComment(event)">
                    <div class="modal-panel-body">
                        <h3 class="text-lg font-medium mb-4" id="modal-title" style="color: var(--text-primary);">
                            <?php echo e(t('Edit comment')); ?></h3>
                        <input type="hidden" name="comment_id" id="edit-comment-id">
                        <div class="editor-wrapper">
                            <div id="edit-comment-editor"></div>
                        </div>
                    </div>
                    <div class="modal-panel-footer">
                        <button type="button" onclick="closeEditCommentModal()"
                            class="btn btn-secondary"><?php echo e(t('Cancel')); ?></button>
                        <button type="submit" class="btn btn-primary"><?php echo e(t('Save')); ?></button>
                    </div>
                </form>
            </div>
        </div>
<?php endif; ?>

<!-- Edit Time Entry Modal -->
<?php if (is_admin() && $time_tracking_available): ?>
        <div id="edit-time-modal" class="modal-overlay hidden" aria-labelledby="time-modal-title" role="dialog"
            aria-modal="true">
            <div class="modal-backdrop" onclick="closeEditTimeModal()"></div>
            <div class="modal-panel max-w-md">
                <form method="post" id="edit-time-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="entry_id" id="edit-time-id">
                    <input type="hidden" name="edit_time_date" id="edit-time-date">
                    <div class="modal-panel-body">
                        <h3 class="text-base font-semibold mb-4 flex items-center gap-2 text-theme-primary"
                            id="time-modal-title">
                            <?php echo get_icon('clock', 'w-5 h-5 td-text-muted'); ?>
                            <?php echo e(t('Edit time entry')); ?>
                        </h3>

                        <div class="space-y-3">
                            <!-- Date + Start + End on one row -->
                            <div class="grid grid-cols-[1fr_auto_auto] gap-2 items-end">
                                <div>
                                    <label class="block text-xs font-medium mb-1 text-theme-muted"><?php echo e(t('Date')); ?></label>
                                    <input type="date" id="edit-time-date-picker" class="form-input w-full text-sm h-9"
                                        required>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1 text-theme-muted"><?php echo e(t('Start')); ?></label>
                                    <input type="time" id="edit-time-start-time" class="form-input w-full text-sm h-9" required>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1 text-theme-muted"><?php echo e(t('End')); ?></label>
                                    <input type="time" id="edit-time-end-time" class="form-input w-full text-sm h-9" required>
                                </div>
                            </div>
                            <!-- Hidden actual datetime-local inputs for form submission -->
                            <input type="hidden" name="started_at" id="edit-time-start">
                            <input type="hidden" name="ended_at" id="edit-time-end">

                            <div class="flex items-center gap-2">
                                <span class="text-xs font-medium text-theme-muted"><?php echo e(t('Duration')); ?>:</span>
                                <span id="edit-time-duration" class="text-sm font-semibold text-blue-600">-</span>
                            </div>

                            <div>
                                <label class="block text-xs font-medium mb-1 text-theme-muted"><?php echo e(t('Summary')); ?></label>
                                <textarea name="summary" id="edit-time-summary" rows="2" class="form-textarea w-full text-sm"
                                    placeholder="<?php echo e(t('Optional work description...')); ?>"></textarea>
                            </div>

                            <div>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="is_billable" id="edit-time-billable" value="1"
                                        class="rounded text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm text-theme-secondary"><?php echo e(t('Billable')); ?></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-panel-footer">
                        <button type="button" onclick="closeEditTimeModal()"
                            class="btn btn-secondary"><?php echo e(t('Cancel')); ?></button>
                        <button type="submit" name="update_time_entry"
                            class="btn btn-primary"><?php echo e(t('Save')); ?></button>
                    </div>
                </form>
            </div>
        </div>
<?php endif; ?>
