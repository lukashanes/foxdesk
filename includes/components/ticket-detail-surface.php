<?php
/**
 * Ticket detail presentation helpers.
 *
 * The page owns workflow data; this component owns stable classes for the
 * primary work surface so page files do not rebuild UI chrome by hand.
 */

function ticket_detail_status_group(array $ticket, array $statuses = []): string
{
    $status_id = isset($ticket['status_id']) ? (int) $ticket['status_id'] : 0;

    foreach ($statuses as $status) {
        if ((int) ($status['id'] ?? 0) === $status_id) {
            return function_exists('ticket_status_group_from_status')
                ? ticket_status_group_from_status($status)
                : (!empty($status['is_closed']) ? 'done' : 'active');
        }
    }

    if (function_exists('ticket_status_group_for_status_id')) {
        return ticket_status_group_for_status_id($status_id);
    }

    return !empty($ticket['is_closed']) ? 'done' : 'active';
}

function ticket_detail_status_pill_class(array $ticket, array $statuses = []): string
{
    $group = ticket_detail_status_group($ticket, $statuses);
    if (function_exists('ticket_status_group_normalize')) {
        $group = ticket_status_group_normalize($group);
    }

    $allowed = ['new', 'active', 'waiting', 'done', 'archived'];
    if (!in_array($group, $allowed, true)) {
        $group = 'active';
    }

    return 'ticket-status-pill ticket-status-pill--' . $group;
}

function ticket_detail_primary_action_class(array $action): string
{
    $style = (string) ($action['style'] ?? 'secondary');
    $allowed = ['primary', 'secondary', 'success', 'warning', 'ghost'];
    if (!in_array($style, $allowed, true)) {
        $style = 'secondary';
    }

    return 'ticket-primary-action ticket-primary-action--' . $style;
}

function ticket_detail_render_status_pill(array $ticket, array $statuses = []): void
{
    $group = ticket_detail_status_group($ticket, $statuses);
    $label = (string) ($ticket['status_name'] ?? 'Status');
    ?>
    <span class="<?php echo e(ticket_detail_status_pill_class($ticket, $statuses)); ?>"
          data-ticket-status-group="<?php echo e($group); ?>">
        <?php echo e(t($label)); ?>
    </span>
    <?php
}
