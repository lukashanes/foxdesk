<?php
$root = dirname(__DIR__);
$shortcuts = file_get_contents($root . '/assets/js/shortcuts.js');

function assert_global_search_ui($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assert_global_search_ui(strpos($shortcuts, "action=global-search") !== false, 'Command palette must call the global search API.');
assert_global_search_ui(strpos($shortcuts, "action=search-tickets") === false, 'Command palette should not use the old ticket-only search API.');
assert_global_search_ui(strpos($shortcuts, "open_tickets") !== false, 'Command palette must render open ticket results.');
assert_global_search_ui(strpos($shortcuts, "done_tickets") !== false, 'Command palette must render done ticket results.');
assert_global_search_ui(strpos($shortcuts, "archived_tickets") !== false, 'Command palette must render archived ticket results.');
assert_global_search_ui(strpos($shortcuts, "clients") !== false, 'Command palette must render client results.');
assert_global_search_ui(strpos($shortcuts, "contacts") !== false, 'Command palette must render contact results.');
assert_global_search_ui(strpos($shortcuts, "reports") !== false, 'Command palette must render report results.');
assert_global_search_ui(strpos($shortcuts, "bindHeaderSearchToPalette") !== false, 'Header search must open the command palette.');
assert_global_search_ui(strpos($shortcuts, "mobile-header-search") !== false, 'Mobile search button must open the command palette.');

echo "Global search UI contract tests passed\n";
