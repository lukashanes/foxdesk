<?php

$root = dirname(__DIR__);
$page = file_get_contents($root . '/pages/ticket-detail.php');
$modals = file_get_contents($root . '/includes/components/ticket-detail-modals.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert($page !== false && $modals !== false, 'Ticket modal surface files must be readable.');
$assert(str_contains($page, "/includes/components/ticket-detail-modals.php"), 'Ticket detail page must include the modal component.');

foreach ([
    'id="edit-ticket-modal"',
    'id="edit-comment-modal"',
    'id="edit-time-modal"',
    'can_edit_ticket($ticket, $user)',
    'is_admin() && $time_tracking_available',
] as $needle) {
    $assert(str_contains($modals, $needle), 'Ticket modal component missing: ' . $needle);
}

foreach ([
    '<div id="edit-ticket-modal"',
    '<div id="edit-comment-modal"',
    '<div id="edit-time-modal"',
] as $needle) {
    $assert(!str_contains($page, $needle), 'Ticket modal markup must not live in the route file: ' . $needle);
}

echo "Ticket detail modals contract OK\n";
