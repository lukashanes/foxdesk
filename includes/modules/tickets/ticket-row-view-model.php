<?php
/**
 * Ticket registry row and board view model helpers.
 */

function ticket_registry_statuses_by_id(array $statuses): array
{
    $by_id = [];
    foreach ($statuses as $status_item) {
        $by_id[(int) ($status_item['id'] ?? 0)] = $status_item;
    }
    return $by_id;
}

function ticket_registry_closed_filter_active(array $statuses, ?int $status_id): bool
{
    foreach ($statuses as $status_item) {
        if ($status_id === (int) ($status_item['id'] ?? 0) && !empty($status_item['is_closed'])) {
            return true;
        }
    }
    return false;
}

function ticket_registry_closed_label(): string
{
    return function_exists('t') ? t('Closed') : 'Closed';
}

function ticket_registry_split_model(array $statuses, array $tickets, ?int $status_id, string $ticket_list_view, ?bool $show_closed_tickets_inline_override = null): array
{
    $statuses_by_id = ticket_registry_statuses_by_id($statuses);
    $is_closed_filter_active = ticket_registry_closed_filter_active($statuses, $status_id);
    $show_closed_tickets_inline = $show_closed_tickets_inline_override
        ?? ticket_list_view_shows_closed_inline($ticket_list_view, $is_closed_filter_active);

    $active_statuses = [];
    $closed_statuses = [];
    foreach ($statuses as $status_item) {
        if (!$show_closed_tickets_inline && !empty($status_item['is_closed'])) {
            $closed_statuses[] = $status_item;
        } else {
            $active_statuses[] = $status_item;
        }
    }

    $active_tickets = [];
    $closed_tickets = [];
    foreach ($tickets as $ticket_item) {
        $ticket_status = $statuses_by_id[(int) ($ticket_item['status_id'] ?? 0)] ?? null;
        if (!$show_closed_tickets_inline && !empty($ticket_status['is_closed'])) {
            $closed_tickets[] = $ticket_item;
        } else {
            $active_tickets[] = $ticket_item;
        }
    }

    $board_active_statuses = $active_statuses;
    $board_closed_statuses = $closed_statuses;
    if (!$is_closed_filter_active && empty($active_statuses) && !empty($closed_statuses)) {
        $active_statuses = $closed_statuses;
        $closed_statuses = [];
        $active_tickets = $tickets;
        $closed_tickets = [];
    }

    if (!$is_closed_filter_active && empty($board_active_statuses) && !empty($board_closed_statuses)) {
        $board_active_statuses = $board_closed_statuses;
    }

    $closed_label = ticket_registry_closed_label();
    $ticket_groups = [
        ['name' => 'active', 'label' => '', 'tickets' => $active_tickets, 'hidden' => false],
    ];
    if (!empty($closed_tickets)) {
        $ticket_groups[] = ['name' => 'closed', 'label' => $closed_label . ' (' . count($closed_tickets) . ')', 'tickets' => $closed_tickets, 'hidden' => true];
    }

    $board_status_groups = [
        ['name' => 'active', 'label' => '', 'statuses' => $active_statuses, 'count' => count($active_tickets), 'hidden' => false],
    ];
    if (!empty($closed_statuses) && !empty($closed_tickets)) {
        $board_status_groups[] = ['name' => 'closed', 'label' => $closed_label, 'statuses' => $closed_statuses, 'count' => count($closed_tickets), 'hidden' => true];
    }

    return [
        'statuses_by_id' => $statuses_by_id,
        'is_closed_filter_active' => $is_closed_filter_active,
        'show_closed_tickets_inline' => $show_closed_tickets_inline,
        'active_statuses' => $active_statuses,
        'closed_statuses' => $closed_statuses,
        'active_tickets' => $active_tickets,
        'closed_tickets' => $closed_tickets,
        'board_active_statuses' => $board_active_statuses,
        'board_closed_statuses' => $board_closed_statuses,
        'ticket_groups' => $ticket_groups,
        'board_status_groups' => $board_status_groups,
    ];
}

