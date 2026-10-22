<?php
/**
 * Ticket detail presentation helpers.
 *
 * The ticket detail page owns the workflow data; this component owns stable
 * chrome classes for the primary work surface.
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

function ticket_detail_priority_key(string $priority_name): string
{
    $text = trim($priority_name);
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);

    if (preg_match('/\b(urgent|critical|blocker|highest)\b/u', $text)) {
        return 'urgent';
    }
    if (preg_match('/\b(high|major)\b/u', $text)) {
        return 'high';
    }
    if (preg_match('/\b(low|minor)\b/u', $text)) {
        return 'low';
    }

    return 'medium';
}

function ticket_detail_priority_pill_class(string $priority_name): string
{
    return 'ticket-priority-pill ticket-priority-pill--' . ticket_detail_priority_key($priority_name);
}

function ticket_detail_render_status_pill(array $ticket, array $statuses = []): void
{
    $group = ticket_detail_status_group($ticket, $statuses);
    $label = function_exists('ticket_registry_status_label_from_ticket')
        ? ticket_registry_status_label_from_ticket($ticket, $statuses)
        : (string) ($ticket['status_name'] ?? 'Status');
    ?>
    <span class="<?php echo e(ticket_detail_status_pill_class($ticket, $statuses)); ?>"
          data-ticket-status-group="<?php echo e($group); ?>">
        <?php echo e($label); ?>
    </span>
    <?php
}

/**
 * Status pipeline: every workspace status in order, the current one marked.
 * Read-only; the status form in the sidebar stays the way to change it.
 */
function ticket_detail_render_status_pipeline(array $ticket, array $statuses = []): void
{
    if (count($statuses) < 2) {
        return;
    }
    // One step per status group (new → active → waiting → done), so two closed
    // statuses never show up as two "Done" steps.
    $group_of = static function (array $status): string {
        if (function_exists('ticket_registry_status_group_from_status')) {
            return ticket_registry_status_group_from_status($status);
        }
        return !empty($status['is_closed']) ? 'done' : 'active';
    };
    $current_id = (int) ($ticket['status_id'] ?? 0);
    $current_group = '';
    $steps = [];
    foreach (array_values($statuses) as $status) {
        $group = $group_of($status);
        if ($group === 'archived') {
            continue;
        }
        if ((int) ($status['id'] ?? 0) === $current_id) {
            $current_group = $group;
            $steps[$group] = $status;
        } elseif (!isset($steps[$group])) {
            $steps[$group] = $status;
        }
    }
    $order = function_exists('ticket_status_group_keys') ? ticket_status_group_keys() : ['new', 'active', 'waiting', 'done'];
    $steps = array_filter(array_replace(array_fill_keys($order, null), $steps));
    if (count($steps) < 2) {
        return;
    }
    $reached = $current_group === '';
    ?>
    <ol class="ticket-status-pipeline" aria-label="<?php echo e(t('Status')); ?>">
        <?php foreach ($steps as $group => $status): ?>
            <?php
            $is_current = $group === $current_group;
            $state = $is_current ? 'is-current' : ($reached ? 'is-next' : 'is-done');
            if ($is_current) {
                $reached = true;
            }
            ?>
            <li class="ticket-status-pipeline__step <?php echo e($state); ?>" data-ticket-status-group="<?php echo e((string) $group); ?>" <?php echo $is_current ? 'aria-current="step"' : ''; ?>>
                <span class="ticket-status-pipeline__dot" aria-hidden="true"></span>
                <span><?php echo e(function_exists('ticket_status_group_display_name') ? ticket_status_group_display_name($status) : (string) ($status['name'] ?? '')); ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php
}

function ticket_detail_render_priority_pill(string $priority_name): void
{
    ?>
    <span class="<?php echo e(ticket_detail_priority_pill_class($priority_name)); ?>">
        <?php echo e(t($priority_name)); ?>
    </span>
    <?php
}
