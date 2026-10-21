<?php
/**
 * agent-docs must be callable by every valid token and must describe each
 * action's availability from the scopes that are actually missing.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_DEBUG', false);
define('APP_URL', 'https://foxdesk.test');
require BASE_PATH . '/includes/locale-functions.php';

$testUser = ['id' => 7, 'role' => 'admin', 'language' => 'en', 'first_name' => 'Agent', 'last_name' => 'Test', 'email' => 'agent@example.test'];
$testScopes = ['work:read', 'tickets:read', 'tickets:write', 'comments:write', 'time:write'];
$canDelete = false;
function current_user() { return $GLOBALS['testUser']; }
function api_token_current_row() { return ['id' => 1, 'token_prefix' => 'fdk_test', 'expires_at' => null]; }
function api_token_scopes_from_row($row) { return $GLOBALS['testScopes']; }
function api_token_scope_catalog($user) { return []; }
function api_token_has_scope($scope) { return in_array($scope, $GLOBALS['testScopes'], true); }
function api_token_allowed_scopes_for_user($user) { return $GLOBALS['testScopes']; }
function can_permanently_delete_tickets($user) { return $GLOBALS['canDelete']; }
function get_setting($key, $default = '') { return $default; }
function api_success($payload) { $GLOBALS['testResponse'] = $payload; }
function api_error($message, $code = 400) { throw new RuntimeException($message, $code); }

require BASE_PATH . '/includes/api/agent-handler.php';

function check(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}
function docs(): array {
    api_agent_docs();
    return $GLOBALS['testResponse']['documentation'];
}
function action(array $docs, string $name): array {
    $actions = array_column($docs['actions'], null, 'action');
    check(isset($actions[$name]), "agent-docs must list {$name}.");
    return $actions[$name];
}

$full = docs();
foreach ($full['actions'] as $tool) {
    check(is_array($tool['scopes'] ?? null), 'Every documented action must expose its required scopes as a list: ' . $tool['action']);
}
check(action($full, 'agent-ticket-workflow')['scopes'] === ['tickets:read', 'tickets:write'], 'Workflow action must require tickets:read and tickets:write.');
check(action($full, 'agent-ticket-workflow')['available'] === true, 'Workflow action must be available with ticket scopes.');
check(action($full, 'agent-delete-comment')['available'] === true, 'Comment deletion must be available with comments:write.');
check(action($full, 'agent-docs')['missing_scopes'] === [], 'agent-docs needs no scope.');
check(action($full, 'agent-delete-ticket-permanently')['available'] === false, 'Permanent deletion needs delete:write and explicit permission.');
check(action($full, 'agent-delete-ticket-permanently')['missing_scopes'] === ['delete:write'], 'Only actually missing scopes should be reported.');

$testScopes = ['tickets:read'];
$readOnly = docs();
check(action($readOnly, 'agent-get-ticket')['available'] === true, 'Read-only token keeps read actions.');
check(action($readOnly, 'agent-add-work-entry')['missing_scopes'] === ['comments:write', 'time:write'], 'Read-only token must report only the missing write scopes.');
check(action($readOnly, 'agent-ticket-workflow')['missing_scopes'] === ['tickets:write'], 'Workflow must report only tickets:write as missing.');

$testScopes = ['tickets:read', 'delete:write'];
$canDelete = true;
check(action(docs(), 'agent-delete-ticket-permanently')['available'] === true, 'Permanent deletion is available with delete:write and permission.');

$auth = file_get_contents(BASE_PATH . '/includes/auth.php');
check(preg_match("/function api_token_enforce_action_scope.*?if \\(\\\$action === 'agent-docs'\\) \\{\\s*return;/s", (string) $auth) === 1, 'Every valid token must be able to read agent-docs.');
check(str_contains((string) $auth, "'agent-delete-comment' => 'comments:write'"), 'agent-delete-comment needs a token scope mapping.');
$router = file_get_contents(BASE_PATH . '/includes/api/router.php');
check(str_contains((string) $router, "'agent-delete-comment' => 'api_agent_delete_comment'"), 'agent-delete-comment route is missing.');

echo "Agent docs availability OK\n";