function ticket_registry_kanban_model(array $statuses, array $tickets, array $statuses_by_id, array $board_active_statuses, array $board_closed_statuses, bool $show_closed_tickets_inline): array
{
    $hide_closed_after_days = function_exists('get_kanban_closed_archive_days')
        ? get_kanban_closed_archive_days()
        : 7;

    $main_tickets_by_status = [];
    foreach ($statuses as $status_item) {
        $main_tickets_by_status[(int) ($status_item['id'] ?? 0)] = [];
    }

    $archived_closed_tickets_by_status = [];
    foreach ($board_closed_statuses as $status_item) {
        $archived_closed_tickets_by_status[(int) ($status_item['id'] ?? 0)] = [];
    }

    $archived_closed_count = 0;
    foreach ($tickets as $ticket_item) {
        $status_key = (int) ($ticket_item['status_id'] ?? 0);
        if (!isset($main_tickets_by_status[$status_key])) {
            continue;
        }

        $ticket_status = $statuses_by_id[$status_key] ?? null;
        $ticket_is_closed = !empty($ticket_item['is_closed']) || !empty($ticket_status['is_closed']);

        if (!$show_closed_tickets_inline
            && $ticket_is_closed
            && function_exists('should_hide_closed_ticket_in_board')
            && should_hide_closed_ticket_in_board($ticket_item, $hide_closed_after_days)
            && isset($archived_closed_tickets_by_status[$status_key])) {
            $archived_closed_tickets_by_status[$status_key][] = $ticket_item;
            $archived_closed_count++;
            continue;
        }

        $main_tickets_by_status[$status_key][] = $ticket_item;
    }

    $main_statuses = [];
    $main_status_ids = [];
    foreach (array_merge($board_active_statuses, $board_closed_statuses) as $status_item) {
        $status_key = (int) ($status_item['id'] ?? 0);
        if ($status_key <= 0 || isset($main_status_ids[$status_key])) {
            continue;
        }
        $main_status_ids[$status_key] = true;
        $main_statuses[] = $status_item;
    }

    $archived_closed_statuses = [];
    foreach ($board_closed_statuses as $status_item) {
        $status_key = (int) ($status_item['id'] ?? 0);
        if (!empty($archived_closed_tickets_by_status[$status_key] ?? [])) {
            $archived_closed_statuses[] = $status_item;
        }
    }

    return [
        'hide_closed_after_days' => $hide_closed_after_days,
        'main_tickets_by_status' => $main_tickets_by_status,
        'archived_closed_tickets_by_status' => $archived_closed_tickets_by_status,
        'archived_closed_count' => $archived_closed_count,
        'main_statuses' => $main_statuses,
        'archived_closed_statuses' => $archived_closed_statuses,
    ];
}

function ticket_registry_status_group_from_ticket(array $ticket, array $statuses): string
{
    if (function_exists('ticket_detail_status_group')) {
        return ticket_status_group_normalize(ticket_detail_status_group($ticket, $statuses));
    }
    return !empty($ticket['is_closed']) ? 'done' : 'active';
}

function ticket_registry_status_group_from_status(array $status): string
{
    if (function_exists('ticket_status_group_from_status')) {
        return ticket_status_group_normalize(ticket_status_group_from_status($status));
    }
    return !empty($status['is_closed']) ? 'done' : 'active';
}

function ticket_registry_status_accent_class(array $ticket, array $statuses): string
{
    return 'ticket-status-accent ticket-status-accent--' . ticket_registry_status_group_from_ticket($ticket, $statuses);
}

function ticket_registry_status_dot_class(string $group, string $base = 'ticket-status-dot'): string
{
    $group = function_exists('ticket_status_group_normalize') ? ticket_status_group_normalize($group) : $group;
    return $base . ' ' . $base . '--' . $group;
}

function ticket_registry_status_badge_class(array $ticket, array $statuses): string
{
    return 'badge-inline ticket-status-inline ticket-status-inline--' . ticket_registry_status_group_from_ticket($ticket, $statuses);
}

function ticket_registry_priority_key(string $priority_name): string
{
    $key = function_exists('ticket_detail_priority_key') ? ticket_detail_priority_key($priority_name) : 'medium';
    return in_array($key, ['low', 'medium', 'high', 'urgent'], true) ? $key : 'medium';
}

function ticket_registry_priority_badge_class(string $priority_name, string $base = 'badge-inline ticket-priority-inline'): string
{
    return $base . ' ticket-priority-inline--' . ticket_registry_priority_key($priority_name);
}
