<?php
/**
 * Ticket registry presentation helpers.
 *
 * Keep the ticket list chrome small and reusable. Ticket query rules stay in
 * the ticket modules; this file only renders view navigation and filter state.
 */

function ticket_registry_render_view_tabs(array $definitions, array $counts, string $active_view, bool $include_archive, array $request): void
{
    ?>
    <nav class="ticket-view-tabs" aria-label="<?php echo e(t('Ticket views')); ?>">
        <?php $tab_keys = ticket_list_view_tab_keys($active_view, $include_archive); ?>
        <?php foreach ($definitions as $view_key => $view_definition): ?>
            <?php if (!in_array((string) $view_key, $tab_keys, true)) { continue; } ?>
            <?php
            $view_url = ticket_list_view_url((string) $view_key, $request, $include_archive);
            $is_active_view = $active_view === (string) $view_key;
            $view_count = (int) ($counts[$view_key] ?? 0);
            ?>
            <a href="<?php echo e($view_url); ?>"
               class="ticket-view-tab <?php echo $is_active_view ? 'is-active' : ''; ?>"
               data-ticket-view-key="<?php echo e((string) $view_key); ?>"
               aria-current="<?php echo $is_active_view ? 'page' : 'false'; ?>"
               title="<?php echo e(t((string) ($view_definition['description'] ?? ''))); ?>">
                <span><?php echo e(t((string) ($view_definition['label'] ?? ucfirst((string) $view_key)))); ?></span>
                <span class="ticket-view-tab__count" data-ticket-view-count><?php echo e((string) $view_count); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php
}

