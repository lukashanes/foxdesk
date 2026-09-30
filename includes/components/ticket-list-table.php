        <?php
        // Check if any filters are active
        $has_filters = !empty($search_query) || !empty($status_id) || !empty($priority_id) ||
                       !empty($organization_id) || !empty($due_date_filter) || !empty($created_date_value) ||
                       !empty($user_search) || !empty($assigned_to) || $staff_scope || ($tags_supported && !empty($tag_filters)) || $sort !== 'newest';
        ?>

        <!-- Mobile Filter Bar -->
        <details class="ticket-mobile-filters">
            <summary><?php echo get_icon('filter', 'w-4 h-4'); ?> <?php echo e(t('workflow.filters')); ?></summary>
            <form method="get" action="index.php" class="ticket-mobile-filter-form">
                <input type="hidden" name="page" value="tickets">
                <input type="hidden" name="search_scope" value="all">
                <?php if (!$is_archive && $ticket_list_view !== 'open'): ?>
                    <input type="hidden" name="work_view" value="<?php echo e($ticket_list_view); ?>">
                <?php endif; ?>
                <?php if ($is_archive): ?>
                    <input type="hidden" name="archived" value="1">
                <?php endif; ?>
                <input type="text" name="search" value="<?php echo e($search_query); ?>"
                    placeholder="<?php echo e(t('Search...')); ?>"
                    class="form-input form-input-sm flex-1 min-w-[120px] text-xs">
                <?php if ($tags_supported): ?>
                    <input type="text" name="tags" value="<?php echo e($tag_filter_csv); ?>"
                        placeholder="#<?php echo e(t('Tags')); ?>"
                        class="form-input form-input-sm w-[140px] text-xs">
                <?php endif; ?>
                <select name="status" class="form-select form-select-sm text-xs" onchange="this.form.submit()">
                    <option value=""><?php echo e(t('Status')); ?></option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?php echo $status['id']; ?>" <?php echo $status_id == $status['id'] ? 'selected' : ''; ?>>
                            <?php echo e($status['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="priority" class="form-select form-select-sm text-xs" onchange="this.form.submit()">
                    <option value=""><?php echo e(t('Priority')); ?></option>
                    <?php foreach ($priorities as $priority): ?>
                        <option value="<?php echo $priority['id']; ?>" <?php echo $priority_id == $priority['id'] ? 'selected' : ''; ?>>
                            <?php echo e($priority['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="sort" class="form-select form-select-sm text-xs" onchange="this.form.submit()">
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>><?php echo e(t('Newest')); ?></option>
                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>><?php echo e(t('Oldest')); ?></option>
                    <option value="completed" <?php echo $sort === 'completed' ? 'selected' : ''; ?>><?php echo e(t('Completed')); ?></option>
                    <option value="last_updated" <?php echo $sort === 'last_updated' ? 'selected' : ''; ?>><?php echo e(t('Last updated')); ?></option>
                    <option value="ticket_number" <?php echo $sort === 'ticket_number' ? 'selected' : ''; ?>><?php echo e(t('Ticket # (newest)')); ?></option>
                    <option value="ticket_number_asc" <?php echo $sort === 'ticket_number_asc' ? 'selected' : ''; ?>><?php echo e(t('Ticket # (oldest)')); ?></option>
                    <option value="priority" <?php echo $sort === 'priority' ? 'selected' : ''; ?>><?php echo e(t('Priority')); ?></option>
                    <option value="status" <?php echo $sort === 'status' ? 'selected' : ''; ?>><?php echo e(t('Status')); ?></option>
                    <option value="due_date" <?php echo $sort === 'due_date' ? 'selected' : ''; ?>><?php echo e(t('Due date')); ?></option>
                    <?php if ($tags_supported): ?>
                        <option value="tags" <?php echo $sort === 'tags' ? 'selected' : ''; ?>><?php echo e(t('Tags')); ?></option>
                    <?php endif; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-xs"><?php echo get_icon('search', 'w-3 h-3'); ?></button>
                <?php if ($has_filters): ?>
                <a href="<?php echo e($ticket_clear_url); ?>" class="btn btn-secondary btn-xs">
                    <?php echo get_icon('x', 'w-3 h-3'); ?>
                </a>
                <?php endif; ?>
            </form>
        </details>
        <?php if ($tags_supported && !empty($tag_filters)): ?>
            <?php
            $clear_tags_params = $_GET;
            unset($clear_tags_params['page'], $clear_tags_params['p'], $clear_tags_params['tag'], $clear_tags_params['tags']);
            if ($is_archive) {
                $clear_tags_params['archived'] = '1';
            } else {
                unset($clear_tags_params['archived']);
            }
            $clear_tags_url = url('tickets', $clear_tags_params);
            ?>
            <div class="border-b px-4 py-2.5 flex flex-wrap items-center gap-2" style="border-color: var(--border-light); background: var(--surface-secondary);">
                <span class="text-xs font-medium text-theme-secondary"><?php echo e(t('Tags')); ?>:</span>
                <?php foreach ($tag_filters as $active_tag): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs" style="background: var(--primary-soft); color: var(--primary);">
                        #<?php echo e($active_tag); ?>
                        <a href="<?php echo e($build_remove_tag_filter_url($active_tag)); ?>" class="opacity-80 hover:opacity-100" aria-label="<?php echo e(t('Remove')); ?>">
                            &times;
                        </a>
                    </span>
                <?php endforeach; ?>
                <a href="<?php echo e($clear_tags_url); ?>" class="text-xs underline text-theme-secondary">
                    <?php echo e(t('Clear all tags')); ?>
                </a>
            </div>
        <?php endif; ?>

        <!-- Mobile List View -->
        <div class="block lg:hidden">
            <?php foreach ($ticket_groups as $group): ?>
                <?php if ($group['name'] === 'closed'): ?>
                    <div class="p-3 text-center border-t cursor-pointer text-xs font-medium text-gray-500 hover:text-gray-700 bg-theme-secondary" onclick="document.getElementById('closed-tickets-mobile').classList.toggle('hidden')">
                        <?php echo e($group['label']); ?>
                    </div>
                    <div id="closed-tickets-mobile" class="hidden divide-y">
                <?php else: ?>
                    <div class="divide-y">
                <?php endif; ?>
                <?php foreach ($group['tickets'] as $ticket):
                $priority_name = $ticket['priority_name'] ?? get_priority_label($ticket['priority_id'] ?? $ticket['priority'] ?? 'medium');
                $priority_color = $ticket['priority_color'] ?? get_priority_color($ticket['priority_id'] ?? $ticket['priority'] ?? 'medium');
                $is_overdue_mobile = is_due_date_overdue($ticket['due_date'] ?? null, !empty($ticket['is_closed']));
                ?>
                <div class="p-4 ticket-list-item<?php echo $is_overdue_mobile ? ' ticket-overdue' : ''; ?>">
                    <div class="flex items-start gap-3">
                        <?php if ($bulk_actions_enabled): ?>
                            <input type="checkbox" name="ticket_ids[]" value="<?php echo (int) $ticket['id']; ?>"
                                class="bulk-checkbox hidden mt-1 rounded" form="bulk-actions-form" onclick="event.stopPropagation()">
                        <?php endif; ?>
                            <div class="ticket-mobile-content flex-1 min-w-0">
                            <a href="<?php echo ticket_url($ticket); ?>" class="ticket-mobile-primary">
                                <div class="ticket-mobile-heading flex items-center gap-2 text-xs text-theme-muted">
                                    <span class="w-2 h-2 rounded-full"
                                        style="background-color: <?php echo e($ticket['status_color']); ?>"></span>
                                    <span><?php echo e($ticket['status_name']); ?></span>
                                    <span class="ticket-code-pill" title="<?php echo e('#' . (int) $ticket['id']); ?>">
                                        <?php echo e(get_ticket_code($ticket['id'])); ?>
                                    </span>
                                </div>
                                <div class="ticket-mobile-title font-medium truncate text-theme-primary" dir="auto"><?php echo e($ticket['title']); ?></div>
                            </a>
                                <div class="ticket-mobile-meta text-sm mt-1 flex flex-wrap items-center gap-2 text-theme-muted">
                                    <span><?php echo format_date($ticket['created_at'], 'd.m.Y'); ?></span>
                                    <?php if (!empty($ticket['due_date'])): ?>
                                        <?php
                                        $due_ts = strtotime($ticket['due_date']);
                                        $is_overdue = is_due_date_overdue($ticket['due_date'] ?? null, !empty($ticket['is_closed']));
                                        ?>
                                        <span
                                            class="<?php echo $is_overdue ? 'text-red-600 font-medium' : ''; ?> text-xs"
                                            <?php if (!$is_overdue): ?>style="color: var(--text-muted);"<?php endif; ?>
                                            title="<?php echo e(t('Due date')); ?>">
                                            <?php echo get_icon('calendar-alt', 'ml-1 mr-0.5 w-3 h-3 inline'); ?>
                                            <?php echo date('d.m.', $due_ts); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="badge ticket-registry-priority-label"
                                        style="background-color: <?php echo e($priority_color); ?>20; color: <?php echo e($priority_color); ?>">
                                        <?php echo e($priority_name); ?>
                                    </span>
                                    <?php if (is_admin() && !empty($ticket['organization_name'])): ?>
                                        <span class="text-xs text-theme-muted"><bdi><?php echo e($ticket['organization_name']); ?></bdi></span>
                                    <?php endif; ?>
                                    <?php if ($tags_supported && !empty($ticket['tags'])): ?>
                                        <?php foreach (array_slice(get_ticket_tags_array($ticket['tags']), 0, 3) as $tag): ?>
                                            <a href="<?php echo e($build_tag_filter_url($tag)); ?>"
                                               class="ticket-tag-pill"
                                               title="<?php echo e(t('Filter by this tag')); ?>">
                                                #<?php echo e($tag); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <?php if ($show_time): ?>
                                        <?php
                                        $ticket_total = $ticket_time_totals[$ticket['id']] ?? 0;
                                        $running_entries = $ticket_running_entries[$ticket['id']] ?? [];
                                        $running_label = '';
                                        $running_elapsed = 0;
                                        if (!empty($running_entries)) {
                                            $first = $running_entries[0];
                                            $name = trim(($first['first_name'] ?? '') . ' ' . ($first['last_name'] ?? ''));
                                            $name = $name !== '' ? $name : t('Unknown user');
                                            $extra = count($running_entries) - 1;
                                            $running_label = $name . ($extra > 0 ? ' +' . $extra : '');
                                            $running_elapsed = (int) ($first['elapsed_minutes'] ?? 0);
                                        }
                                        ?>
                                        <span class="text-xs text-theme-muted">
                                            <?php echo get_icon('clock', 'mr-1 w-3 h-3 inline'); ?><?php echo $ticket_total > 0 ? format_duration_minutes($ticket_total) : '-'; ?>
                                        </span>
                                        <?php if (!empty($running_label)): ?>
                                            <span class="text-xs text-green-600">
                                                <?php echo e(t('Running')); ?>: <?php echo e($running_label); ?> -
                                                <?php echo e(format_duration_minutes($running_elapsed)); ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
            <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Desktop Table View with Inline Filters -->
        <form method="get" action="index.php" id="filter-form">
                <input type="hidden" name="page" value="tickets">
                <input type="hidden" name="search_scope" value="all">
                <?php if (!$is_archive && $ticket_list_view !== 'open'): ?>
                    <input type="hidden" name="work_view" value="<?php echo e($ticket_list_view); ?>">
                <?php endif; ?>
                <?php if ($is_archive): ?>
                    <input type="hidden" name="archived" value="1">
                <?php endif; ?>
            <div class="ticket-filter-toolbar">
                <div class="ticket-search-wrap">
                                        <input type="text" name="search" value="<?php echo e($search_query); ?>"
                                            placeholder="<?php echo e(t('Search...')); ?>"
                                            class="ticket-search-input"
                                            id="ticket-search-input"
                                            autocomplete="off">
                                        <span class="search-icon"><?php echo get_icon('search', 'w-3 h-3'); ?></span>
                                        <div class="ticket-search-suggestions" id="ticket-search-suggestions"></div>
                                    </div>
                <select name="status" class="filter-select" onchange="this.form.submit()">
                                <option value=""><?php echo e(t('Status')); ?></option>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo $status['id']; ?>" <?php echo $status_id == $status['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($status['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                <select name="priority" class="filter-select" onchange="this.form.submit()">
                                <option value=""><?php echo e(t('Priority')); ?></option>
                                <?php foreach ($priorities as $priority): ?>
                                    <option value="<?php echo $priority['id']; ?>" <?php echo $priority_id == $priority['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($priority['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                <select name="due_date" class="filter-select" onchange="this.form.submit()">
                                <option value=""><?php echo e(t('Due')); ?></option>
                                <option value="overdue" <?php echo $due_date_filter === 'overdue' ? 'selected' : ''; ?>>!</option>
                                <option value="today" <?php echo $due_date_filter === 'today' ? 'selected' : ''; ?>><?php echo e(t('Today')); ?></option>
                                <option value="week" <?php echo $due_date_filter === 'week' ? 'selected' : ''; ?>><?php echo e(t('Week')); ?></option>
                            </select>
                <?php if (is_admin() && !empty($organizations)): ?>
                    <select name="organization" class="filter-select" onchange="this.form.submit()">
                                    <option value=""><?php echo e(t('Company')); ?></option>
                                    <?php foreach ($organizations as $org): ?>
                                        <option value="<?php echo $org['id']; ?>" <?php echo $organization_id == $org['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($org['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                <?php endif; ?>
                <?php if (is_admin() || is_agent()): ?>
                    <select name="user" class="filter-select" onchange="this.form.submit()">
                                    <option value=""><?php echo e(t('User...')); ?></option>
                                    <?php foreach ($filter_users as $fu): ?>
                                        <option value="<?php echo e($fu['first_name'] . ' ' . $fu['last_name']); ?>"
                                            <?php echo $user_search === ($fu['first_name'] . ' ' . $fu['last_name']) ? 'selected' : ''; ?>>
                                            <?php echo e($fu['first_name'] . ' ' . substr($fu['last_name'] ?? '', 0, 1) . '.'); ?>
                                            <?php if ($fu['role'] !== 'user'): ?><span style="opacity:0.5">(<?php echo e($fu['role']); ?>)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                <?php endif; ?>
                <?php if ($has_filters): ?>
                    <a href="<?php echo e($ticket_clear_url); ?>" class="btn btn-secondary btn-sm"><?php echo e(t('Clear')); ?></a>
                <?php endif; ?>
            </div>
            <input type="hidden" name="created_date" value="<?php echo e($created_date_value); ?>">
            <input type="hidden" name="sort" value="<?php echo e($sort); ?>">
            <div class="ticket-table-scroll" tabindex="0" role="region" aria-label="<?php echo e(t('Tickets')); ?>">
            <table class="w-full hidden lg:table tickets-table tickets-table--fixed text-xs">
                <thead>
                    <tr class="border-b border-theme-light">
                        <th scope="col" class="px-3 py-2.5 text-left tickets-col-date">
                            <div class="flex items-center gap-1">
                                <?php if ($bulk_actions_enabled): ?>
                                    <input type="checkbox" id="select-all" aria-label="<?php echo e(t('Select all')); ?>" class="rounded hidden" onchange="toggleAll(this)">
                                <?php endif; ?>
                                <a href="<?php echo e($date_sort_url); ?>"
                                   class="ticket-date-sort <?php echo $date_sort_is_active ? 'is-active' : ''; ?>"
                                   title="<?php echo e($date_sort_title); ?>"
                                   aria-label="<?php echo e($date_sort_title); ?>">
                                    <span><?php echo e(t('Date')); ?></span>
                                    <?php echo get_icon($date_sort_icon, 'w-3 h-3'); ?>
                                </a>
                            </div>
                        </th>
                        <th scope="col" class="tickets-col-subject"><?php echo e(t('Subject')); ?></th>
                        <th scope="col" class="tickets-col-status"><?php echo e(t('Status')); ?></th>
                        <th scope="col" class="tickets-col-priority"><?php echo e(t('Priority')); ?></th>
                        <th scope="col" class="tickets-col-due" title="<?php echo e(t('Due date')); ?>"><?php echo e(t('Due')); ?></th>
                        <?php if (is_admin()): ?>
                            <th scope="col" class="tickets-col-company"><?php echo e(t('Company')); ?></th>
                        <?php endif; ?>
                        <?php if (is_admin() || is_agent()): ?>
                            <th scope="col" class="tickets-col-user"><?php echo e(t('workflow.assigned')); ?></th>
                            <th scope="col" class="tickets-col-time"><?php echo e(t('Time')); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ((is_agent() || is_admin()) && !$is_archive): ?>
                    <tr id="new-ticket-row" class="new-ticket-row" style="background: var(--surface-secondary); display: none;">
                        <td class="px-3 py-1.5 whitespace-nowrap align-middle text-center">
                            <button type="button" id="new-ticket-submit-btn"
                                    class="nt-plus-btn"
                                    title="<?php echo e(t('Add ticket')); ?>">
                                <?php echo get_icon('plus', 'w-4 h-4'); ?>
                            </button>
                        </td>
                        <td class="px-3 py-1.5 align-middle">
                            <input type="text" id="new-ticket-subject"
                                   class="nt-input w-full"
                                   placeholder="<?php echo e(t('New ticket subject — press Enter')); ?>"
                                   maxlength="500">
                            <?php if (!empty($ticket_types_list)): ?>
                            <select id="new-ticket-type" class="nt-input nt-input-sm mt-1 w-full" style="font-size: 10px;">
                                <option value=""><?php echo e(t('Type')); ?></option>
                                <?php foreach ($ticket_types_list as $tt): ?>
                                    <option value="<?php echo e($tt['slug']); ?>"><?php echo e($tt['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-1.5 align-middle">
                            <select id="new-ticket-status" class="nt-input w-full">
                                <?php foreach ($statuses as $st): ?>
                                    <option value="<?php echo (int)$st['id']; ?>"><?php echo e($st['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="px-2 py-1.5 align-middle">
                            <select id="new-ticket-priority" class="nt-input w-full">
                                <option value=""><?php echo e(t('Priority')); ?></option>
                                <?php foreach ($priorities as $pr): ?>
                                    <option value="<?php echo (int)$pr['id']; ?>"><?php echo e($pr['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="px-2 py-1.5 align-middle">
                            <input type="date" id="new-ticket-due" class="nt-input w-full">
                        </td>
                        <?php if (is_admin()): ?>
                            <td class="px-2 py-1.5 align-middle">
                                <?php if (!empty($organizations)): ?>
                                <select id="new-ticket-company" class="nt-input w-full">
                                    <option value=""><?php echo e(t('Company')); ?></option>
                                    <?php foreach ($organizations as $org): ?>
                                        <option value="<?php echo (int)$org['id']; ?>"><?php echo e($org['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td class="px-2 py-1.5 align-middle">
                            <select id="new-ticket-assignee" class="nt-input w-full">
                                <option value=""><?php echo e(t('Unassigned')); ?></option>
                                <?php foreach ($assignable_agents as $_ag): ?>
                                    <option value="<?php echo (int)$_ag['id']; ?>">
                                        <?php echo e($_ag['first_name'] . ' ' . substr($_ag['last_name'] ?? '', 0, 1) . '.'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="px-3 py-1.5 align-middle">
                            <input type="number" id="new-ticket-minutes"
                                   class="nt-input w-full"
                                   placeholder="<?php echo e(t('min')); ?>"
                                   min="0" max="1440" step="1"
                                   title="<?php echo e(t('Log time (minutes)')); ?>">
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($ticket_groups as $group): ?>
                    <?php if ($group['name'] === 'closed'): ?>
                        </tbody>
                        <tbody class="border-t-2" style="border-top-color: var(--border-light)">
                            <tr class="cursor-pointer bg-theme-secondary" onclick="document.getElementById('closed-tickets-desktop').classList.toggle('hidden')">
                                <?php $colspan = is_admin() ? 8 : (is_agent() ? 6 : 5); ?>
                                <td colspan="<?php echo $colspan; ?>" class="px-3 py-2 font-medium text-xs text-center text-gray-500 hover:text-gray-700">
                                   <?php echo e($group['label']); ?>
                                </td>
                            </tr>
                        </tbody>
                        <tbody id="closed-tickets-desktop" class="hidden">
                    <?php endif; ?>
                    <?php foreach ($group['tickets'] as $ticket):
                        $priority_name = $ticket['priority_name'] ?? get_priority_label($ticket['priority_id'] ?? $ticket['priority'] ?? 'medium');
                        $priority_color = $ticket['priority_color'] ?? get_priority_color($ticket['priority_id'] ?? $ticket['priority'] ?? 'medium');
                        $is_overdue = is_due_date_overdue($ticket['due_date'] ?? null, !empty($ticket['is_closed']));
                        ?>
                        <tr class="ticket-row<?php echo $is_overdue ? ' ticket-overdue' : ''; ?>" data-href="<?php echo e(ticket_url($ticket)); ?>">
                            <td class="px-3 py-2.5 whitespace-nowrap align-top">
                                <div class="flex items-center gap-1.5">
                                    <?php if ($bulk_actions_enabled): ?>
                                        <input type="checkbox" name="ticket_ids[]" value="<?php echo (int) $ticket['id']; ?>"
                                            class="bulk-checkbox hidden rounded flex-shrink-0" form="bulk-actions-form">
                                    <?php endif; ?>
                                    <div>
                                        <a href="<?php echo ticket_url($ticket); ?>" class="font-medium text-xs text-theme-primary" title="<?php echo e(get_ticket_code($ticket['id'])); ?>">
                                            <?php echo date('d.m.', strtotime($ticket['created_at'])); ?>
                                        </a>
                                        <div class="text-[10px] text-theme-muted"><?php echo e(get_ticket_code($ticket['id'])); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 align-top">
                                <div class="flex items-center gap-1.5">
                                    <?php if (is_agent() || is_admin()): ?>
                                    <span class="ticket-subject-link tl-inline-text tl-inline-edit"
                                          dir="auto"
                                          data-ticket="<?php echo (int)$ticket['id']; ?>"
                                          data-field="subject"
                                          data-value="<?php echo e($ticket['title']); ?>"
                                          title="<?php echo e(t('Click to edit')); ?>"
                                          style="cursor: text;"><?php echo e($ticket['title']); ?></span>
                                    <?php else: ?>
                                    <a href="<?php echo ticket_url($ticket); ?>" class="ticket-subject-link" dir="auto">
                                        <?php echo e($ticket['title']); ?>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (!empty($ticket['attachment_count']) && $ticket['attachment_count'] > 0): ?>
                                        <span class="flex-shrink-0 text-theme-muted" title="<?php echo e(t('Attachments')); ?>: <?php echo $ticket['attachment_count']; ?>">
                                            <?php echo get_icon('paperclip', 'w-3 h-3'); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="ticket-secondary-meta text-theme-muted">
                                    <?php if ((is_agent() || is_admin()) && !empty($ticket_types_list)): ?>
                                        <span class="tl-inline-edit" style="position:relative; display:inline-block;">
                                            <span class="tl-edit-trigger tl-type-trigger"
                                                  data-ticket="<?php echo (int)$ticket['id']; ?>"
                                                  data-field="type"
                                                  style="cursor:pointer; text-decoration: underline dotted; text-underline-offset: 2px;">
                                                <?php echo e(get_type_label($ticket['type'])); ?>
                                            </span>
                                            <span class="tl-dropdown hidden" data-dropdown="type-<?php echo (int)$ticket['id']; ?>">
                                                <?php foreach ($ticket_types_list as $tt): ?>
                                                    <button type="button" class="tl-dropdown-item"
                                                        onclick="inlineUpdateType(<?php echo (int)$ticket['id']; ?>, '<?php echo e($tt['slug']); ?>', <?php echo e(json_encode($tt['name'])); ?>, this)">
                                                        <?php echo e($tt['name']); ?>
                                                    </button>
                                                <?php endforeach; ?>
                                            </span>
                                        </span>
                                    <?php else: ?>
                                        <?php echo e(get_type_label($ticket['type'])); ?>
                                    <?php endif; ?>
                                    <?php if (!is_admin() && !empty($ticket['organization_name'])): ?>
                                        <span class="ml-1"><bdi><?php echo e($ticket['organization_name']); ?></bdi></span>
                                    <?php endif; ?>
                                    <?php if ($tags_supported && !empty($ticket['tags'])): ?>
                                        <?php foreach (array_slice(get_ticket_tags_array($ticket['tags']), 0, 3) as $tag): ?>
                                            <a href="<?php echo e($build_tag_filter_url($tag)); ?>"
                                               class="ticket-tag-pill ml-1"
                                               title="<?php echo e(t('Filter by this tag')); ?>">
                                                #<?php echo e($tag); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-2 py-2.5 whitespace-nowrap align-top">
                                <?php if (is_agent() || is_admin()): ?>
                                <div class="tl-inline-edit" style="position:relative;">
                                    <span class="badge-inline ticket-registry-status-label tl-edit-trigger" data-ticket="<?php echo (int)$ticket['id']; ?>" data-field="status"
                                        style="background-color: <?php echo e($ticket['status_color']); ?>20; color: <?php echo e($ticket['status_color']); ?>; cursor:pointer;"
                                        title="<?php echo e(t('Click to change')); ?>">
                                        <?php echo e($ticket['status_name']); ?>
                                    </span>
                                    <div class="tl-dropdown hidden" data-dropdown="status-<?php echo (int)$ticket['id']; ?>">
                                        <?php foreach ($statuses as $st): ?>
                                        <button type="button" class="tl-dropdown-item" onclick="inlineUpdate(<?php echo (int)$ticket['id']; ?>, 'status', <?php echo (int)$st['id']; ?>, this)"
                                            style="color: <?php echo e($st['color']); ?>;">
                                            <span class="w-2 h-2 rounded-full inline-block mr-1.5" style="background:<?php echo e($st['color']); ?>;"></span>
                                            <?php echo e($st['name']); ?>
                                        </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <span class="badge-inline ticket-registry-status-label"
                                    style="background-color: <?php echo e($ticket['status_color']); ?>20; color: <?php echo e($ticket['status_color']); ?>">
                                    <?php echo e($ticket['status_name']); ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-2 py-2.5 whitespace-nowrap align-top">
                                <?php if (is_agent() || is_admin()): ?>
                                <div class="tl-inline-edit" style="position:relative;">
                                    <span class="badge-inline ticket-registry-priority-label tl-edit-trigger" data-ticket="<?php echo (int)$ticket['id']; ?>" data-field="priority"
                                        style="background-color: <?php echo e($priority_color); ?>20; color: <?php echo e($priority_color); ?>; cursor:pointer;"
                                        title="<?php echo e(t('Click to change')); ?>">
                                        <?php echo e($priority_name); ?>
                                    </span>
                                    <div class="tl-dropdown hidden" data-dropdown="priority-<?php echo (int)$ticket['id']; ?>">
                                        <?php foreach ($priorities as $pr): ?>
                                        <button type="button" class="tl-dropdown-item" onclick="inlineUpdate(<?php echo (int)$ticket['id']; ?>, 'priority', <?php echo (int)$pr['id']; ?>, this)"
                                            style="color: <?php echo e($pr['color']); ?>;">
                                            <span class="w-2 h-2 rounded-full inline-block mr-1.5" style="background:<?php echo e($pr['color']); ?>;"></span>
                                            <?php echo e($pr['name']); ?>
                                        </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <span class="badge-inline ticket-registry-priority-label"
                                    style="background-color: <?php echo e($priority_color); ?>20; color: <?php echo e($priority_color); ?>">
                                    <?php echo e($priority_name); ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-2 py-2.5 whitespace-nowrap align-top text-xs text-theme-muted">
                                <?php
                                $_due_ts = !empty($ticket['due_date']) ? strtotime($ticket['due_date']) : null;
                                $_is_overdue = is_due_date_overdue($ticket['due_date'] ?? null, !empty($ticket['is_closed']));
                                $_due_iso = $_due_ts ? date('Y-m-d', $_due_ts) : '';
                                ?>
                                <?php if (is_agent() || is_admin()): ?>
                                    <span class="tl-due-trigger tl-inline-edit <?php echo $_is_overdue ? 'text-red-600 font-medium' : ''; ?>"
                                          data-ticket="<?php echo (int)$ticket['id']; ?>"
                                          data-due="<?php echo e($_due_iso); ?>"
                                          data-is-closed="<?php echo !empty($ticket['is_closed']) ? '1' : '0'; ?>"
                                          style="cursor:pointer; text-decoration: underline dotted; text-underline-offset: 2px;"
                                          title="<?php echo e(t('Click to change')); ?>">
                                        <?php if ($_due_ts): ?>
                                            <?php echo date('d.m', $_due_ts); ?>
                                            <?php if ($_is_overdue): ?>
                                                <?php echo get_icon('exclamation-circle', 'w-2.5 h-2.5 inline ml-0.5'); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="opacity:0.4;">—</span>
                                        <?php endif; ?>
                                    </span>
                                <?php elseif ($_due_ts): ?>
                                    <span class="<?php echo $_is_overdue ? 'text-red-600 font-medium' : ''; ?>">
                                        <?php echo date('d.m', $_due_ts); ?>
                                        <?php if ($_is_overdue): ?>
                                            <?php echo get_icon('exclamation-circle', 'w-2.5 h-2.5 inline ml-0.5'); ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <?php if (is_admin()): ?>
                                <td class="px-2 py-2.5 text-xs truncate align-top" style="color: var(--text-muted); overflow: visible;" title="<?php echo e($ticket['organization_name'] ?? ''); ?>">
                                    <span class="tl-inline-edit" style="position:relative; display:inline-block;">
                                        <span class="tl-edit-trigger tl-company-trigger"
                                              data-ticket="<?php echo (int)$ticket['id']; ?>"
                                              data-field="company"
                                              style="cursor:pointer; text-decoration: underline dotted; text-underline-offset: 2px;"
                                              title="<?php echo e(t('Click to change')); ?>">
                                            <?php if (!empty($ticket['organization_name'])): ?>
                                                <bdi><?php echo e($ticket['organization_name']); ?></bdi>
                                            <?php else: ?>
                                                <span style="opacity:0.4;">—</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="tl-dropdown hidden" data-dropdown="company-<?php echo (int)$ticket['id']; ?>">
                                            <button type="button" class="tl-dropdown-item"
                                                onclick="inlineUpdateCompany(<?php echo (int)$ticket['id']; ?>, '', <?php echo e(json_encode(t('No company'))); ?>, this)">
                                                <span style="opacity:0.6;"><?php echo e(t('No company')); ?></span>
                                            </button>
                                            <?php foreach ($organizations as $org): ?>
                                                <button type="button" class="tl-dropdown-item"
                                                    onclick="inlineUpdateCompany(<?php echo (int)$ticket['id']; ?>, <?php echo (int)$org['id']; ?>, <?php echo e(json_encode($org['name'])); ?>, this)">
                                                    <?php echo e($org['name']); ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </span>
                                    </span>
                                </td>
                            <?php endif; ?>
                            <?php if (is_admin()): ?>
                                <td class="px-2 py-2.5 text-xs truncate align-top" style="color: var(--text-muted); overflow: visible;" title="<?php echo e($ticket['first_name'] . ' ' . $ticket['last_name']); ?>">
                                    <span class="tl-inline-edit" style="position:relative; display:inline-block;">
                                        <span class="tl-edit-trigger tl-assign-trigger"
                                              data-ticket="<?php echo (int)$ticket['id']; ?>"
                                              data-field="assign"
                                              style="cursor:pointer; text-decoration: underline dotted; text-underline-offset: 2px;"
                                              title="<?php echo e(t('Click to change')); ?>">
                                            <?php if (!empty($ticket['assignee_id'])):
                                                $_assigned = null;
                                                foreach ($assignable_agents as $_ag) { if ((int)$_ag['id'] === (int)$ticket['assignee_id']) { $_assigned = $_ag; break; } }
                                            ?>
                                                <?php if ($_assigned): ?>
                                                    <?php echo e($_assigned['first_name'] . ' ' . substr($_assigned['last_name'] ?? '', 0, 1) . '.'); ?>
                                                <?php else: ?>
                                                    <?php echo e(t('Unassigned')); ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="opacity:0.4;"><?php echo e(t('Unassigned')); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="tl-dropdown hidden" data-dropdown="assign-<?php echo (int)$ticket['id']; ?>">
                                            <button type="button" class="tl-dropdown-item"
                                                onclick="inlineUpdateAssign(<?php echo (int)$ticket['id']; ?>, '', <?php echo e(json_encode(t('Unassigned'))); ?>, this)">
                                                <span style="opacity:0.6;"><?php echo e(t('Unassigned')); ?></span>
                                            </button>
                                            <?php foreach ($assignable_agents as $_ag): ?>
                                                <button type="button" class="tl-dropdown-item"
                                                    onclick="inlineUpdateAssign(<?php echo (int)$ticket['id']; ?>, <?php echo (int)$_ag['id']; ?>, <?php echo e(json_encode($_ag['first_name'] . ' ' . substr($_ag['last_name'] ?? '', 0, 1) . '.')); ?>, this)">
                                                    <?php echo e($_ag['first_name'] . ' ' . $_ag['last_name']); ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </span>
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-xs whitespace-nowrap align-top text-theme-muted">
                                    <?php
                                    $ticket_total = $ticket_time_totals[$ticket['id']] ?? 0;
                                    $running_entries = $ticket_running_entries[$ticket['id']] ?? [];
                                    ?>
                                    <div class="flex items-center gap-1.5">
                                        <?php if (!empty($running_entries)): ?>
                                            <span class="text-green-600"><?php echo get_icon('play', 'w-2.5 h-2.5 inline'); ?></span>
                                        <?php endif; ?>
                                        <?php if ($ticket_total > 0): ?>
                                            <span><?php echo e(format_duration_minutes($ticket_total)); ?></span>
                                        <?php endif; ?>
                                        <button type="button" class="inline-log-time__btn js-inline-log-time"
                                            data-ticket-id="<?php echo (int) $ticket['id']; ?>"
                                            title="<?php echo e(t('Log time')); ?>"
                                            aria-label="<?php echo e(t('Log time')); ?>">
                                            <?php echo get_icon('clock', 'w-3.5 h-3.5'); ?>
                                        </button>
                                    </div>
                                </td>
                            <?php elseif (is_agent()): ?>
                                <td class="px-2 py-2.5 text-xs truncate align-top" style="color: var(--text-muted); overflow: visible;" title="<?php echo e($ticket['first_name'] . ' ' . $ticket['last_name']); ?>">
                                    <span class="tl-inline-edit" style="position:relative; display:inline-block;">
                                        <span class="tl-edit-trigger tl-assign-trigger"
                                              data-ticket="<?php echo (int)$ticket['id']; ?>"
                                              data-field="assign"
                                              style="cursor:pointer; text-decoration: underline dotted; text-underline-offset: 2px;"
                                              title="<?php echo e(t('Click to change')); ?>">
                                            <?php if (!empty($ticket['assignee_id'])):
                                                $_assigned = null;
                                                foreach ($assignable_agents as $_ag) { if ((int)$_ag['id'] === (int)$ticket['assignee_id']) { $_assigned = $_ag; break; } }
                                            ?>
                                                <?php if ($_assigned): ?>
                                                    <?php echo e($_assigned['first_name'] . ' ' . substr($_assigned['last_name'] ?? '', 0, 1) . '.'); ?>
                                                <?php else: ?>
                                                    <?php echo e(t('Unassigned')); ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="opacity:0.4;"><?php echo e(t('Unassigned')); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="tl-dropdown hidden" data-dropdown="assign-<?php echo (int)$ticket['id']; ?>">
                                            <button type="button" class="tl-dropdown-item"
                                                onclick="inlineUpdateAssign(<?php echo (int)$ticket['id']; ?>, '', <?php echo e(json_encode(t('Unassigned'))); ?>, this)">
                                                <span style="opacity:0.6;"><?php echo e(t('Unassigned')); ?></span>
                                            </button>
                                            <?php foreach ($assignable_agents as $_ag): ?>
                                                <button type="button" class="tl-dropdown-item"
                                                    onclick="inlineUpdateAssign(<?php echo (int)$ticket['id']; ?>, <?php echo (int)$_ag['id']; ?>, <?php echo e(json_encode($_ag['first_name'] . ' ' . substr($_ag['last_name'] ?? '', 0, 1) . '.')); ?>, this)">
                                                    <?php echo e($_ag['first_name'] . ' ' . $_ag['last_name']); ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </span>
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-xs whitespace-nowrap align-top text-theme-muted">
                                    <?php
                                    $ticket_total = $ticket_time_totals[$ticket['id']] ?? 0;
                                    $running_entries = $ticket_running_entries[$ticket['id']] ?? [];
                                    ?>
                                    <div class="flex items-center gap-1.5">
                                        <?php if (!empty($running_entries)): ?>
                                            <span class="text-green-600"><?php echo get_icon('play', 'w-2.5 h-2.5 inline'); ?></span>
                                        <?php endif; ?>
                                        <?php if ($ticket_total > 0): ?>
                                            <span><?php echo e(format_duration_minutes($ticket_total)); ?></span>
                                        <?php endif; ?>
                                        <button type="button" class="inline-log-time__btn js-inline-log-time"
                                            data-ticket-id="<?php echo (int) $ticket['id']; ?>"
                                            title="<?php echo e(t('Log time')); ?>"
                                            aria-label="<?php echo e(t('Log time')); ?>">
                                            <?php echo get_icon('clock', 'w-3.5 h-3.5'); ?>
                                        </button>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php endforeach; ?>
            </table>
            </div>
        </form>

        <!-- Bulk Actions Bar -->
        <?php if ($bulk_actions_enabled): ?>
            <form method="post" id="bulk-actions-form">
                <?php echo csrf_field(); ?>
                <div id="bulk-actions"
                    class="hidden sticky bottom-0 border-t card-body space-y-3 <?php echo $bulk_delete_mode ? 'bg-red-50 border-red-200' : ''; ?>"
                    style="<?php echo $bulk_delete_mode ? '' : 'border-color: var(--border-light); background: var(--surface-secondary);'; ?>">
                    <div class="flex items-center justify-between">
                        <div class="inline-flex items-center gap-2 text-sm">
                            <span class="inline-flex items-center justify-center min-w-[1.75rem] h-7 px-2 fd-rounded-pill font-semibold bg-theme-tertiary text-theme-secondary">
                                <span id="selected-count">0</span>
                            </span>
                            <span class="text-theme-secondary"><?php echo e(t('selected')); ?></span>
                        </div>
                    </div>

                    <?php if ($bulk_archive_mode): ?>
                        <div class="grid grid-cols-1 <?php echo $tags_supported ? 'lg:grid-cols-5' : 'lg:grid-cols-3'; ?> gap-2 lg:gap-3">
                            <select name="bulk_organization_id" class="form-select form-select-sm">
                                <option value="__keep__"><?php echo e(t('Keep company')); ?></option>
                                <option value="__none__"><?php echo e(t('No company')); ?></option>
                                <?php foreach ($organizations as $org): ?>
                                    <option value="<?php echo (int) $org['id']; ?>"><?php echo e($org['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="bulk_status_id" class="form-select form-select-sm">
                                <option value=""><?php echo e(t('Keep status')); ?></option>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo (int) $status['id']; ?>"><?php echo e($status['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="bulk_priority_id" class="form-select form-select-sm">
                                <option value=""><?php echo e(t('Keep priority')); ?></option>
                                <?php foreach ($priorities as $priority): ?>
                                    <option value="<?php echo (int) $priority['id']; ?>"><?php echo e($priority['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($tags_supported): ?>
                                <input type="text" name="bulk_tags" class="form-input form-input-sm"
                                    placeholder="<?php echo e(t('Tags')); ?>">
                                <select name="bulk_tags_mode" class="form-select form-select-sm">
                                    <option value="keep"><?php echo e(t('Keep tags')); ?></option>
                                    <option value="replace"><?php echo e(t('Replace tags')); ?></option>
                                    <option value="append"><?php echo e(t('Append tags')); ?></option>
                                    <option value="clear"><?php echo e(t('Clear tags')); ?></option>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 justify-end flex-wrap">
                            <button type="submit" name="bulk_update" class="btn btn-primary btn-sm">
                                <?php echo get_icon('edit', 'mr-2'); ?><?php echo e(t('Apply bulk update')); ?>
                            </button>
                            <button type="submit" name="bulk_archive" class="btn btn-secondary btn-sm"
                                onclick="return confirm('<?php echo e(t('Move selected tickets to archive?')); ?>')">
                                <?php echo get_icon('archive', 'mr-2'); ?><?php echo e(t('Archive selected')); ?>
                            </button>
                        </div>
                    <?php elseif ($bulk_delete_mode): ?>
                        <div class="flex items-center justify-end">
                            <button type="submit" name="bulk_delete" class="btn btn-danger btn-sm"
                                onclick="return confirm('<?php echo e(t('Are you sure you want to permanently delete selected tickets? This action cannot be undone.')); ?>')">
                                <?php echo get_icon('trash', 'mr-2'); ?><?php echo e(t('Delete selected')); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
