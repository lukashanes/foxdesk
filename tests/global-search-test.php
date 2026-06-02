<?php
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/modules/bootstrap.php';

function assert_global_search($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$sections = global_search_sections();
foreach (['open_tickets', 'done_tickets', 'archived_tickets', 'clients', 'contacts', 'reports'] as $key) {
    assert_global_search(isset($sections[$key]), "Missing search section: {$key}");
}

assert_global_search(global_search_normalize_query("  Aenze   faktura  ") === 'Aenze faktura', 'Search query should normalize whitespace.');

$agent = ['id' => 7, 'role' => 'agent'];
$open_filters = global_search_ticket_filters('router', $agent, 'open_tickets', 9);
assert_global_search(($open_filters['search'] ?? '') === 'router', 'Open ticket search should include query.');
assert_global_search(($open_filters['limit'] ?? null) === 9, 'Open ticket search should preserve limit.');
assert_global_search(in_array('done', $open_filters['status_group_not'] ?? [], true), 'Open ticket search should exclude done.');

$done_filters = global_search_ticket_filters('router', $agent, 'done_tickets', 9);
assert_global_search(($done_filters['status_group'] ?? '') === 'done', 'Done ticket search should use done status group.');
assert_global_search(empty($done_filters['status_group_not']), 'Done ticket search should not also exclude done.');

$archived_filters = global_search_ticket_filters('router', $agent, 'archived_tickets', 9);
assert_global_search(($archived_filters['is_archived'] ?? null) === 1, 'Archived ticket search should force archived tickets.');
assert_global_search(empty($archived_filters['status_group_not']), 'Archived ticket search should not exclude done.');

$empty = global_search('x', $agent);
assert_global_search($empty['total'] === 0 && empty($empty['sections']), 'Short query should return empty result.');

echo "Global search tests passed\n";
