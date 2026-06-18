<?php
/**
 * Ticket detail sidebar surface.
 *
 * Included from pages/ticket-detail.php with the ticket detail view-model
 * variables already prepared by the route.
 */
?>
    <!-- Sidebar -->
    <?php
    // Pre-fetch data for sidebar dropdowns (agents only)
    if (is_agent()) {
        $_ai_excl = (function_exists('ai_agent_column_exists') && ai_agent_column_exists()) ? ' AND is_ai_agent = 0' : '';
        $_sidebar_agents = db_fetch_all("SELECT id, first_name, last_name FROM users WHERE role IN ('agent', 'admin') AND is_active = 1{$_ai_excl} ORDER BY first_name");
        $_sidebar_priorities = db_fetch_all("SELECT id, name FROM priorities ORDER BY sort_order");
        $_sidebar_types = db_fetch_all("SELECT slug, name FROM ticket_types WHERE is_active = 1 ORDER BY sort_order");
    }
    ?>
    <div class="ticket-sidebar" id="ticket-side-panel" data-ticket-sidebar-surface>
        <!-- Details -->
        <div class="card card-body">
            <div class="ticket-side-heading">
                <span><?php echo e(t('Ticket properties')); ?></span>
                <span class="font-mono text-xs" style="color: var(--text-muted);"><?php echo get_ticket_code($ticket_id); ?></span>
            </div>
            <?php if (!empty($ticket['organization_name'])): ?>
                    <div class="flex items-center gap-2 px-2.5 py-2 -mx-1 mb-2 rounded-lg"
                        style="background: var(--primary-soft);">
                        <span style="color: var(--primary);"><?php echo get_icon('building', 'w-4 h-4 flex-shrink-0'); ?></span>
                        <span class="text-sm font-semibold truncate" style="color: var(--primary-dark);"
                            title="<?php echo e($ticket['organization_name']); ?>">
                            <?php echo e($ticket['organization_name']); ?>
                        </span>
                    </div>
            <?php endif; ?>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-xs" style="color: var(--text-muted);">ID</dt>
                    <dd class="text-xs font-mono font-medium" style="color: var(--text-primary);">
                        <?php echo get_ticket_code($ticket_id); ?>
                    </dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Status')); ?></dt>
                    <dd>
                        <?php ticket_detail_render_status_pill($ticket, $statuses); ?>
                    </dd>
                </div>
                <?php if (is_agent()): ?>
                <div class="flex justify-between items-center">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Assigned')); ?></dt>
                    <dd>
                        <select class="text-xs py-0.5 px-1 rounded border-0 cursor-pointer" style="color: var(--text-primary); background: var(--surface-secondary);" onchange="quickEditField('quick-assign', {assignee_id: this.value})">
                            <option value=""><?php echo e(t('-- Unassigned --')); ?></option>
                            <?php foreach ($_sidebar_agents as $_ag): ?>
                                <option value="<?php echo $_ag['id']; ?>" <?php echo ($ticket['assignee_id'] ?? 0) == $_ag['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($_ag['first_name'] . ' ' . $_ag['last_name']); ?><?php if ($_ag['id'] == $user['id']): ?> (<?php echo e(t('me')); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </dd>
                </div>
                <?php endif; ?>
                <div class="flex justify-between items-center">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Priority')); ?></dt>
                    <dd>
                        <?php if (is_agent()): ?>
                        <select class="text-xs py-0.5 px-1 rounded border-0 cursor-pointer" style="color: var(--text-primary); background: var(--surface-secondary);" onchange="quickEditField('quick-priority', {priority_id: this.value})">
                            <option value=""><?php echo e(t('-- Select --')); ?></option>
                            <?php foreach ($_sidebar_priorities as $_pr): ?>
                                <option value="<?php echo $_pr['id']; ?>" <?php echo ($ticket['priority_id'] ?? 0) == $_pr['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($_pr['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <span class="badge px-2 py-0.5 text-xs"
                            style="background-color: <?php echo e($priority_color); ?>20; color: <?php echo e($priority_color); ?>">
                            <?php echo e($priority_name); ?>
                        </span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="flex justify-between items-center">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Type')); ?></dt>
                    <dd>
                        <?php if (is_agent()): ?>
                        <select class="text-xs py-0.5 px-1 rounded border-0 cursor-pointer" style="color: var(--text-primary); background: var(--surface-secondary);" onchange="quickEditField('quick-type', {type: this.value})">
                            <option value=""><?php echo e(t('-- Select --')); ?></option>
                            <?php foreach ($_sidebar_types as $_tp): ?>
                                <option value="<?php echo $_tp['slug']; ?>" <?php echo ($ticket['type'] ?? '') === $_tp['slug'] ? 'selected' : ''; ?>>
                                    <?php echo e($_tp['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <span class="text-xs" style="color: var(--text-primary);"><?php echo e(get_type_label($ticket['type'])); ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
                <?php if ($tags_supported): ?>
                <div class="space-y-1" id="sidebar-tags-section">
                    <dt class="text-xs flex items-center justify-between" style="color: var(--text-muted);">
                        <?php echo e(t('Tags')); ?>
                        <?php if (can_edit_ticket($ticket, $user)): ?>
                            <button type="button" id="sidebar-tags-edit-btn"
                                class="text-xs font-medium" style="color: var(--primary); cursor: pointer;">
                                <?php echo e(t('Edit')); ?>
                            </button>
                        <?php endif; ?>
                    </dt>
                    <!-- Display mode -->
                    <dd id="sidebar-tags-display" class="flex flex-wrap gap-1 justify-end">
                        <?php if (!empty($ticket_tags)): ?>
                            <?php foreach ($ticket_tags as $tag): ?>
                                <a href="<?php echo e($ticket_tag_filter_url($tag)); ?>" class="ticket-tag-pill"
                                    title="<?php echo e(t('Filter by this tag')); ?>">
                                    #<?php echo e($tag); ?>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-xs" style="color: var(--text-muted);">—</span>
                        <?php endif; ?>
                    </dd>
                    <!-- Edit mode -->
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
                        <div class="flex gap-2 mt-2">
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
                <div class="flex justify-between">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Created')); ?></dt>
                    <dd class="text-xs" style="color: var(--text-primary);">
                        <?php echo format_date($ticket['created_at']); ?></dd>
                </div>
                <?php if (!empty($ticket['due_date'])): ?>
                        <div class="flex justify-between">
                            <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Due date')); ?></dt>
                            <dd class="text-xs">
                                <?php
                                $is_overdue = is_due_date_overdue($ticket['due_date'], !empty($ticket['is_closed']));
                                ?>
                                <span class="<?php echo $is_overdue ? 'text-red-600 font-bold' : ''; ?>"
                                    style="<?php echo !$is_overdue ? 'color: var(--text-primary);' : ''; ?>">
                                    <?php echo format_date($ticket['due_date']); ?>
                                </span>
                            </dd>
                        </div>
                <?php endif; ?>
                <?php if (!empty($attachment_list)): ?>
                        <div class="flex justify-between">
                            <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Attachments')); ?></dt>
                            <dd class="text-xs" style="color: var(--text-primary);"><?php echo count($attachment_list); ?>
                                <?php echo e(t('files')); ?>
                            </dd>
                        </div>
                <?php endif; ?>
                <?php if ($time_tracking_available && can_view_time($user)): ?>
                        <div class="flex justify-between items-center">
                            <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Logged time')); ?></dt>
                            <dd>
                                <?php if ($total_time_minutes > 0): ?>
                                        <span class="badge-inline bg-blue-50 text-blue-700">
                                            <?php echo get_icon('clock', 'mr-1'); ?>                <?php echo e(format_duration_minutes($total_time_minutes)); ?>
                                        </span>
                                <?php else: ?>
                                        <span class="text-xs" style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <?php if ($time_breakdown['human'] > 0 && $time_breakdown['ai'] > 0): ?>
                                <div class="flex justify-end items-center gap-2 -mt-1">
                                    <span
                                        class="inline-flex items-center text-xs bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded">
                                        <?php echo get_icon('user', 'w-3 h-3 mr-0.5'); ?>                <?php echo e(format_duration_minutes($time_breakdown['human'])); ?>
                                    </span>
                                    <span class="inline-flex items-center text-xs bg-purple-50 text-purple-700 px-1.5 py-0.5 rounded">
                                        <?php echo get_icon('bot', 'w-3 h-3 mr-0.5'); ?>                <?php echo e(format_duration_minutes($time_breakdown['ai'])); ?>
                                    </span>
                                </div>
                        <?php endif; ?>
                <?php endif; ?>
                <?php if (is_admin()): ?>
                        <div class="flex justify-between items-center">
                            <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Billable rate')); ?></dt>
                            <dd class="text-xs font-medium" style="color: var(--text-primary);">
                                <?php echo format_money($ticket_effective_billable_rate); ?>
                            </dd>
                        </div>
                <?php endif; ?>
            </dl>
        </div>

        <?php if (function_exists('can_view_timeline') && can_view_timeline($user)): ?>
        <div class="card card-body">
            <button onclick="openTicketTimeline(<?php echo (int)$ticket['id']; ?>)"
                class="w-full flex items-center gap-2 text-sm font-medium rounded-lg px-3 py-2 transition-colors"
                style="color: var(--text-secondary); background: var(--surface-secondary);"
                onmouseover="this.style.background='var(--surface-hover)'"
                onmouseout="this.style.background='var(--surface-secondary)'">
                <?php echo get_icon('history', 'w-4 h-4'); ?>
                <?php echo e(t('Activity Timeline')); ?>
                <span class="ml-auto"><?php echo get_icon('chevron-right', 'w-3 h-3'); ?></span>
            </button>
        </div>
        <?php endif; ?>

        <?php if (!empty($attachment_list)): ?>
                <div class="card card-body">
                    <h3 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">
                        <?php echo e(t('All attachments')); ?></h3>
                    <div class="space-y-1">
                        <?php foreach ($attachment_list as $attachment): ?>
                                <?php
                                $comment_anchor = !empty($attachment['comment_id']) ? ('#comment-' . $attachment['comment_id']) : '';
                                $uploader_name = trim(($attachment['first_name'] ?? '') . ' ' . ($attachment['last_name'] ?? ''));
                                $_att_url = e(attachment_download_url($attachment));
                                $_is_img = is_image_mime($attachment['mime_type'] ?? '');
                                ?>
                                <div class="flex items-start gap-2 p-1.5 rounded group tr-hover">
                                    <?php if ($_is_img): ?>
                                        <a href="<?php echo $_att_url; ?>" target="_blank"
                                           class="flex-shrink-0 rounded overflow-hidden border cursor-pointer"
                                           style="border-color: var(--border-light);"
                                           onclick="event.preventDefault(); openImagePreview('<?php echo $_att_url; ?>', '<?php echo e($attachment['original_name']); ?>');">
                                            <img src="<?php echo $_att_url; ?>" alt="" class="w-8 h-8 object-cover" loading="lazy">
                                        </a>
                                    <?php else: ?>
                                        <?php echo get_icon(get_file_icon($attachment['mime_type']), 'td-text-muted mt-0.5 w-3 h-3 flex-shrink-0'); ?>
                                    <?php endif; ?>
                                    <div class="min-w-0 flex-1">
                                        <?php if ($_is_img): ?>
                                            <a href="<?php echo $_att_url; ?>"
                                               class="text-xs font-medium truncate hover:text-blue-600 block cursor-pointer"
                                               style="color: var(--text-secondary);"
                                               onclick="event.preventDefault(); openImagePreview('<?php echo $_att_url; ?>', '<?php echo e($attachment['original_name']); ?>');">
                                                <?php echo e($attachment['original_name']); ?>
                                            </a>
                                        <?php else: ?>
                                            <a href="<?php echo $_att_url; ?>" target="_blank"
                                               class="text-xs font-medium truncate hover:text-blue-600 block"
                                               style="color: var(--text-secondary);">
                                                <?php echo e($attachment['original_name']); ?>
                                            </a>
                                        <?php endif; ?>
                                        <div class="text-xs flex items-center gap-1" style="color: var(--text-muted);">
                                            <?php echo format_file_size($attachment['file_size']); ?>
                                            <?php if (!empty($uploader_name)): ?>
                                                    &middot; <?php echo e($uploader_name); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                        <?php endforeach; ?>
                    </div>
                </div>
        <?php endif; ?>

        <?php if (is_agent()): ?>
                <!-- Compact Actions Panel -->
                <div class="card card-body">
                    <!-- Collapsible Options (collapsed by default) -->
                    <details class="group">
                        <summary class="flex items-center justify-between cursor-pointer py-1 text-xs"
                            style="color: var(--text-muted);">
                            <span class="flex items-center gap-1.5">
                                <?php echo get_icon('cog', 'w-3.5 h-3.5'); ?>
                                <?php echo e(t('More options')); ?>
                            </span>
                            <?php echo get_icon('chevron-down', 'w-3.5 h-3.5 group-open:rotate-180 transition-transform'); ?>
                        </summary>

                        <div class="pt-3">
                            <!-- Advanced Fields Grid -->
                            <div class="grid grid-cols-1 gap-3 mb-3">
                                <!-- On Behalf Of -->
                                <div>
                                    <label class="form-label-sm mb-0.5">
                                        <?php echo get_icon('user-shield', 'w-3 h-3 inline mr-1'); ?>        <?php echo e(t('On behalf of')); ?>
                                    </label>
                                    <?php $behalf_users = db_fetch_all("SELECT id, first_name, last_name FROM users WHERE role IN ('user') AND is_active = 1 ORDER BY first_name"); ?>
                                    <select class="form-select text-sm py-1.5 w-full" onchange="quickEditField('quick-behalf', {created_for_user_id: this.value})">
                                        <option value=""><?php echo e(t('-- None --')); ?></option>
                                        <?php foreach ($behalf_users as $behalf_user): ?>
                                                <option value="<?php echo $behalf_user['id']; ?>" <?php echo ($ticket['created_for_user_id'] ?? 0) == $behalf_user['id'] ? 'selected' : ''; ?>>
                                                    <?php echo e($behalf_user['first_name'] . ' ' . $behalf_user['last_name']); ?>
                                                </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Due Date -->
                                <div>
                                    <label class="form-label-sm mb-0.5">
                                        <?php echo get_icon('calendar-alt', 'w-3 h-3 inline mr-1'); ?>        <?php echo e(t('Due date')); ?>
                                    </label>
                                    <?php $quick_due_overdue = is_due_date_overdue($ticket['due_date'] ?? null, !empty($ticket['is_closed'])); ?>
                                    <input type="datetime-local"
                                        value="<?php echo !empty($ticket['due_date']) ? date('Y-m-d\TH:i', strtotime($ticket['due_date'])) : ''; ?>"
                                        class="form-input text-sm py-1.5 w-full <?php echo $quick_due_overdue ? 'border-red-400 bg-red-50 text-red-700' : ''; ?>"
                                        onchange="quickEditField('quick-due-date', {due_date: this.value})">
                                    <?php if ($quick_due_overdue): ?>
                                            <p class="mt-1 text-xs font-medium text-red-600"><?php echo e(t('Overdue')); ?></p>
                                    <?php endif; ?>
                                </div>

                                <!-- Company -->
                                <div>
                                    <label class="form-label-sm mb-0.5">
                                        <?php echo get_icon('building', 'w-3 h-3 inline mr-1'); ?>        <?php echo e(t('Company')); ?>
                                    </label>
                                    <?php $companies = db_fetch_all("SELECT id, name FROM organizations ORDER BY name"); ?>
                                    <select class="form-select text-sm py-1.5 w-full" onchange="quickEditField('quick-company', {organization_id: this.value})">
                                        <option value=""><?php echo e(t('-- None --')); ?></option>
                                        <?php foreach ($companies as $company): ?>
                                                <option value="<?php echo $company['id']; ?>" <?php echo ($ticket['organization_id'] ?? 0) == $company['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($company['name']); ?>
                                                </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <?php if (is_admin()): ?>
                                        <div>
                                            <label class="form-label-sm mb-0.5">
                                                <?php echo e(t('Custom billable rate (per hour)')); ?>
                                            </label>
                                            <form method="post" class="space-y-2">
                                                <?php echo csrf_field(); ?>
                                                <input type="number"
                                                    name="custom_billable_rate"
                                                    step="0.01"
                                                    min="0"
                                                    class="form-input text-sm py-1.5 w-full"
                                                    value="<?php echo e($ticket_custom_billable_rate !== null ? number_format((float) $ticket_custom_billable_rate, 2, '.', '') : ''); ?>"
                                                    placeholder="<?php echo e(t('Leave empty to use the company default')); ?>">
                                                <p class="text-xs" style="color: var(--text-muted);">
                                                    <?php echo e(t('Company default rate: {rate}', ['rate' => format_money($org_billable_rate)])); ?>
                                                </p>
                                                <button type="submit" name="update_ticket_billing_rate" class="btn btn-primary btn-xs w-full justify-center">
                                                    <?php echo e(t('Save')); ?>
                                                </button>
                                            </form>
                                        </div>
                                <?php endif; ?>
                            </div>

                            <!-- Additional Fields (Ticket Access, Share Link, etc.) -->
                            <div class="space-y-3">
                                <!-- Ticket Access -->
                            <div>
                                <label class="form-label-sm mb-1.5">
                                    <?php echo get_icon('users', 'w-3 h-3 inline mr-1'); ?>        <?php echo e(t('Ticket access')); ?>
                                    <span style="color: var(--border-light);">(<?php echo count($shared_users); ?>)</span>
                                </label>
                                <?php if (!empty($shared_users)): ?>
                                        <div class="flex flex-wrap gap-1 mb-2">
                                            <?php foreach ($shared_users as $shared_user): ?>
                                                    <span
                                                        class="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded-full bg-blue-50 text-blue-700 group"
                                                        title="<?php echo e($shared_user['first_name'] . ' ' . $shared_user['last_name']); ?>">
                                                        <?php echo e($shared_user['first_name'] . ' ' . substr($shared_user['last_name'], 0, 1) . '.'); ?>
                                                        <form method="post" class="inline">
                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="shared_user_id"
                                                                value="<?php echo $shared_user['id']; ?>">
                                                            <button type="submit" name="remove_shared_user"
                                                                class="text-blue-400 hover:text-red-500 ml-0.5">
                                                                <?php echo get_icon('times', 'w-3 h-3'); ?>
                                                            </button>
                                                        </form>
                                                    </span>
                                            <?php endforeach; ?>
                                        </div>
                                <?php endif; ?>
                                <form method="post" class="flex gap-1">
                                    <?php echo csrf_field(); ?>
                                    <select name="shared_user_id" class="form-select text-xs py-1.5 flex-1">
                                        <option value=""><?php echo e(t('Add user...')); ?></option>
                                        <?php
                                        $shared_lookup = array_flip($shared_user_ids);
                                        foreach ($all_users as $candidate):
                                            if (empty($candidate['is_active']))
                                                continue;
                                            if ((int) $candidate['id'] === (int) $ticket['user_id'])
                                                continue;
                                            if (isset($shared_lookup[$candidate['id']]))
                                                continue;
                                            ?>
                                                <option value="<?php echo $candidate['id']; ?>">
                                                    <?php echo e($candidate['first_name'] . ' ' . $candidate['last_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="add_shared_user" class="btn btn-secondary btn-xs px-2">
                                        <?php echo get_icon('plus', 'w-3 h-3'); ?>
                                    </button>
                                </form>
                            </div>

                            <!-- Share Link -->
                            <div>
                                <label class="form-label-sm mb-1.5">
                                    <?php echo get_icon('link', 'w-3 h-3 inline mr-1'); ?>        <?php echo e(t('Public link')); ?>
                                    <span
                                        class="<?php echo $share_status_class; ?>"><?php echo e($share_status_label); ?></span>
                                </label>
                                <?php if (!empty($share_url)): ?>
                                        <div class="flex gap-1 mb-1.5">
                                            <input type="text" id="share-link-input" readonly value="<?php echo e($share_url); ?>"
                                                class="form-input text-xs py-1.5 flex-1 font-mono"
                                                style="background: var(--surface-secondary); color: var(--text-secondary);">
                                            <button type="button" id="share-copy-btn" class="btn btn-secondary btn-xs px-2"
                                                title="<?php echo e(t('Copy')); ?>">
                                                <?php echo get_icon('copy', 'w-3 h-3'); ?>
                                            </button>
                                        </div>
                                <?php endif; ?>
                                <form method="post" class="flex gap-1">
                                    <?php echo csrf_field(); ?>
                                    <?php if ($share_status !== 'active'): ?>
                                            <button type="submit" name="create_share_link"
                                                class="btn btn-secondary btn-xs flex-1 justify-center">
                                                <?php echo get_icon('link', 'w-3 h-3 mr-1'); ?>                <?php echo e(t('Create')); ?>
                                            </button>
                                    <?php else: ?>
                                            <button type="submit" name="create_share_link"
                                                class="btn btn-secondary btn-xs flex-1 justify-center">
                                                <?php echo e(t('New')); ?>
                                            </button>
                                            <button type="submit" name="revoke_share_link"
                                                class="btn btn-xs border border-red-200 text-red-600 hover:bg-red-50 flex-1 justify-center">
                                                <?php echo e(t('Revoke')); ?>
                                            </button>
                                    <?php endif; ?>
                                </form>
                            </div>


                            <?php if (is_admin() || (is_agent() && can_archive_tickets())): ?>
                                    <!-- Archive -->
                                    <div class="pt-2 border-t" style="border-color: var(--border-light);">
                                        <?php if (empty($ticket['is_archived'])): ?>
                                                <form method="post"
                                                    onsubmit="return confirm('<?php echo e(t('Are you sure you want to move this ticket to the archive?')); ?>')">
                                                    <?php echo csrf_field(); ?>
                                                    <button type="submit" name="archive_ticket"
                                                        class="btn btn-ghost btn-sm w-full justify-center hover:text-orange-600 hover:bg-orange-50"
                                                        style="color: var(--text-muted);">
                                                        <?php echo get_icon('archive', 'w-4 h-4 mr-1.5'); ?>                        <?php echo e(t('Archive')); ?>
                                                    </button>
                                                </form>
                                        <?php else: ?>
                                                <form method="post">
                                                    <?php echo csrf_field(); ?>
                                                    <button type="submit" name="restore_ticket"
                                                        class="btn btn-success btn-sm w-full justify-center">
                                                        <?php echo get_icon('undo', 'w-4 h-4 mr-1.5'); ?>                        <?php echo e(t('Restore')); ?>
                                                    </button>
                                                </form>
                                        <?php endif; ?>
                                    </div>
                            <?php endif; ?>
                        </div>
                    </details>
                </div>
        <?php endif; ?>
    </div>
