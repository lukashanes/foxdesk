<?php

$root = dirname(__DIR__);
$page = file_get_contents($root . '/pages/ticket-detail.php');
$content = file_get_contents($root . '/includes/components/ticket-detail-content.php');
$modals = file_get_contents($root . '/includes/components/ticket-detail-modals.php');
$handlers = file_get_contents($root . '/includes/components/ticket-form-handlers.php');
$ticketCrud = file_get_contents($root . '/includes/ticket-crud-functions.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert($page !== false && $content !== false && $modals !== false && $handlers !== false && $ticketCrud !== false, 'Ticket modal surface files must be readable.');
$assert(str_contains($page, "/includes/components/ticket-detail-content.php"), 'Ticket detail route must delegate its content surface.');
$assert(str_contains($content, "/includes/components/ticket-detail-modals.php"), 'Ticket detail content must include the modal component.');

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
    'name="update_ticket" value="1"',
    'name="edit_status_id"',
    'name="edit_priority_id"',
    'name="edit_assignee_id"',
    'name="edit_organization_id"',
    'name="edit_due_date"',
    'maxlength="255"',
] as $needle) {
    $assert(str_contains($modals, $needle), 'Full ticket editor missing: ' . $needle);
}

foreach ([
    "array_key_exists('edit_status_id', \$_POST)",
    "array_key_exists('edit_priority_id', \$_POST)",
    "array_key_exists('edit_assignee_id', \$_POST)",
    "array_key_exists('edit_due_date', \$_POST)",
    'ticket_transition_status(',
    'beginTransaction()',
    'No changes to save.',
] as $needle) {
    $assert(str_contains($handlers, $needle), 'Ticket edit handler missing: ' . $needle);
}

$assert(str_contains($ticketCrud, 'array_key_exists($field, $data)'), 'Clearing an editable ticket field must remain visible in ticket history.');

foreach ([
    '<div id="edit-ticket-modal"',
    '<div id="edit-comment-modal"',
    '<div id="edit-time-modal"',
] as $needle) {
    $assert(!str_contains($page, $needle), 'Ticket modal markup must not live in the route file: ' . $needle);
    $assert(!str_contains($content, $needle), 'Ticket modal markup must not move into the content composition file: ' . $needle);
}

echo "Ticket detail modals contract OK\n";
