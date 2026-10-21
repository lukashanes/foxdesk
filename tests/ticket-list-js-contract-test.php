<?php

$root = dirname(__DIR__);
require_once __DIR__ . '/support/ticket-list-source.php';
$route = ticket_list_route_and_controller_source($root);
$surface = file_get_contents($root . '/includes/components/ticket-list-page.php');
$boardSurface = file_get_contents($root . '/includes/components/ticket-list-board.php');
$tableSurface = file_get_contents($root . '/includes/components/ticket-list-table.php');
$assetsComponent = file_get_contents($root . '/includes/components/ticket-list-assets.php');
$coreAsset = file_get_contents($root . '/assets/js/ticket-list.js');
$actionsAsset = file_get_contents($root . '/assets/js/ticket-list-actions.js');
$page = implode("\n", [(string) $route, (string) $surface, (string) $boardSurface, (string) $tableSurface, (string) $assetsComponent]);
$asset = (string) $coreAsset . "\n" . (string) $actionsAsset;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert($route !== false && $surface !== false && $boardSurface !== false && $tableSurface !== false && $assetsComponent !== false, 'Ticket list route and components must be readable.');
$assert($coreAsset !== false && $actionsAsset !== false, 'Ticket list JS modules must be readable.');
$assert(str_contains((string) $route, '/includes/components/ticket-list-page.php'), 'Tickets route must delegate rendering to the ticket-list page component.');
$assert(str_contains($page, 'window.FoxDeskTicketListConfig'), 'Tickets page must expose only the ticket-list JS config.');
$assert(str_contains($page, 'assets/js/ticket-list.js'), 'Tickets page must load the extracted ticket-list JS asset.');
$assert(str_contains($page, 'assets/js/ticket-list-actions.js'), 'Tickets page must load the extracted action module.');
$assert(str_contains($page, '$date_sort_url'), 'Tickets page must expose a clickable date sort URL.');
$assert(str_contains($page, '$date_sort_next = $sort === \'oldest\' ? \'newest\' : \'oldest\';'), 'Date header must toggle newest/oldest sorting.');
$assert(str_contains($page, 'class="ticket-date-sort'), 'Date column header must be a clickable sort control.');
$assert(str_contains($page, 'get_icon($date_sort_icon'), 'Date sort control must show direction.');

foreach ([
    'window.applyHeaderSort',
    'window.toggleBulkMode',
    'window.toggleAll',
    'window.updateSelectedCount',
    'window.inlineUpdate = function',
    'window.inlineUpdateType = function',
    'window.inlineUpdateCompany = function',
    'window.inlineUpdateAssign = function',
    'function bindSearchSuggestions',
    'function bindInlineLogTime',
    "document.addEventListener('DOMContentLoaded'",
] as $needle) {
    $assert(str_contains($asset, $needle), 'Ticket list JS asset missing behavior: ' . $needle);
}

$assert(str_contains((string) $coreAsset, 'window.FoxDeskTicketListCore = Object.freeze'), 'Ticket list core must publish an explicit immutable module interface.');
$assert(str_contains((string) $actionsAsset, 'var core = window.FoxDeskTicketListCore || {};'), 'Ticket list actions must consume the shared core interface.');
$assert(count(file($root . '/pages/tickets.php') ?: []) < 700, 'Tickets route must remain below 700 lines.');
$assert(count(file($root . '/assets/js/ticket-list.js') ?: []) < 900, 'Ticket list core browser module must remain below 900 lines.');
$assert(count(file($root . '/assets/js/ticket-list-actions.js') ?: []) < 900, 'Ticket list action browser module must remain below 900 lines.');

foreach ([
    'function applyHeaderSort',
    'let bulkMode',
    'window.inlineUpdate = function',
    'window.inlineUpdateType = function',
    'window.inlineUpdateCompany = function',
    'window.inlineUpdateAssign = function',
    'let activeChips',
] as $needle) {
    $assert(!str_contains($page, $needle), 'Tickets page must not own extracted JS behavior: ' . $needle);
}

echo "Ticket list JS contract OK\n";
