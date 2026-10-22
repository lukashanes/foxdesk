<?php

$root = dirname(__DIR__);

$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        fwrite(STDERR, 'Unable to read ' . $path . PHP_EOL);
        exit(1);
    }
    return $contents;
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$ticketsRoute = $read('pages/tickets.php');
$ticketsSurface = $read('includes/components/ticket-list-page.php');
$ticketsBoard = $read('includes/components/ticket-list-board.php');
$ticketsTable = $read('includes/components/ticket-list-table.php');
$ticketAssets = $read('includes/components/ticket-list-assets.php');
$ticketCoreJs = $read('assets/js/ticket-list.js');
$ticketActionsJs = $read('assets/js/ticket-list-time.js');
$newTicketRoute = $read('pages/new-ticket.php');
$newTicketForm = $read('includes/components/new-ticket-form.php');
$newTicketAssets = $read('includes/components/new-ticket-assets.php');
$newTicketCss = $read('theme.css');

foreach ([
    'pages/tickets.php' => 700,
    'pages/new-ticket.php' => 700,
    'assets/js/ticket-list.js' => 900,
    'assets/js/ticket-list-time.js' => 900,
    'includes/components/ticket-list-board.php' => 700,
    'includes/components/ticket-list-table.php' => 900,
    'includes/components/new-ticket-form.php' => 700,
    'includes/components/new-ticket-assets.php' => 900,
] as $path => $limit) {
    $lineCount = count(file($root . '/' . $path) ?: []);
    $assert($lineCount < $limit, "{$path} has {$lineCount} lines; expected fewer than {$limit}.");
}

$assert(str_contains($ticketsRoute, "require BASE_PATH . '/includes/components/ticket-list-page.php'"), 'Tickets route must delegate the registry surface.');
$assert(!str_contains($ticketsRoute, 'data-ticket-registry-surface'), 'Tickets route must not regain registry markup.');
$assert(str_contains($ticketsSurface, 'data-ticket-registry-surface'), 'Ticket list surface must own registry markup.');
$assert(str_contains($ticketsSurface, 'includes/components/ticket-list-board.php'), 'Ticket list surface must delegate kanban rendering.');
$assert(str_contains($ticketsSurface, 'includes/components/ticket-list-table.php'), 'Ticket list surface must delegate list rendering.');
$assert(str_contains($ticketsBoard, 'data-kanban-scope="main"'), 'Ticket board component must own kanban markup.');
$assert(str_contains($ticketsTable, 'tickets-table'), 'Ticket table component must own desktop table markup.');
$assert(str_contains($ticketsRoute, "require BASE_PATH . '/includes/components/ticket-list-assets.php'"), 'Tickets route must load the asset component.');

$corePosition = strpos($ticketAssets, 'assets/js/ticket-list.js');
$actionsPosition = strpos($ticketAssets, 'assets/js/ticket-list-time.js');
$assert($corePosition !== false && $actionsPosition !== false && $corePosition < $actionsPosition, 'Ticket list browser modules must load core before actions.');
$assert(str_contains($ticketActionsJs, 'quick-log-time'), 'The time module must own inline time behavior.');

$assert(str_contains($newTicketRoute, "require BASE_PATH . '/includes/components/new-ticket-form.php'"), 'New ticket route must delegate form rendering.');
$assert(!str_contains($newTicketRoute, '<form'), 'New ticket route must not regain form markup.');
$assert(str_contains($newTicketForm, 'id="new-ticket-form"'), 'New ticket form component must own form markup.');
$assert(!str_contains($newTicketForm, 'assets/css/new-ticket.css'), 'New ticket styles live in theme.css.');
$assert(str_contains($newTicketRoute, "require BASE_PATH . '/includes/components/new-ticket-assets.php'"), 'New ticket route must load extracted browser behavior.');
$assert(!str_contains($newTicketForm, '<style>'), 'New ticket form must not contain local style blocks.');
$assert(str_contains($newTicketCss, '.editor-wrapper'), 'New ticket stylesheet must preserve editor styling.');
foreach (['attachment-paste-drop.js', 'FoxDeskAttachmentPasteDrop.bind', 'quill-image-upload.js', 'autosave.js'] as $needle) {
    $assert(str_contains($newTicketAssets, $needle), 'New ticket assets missing behavior: ' . $needle);
}

echo "Ticket list and new ticket extraction contract OK\n";
