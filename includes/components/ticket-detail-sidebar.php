<?php
/**
 * Ticket detail sidebar surface.
 *
 * Included from pages/ticket-detail.php with the ticket detail view-model
 * variables already prepared by the route.
 */

if (is_agent()) {
    $_ai_excl = (function_exists('ai_agent_column_exists') && ai_agent_column_exists()) ? ' AND is_ai_agent = 0' : '';
    $_sidebar_agents = db_fetch_all("SELECT id, first_name, last_name FROM users WHERE role IN ('agent', 'admin') AND is_active = 1{$_ai_excl} ORDER BY first_name");
    $_sidebar_priorities = function_exists('get_priorities') ? get_priorities() : db_fetch_all("SELECT id, name FROM priorities ORDER BY sort_order");
    $_sidebar_types = function_exists('get_ticket_types') ? get_ticket_types(false) : db_fetch_all("SELECT slug, name FROM ticket_types WHERE is_active = 1 ORDER BY sort_order");
    $_sidebar_organizations = $organizations ?? [];
    $_sidebar_behalf_users = db_fetch_all("SELECT id, first_name, last_name FROM users WHERE role IN ('user') AND is_active = 1 ORDER BY first_name");
}
?>

<div class="ticket-sidebar" id="ticket-side-panel" data-ticket-sidebar-surface>
    <div class="card card-body ticket-side-card ticket-side-card--properties">
        <div class="ticket-side-heading">
            <span><?php echo e(t('Ticket properties')); ?></span>
            <span class="ticket-side-code"><?php echo get_ticket_code($ticket_id); ?></span>
        </div>

        <dl class="ticket-side-list">
            <div class="ticket-side-row">
                <dt class="ticket-side-label">ID</dt>
                <dd class="ticket-side-value ticket-side-value--mono"><?php echo get_ticket_code($ticket_id); ?></dd>
            </div>

            <div class="ticket-side-row">
                <dt class="ticket-side-label"><?php echo e(t('Status')); ?></dt>
                <dd class="ticket-side-value"><?php ticket_detail_render_status_pill($ticket, $statuses); ?></dd>
            </div>

            <div class="ticket-side-row">
                <dt class="ticket-side-label"><?php echo e(t('Client')); ?></dt>
                <dd class="ticket-side-value ticket-side-value--control">
                    <?php if (is_agent()): ?>
                        <select class="ticket-side-control ticket-side-select" onchange="quickEditField('quick-company', {organization_id: this.value})">
                            <option value=""><?php echo e(t('-- No Client --')); ?></option>
                            <?php foreach ($_sidebar_organizations as $_org): ?>
                                <option value="<?php echo (int) $_org['id']; ?>" <?php echo ((int) ($ticket['organization_id'] ?? 0) === (int) $_org['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($_org['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <?php if (!empty($ticket['organization_name'])): ?>
                            <span class="ticket-side-readonly"><?php echo e($ticket['organization_name']); ?></span>
                        <?php else: ?>
                            <span class="ticket-side-empty">—</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </dd>
            </div>

            <?php if (is_agent()): ?>
                <div class="ticket-side-row">
                    <dt class="ticket-side-label"><?php echo e(t('Assigned')); ?></dt>
                    <dd class="ticket-side-value ticket-side-value--control">
                        <select class="ticket-side-control ticket-side-select" onchange="quickEditField('quick-assign', {assignee_id: this.value})">
                            <option value=""><?php echo e(t('-- Unassigned --')); ?></option>
                            <?php foreach ($_sidebar_agents as $_ag): ?>
                                <option value="<?php echo (int) $_ag['id']; ?>" <?php echo ((int) ($ticket['assignee_id'] ?? 0) === (int) $_ag['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($_ag['first_name'] . ' ' . $_ag['last_name']); ?><?php if ((int) $_ag['id'] === (int) $user['id']): ?> (<?php echo e(t('me')); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </dd>
                </div>
            <?php endif; ?>

            <div class="ticket-side-row">
                <dt class="ticket-side-label"><?php echo e(t('Priority')); ?></dt>
                <dd class="ticket-side-value <?php echo is_agent() ? 'ticket-side-value--control' : ''; ?>">
                    <?php if (is_agent()): ?>
                        <select class="ticket-side-control ticket-side-select" onchange="quickEditField('quick-priority', {priority_id: this.value})">
                            <option value=""><?php echo e(t('-- Select --')); ?></option>
                            <?php foreach ($_sidebar_priorities as $_pr): ?>
                                <option value="<?php echo (int) $_pr['id']; ?>" <?php echo ((int) ($ticket['priority_id'] ?? 0) === (int) $_pr['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($_pr['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <?php ticket_detail_render_priority_pill($priority_name); ?>
                    <?php endif; ?>
                </dd>
            </div>

            <div class="ticket-side-row">
                <dt class="ticket-side-label"><?php echo e(t('Type')); ?></dt>
                <dd class="ticket-side-value <?php echo is_agent() ? 'ticket-side-value--control' : ''; ?>">
                    <?php if (is_agent()): ?>
                        <select class="ticket-side-control ticket-side-select" onchange="quickEditField('quick-type', {type: this.value})">
                            <option value=""><?php echo e(t('-- Select --')); ?></option>
                            <?php foreach ($_sidebar_types as $_tp): ?>
                                <option value="<?php echo e($_tp['slug']); ?>" <?php echo ((string) ($ticket['type'] ?? '') === (string) $_tp['slug']) ? 'selected' : ''; ?>>
                                    <?php echo e($_tp['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <span><?php echo e(get_type_label($ticket['type'])); ?></span>
                    <?php endif; ?>
                </dd>
            </div>

            <div class="ticket-side-row">
                <dt class="ticket-side-label"><?php echo e(t('Due date')); ?></dt>
                <dd class="ticket-side-value <?php echo is_agent() ? 'ticket-side-value--control' : ''; ?>">
                    <?php if (is_agent()): ?>
                        <?php $quick_due_overdue = is_due_date_overdue($ticket['due_date'] ?? null, !empty($ticket['is_closed'])); ?>
                        <input type="datetime-local"
                            value="<?php echo !empty($ticket['due_date']) ? date('Y-m-d\TH:i', strtotime($ticket['due_date'])) : ''; ?>"
                            class="ticket-side-control ticket-side-input <?php echo $quick_due_overdue ? 'ticket-side-input--warning' : ''; ?>"
                            onchange="quickEditField('quick-due-date', {due_date: this.value})">
                    <?php elseif (!empty($ticket['due_date'])): ?>
                        <?php $is_overdue = is_due_date_overdue($ticket['due_date'], !empty($ticket['is_closed'])); ?>
                        <span class="ticket-date-value <?php echo $is_overdue ? 'ticket-date-value--overdue' : ''; ?>">
                            <?php echo format_date($ticket['due_date']); ?>
                        </span>
                    <?php else: ?>
                        <span class="ticket-side-empty">—</span>
                    <?php endif; ?>
                </dd>
            </div>

            <?php if ($tags_supported): ?>
                <div class="ticket-side-row ticket-side-row--stack" id="sidebar-tags-section">
                    <dt class="ticket-side-label ticket-side-label--wide">
                        <?php echo e(t('Tags')); ?>
                        <?php if (can_edit_ticket($ticket, $user)): ?>
                            <button type="button" id="sidebar-tags-edit-btn" class="ticket-side-edit-button">
                                <?php echo e(t('Edit')); ?>
                            </button>
                        <?php endif; ?>
                    </dt>
                    <dd id="sidebar-tags-display" class="ticket-side-value ticket-side-tags">
                        <?php if (!empty($ticket_tags)): ?>
                            <?php foreach ($ticket_tags as $tag): ?>
                                <a href="<?php echo e($ticket_tag_filter_url($tag)); ?>" class="ticket-tag-pill"
                                    title="<?php echo e(t('Filter by this tag')); ?>">
                                    #<?php echo e($tag); ?>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="ticket-side-empty">—</span>
                        <?php endif; ?>
                    </dd>
                    <?php if (can_edit_ticket($ticket, $user)): ?>
                        <dd id="sidebar-tags-editor" class="hidden">
                            <div class="chip-select" id="cs-tags-detail">
                                <div class="chip-select__wrap" id="cs-tags-detail-wrap">
                                    <div class="chip-select__chips" id="cs-tags-detail-chips"></div>
                                    <input type="text" class="chip-select__input" id="cs-tags-detail-input"
                                        placeholder="<?php echo e(t('Type to add tag...')); ?>" autocomplete="off">
                                </div>
                                <div class="chip-select__dropdown hidden" id="cs-tags-detail-dropdown"></div>
                                <div id="cs-tags-detail-hidden"></div>
                            </div>
                            <div class="ticket-side-button-row">
                                <button type="button" id="sidebar-tags-save" class="btn btn-primary btn-xs">
                                    <?php echo e(t('Save')); ?>
                                </button>
                                <button type="button" id="sidebar-tags-cancel" class="btn btn-ghost btn-xs">
                                    <?php echo e(t('Cancel')); ?>
                                </button>
                            </div>
                        </dd>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="ticket-side-row">
                <dt class="ticket-side-label"><?php echo e(t('Created')); ?></dt>
                <dd class="ticket-side-value"><?php echo format_date($ticket['created_at']); ?></dd>
            </div>

            <?php if (!empty($attachment_list)): ?>
                <div class="ticket-side-row">
                    <dt class="ticket-side-label"><?php echo e(t('Attachments')); ?></dt>
                    <dd class="ticket-side-value"><?php echo count($attachment_list); ?> <?php echo e(t('files')); ?></dd>
                </div>
            <?php endif; ?>

            <?php if ($time_tracking_available && can_view_time($user)): ?>
                <div class="ticket-side-row">
                    <dt class="ticket-side-label"><?php echo e(t('Logged time')); ?></dt>
                    <dd class="ticket-side-value">
                        <?php if ($total_time_minutes > 0): ?>
                            <span class="badge-inline bg-blue-50 text-blue-700">
                                <?php echo get_icon('clock', 'mr-1'); ?><?php echo e(format_duration_minutes($total_time_minutes)); ?>
                            </span>
                        <?php else: ?>
                            <span class="ticket-side-empty">—</span>
                        <?php endif; ?>
                    </dd>
                </div>
            <?php endif; ?>

            <?php if (is_admin()): ?>
                <div class="ticket-side-row">
                    <dt class="ticket-side-label"><?php echo e(t('Billable rate')); ?></dt>
                    <dd class="ticket-side-value"><?php echo format_money($ticket_effective_billable_rate); ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </div>

    <?php if (function_exists('can_view_timeline') && can_view_timeline($user)): ?>
        <details class="card ticket-side-section">
            <summary class="ticket-side-section__summary">
                <span class="ticket-side-section__title">
                    <?php echo get_icon('history', 'w-4 h-4'); ?>
                    <?php echo e(t('Activity')); ?>
                </span>
                <span class="ticket-side-section__meta"><?php echo e(t('Activity Timeline')); ?></span>
                <?php echo get_icon('chevron-down', 'ticket-side-section__chevron'); ?>
            </summary>
            <div class="ticket-side-section__body">
                <button type="button" onclick="openTicketTimeline(<?php echo (int) $ticket['id']; ?>)"
                    class="ticket-side-action-button">
                    <?php echo get_icon('history', 'w-4 h-4'); ?>
                    <?php echo e(t('Activity Timeline')); ?>
                    <span class="ml-auto"><?php echo get_icon('chevron-right', 'w-3 h-3'); ?></span>
                </button>
            </div>
        </details>
    <?php endif; ?>

    <?php if (!empty($attachment_list)): ?>
        <details class="card ticket-side-section">
            <summary class="ticket-side-section__summary">
                <span class="ticket-side-section__title">
                    <?php echo get_icon('paperclip', 'w-4 h-4'); ?>
                    <?php echo e(t('Attachments')); ?>
                </span>
                <span class="ticket-side-section__meta"><?php echo count($attachment_list); ?></span>
                <?php echo get_icon('chevron-down', 'ticket-side-section__chevron'); ?>
            </summary>
            <div class="ticket-side-section__body">
                <div class="ticket-side-stack">
                    <?php foreach ($attachment_list as $attachment): ?>
                        <?php
                        $uploader_name = trim(($attachment['first_name'] ?? '') . ' ' . ($attachment['last_name'] ?? ''));
                        $_att_url = e(attachment_download_url($attachment));
                        $_is_img = is_image_mime($attachment['mime_type'] ?? '');
                        $_can_delete_attachment = function_exists('attachment_user_can_delete') && attachment_user_can_delete($attachment, $user ?? null);
                        ?>
                        <div class="ticket-attachment-item" data-attachment-id="<?php echo (int) $attachment['id']; ?>">
                            <?php if ($_is_img): ?>
                                <a href="<?php echo $_att_url; ?>" target="_blank"
                                    class="ticket-attachment-thumb"
                                    onclick="event.preventDefault(); openImagePreview('<?php echo $_att_url; ?>', '<?php echo e($attachment['original_name']); ?>');">
                                    <img src="<?php echo $_att_url; ?>" alt="" class="w-8 h-8 object-cover" loading="lazy">
                                </a>
                            <?php else: ?>
                                <?php echo get_icon(get_file_icon($attachment['mime_type']), 'td-text-muted mt-0.5 w-3 h-3 flex-shrink-0'); ?>
                            <?php endif; ?>
                            <div class="min-w-0 flex-1">
                                <?php if ($_is_img): ?>
                                    <a href="<?php echo $_att_url; ?>"
                                        class="ticket-attachment-link cursor-pointer"
                                        onclick="event.preventDefault(); openImagePreview('<?php echo $_att_url; ?>', '<?php echo e($attachment['original_name']); ?>');">
                                        <?php echo e($attachment['original_name']); ?>
                                    </a>
                                <?php else: ?>
                                    <a href="<?php echo $_att_url; ?>" target="_blank" class="ticket-attachment-link">
                                        <?php echo e($attachment['original_name']); ?>
                                    </a>
                                <?php endif; ?>
                                <div class="ticket-attachment-meta">
                                    <?php echo format_file_size($attachment['file_size']); ?>
                                    <?php if (!empty($uploader_name)): ?>
                                        &middot; <?php echo e($uploader_name); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($_can_delete_attachment): ?>
                                <button type="button"
                                    class="btn-icon btn-icon-danger shrink-0 opacity-70 hover:opacity-100"
                                    title="<?php echo e(t('Delete attachment')); ?>"
                                    aria-label="<?php echo e(t('Delete attachment')); ?>"
                                    onclick="deleteAttachment(<?php echo (int) $attachment['id']; ?>)">
                                    <?php echo get_icon('trash', 'w-3.5 h-3.5'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </details>
    <?php endif; ?>

    <?php if (is_agent()): ?>
        <details class="card ticket-side-section">
            <summary class="ticket-side-section__summary">
                <span class="ticket-side-section__title">
                    <?php echo get_icon('users', 'w-4 h-4'); ?>
                    <?php echo e(t('Ticket access')); ?>
                </span>
                <span class="ticket-side-section__meta"><?php echo count($shared_users); ?></span>
                <?php echo get_icon('chevron-down', 'ticket-side-section__chevron'); ?>
            </summary>
            <div class="ticket-side-section__body">
                <div class="ticket-side-field">
                    <label class="form-label-sm"><?php echo e(t('Ticket access')); ?></label>
                    <?php if (!empty($shared_users)): ?>
                        <div class="ticket-side-chip-list">
                            <?php foreach ($shared_users as $shared_user): ?>
                                <span class="ticket-side-user-chip" title="<?php echo e($shared_user['first_name'] . ' ' . $shared_user['last_name']); ?>">
                                    <?php echo e($shared_user['first_name'] . ' ' . substr($shared_user['last_name'], 0, 1) . '.'); ?>
                                    <form method="post" class="inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="shared_user_id" value="<?php echo (int) $shared_user['id']; ?>">
                                        <button type="submit" name="remove_shared_user" class="ticket-side-chip-remove" aria-label="<?php echo e(t('Remove')); ?>">
                                            <?php echo get_icon('times', 'w-3 h-3'); ?>
                                        </button>
                                    </form>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" class="ticket-side-inline-form">
                        <?php echo csrf_field(); ?>
                        <select name="shared_user_id" class="ticket-side-control ticket-side-select">
                            <option value=""><?php echo e(t('Add user...')); ?></option>
                            <?php
                            $shared_lookup = array_flip($shared_user_ids);
                            foreach ($all_users as $candidate):
                                if (empty($candidate['is_active'])) {
                                    continue;
                                }
                                if ((int) $candidate['id'] === (int) $ticket['user_id']) {
                                    continue;
                                }
                                if (isset($shared_lookup[$candidate['id']])) {
                                    continue;
                                }
                            ?>
                                <option value="<?php echo (int) $candidate['id']; ?>">
                                    <?php echo e($candidate['first_name'] . ' ' . $candidate['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="add_shared_user" class="btn btn-secondary btn-xs">
                            <?php echo get_icon('plus', 'w-3 h-3'); ?>
                        </button>
                    </form>
                </div>

                <div class="ticket-side-field">
                    <label class="form-label-sm">
                        <?php echo e(t('Public link')); ?>
                        <span class="<?php echo $share_status_class; ?>"><?php echo e($share_status_label); ?></span>
                    </label>
                    <?php if (!empty($share_url)): ?>
                        <div class="ticket-side-inline-form">
                            <input type="text" id="share-link-input" readonly value="<?php echo e($share_url); ?>"
                                class="ticket-side-control ticket-share-input">
                            <button type="button" id="share-copy-btn" class="btn btn-secondary btn-xs"
                                title="<?php echo e(t('Copy')); ?>">
                                <?php echo get_icon('copy', 'w-3 h-3'); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                    <form method="post" class="ticket-side-button-row">
                        <?php echo csrf_field(); ?>
                        <?php if ($share_status !== 'active'): ?>
                            <button type="submit" name="create_share_link" class="btn btn-secondary btn-xs flex-1 justify-center">
                                <?php echo get_icon('link', 'w-3 h-3 mr-1'); ?><?php echo e(t('Create')); ?>
                            </button>
                        <?php else: ?>
                            <button type="submit" name="create_share_link" class="btn btn-secondary btn-xs flex-1 justify-center">
                                <?php echo e(t('New')); ?>
                            </button>
                            <button type="submit" name="revoke_share_link" class="btn btn-xs border border-red-200 text-red-600 hover:bg-red-50 flex-1 justify-center">
                                <?php echo e(t('Revoke')); ?>
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </details>

        <details class="card ticket-side-section">
            <summary class="ticket-side-section__summary">
                <span class="ticket-side-section__title">
                    <?php echo get_icon('cog', 'w-4 h-4'); ?>
                    <?php echo e(t('Advanced')); ?>
                </span>
                <?php echo get_icon('chevron-down', 'ticket-side-section__chevron'); ?>
            </summary>
            <div class="ticket-side-section__body">
                <div class="ticket-side-field">
                    <label class="form-label-sm"><?php echo e(t('On behalf of')); ?></label>
                    <select class="ticket-side-control ticket-side-select" onchange="quickEditField('quick-behalf', {created_for_user_id: this.value})">
                        <option value=""><?php echo e(t('-- None --')); ?></option>
                        <?php foreach ($_sidebar_behalf_users as $behalf_user): ?>
                            <option value="<?php echo (int) $behalf_user['id']; ?>" <?php echo ((int) ($ticket['created_for_user_id'] ?? 0) === (int) $behalf_user['id']) ? 'selected' : ''; ?>>
                                <?php echo e($behalf_user['first_name'] . ' ' . $behalf_user['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (is_admin()): ?>
                    <div class="ticket-side-field">
                        <label class="form-label-sm"><?php echo e(t('Custom billable rate (per hour)')); ?></label>
                        <form method="post" class="ticket-side-stack">
                            <?php echo csrf_field(); ?>
                            <input type="number"
                                name="custom_billable_rate"
                                step="0.01"
                                min="0"
                                class="ticket-side-control ticket-side-input"
                                value="<?php echo e($ticket_custom_billable_rate !== null ? number_format((float) $ticket_custom_billable_rate, 2, '.', '') : ''); ?>"
                                placeholder="<?php echo e(t('Leave empty to use the company default')); ?>">
                            <p class="ticket-side-help"><?php echo e(t('Company default rate: {rate}', ['rate' => format_money($org_billable_rate)])); ?></p>
                            <button type="submit" name="update_ticket_billing_rate" class="btn btn-primary btn-xs w-full justify-center">
                                <?php echo e(t('Save')); ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (is_admin() || (is_agent() && can_archive_tickets())): ?>
                    <div class="ticket-side-field ticket-side-field--danger">
                        <?php if (empty($ticket['is_archived'])): ?>
                            <form method="post" onsubmit="return confirm('<?php echo e(t('Are you sure you want to move this ticket to the archive?')); ?>')">
                                <?php echo csrf_field(); ?>
                                <button type="submit" name="archive_ticket" class="btn btn-ghost btn-sm w-full justify-center hover:text-orange-600 hover:bg-orange-50 text-theme-muted">
                                    <?php echo get_icon('archive', 'w-4 h-4 mr-1.5'); ?><?php echo e(t('Archive')); ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <?php echo csrf_field(); ?>
                                <button type="submit" name="restore_ticket" class="btn btn-success btn-sm w-full justify-center">
                                    <?php echo get_icon('undo', 'w-4 h-4 mr-1.5'); ?><?php echo e(t('Restore')); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </details>
    <?php endif; ?>
</div>
