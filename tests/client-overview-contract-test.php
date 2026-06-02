<?php

$root = dirname(__DIR__);

$module = file_get_contents($root . '/includes/modules/clients/client-overview.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$page = file_get_contents($root . '/pages/client.php');
$index = file_get_contents($root . '/index.php');
$organizations = file_get_contents($root . '/pages/admin/organizations.php');

if ($module === false || $bootstrap === false || $page === false || $index === false || $organizations === false) {
    fwrite(STDERR, "Unable to read client overview files.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$assert(str_contains($bootstrap, '/clients/client-overview.php'), 'Module bootstrap must load client overview.');
$assert(str_contains($module, 'function client_overview('), 'Client overview service is missing.');
$assert(str_contains($module, 'function client_overview_ticket_counts'), 'Client ticket counts are missing.');
$assert(str_contains($module, 'function client_overview_contacts'), 'Client contacts are missing.');
$assert(str_contains($module, 'function client_overview_time_summary'), 'Client monthly time summary is missing.');
$assert(str_contains($index, "case 'client':"), 'Client route is missing.');
$assert(str_contains($page, 'client_overview('), 'Client page must consume the overview service.');
$assert(str_contains($page, "redirect('work')"), 'Client users should be redirected away from staff client center.');
$assert(str_contains($page, "t('Client tickets')"), 'Client page must show ticket section.');
$assert(str_contains($page, "t('Contacts')"), 'Client page must show contacts.');
$assert(str_contains($page, "t('Billable rate')"), 'Client page must show billing context.');
$assert(str_contains($organizations, "url('client'"), 'Organizations list must link to client center.');

echo "Client overview contract OK\n";
