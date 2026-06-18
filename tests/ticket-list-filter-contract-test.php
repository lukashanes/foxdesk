<?php

$root = dirname(__DIR__);
require_once $root . '/includes/modules/tickets/ticket-list-filters.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$state = ticket_list_filter_state_from_request([
    'status' => '4',
    'organization' => '7',
    'priority' => '2',
    'assigned_to' => '9',
    'search' => '  billing issue  ',
    'user' => '  Eva  ',
    'created_date' => '2026-06-17',
    'due_date' => 'week',
    'tags' => ' #Urgent, urgent, paid ',
    'sort' => 'tags',
    'view' => 'board',
], [], false, ['tags_supported' => true, 'archive_supported' => true]);

$assert($state['status_id'] === 4, 'Status id must be normalized.');
$assert($state['organization_id'] === 7, 'Organization id must be normalized.');
$assert($state['priority_id'] === 2, 'Priority id must be normalized.');
$assert($state['assigned_to'] === 9, 'Assigned-to id must be normalized.');
$assert($state['search_query'] === 'billing issue', 'Search query must be trimmed.');
$assert($state['user_search'] === 'Eva', 'User search must be trimmed.');
$assert($state['created_date_value'] === '2026-06-17', 'Created date must be normalized.');
$assert($state['tag_filters'] === ['Urgent', 'paid'], 'Tag filters must be normalized and deduplicated case-insensitively.');
$assert($state['tag_filter_csv'] === 'Urgent, paid', 'Tag filter CSV must match normalized tags.');
$assert($state['sort'] === 'tags', 'Tags sort must be allowed when tags are supported.');
$assert($state['ticket_view'] === 'board', 'Explicit board view must be used.');
$assert($state['ticket_view_should_persist'] === true, 'Explicit view must be persisted.');
$assert($state['filters']['created_from'] === '2026-06-17', 'Created date from filter must be set.');
$assert($state['filters']['created_to'] === '2026-06-18', 'Created date to filter must be exclusive next day.');
$assert($state['filters']['due_date_week'] === true, 'Due-date week filter must be set.');
$assert($state['filters']['is_archived'] === 0, 'Non-archive list must set archive filter to zero when supported.');

$fallback = ticket_list_filter_state_from_request([
    'sort' => 'tags',
    'view' => 'bad',
    'tag' => 'legacy',
    'due_date' => '2026-06-20',
], ['foxdesk_ticket_view' => 'board'], true, ['tags_supported' => false, 'archive_supported' => true]);

$assert($fallback['sort'] === 'newest', 'Tags sort must fall back when tags are not supported.');
$assert($fallback['ticket_view'] === 'board', 'Cookie board view must be used when request view is invalid.');
$assert($fallback['ticket_view_should_persist'] === false, 'Cookie-derived view must not be re-persisted.');
$assert($fallback['tag_filters'] === ['legacy'], 'Legacy single tag parameter must be supported.');
$assert(!isset($fallback['filters']['tags']), 'Unsupported tags must not enter query filters.');
$assert($fallback['filters']['due_date_from'] === '2026-06-20', 'Specific due date must set from filter.');
$assert($fallback['filters']['due_date_to'] === '2026-06-21', 'Specific due date must set exclusive next-day filter.');
$assert($fallback['filters']['is_archived'] === 1, 'Archive list must set archive filter to one when supported.');

$page = file_get_contents($root . '/pages/tickets.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$assert($page !== false && $bootstrap !== false, 'Ticket filter files must be readable.');
$assert(str_contains($bootstrap, '/tickets/ticket-list-filters.php'), 'Module bootstrap must load ticket list filters.');
$assert(str_contains($page, 'ticket_list_filter_state_from_request($_GET, $_COOKIE, $is_archive)'), 'Tickets page must consume ticket list filter state.');
$assert(!str_contains($page, '$normalize_tag_filters ='), 'Tickets page must not own tag normalization closure.');
$assert(!str_contains($page, '$allowed_sorts ='), 'Tickets page must not own sort allow-list logic.');

echo "Ticket list filter contract OK\n";
