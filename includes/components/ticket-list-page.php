<?php
/**
 * Ticket-list registry view.
 *
 * All data is prepared by ticket-list-page-controller.php.
 */
$is_closed_filter_active = ticket_registry_closed_filter_active($statuses, $status_id);
$show_closed_tickets_inline = ticket_list_view_shows_closed_inline($ticket_list_view, $is_closed_filter_active);
$ticket_registry_closed_mode_class = 'ticket-registry-page--closed-inline';
if (!$show_closed_tickets_inline) {
    $ticket_registry_closed_mode_class = 'ticket-registry-page--closed-collapsible';
}
$ticket_registry_model = ticket_registry_split_model($statuses, $tickets, $status_id, $ticket_list_view);
extract($ticket_registry_model, EXTR_SKIP);
$ticket_kanban_model = ticket_registry_kanban_model($statuses, $tickets, $statuses_by_id, $board_active_statuses, $board_closed_statuses, $show_closed_tickets_inline);
$kanban_hide_closed_after_days = $ticket_kanban_model['hide_closed_after_days'];
$kanban_main_tickets_by_status = $ticket_kanban_model['main_tickets_by_status'];
$kanban_archived_closed_tickets_by_status = $ticket_kanban_model['archived_closed_tickets_by_status'];
$kanban_archived_closed_count = $ticket_kanban_model['archived_closed_count'];
$kanban_main_statuses = $ticket_kanban_model['main_statuses'];
$kanban_archived_closed_statuses = $ticket_kanban_model['archived_closed_statuses'];
?>
<div class="workflow-surface workflow-surface--registry ticket-registry-page <?php echo e($ticket_registry_closed_mode_class); ?>"
     data-core-workflow-surface="tickets"
     data-ticket-registry-surface
     data-app-contract-surface="tickets"
     data-app-contract-action="app-ticket-list"
     data-app-contract-limit="<?php echo max(25, min(100, (int) $total_tickets)); ?>"
     data-ticket-contract-mode="refresh">
    <div class="ticket-registry-toolbar">
        <?php
        ticket_registry_render_view_tabs(
            $ticket_list_view_definitions,
            $ticket_list_view_counts,
            $ticket_list_view,
            $ticket_list_include_archive,
            $_GET
        );
        ticket_registry_render_filter_summary($total_tickets, $filter_notes, $ticket_clear_url, $has_filters);
        ?>
    </div>
<?php if (is_agent() && !$is_archive): ?>
    <nav class="workflow-assignment-views" aria-label="<?php echo e(t('workflow.assigned')); ?>">
        <?php
        $assignment_query = $_GET;
        unset($assignment_query['assigned_to'], $assignment_query['assignment'], $assignment_query['p']);
        foreach ([['workflow.mine', ['assigned_to' => (int) $user['id']]], ['workflow.unassigned', ['assignment' => 'unassigned']]] as [$assignment_label, $assignment_filter]):
            $assignment_active = isset($assignment_filter['assigned_to']) ? (int) ($assigned_to ?? 0) === (int) $user['id'] : ($_GET['assignment'] ?? '') === 'unassigned';
            $assignment_url = 'index.php?' . http_build_query(array_merge($assignment_query, $assignment_active ? [] : $assignment_filter));
        ?>
        <a class="btn btn-sm <?php echo $assignment_active ? 'btn-primary' : 'btn-secondary'; ?>" href="<?php echo e($assignment_url); ?>" <?php if ($assignment_active) echo 'aria-current="page"'; ?>><?php echo e(t($assignment_label)); ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
<div class="card ticket-registry-card overflow-hidden <?php echo $ticket_view === 'board' ? 'kanban-board-wrapper' : ''; ?>">
    <?php if (empty($tickets)): ?>
        <?php
        // Check if filters are active to show "Show all" button
        $empty_has_filters = !empty($search_query) || !empty($status_id) || !empty($priority_id) ||
                       !empty($organization_id) || !empty($due_date_filter) || !empty($created_date_value) ||
                       !empty($user_search) || !empty($assigned_to) || ($tags_supported && !empty($tag_filters));
        $empty_title = $is_archive ? t('Archive is empty') : t('No tickets found');
        $empty_message = $is_archive ? t('There are no archived tickets yet.') : t('Try adjusting filters or create a new ticket.');
        $empty_icon = $is_archive ? 'archive' : 'inbox';
        $empty_action_label = $is_archive ? null : t('Create ticket');
        $empty_action_url = $is_archive ? null : url('new-ticket');
        include BASE_PATH . '/includes/components/empty-state.php';
        ?>
        <?php if ($empty_has_filters): ?>
            <div class="text-center mt-4">
                                <a href="<?php echo e($ticket_show_all_url); ?>"
                                   class="btn btn-outline btn-sm inline-flex items-center gap-1.5">
                    <?php echo get_icon('list', 'w-4 h-4'); ?>
                    <?php echo e(t('Show all tickets')); ?>
                </a>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($ticket_view === 'board'): ?>
            <?php include BASE_PATH . '/includes/components/ticket-list-board.php'; ?>
        <?php else: ?>
            <?php include BASE_PATH . '/includes/components/ticket-list-table.php'; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php if ($ticket_view === 'list'): ?>
<nav class="registry-pagination" aria-label="<?php echo e(t('Tickets')); ?>">
    <form method="get" class="registry-pagination__size">
        <?php foreach ($_GET as $key => $value): ?>
            <?php if (!in_array($key, ['page', 'p', 'page_size'], true) && is_scalar($value)): ?><input type="hidden" name="<?php echo e($key); ?>" value="<?php echo e((string) $value); ?>"><?php endif; ?>
        <?php endforeach; ?>
        <input type="hidden" name="page" value="tickets">
        <label for="ticket-page-size"><?php echo e(t('Show')); ?></label>
        <select id="ticket-page-size" name="page_size" data-soft-select onchange="this.form.submit()">
            <?php foreach (['10' => '10', '20' => '20', '50' => '50', 'all' => t('All')] as $value => $label): ?><option value="<?php echo e((string) $value); ?>" <?php echo (string) $value === $ticket_page_size ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-secondary btn-sm" type="submit"><?php echo e(t('Apply')); ?></button></noscript>
    </form>
    <span><?php echo $ticket_page_number; ?> / <?php echo $ticket_page_count; ?> · <?php echo (int) $total_tickets; ?> <?php echo e(t('Tickets')); ?></span>
    <?php foreach ([-1 => t('Previous'), 1 => t('Next')] as $step => $label): ?>
        <?php $list_page_params = $_GET; unset($list_page_params['page']); $list_page_params['p'] = $ticket_page_number + $step; $list_page_params['page_size'] = $ticket_page_size; ?>
        <?php if ($list_page_params['p'] >= 1 && $list_page_params['p'] <= $ticket_page_count): ?><a class="btn btn-secondary btn-sm" href="<?php echo e(url('tickets', $list_page_params)); ?>"><?php echo e($label); ?></a><?php else: ?><button type="button" class="btn btn-secondary btn-sm" disabled><?php echo e($label); ?></button><?php endif; ?>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
</div>
