<?php

$root = dirname(__DIR__);
require_once $root . '/includes/modules/tickets/ticket-detail-read-model.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$comments = [
    ['id' => 10, 'created_at' => '2026-06-01 10:00:00', 'is_internal' => 0],
    ['id' => 11, 'created_at' => '2026-06-01 12:00:00', 'is_internal' => 1],
];
$attachments = [
    ['id' => 1, 'comment_id' => null],
    ['id' => 2, 'comment_id' => 10],
    ['id' => 3, 'comment_id' => 11],
];
$time_entries = [
    ['id' => 21, 'comment_id' => 10, 'started_at' => '2026-06-01 11:00:00'],
    ['id' => 22, 'comment_id' => null, 'started_at' => '2026-06-01 09:00:00'],
];

$visible_customer_comments = ticket_detail_visible_comments($comments, false);
$visible_customer_ids = ticket_detail_visible_comment_ids($visible_customer_comments);
$visible_customer_attachments = ticket_detail_visible_attachments($attachments, $visible_customer_ids, false);

$assert(count($visible_customer_comments) === 1, 'Customers must not see internal comments.');
$assert(count($visible_customer_attachments) === 2, 'Customers must see initial attachments and visible comment attachments only.');
$assert(count(ticket_detail_initial_attachments($attachments)) === 1, 'Initial attachment helper must return only ticket-level attachments.');
$assert(count(ticket_detail_comment_attachments($attachments, 10)) === 1, 'Comment attachment helper must return the requested comment attachments.');

$timeline = ticket_detail_build_timeline($visible_customer_comments, $time_entries);
$assert(isset($timeline['time_entries_by_comment'][10]), 'Linked time entries must be indexed by comment id.');
$assert(count($timeline['orphan_time_entries']) === 1, 'Orphan time entries must stay separate in the read model.');
$assert(array_column($timeline['timeline_items'], 'type') === ['time_entry', 'comment'], 'Timeline must merge orphan time entries and comments chronologically.');

echo "Ticket detail timeline contract OK\n";