function ticket_registry_render_filter_summary(int $total_tickets, array $filter_notes, string $clear_url, bool $has_filters): void
{
    if (empty($filter_notes) && !$has_filters) {
        return;
    }
    ?>
    <div class="ticket-filter-summary">
        <?php if (!empty($filter_notes)): ?>
            <div class="ticket-filter-summary__chips" aria-label="<?php echo e(t('Active filters')); ?>">
                <?php foreach ($filter_notes as $note): ?>
                    <span class="ticket-filter-chip"><?php echo e((string) $note); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($has_filters): ?>
            <a href="<?php echo e($clear_url); ?>" class="ticket-filter-summary__clear">
                <?php echo get_icon('x', 'w-3.5 h-3.5'); ?>
                <?php echo e(t('Clear')); ?>
            </a>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Column-header sort link. Clicking an inactive header applies $desc_key;
 * clicking an active header toggles between $desc_key and $asc_key when an
 * ascending variant exists. $aliases maps other sort keys onto a direction so
 * legacy keys (newest/oldest) still light up the matching header.
 */
function ticket_registry_sort_header(string $label, string $desc_key, ?string $asc_key, string $current_sort, array $request, bool $is_archive, array $aliases = [], ?string $href = null): void
{
    $direction = null;
    if ($current_sort === $desc_key) {
        $direction = 'desc';
    } elseif ($asc_key !== null && $current_sort === $asc_key) {
        $direction = 'asc';
    } elseif (isset($aliases[$current_sort])) {
        $direction = $aliases[$current_sort] === 'asc' ? 'asc' : 'desc';
    }
    $is_active = $direction !== null;
    $next_sort = $desc_key;
    if ($is_active && $asc_key !== null && $direction === 'desc') {
        $next_sort = $asc_key;
    }
    $params = $request;
    unset($params['page'], $params['p']);
    $params['sort'] = $next_sort;
    if ($is_archive) {
        $params['archived'] = '1';
    } else {
        unset($params['archived']);
    }
    $icon = $is_active ? ($direction === 'asc' ? 'arrow-up' : 'arrow-down') : 'arrow-up-down';
    $title = t('tickets.sort.by', ['column' => $label]);
    ?>
    <a href="<?php echo e($href ?? url('tickets', $params)); ?>"
       class="ticket-date-sort ticket-sort-link <?php echo $is_active ? 'is-active' : ''; ?>"
       data-ticket-sort="<?php echo e($next_sort); ?>"
       title="<?php echo e($title); ?>"
       aria-label="<?php echo e($title); ?>">
        <span><?php echo e($label); ?></span>
        <?php echo get_icon($icon, 'w-3 h-3'); ?>
    </a>
    <?php
}

/**
 * Count the filters a staff member has applied on top of the current view.
 */
function ticket_registry_active_filter_count(array $state): int
{
    $count = 0;
    foreach (['status_id', 'priority_id', 'organization_id', 'due_date_filter', 'user_search', 'search_query', 'created_date_value', 'assigned_to'] as $key) {
        if (!empty($state[$key])) {
            $count++;
        }
    }
    if (!empty($state['unassigned'])) {
        $count++;
    }
    if (!empty($state['staff_scope'])) {
        $count++;
    }
    if (!empty($state['tags_supported']) && !empty($state['tag_filters'])) {
        $count++;
    }
    return $count;
}

/**
 * One "Filter" button in the page header that opens a popover with the list
 * filters. <details>/<summary> keeps it usable without JavaScript; the form
 * inside submits like the former inline toolbar did.
 */
function ticket_registry_render_filter_menu(array $m): string
{
    $request = (array) ($m['request'] ?? []);
    $is_archive = !empty($m['is_archive']);
    $active_count = (int) ($m['active_count'] ?? 0);
    $status_display_name = $m['status_display_name'] ?? static fn (array $status): string => (string) ($status['name'] ?? '');
    $assignment_query = $request;
    unset($assignment_query['assigned_to'], $assignment_query['assignment'], $assignment_query['p'], $assignment_query['page']);
    $mine_active = !empty($m['assigned_to']) && (int) $m['assigned_to'] === (int) ($m['current_user_id'] ?? 0);
    $unassigned_active = ($request['assignment'] ?? '') === 'unassigned';
    $hidden = [
        'page' => 'tickets',
        'search_scope' => 'all',
    ];
    if (!$is_archive && ($m['ticket_list_view'] ?? 'open') !== 'open') {
        $hidden['work_view'] = (string) $m['ticket_list_view'];
    }
    if ($is_archive) {
        $hidden['archived'] = '1';
    }
    $sort_options = (array) ($m['sort_options'] ?? []);
    foreach (['search' => 'search_query', 'sort' => 'sort', 'created_date' => 'created_date_value', 'tags' => 'tag_filter_csv', 'assigned_to' => 'assigned_to'] as $name => $key) {
        if (!empty($m[$key])) {
            $hidden[$name] = (string) $m[$key];
        }
    }
    if ($unassigned_active) {
        $hidden['assignment'] = 'unassigned';
    }
    if (!empty($m['staff_scope'])) {
        $hidden['scope'] = 'staff';
    }
    ob_start();
    ?>
    <details class="ticket-filter-menu" data-ticket-filter-menu>
        <summary class="ticket-filter-menu__toggle" title="<?php echo e(t('workflow.filters')); ?>">
            <?php echo get_icon('bars', 'w-3.5 h-3.5'); ?>
            <span><?php echo e(t('tickets.filters.toggle')); ?></span>
            <?php if ($active_count > 0): ?>
                <b class="ticket-filter-menu__count" aria-label="<?php echo e(tn('tickets.filters.active_count', $active_count)); ?>"><?php echo (int) $active_count; ?></b>
            <?php endif; ?>
        </summary>
        <div class="ticket-filter-menu__panel">
            <form method="get" action="index.php" class="ticket-filter-menu__form" id="ticket-filter-menu-form">
                <?php foreach ($hidden as $name => $value): ?>
                    <?php if ($name === 'sort' && !empty($sort_options)) { continue; } ?>
                    <input type="hidden" name="<?php echo e($name); ?>" value="<?php echo e($value); ?>">
                <?php endforeach; ?>
                <select name="status" aria-label="<?php echo e(t('Status')); ?>" class="filter-select" onchange="this.form.submit()">
                    <option value=""><?php echo e(t('Status')); ?></option>
                    <?php foreach ((array) ($m['filter_statuses'] ?? []) as $status): ?>
                        <option value="<?php echo (int) $status['id']; ?>" <?php echo (int) ($m['status_id'] ?? 0) === (int) $status['id'] ? 'selected' : ''; ?>><?php echo e($status_display_name($status)); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="priority" aria-label="<?php echo e(t('Priority')); ?>" class="filter-select" onchange="this.form.submit()">
                    <option value=""><?php echo e(t('Priority')); ?></option>
                    <?php foreach ((array) ($m['priorities'] ?? []) as $priority): ?>
                        <option value="<?php echo (int) $priority['id']; ?>" <?php echo (int) ($m['priority_id'] ?? 0) === (int) $priority['id'] ? 'selected' : ''; ?>><?php echo e($priority['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="due_date" aria-label="<?php echo e(t('Due date')); ?>" class="filter-select" onchange="this.form.submit()">
                    <option value=""><?php echo e(t('Due')); ?></option>
                    <option value="overdue" <?php echo ($m['due_date_filter'] ?? '') === 'overdue' ? 'selected' : ''; ?>>!</option>
                    <option value="today" <?php echo ($m['due_date_filter'] ?? '') === 'today' ? 'selected' : ''; ?>><?php echo e(t('Today')); ?></option>
                    <option value="week" <?php echo ($m['due_date_filter'] ?? '') === 'week' ? 'selected' : ''; ?>><?php echo e(t('Week')); ?></option>
                </select>
                <?php if (!empty($m['show_organizations']) && !empty($m['organizations'])): ?>
                    <select name="organization" aria-label="<?php echo e(t('Company')); ?>" class="filter-select" onchange="this.form.submit()">
                        <option value=""><?php echo e(t('Company')); ?></option>
                        <?php foreach ((array) $m['organizations'] as $org): ?>
                            <option value="<?php echo (int) $org['id']; ?>" <?php echo (int) ($m['organization_id'] ?? 0) === (int) $org['id'] ? 'selected' : ''; ?>><?php echo e($org['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if (!empty($m['show_users'])): ?>
                    <select name="user" aria-label="<?php echo e(t('User')); ?>" class="filter-select" onchange="this.form.submit()">
                        <option value=""><?php echo e(t('User...')); ?></option>
                        <?php foreach ((array) ($m['filter_users'] ?? []) as $fu): ?>
                            <?php $fu_value = $fu['first_name'] . ' ' . $fu['last_name']; ?>
                            <option value="<?php echo e($fu_value); ?>" <?php echo ($m['user_search'] ?? '') === $fu_value ? 'selected' : ''; ?>>
                                <?php echo e($fu['first_name'] . ' ' . substr($fu['last_name'] ?? '', 0, 1) . '.'); ?><?php if (($fu['role'] ?? 'user') !== 'user'): ?> (<?php echo e($fu['role']); ?>)<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if (!empty($sort_options)): ?>
                    <select name="sort" aria-label="<?php echo e(t('Sort')); ?>" class="filter-select" onchange="this.form.submit()">
                        <?php foreach ($sort_options as $sort_value => $sort_label): ?>
                            <option value="<?php echo e((string) $sort_value); ?>" <?php echo (string) ($m['sort'] ?? '') === (string) $sort_value ? 'selected' : ''; ?>><?php echo e((string) $sort_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if (!empty($m['show_assignment'])): ?>
                    <div class="ticket-filter-menu__group" role="group" aria-label="<?php echo e(t('tickets.filters.assignment')); ?>">
                        <span class="ticket-filter-menu__label"><?php echo e(t('tickets.filters.assignment')); ?></span>
                        <?php
                        $assignment_links = [
                            ['workflow.mine', ['assigned_to' => (int) ($m['current_user_id'] ?? 0)], $mine_active],
                            ['workflow.unassigned', ['assignment' => 'unassigned'], $unassigned_active],
                        ];
                        foreach ($assignment_links as [$assignment_label, $assignment_filter, $assignment_active]):
                            $assignment_url = url('tickets', array_merge($assignment_query, $assignment_active ? [] : $assignment_filter));
                        ?>
                            <a class="ticket-filter-menu__chip <?php echo $assignment_active ? 'is-active' : ''; ?>" href="<?php echo e($assignment_url); ?>" <?php if ($assignment_active) echo 'aria-current="page"'; ?>><?php echo e(t($assignment_label)); ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($m['show_columns_toggle'])): ?>
                    <div class="ticket-filter-menu__group">
                        <span class="ticket-filter-menu__label"><?php echo e(t('tickets.filters.columns')); ?></span>
                        <label class="ticket-column-toggle ticket-filter-menu__toggle-row"><input id="ticket-extra-columns" type="checkbox"> <?php echo e(t('Details')); ?></label>
                    </div>
                <?php endif; ?>
                <div class="ticket-filter-menu__footer">
                    <?php if (!empty($m['has_filters'])): ?>
                        <a href="<?php echo e((string) ($m['clear_url'] ?? url('tickets'))); ?>" class="btn btn-secondary btn-sm"><?php echo get_icon('x', 'w-3 h-3'); ?> <?php echo e(t('Clear')); ?></a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary btn-sm"><?php echo e(t('Apply')); ?></button>
                </div>
            </form>
        </div>
    </details>
    <?php
    return (string) ob_get_clean();
}
