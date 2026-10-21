<?php
/**
 * Long-lived ticket list tabs must recover from a rotated session CSRF token
 * (re-login or update) by fetching the current token once and retrying.
 */

$root = dirname(__DIR__);
$router = (string) file_get_contents($root . '/includes/api/router.php');
$handler = (string) file_get_contents($root . '/includes/api/ticket-handler.php');
$list = (string) file_get_contents($root . '/assets/js/ticket-list.js');
$auth = (string) file_get_contents($root . '/includes/auth.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'CSRF recovery contract failed: ' . $message . PHP_EOL);
        exit(1);
    }
};

$assert(str_contains($router, "'csrf-token' => 'api_csrf_token'"), 'csrf-token route is missing.');
$assert(str_contains($handler, "function api_csrf_token() {\n    api_success(['token' => csrf_token()]);")
    || str_contains($handler, "function api_csrf_token() {\r\n    api_success(['token' => csrf_token()]);"), 'csrf-token must return the session token.');
$assert(!str_contains($auth, "'csrf-token' =>"), 'API tokens must not be able to read a browser CSRF token.');
$assert(str_contains($list, "function apiCall(action, ticketId, payload, retried)"), 'Ticket list apiCall must support a single retry.');
$assert(str_contains($list, "!retried && response.status === 403 && /csrf/i.test("), 'Ticket list must retry only once and only on a CSRF rejection.');
$assert(str_contains($list, "apiUrl('csrf-token')"), 'Ticket list must refresh the token from the csrf-token action.');
$assert(str_contains($list, "credentials: 'same-origin'"), 'Ticket list requests must send the session cookie.');

echo "CSRF recovery contract OK\n";
