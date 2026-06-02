<?php

$root = dirname(__DIR__);

$module = file_get_contents($root . '/includes/modules/tickets/ticket-detail-actions.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$page = file_get_contents($root . '/pages/ticket-detail.php');

if ($module === false || $bootstrap === false || $page === false) {
    fwrite(STDERR, "Unable to read ticket detail action files.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$assert(str_contains($bootstrap, '/tickets/ticket-detail-actions.php'), 'Module bootstrap must load ticket detail actions.');
$assert(str_contains($module, 'function ticket_detail_primary_actions'), 'Primary action model is missing.');
$assert(str_contains($module, "'key' => 'reply'"), 'Reply must be a primary action.');
$assert(str_contains($module, "'key' => 'start_work'"), 'Start work must be a primary action.');
$assert(str_contains($module, "'key' => 'assign'"), 'Assign must be a primary action.');
$assert(str_contains($module, "'key' => 'complete'"), 'Complete must be a primary action.');
$assert(str_contains($page, 'ticket_detail_primary_actions('), 'Ticket detail page must consume the action model.');
$assert(str_contains($page, 'class="card ticket-work-panel"'), 'Ticket detail must render the work panel.');
$assert(str_contains($module, "'id' => 'toolbar-timer-btn'"), 'Timer button id must stay stable in the action model.');
$assert(str_contains($page, "document.getElementById('toolbar-timer-btn')"), 'Existing timer JS must still target toolbar-timer-btn.');
$assert(str_contains($page, 'id="ticket-side-panel"'), 'Assign action must target the side properties panel.');
$assert(str_contains($page, "t('Ticket properties')"), 'Side panel must have a clear properties heading.');

echo "Ticket detail action contract OK\n";
