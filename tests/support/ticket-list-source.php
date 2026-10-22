<?php

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

function ticket_list_view_source(string $root): string
{
    return ticket_list_read_source($root, 'includes/components/ticket-list-page.php');
}

function ticket_list_board_source(string $root): string
{
    return ticket_list_read_source($root, 'includes/components/ticket-list-board.php');
}

function ticket_list_table_source(string $root): string
{
    return ticket_list_read_source($root, 'includes/components/ticket-list-table.php');
}

function ticket_list_surface_source(string $root): string
{
    return implode("\n", [
        ticket_list_route_source($root),
        ticket_list_controller_source($root),
        ticket_list_view_source($root),
        ticket_list_board_source($root),
        ticket_list_table_source($root),
    ]);
}

function ticket_list_script_source(string $root): string
{
    return implode("\n", [
        ticket_list_read_source($root, 'assets/js/ticket-list.js'),
        ticket_list_read_source($root, 'assets/js/ticket-list-due-date.js'),
        ticket_list_read_source($root, 'assets/js/ticket-list-time.js'),
    ]);
}

/** Route plus controller: everything that used to live in pages/tickets.php. */
function ticket_list_route_and_controller_source(string $root): string
{
    return ticket_list_route_source($root) . "
" . ticket_list_controller_source($root);
}
