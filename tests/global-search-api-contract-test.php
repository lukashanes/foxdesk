<?php
$root = dirname(__DIR__);
$router = file_get_contents($root . '/includes/api/router.php');
$handler = file_get_contents($root . '/includes/api/ticket-handler.php');

if (strpos($router, "'global-search' => 'api_global_search'") === false) {
    fwrite(STDERR, "global-search route is not registered.\n");
    exit(1);
}

if (strpos($handler, 'function api_global_search()') === false) {
    fwrite(STDERR, "api_global_search handler is missing.\n");
    exit(1);
}

if (strpos($handler, 'global_search($q, $user, $limit)') === false) {
    fwrite(STDERR, "api_global_search does not delegate to global_search().\n");
    exit(1);
}

echo "Global search API contract tests passed\n";
