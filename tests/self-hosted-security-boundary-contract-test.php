<?php

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        fwrite(STDERR, "Unable to read {$path}" . PHP_EOL);
        exit(1);
    }
    return $contents;
};

$database = $read('includes/database.php');
$ticket_api = $read('includes/api/ticket-handler.php');
$ticket_crud = $read('includes/ticket-crud-functions.php');
$ticket_forms = $read('includes/components/ticket-form-handlers.php');
$email_ingest = $read('includes/email-ingest-functions.php');
$allowed = $read('includes/api/allowed-senders-handler.php');
$uploads = $read('includes/upload-functions.php');
$image = $read('image.php');
$billing_review = $read('includes/modules/reports/billing-review.php');
$migration = $read('includes/migration-functions.php');

foreach ([
    'pages/platform.php',
    'pages/billing.php',
    'pages/cloud.php',
    'pages/signup.php',
    'pages/stripe-webhook.php',
] as $saas_route) {
    $assert(!is_file($root . '/' . $saas_route), "Self-hosted must not expose SaaS-only route {$saas_route}.");
}

$assert(!is_file($root . '/includes/tenant-functions.php'), 'Self-hosted must not grow the SaaS tenant helper layer.');
$assert(!str_contains($database, 'tenant_scope_mutation_where'), 'Self-hosted database helper must stay single-tenant/simple.');
$assert(str_contains($migration, 'tenant lifecycle stay in the separate foxdesk_saas repository'), 'Migration bridge must keep SaaS tenant lifecycle out of self-hosted.');

$combined_ticket_writes = $ticket_api . "\n" . $ticket_crud . "\n" . $ticket_forms . "\n" . $email_ingest;
$assert(!str_contains($combined_ticket_writes, 'UPDATE tickets SET updated_at = NOW() WHERE id = ?'), 'Ticket touch writes should use db_update helper.');
$assert(!str_contains($combined_ticket_writes, 'UPDATE tickets SET updated_at = ? WHERE id = ?'), 'Ticket touch writes should use db_update helper.');
$assert(substr_count($combined_ticket_writes, "db_update('tickets', ['updated_at'") >= 4, 'Ticket touch writes should be unified through db_update.');

$assert(str_contains($ticket_api, 'can_see_ticket($ticket, $user)'), 'Ticket API must keep central can_see_ticket checks.');
$assert(str_contains($ticket_api, 'can_edit_ticket($ticket, $user)'), 'Ticket API must keep central can_edit_ticket checks.');
$assert(str_contains($ticket_crud, 'function can_edit_ticket'), 'Ticket edit permission helper is missing.');
$assert(str_contains($ticket_crud, 'return can_see_ticket($ticket, $user);'), 'Agent edit permission should derive from visibility.');

$assert(str_contains($uploads, 'function attachment_user_can_access'), 'Attachment access helper is missing.');
$assert(str_contains($uploads, 'can_see_ticket($ticket, $user)'), 'Attachment access must depend on ticket visibility.');
$assert(str_contains($image, 'attachment_user_can_access'), 'Image proxy must authorize protected attachments.');

$assert(str_contains($allowed, "db_delete('allowed_senders'"), 'Allowed sender delete should use db_delete helper.');
$assert(str_contains($allowed, "db_update('allowed_senders'"), 'Allowed sender toggle should use db_update helper.');
$assert(!str_contains($allowed, 'DELETE FROM allowed_senders WHERE id = ?'), 'Allowed sender delete should not use raw delete.');
$assert(!str_contains($allowed, 'UPDATE allowed_senders SET active = NOT active WHERE id = ?'), 'Allowed sender toggle should not use raw update.');

$assert(str_contains($billing_review, 'WHERE 1 = 1'), 'Self-hosted report billing review must not require a tenant_id column.');
$assert(!str_contains($billing_review, 'WHERE t.tenant_id = ?";'), 'Self-hosted report billing review must not hard-require SaaS tenant_id.');

echo "Self-hosted security boundary contract OK\n";
