<?php

$root = dirname(__DIR__);
$page = file_get_contents($root . '/pages/tickets.php');
$asset = file_get_contents($root . '/assets/js/ticket-list.js');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert($page !== false, 'Tickets page must be readable.');
$assert($asset !== false, 'Ticket list JS asset must be readable.');
$assert(str_contains($page, 'window.FoxDeskTicketListConfig'), 'Tickets page must expose only the ticket-list JS config.');
$assert(str_contains($page, 'assets/js/ticket-list.js'), 'Tickets page must load the extracted ticket-list JS asset.');
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
