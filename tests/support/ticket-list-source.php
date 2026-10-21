<?php
/**
 * Contract tests read the ticket list as one surface: the thin route, the
 * request controller and the rendering components.
 */

function ticket_list_read_source(string $root, string $path): string
{
    $source = file_get_contents($root . '/' . $path);
    if ($source === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function ticket_list_route_source(string $root): string
{
    return ticket_list_read_source($root, 'pages/tickets.php');
}

function ticket_list_controller_source(string $root): string
{
    return ticket_list_read_source($root, 'includes/modules/tickets/ticket-list-page-controller.php');
}

/** Route plus controller: everything that used to live in pages/tickets.php. */
function ticket_list_route_and_controller_source(string $root): string
{
    return ticket_list_route_source($root) . "\n" . ticket_list_controller_source($root);
}

function ticket_list_surface_source(string $root): string
{
    return implode("\n", [
        ticket_list_route_and_controller_source($root),
        ticket_list_read_source($root, 'includes/components/ticket-list-page.php'),
        ticket_list_read_source($root, 'includes/components/ticket-list-board.php'),
        ticket_list_read_source($root, 'includes/components/ticket-list-table.php'),
    ]);
}
