        <!-- List mode: mobile filters, registry table, inline edits, and bulk actions. -->
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
                    aria-label="<?php echo e(t('Search')); ?>"
                    class="form-input form-input-sm flex-1 min-w-[120px] text-xs">
                <?php if ($tags_supported): ?>
                    <input type="text" name="tags" value="<?php echo e($tag_filter_csv); ?>"
                        placeholder="#<?php echo e(t('Tags')); ?>"
                        aria-label="<?php echo e(t('Tags')); ?>"
                        class="form-input form-input-sm w-[140px] text-xs">
                <?php endif; ?>
                <select name="status" aria-label="<?php echo e(t('Status')); ?>" class="form-select form-select-sm text-xs" onchange="this.form.submit()">
                    <option value=""><?php echo e(t('Status')); ?></option>
                    <?php foreach ($filter_statuses as $status): ?>
                        <option value="<?php echo $status['id']; ?>" <?php echo $status_id == $status['id'] ? 'selected' : ''; ?>>
                            <?php echo e($status_display_name($status)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="priority" aria-label="<?php echo e(t('Priority')); ?>" class="form-select form-select-sm text-xs" onchange="this.form.submit()">
                    <option value=""><?php echo e(t('Priority')); ?></option>
                    <?php foreach ($priorities as $priority): ?>
                        <option value="<?php echo $priority['id']; ?>" <?php echo $priority_id == $priority['id'] ? 'selected' : ''; ?>>
                            <?php echo e($priority['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="sort" aria-label="<?php echo e(t('Sort')); ?>" class="form-select form-select-sm text-xs" onchange="this.form.submit()">
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
                <button type="submit" class="btn btn-primary btn-xs" aria-label="<?php echo e(t('Search')); ?>"><?php echo get_icon('search', 'w-3 h-3'); ?></button>
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
            <div class="ticket-active-tags-bar">
                <span class="ticket-active-tags-label"><?php echo e(t('Tags')); ?>:</span>
                <?php foreach ($tag_filters as $active_tag): ?>
                    <span class="ticket-active-tag">
                        #<?php echo e($active_tag); ?>
                        <a href="<?php echo e($build_remove_tag_filter_url($active_tag)); ?>" class="ticket-active-tag-remove" aria-label="<?php echo e(t('Remove')); ?>">
                            &times;
                        </a>
                    </span>
                <?php endforeach; ?>
                <a href="<?php echo e($clear_tags_url); ?>" class="ticket-active-tags-clear">
                    <?php echo e(t('Clear all tags')); ?>
                </a>
            </div>
        <?php endif; ?>
        <!-- Mobile List View -->
        <div class="block lg:hidden">
            <?php foreach ($ticket_groups as $group): ?>
                <?php if ($group['name'] === 'closed'): ?>
                    <button type="button"
                        class="ticket-closed-toggle ticket-closed-toggle--mobile p-3 text-xs font-medium"
                        aria-controls="closed-tickets-mobile"
                        aria-expanded="false"
                        onclick="var target = document.getElementById('closed-tickets-mobile'); var collapsed = target.classList.toggle('hidden'); this.setAttribute('aria-expanded', collapsed ? 'false' : 'true');">
                        <?php echo e($group['label']); ?>
                    </button>
                    <div id="closed-tickets-mobile" class="hidden divide-y">
                <?php else: ?>
                    <?php if (!empty($group['label'])): ?>
                        <div class="ticket-list-section-label">
                            <span><?php echo e($group['label']); ?></span>
                            <span><?php echo e((string) count($group['tickets'])); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="divide-y">
                <?php endif; ?>
                <?php foreach ($group['tickets'] as $ticket):
                $priority_name = $ticket['priority_name'] ?? get_priority_label($ticket['priority_id'] ?? $ticket['priority'] ?? 'medium');
                $priority_color = $ticket['priority_color'] ?? get_priority_color($ticket['priority_id'] ?? $ticket['priority'] ?? 'medium');
                $is_overdue_mobile = is_due_date_overdue($ticket['due_date'] ?? null, !empty($ticket['is_closed']));
                ?>
                <div class="ticket-list-item <?php echo e(ticket_registry_status_accent_class($ticket, $statuses)); ?><?php echo $is_overdue_mobile ? ' ticket-overdue' : ''; ?>"
                     data-ticket-contract-row
                     data-ticket-id="<?php echo (int) $ticket['id']; ?>">
                    <div class="ticket-mobile-layout">
                        <?php if ($bulk_actions_enabled): ?>
                            <input type="checkbox" name="ticket_ids[]" value="<?php echo (int) $ticket['id']; ?>"
                                class="bulk-checkbox hidden mt-1 fd-rounded-control" form="bulk-actions-form" onclick="event.stopPropagation()"
                                aria-label="<?php echo e(t('Select') . ': ' . $ticket['title']); ?>">
                        <?php endif; ?>
                            <div class="ticket-mobile-content">
                                <a href="<?php echo ticket_url($ticket); ?>" class="ticket-mobile-primary">
                                <div class="ticket-mobile-heading">
                                    <?php echo function_exists('ticket_status_color_dot_svg')
                                        ? ticket_status_color_dot_svg(ticket_registry_status_from_ticket($ticket, $statuses))
                                        : '<span class="' . e(ticket_registry_status_dot_class(ticket_registry_status_group_from_ticket($ticket, $statuses))) . '"></span>'; ?>
                                    <span data-ticket-field="status"><?php echo e($ticket_status_display_name($ticket)); ?></span>
                                    <span class="ticket-code-pill" data-ticket-field="code" title="<?php echo e('#' . (int) $ticket['id']); ?>">
                                        <?php echo e(get_ticket_code($ticket['id'])); ?>
                                    </span>
                                </div>
                                <div class="ticket-mobile-title" data-ticket-field="title"><bdi dir="auto"><?php echo e($ticket['title']); ?></bdi></div>
                                </a>
                                <div class="ticket-mobile-meta">
                                    <span><?php echo format_date($ticket['created_at'], 'd.m.Y'); ?></span>
                                    <?php if (!empty($ticket['due_date'])): ?>
                                        <?php
                                        $due_ts = strtotime($ticket['due_date']);
                                        $is_overdue = is_due_date_overdue($ticket['due_date'] ?? null, !empty($ticket['is_closed']));
                                        ?>
                                        <span
                                            class="<?php echo $is_overdue ? 'text-red-600 font-medium' : 'text-theme-muted'; ?> text-xs"
                                            title="<?php echo e(t('Due date')); ?>">
                                            <?php echo get_icon('calendar-alt', 'ml-1 mr-0.5 w-3 h-3 inline'); ?>
                                            <?php echo date('d.m.', $due_ts); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="<?php echo e(ticket_registry_priority_badge_class($priority_name)); ?>" data-ticket-field="priority">
                                        <?php echo e($priority_name); ?>
                                    </span>
                                    <?php if (is_admin() && !empty($ticket['organization_name'])): ?>
                                        <span class="text-xs text-theme-muted" data-ticket-field="client"><?php echo e($ticket['organization_name']); ?></span>
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
            <label class="ticket-column-toggle"><input id="ticket-extra-columns" type="checkbox"> <?php echo e(t('Details')); ?></label>
            <div class="ticket-filter-toolbar">
                <div class="ticket-search-wrap">
                                        <input type="text" name="search" value="<?php echo e($search_query); ?>"
                                            placeholder="<?php echo e(t('Search...')); ?>"
                                            class="ticket-search-input"
                                            id="ticket-search-input"
                                            aria-label="<?php echo e(t('Search')); ?>"
                                            autocomplete="off">
                                        <span class="search-icon"><?php echo get_icon('search', 'w-3 h-3'); ?></span>
                                        <div class="ticket-search-suggestions" id="ticket-search-suggestions"></div>
                                    </div>
                <select name="status" aria-label="<?php echo e(t('Status')); ?>" class="filter-select" onchange="this.form.submit()">
                                <option value=""><?php echo e(t('Status')); ?></option>
                                <?php foreach ($filter_statuses as $status): ?>
                                    <option value="<?php echo $status['id']; ?>" <?php echo $status_id == $status['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($status_display_name($status)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                <select name="priority" aria-label="<?php echo e(t('Priority')); ?>" class="filter-select" onchange="this.form.submit()">
                                <option value=""><?php echo e(t('Priority')); ?></option>
                                <?php foreach ($priorities as $priority): ?>
                                    <option value="<?php echo $priority['id']; ?>" <?php echo $priority_id == $priority['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($priority['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                <select name="due_date" aria-label="<?php echo e(t('Due date')); ?>" class="filter-select" onchange="this.form.submit()">
                                <option value=""><?php echo e(t('Due')); ?></option>
                                <option value="overdue" <?php echo $due_date_filter === 'overdue' ? 'selected' : ''; ?>>!</option>
                                <option value="today" <?php echo $due_date_filter === 'today' ? 'selected' : ''; ?>><?php echo e(t('Today')); ?></option>
                                <option value="week" <?php echo $due_date_filter === 'week' ? 'selected' : ''; ?>><?php echo e(t('Week')); ?></option>
                            </select>
                <?php if (is_admin() && !empty($organizations)): ?>
                    <select name="organization" aria-label="<?php echo e(t('Company')); ?>" class="filter-select" onchange="this.form.submit()">
                                    <option value=""><?php echo e(t('Company')); ?></option>
                                    <?php foreach ($organizations as $org): ?>
                                        <option value="<?php echo $org['id']; ?>" <?php echo $organization_id == $org['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($org['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                <?php endif; ?>
                <?php if (is_admin() || is_agent()): ?>
                    <select name="user" aria-label="<?php echo e(t('User')); ?>" class="filter-select" onchange="this.form.submit()">
                                    <option value=""><?php echo e(t('User...')); ?></option>
                                    <?php foreach ($filter_users as $fu): ?>
                                        <option value="<?php echo e($fu['first_name'] . ' ' . $fu['last_name']); ?>"
                                            <?php echo $user_search === ($fu['first_name'] . ' ' . $fu['last_name']) ? 'selected' : ''; ?>>
                                            <?php echo e($fu['first_name'] . ' ' . substr($fu['last_name'] ?? '', 0, 1) . '.'); ?>
                                            <?php if ($fu['role'] !== 'user'): ?>(<?php echo e($fu['role']); ?>)<?php endif; ?>
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
                                    <input type="checkbox" id="select-all" class="fd-rounded-control hidden" onchange="toggleAll(this)"
                                        aria-label="<?php echo e(t('Select all')); ?>">
                                <?php endif; ?>
                                <a href="<?php echo e($date_sort_url); ?>"
                                   class="ticket-date-sort <?php echo $date_sort_is_active ? 'is-active' : ''; ?>"
                                   title="<?php echo e($date_sort_title); ?>"
                                   aria-label="<?php echo e($date_sort_title); ?>">
                                    <span><?php echo e(t('ID')); ?></span>
                                    <?php echo get_icon($date_sort_icon, 'w-3 h-3'); ?>
                                </a>
                            </div>
                        </th>
                        <th scope="col" class="tickets-col-subject"><?php echo e(t('Subject')); ?></th>
                        <?php if (is_admin()): ?>
                            <th scope="col" class="tickets-col-company"><?php echo e(t('Client')); ?></th>
                        <?php endif; ?>
                        <th scope="col" class="tickets-col-status"><?php echo e(t('Status')); ?></th>
                        <th scope="col" class="tickets-col-updated"><?php echo e(t('Last updated')); ?></th>
                        <th scope="col" class="tickets-col-priority ticket-extra-column"><?php echo e(t('Priority')); ?></th>
                        <th scope="col" class="tickets-col-due ticket-extra-column" title="<?php echo e(t('Due date')); ?>"><?php echo e(t('Due')); ?></th>
                        <?php if (is_admin() || is_agent()): ?>
                            <th scope="col" class="tickets-col-user ticket-extra-column"><?php echo e(t('workflow.assigned')); ?></th>
                            <th scope="col" class="tickets-col-time"><?php echo e(t('Time')); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ((is_agent() || is_admin()) && !$is_archive): ?>
                    <tr id="new-ticket-row" class="new-ticket-row ticket-status-accent ticket-status-accent--archived hidden">
                        <td class="px-3 py-1.5 whitespace-nowrap align-middle text-center">
                            <button type="button" id="new-ticket-submit-btn"
                                    class="nt-plus-btn"
                                    title="<?php echo e(t('Add ticket')); ?>"
                                    aria-label="<?php echo e(t('Add ticket')); ?>">
                                <?php echo get_icon('plus', 'w-4 h-4'); ?>
                            </button>
                        </td>
                        <td class="px-3 py-1.5 align-middle">
                            <input type="text" id="new-ticket-subject"
                                   class="nt-input w-full"
                                   placeholder="<?php echo e(t('New ticket subject — press Enter')); ?>"
                                   aria-label="<?php echo e(t('Subject')); ?>"
                                   maxlength="500">
                            <?php if (!empty($ticket_types_list)): ?>
                            <select id="new-ticket-type" aria-label="<?php echo e(t('Ticket type')); ?>" class="nt-input nt-input-sm mt-1 w-full ticket-new-type-select">
                                <option value=""><?php echo e(t('Type')); ?></option>
                                <?php foreach ($ticket_types_list as $tt): ?>
                                    <option value="<?php echo e($tt['slug']); ?>"><?php echo e($tt['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </td>
                                                <?php if (is_admin()): ?>
                            <td class="px-2 py-1.5 align-middle">
                                <?php if (!empty($organizations)): ?>
                                <select id="new-ticket-company" aria-label="<?php echo e(t('Company')); ?>" class="nt-input w-full">
                                    <option value=""><?php echo e(t('Company')); ?></option>
                                    <?php foreach ($organizations as $org): ?>
                                        <option value="<?php echo (int)$org['id']; ?>"><?php echo e($org['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
<td class="px-2 py-1.5 align-middle">
                            <select id="new-ticket-status" aria-label="<?php echo e(t('Status')); ?>" class="nt-input w-full">
                                <?php foreach ($workflow_statuses as $st): ?>
                                    <option value="<?php echo (int)$st['id']; ?>"><?php echo e($status_display_name($st)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="ticket-cell-updated">—</td>
                        <td class="ticket-extra-column px-2 py-1.5 align-middle">
                            <select id="new-ticket-priority" aria-label="<?php echo e(t('Priority')); ?>" class="nt-input w-full">
                                <option value=""><?php echo e(t('Priority')); ?></option>
                                <?php foreach ($priorities as $pr): ?>
                                    <option value="<?php echo (int)$pr['id']; ?>"><?php echo e($pr['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="ticket-extra-column px-2 py-1.5 align-middle">
                            <input type="date" id="new-ticket-due" aria-label="<?php echo e(t('Due date')); ?>" class="nt-input w-full">
                        </td>

                            </td>
                        <?php endif; ?>
                        <td class="ticket-extra-column px-2 py-1.5 align-middle">
                            <select id="new-ticket-assignee" aria-label="<?php echo e(t('Assign to')); ?>" class="nt-input w-full">
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
                                   aria-label="<?php echo e(t('Log time (minutes)')); ?>"
                                   title="<?php echo e(t('Log time (minutes)')); ?>">
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($ticket_groups as $group): ?>
                    <?php if ($group['name'] === 'closed'): ?>
                        </tbody>
                        <tbody class="ticket-closed-group">
                            <tr class="bg-theme-secondary">
                                <?php $colspan = is_admin() ? 9 : (is_agent() ? 8 : 6); ?>
                                <td colspan="<?php echo $colspan; ?>" class="p-0">
                                    <button type="button"
                                        class="ticket-closed-toggle ticket-closed-toggle--desktop px-3 py-2 text-xs font-medium"
                                        aria-controls="closed-tickets-desktop"
                                        aria-expanded="false"
                                        onclick="var target = document.getElementById('closed-tickets-desktop'); var collapsed = target.classList.toggle('hidden'); this.setAttribute('aria-expanded', collapsed ? 'false' : 'true');">
                                        <?php echo e($group['label']); ?>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                        <tbody id="closed-tickets-desktop" class="hidden">
                    <?php elseif (!empty($group['label'])): ?>
                        <tr class="ticket-list-section-row">
                            <?php $colspan = is_admin() ? 8 : (is_agent() ? 6 : 5); ?>
                            <td colspan="<?php echo $colspan; ?>">
                                <span><?php echo e($group['label']); ?></span>
                                <strong><?php echo e((string) count($group['tickets'])); ?></strong>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($group['tickets'] as $ticket):
                        $priority_name = $ticket['priority_name'] ?? get_priority_label($ticket['priority_id'] ?? $ticket['priority'] ?? 'medium');
                        $priority_color = $ticket['priority_color'] ?? get_priority_color($ticket['priority_id'] ?? $ticket['priority'] ?? 'medium');
                        $is_overdue = is_due_date_overdue($ticket['due_date'] ?? null, !empty($ticket['is_closed']));
                        ?>
                        <tr class="ticket-row <?php echo e(ticket_registry_status_accent_class($ticket, $statuses)); ?><?php echo $is_overdue ? ' ticket-overdue' : ''; ?>"
                            data-href="<?php echo e(ticket_url($ticket)); ?>"
                            data-ticket-contract-row
                            data-ticket-id="<?php echo (int) $ticket['id']; ?>">
                            <td class="px-3 py-2.5 whitespace-nowrap align-top">
                                <div class="flex items-center gap-1.5">
                                    <?php if ($bulk_actions_enabled): ?>
                                        <input type="checkbox" name="ticket_ids[]" value="<?php echo (int) $ticket['id']; ?>"
                                            class="bulk-checkbox hidden fd-rounded-control flex-shrink-0" form="bulk-actions-form"
                                            aria-label="<?php echo e(t('Select') . ': ' . $ticket['title']); ?>">
                                    <?php endif; ?>
                                    <div>
                                        <a href="<?php echo ticket_url($ticket); ?>" class="ticket-row-date-link" data-ticket-field="code" title="<?php echo e(get_ticket_code($ticket['id'])); ?>">
                                            <?php echo e(get_ticket_code($ticket['id'])); ?>
                                        </a>

                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 align-top">
                                <div class="flex items-center gap-1.5">
                                    <a href="<?php echo ticket_url($ticket); ?>" class="ticket-subject-link" data-ticket-field="title" title="<?php echo e($ticket['title']); ?>">
                                        <bdi dir="auto"><?php echo e($ticket['title']); ?></bdi>
                                    </a>
                                    <?php if (is_agent() || is_admin()): ?>
                                    <button type="button"
                                            class="ticket-subject-edit-button tl-inline-edit"
                                            data-ticket="<?php echo (int)$ticket['id']; ?>"
                                            data-field="subject"
                                            data-value="<?php echo e($ticket['title']); ?>"
                                            aria-label="<?php echo e(t('Edit')); ?>: <?php echo e($ticket['title']); ?>"
                                            title="<?php echo e(t('Edit')); ?>">
                                        <?php echo get_icon('edit', 'w-3 h-3'); ?>
                                    </button>
                                    <?php endif; ?>
                                    <?php if (!empty($ticket['attachment_count']) && $ticket['attachment_count'] > 0): ?>
                                        <span class="flex-shrink-0 text-theme-muted" title="<?php echo e(t('Attachments')); ?>: <?php echo $ticket['attachment_count']; ?>">
                                            <?php echo get_icon('paperclip', 'w-3 h-3'); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="ticket-secondary-meta text-theme-muted">
                                    <?php if ((is_agent() || is_admin()) && !empty($ticket_types_list)): ?>
                                        <span class="tl-inline-edit tl-inline-anchor">
                                            <span class="tl-edit-trigger tl-type-trigger"
                                                  data-ticket="<?php echo (int)$ticket['id']; ?>"
                                                  data-field="type">
                                                <?php echo e(get_type_label($ticket['type'])); ?>
                                            </span>
                                            <span class="tl-dropdown hidden" data-dropdown="type-<?php echo (int)$ticket['id']; ?>">
                                                <?php foreach ($ticket_types_list as $tt): ?>
                                                    <button type="button" class="tl-dropdown-item"
                                                        onclick="inlineUpdateType(<?php echo (int)$ticket['id']; ?>, <?php echo e(json_encode((string) $tt['slug'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>, <?php echo e(json_encode((string) $tt['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>, this)">
                                                        <?php echo e($tt['name']); ?>
                                                    </button>
                                                <?php endforeach; ?>
                                            </span>
                                        </span>
                                    <?php else: ?>
                                        <?php echo e(get_type_label($ticket['type'])); ?>
                                    <?php endif; ?>
                                    <?php if (!is_admin() && !empty($ticket['organization_name'])): ?>
                                        <span class="ml-1" data-ticket-field="client"><?php echo e($ticket['organization_name']); ?></span>
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
                                                        <?php if (is_admin()): ?>
                                <td class="px-2 py-2.5 text-xs truncate align-top ticket-cell-muted ticket-cell-company" title="<?php echo e($ticket['organization_name'] ?? ''); ?>">
                                    <span class="tl-inline-edit tl-inline-anchor">
                                        <span class="tl-edit-trigger tl-company-trigger"
                                              data-ticket="<?php echo (int)$ticket['id']; ?>"
                                              data-field="company"
                                              data-ticket-field="client"
                                              title="<?php echo e(t('Click to change')); ?>">
                                            <?php if (!empty($ticket['organization_name'])): ?>
                                                <?php echo e($ticket['organization_name']); ?>
                                            <?php else: ?>
                                                <span class="ticket-empty-value">—</span>
                                            <?php endif; ?>
<td class="px-2 py-2.5 whitespace-nowrap align-top">
                                <?php if (is_agent() || is_admin()): ?>
                                <div class="tl-inline-edit tl-inline-anchor">
                                    <span class="<?php echo e(ticket_registry_status_badge_class($ticket, $statuses)); ?> tl-edit-trigger" data-ticket="<?php echo (int)$ticket['id']; ?>" data-field="status" data-ticket-field="status"
                                        title="<?php echo e(t('Click to change')); ?>">
                                        <?php echo e($ticket_status_display_name($ticket)); ?>
                                    </span>
                                    <div class="tl-dropdown hidden" data-dropdown="status-<?php echo (int)$ticket['id']; ?>">
                                        <?php foreach ($workflow_statuses as $st): ?>
                                        <?php $status_group = ticket_registry_status_group_from_status($st); ?>
                                        <button type="button" class="tl-dropdown-item ticket-status-option ticket-status-option--<?php echo e($status_group); ?>"
                                            data-tone-class="ticket-status-inline--<?php echo e($status_group); ?>"
                                            data-row-accent-class="ticket-status-accent--<?php echo e($status_group); ?>"
                                            onclick="inlineUpdate(<?php echo (int)$ticket['id']; ?>, 'status', <?php echo (int)$st['id']; ?>, this)">
                                            <?php echo function_exists('ticket_status_color_dot_svg')
                                                ? ticket_status_color_dot_svg($st, 'ticket-status-color-dot ticket-status-color-dot--spaced')
                                                : '<span class="' . e(ticket_registry_status_dot_class($status_group)) . ' mr-1.5"></span>'; ?>
                                            <?php echo e($status_display_name($st)); ?>
                                        </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <span class="<?php echo e(ticket_registry_status_badge_class($ticket, $statuses)); ?>" data-ticket-field="status">
                                    <?php echo e($ticket_status_display_name($ticket)); ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="ticket-cell-updated" data-ticket-field="updated" title="<?php echo e(format_date($ticket['updated_at'] ?? $ticket['created_at'])); ?>">
                                <?php echo e(format_date($ticket['updated_at'] ?? $ticket['created_at'], 'd.m. H:i')); ?>
                            </td>
                            <td class="ticket-extra-column px-2 py-2.5 whitespace-nowrap align-top">
                                <?php if (is_agent() || is_admin()): ?>
                                <div class="tl-inline-edit tl-inline-anchor">
                                    <span class="<?php echo e(ticket_registry_priority_badge_class($priority_name)); ?> tl-edit-trigger" data-ticket="<?php echo (int)$ticket['id']; ?>" data-field="priority" data-ticket-field="priority"
                                        title="<?php echo e(t('Click to change')); ?>">
                                        <?php echo e($priority_name); ?>
                                    </span>
                                    <div class="tl-dropdown hidden" data-dropdown="priority-<?php echo (int)$ticket['id']; ?>">
                                        <?php foreach ($priorities as $pr): ?>
                                        <?php $priority_option_key = ticket_registry_priority_key($pr['name']); ?>
                                        <button type="button" class="tl-dropdown-item ticket-priority-option ticket-priority-option--<?php echo e($priority_option_key); ?>"
                                            data-tone-class="ticket-priority-inline--<?php echo e($priority_option_key); ?>"
                                            onclick="inlineUpdate(<?php echo (int)$ticket['id']; ?>, 'priority', <?php echo (int)$pr['id']; ?>, this)">
                                            <span class="ticket-priority-dot ticket-priority-dot--<?php echo e($priority_option_key); ?> w-2 h-2 fd-rounded-pill inline-block mr-1.5"></span>
                                            <?php echo e($pr['name']); ?>
                                        </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <span class="<?php echo e(ticket_registry_priority_badge_class($priority_name)); ?>" data-ticket-field="priority">
                                    <?php echo e($priority_name); ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="ticket-extra-column px-2 py-2.5 whitespace-nowrap align-top text-xs text-theme-muted">
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
                                          title="<?php echo e(t('Click to change')); ?>">
                                        <?php if ($_due_ts): ?>
                                            <?php echo date('d.m', $_due_ts); ?>
                                            <?php if ($_is_overdue): ?>
                                                <?php echo get_icon('exclamation-circle', 'w-2.5 h-2.5 inline ml-0.5'); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="ticket-empty-value">—</span>
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

                                        </span>
                                        <span class="tl-dropdown hidden" data-dropdown="company-<?php echo (int)$ticket['id']; ?>">
                                            <button type="button" class="tl-dropdown-item"
                                                onclick="inlineUpdateCompany(<?php echo (int)$ticket['id']; ?>, '', <?php echo e(json_encode(t('No company'))); ?>, this)">
                                                <span class="ticket-muted-value"><?php echo e(t('No company')); ?></span>
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
                                <td class="ticket-extra-column px-2 py-2.5 text-xs truncate align-top ticket-cell-muted ticket-cell-user" title="<?php echo e($ticket['first_name'] . ' ' . $ticket['last_name']); ?>">
                                    <span class="tl-inline-edit tl-inline-anchor">
                                        <span class="tl-edit-trigger tl-assign-trigger"
                                              data-ticket="<?php echo (int)$ticket['id']; ?>"
                                              data-field="assign"
                                              data-ticket-field="assignee"
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
                                                <span class="ticket-empty-value"><?php echo e(t('Unassigned')); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="tl-dropdown hidden" data-dropdown="assign-<?php echo (int)$ticket['id']; ?>">
                                            <button type="button" class="tl-dropdown-item"
                                                onclick="inlineUpdateAssign(<?php echo (int)$ticket['id']; ?>, '', <?php echo e(json_encode(t('Unassigned'))); ?>, this)">
                                                <span class="ticket-muted-value"><?php echo e(t('Unassigned')); ?></span>
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
                                <td class="ticket-extra-column px-2 py-2.5 text-xs truncate align-top ticket-cell-muted ticket-cell-user" title="<?php echo e($ticket['first_name'] . ' ' . $ticket['last_name']); ?>">
                                    <span class="tl-inline-edit tl-inline-anchor">
                                        <span class="tl-edit-trigger tl-assign-trigger"
                                              data-ticket="<?php echo (int)$ticket['id']; ?>"
                                              data-field="assign"
                                              data-ticket-field="assignee"
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
                                                <span class="ticket-empty-value"><?php echo e(t('Unassigned')); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="tl-dropdown hidden" data-dropdown="assign-<?php echo (int)$ticket['id']; ?>">
                                            <button type="button" class="tl-dropdown-item"
                                                onclick="inlineUpdateAssign(<?php echo (int)$ticket['id']; ?>, '', <?php echo e(json_encode(t('Unassigned'))); ?>, this)">
                                                <span class="ticket-muted-value"><?php echo e(t('Unassigned')); ?></span>
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
                    class="ticket-bulk-actions hidden <?php echo $bulk_delete_mode ? 'ticket-bulk-actions--danger' : ''; ?>">
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
                                <?php foreach ($workflow_statuses as $status): ?>
                                    <option value="<?php echo (int) $status['id']; ?>"><?php echo e($status_display_name($status)); ?></option>
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
