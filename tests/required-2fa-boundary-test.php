<?php
$logged_in = true; $bearer = false; $side_effects = 0;
function api_validate_utf8_values(...$args) {}
function is_logged_in() { return $GLOBALS['logged_in']; }
function is_api_token_request() { return $GLOBALS['bearer']; }
function authenticate_api_token() { return null; }
function auth_requires_2fa_setup() { return true; }
function ticket_undo_finalize_expired() { $GLOBALS['side_effects']++; }
function api_error($message, $code, $details = []) { throw new RuntimeException($message, $code); }
$root = dirname(__DIR__);
$router = file_get_contents($root . '/includes/api/router.php');
eval(substr($router, strpos($router, 'function route_api_request')));
foreach ([['global-search', true, false, 403], ['agent-me', true, true, 401], ['global-search', false, false, 401]] as [$action, $logged_in, $bearer, $expected]) {
    try { route_api_request($action); throw new LogicException('Protected request was accepted.'); }
    catch (RuntimeException $e) { if ($e->getCode() !== $expected) throw new LogicException('Wrong boundary response: ' . $e->getCode()); }
    if ($side_effects !== 0) throw new RuntimeException('Rejected request caused a write.');
}
$uploads = file_get_contents($root . '/includes/upload-functions.php');
if (!str_contains($uploads, '!$user || auth_requires_2fa_setup()')) throw new RuntimeException('Attachment boundary lacks required 2FA.');
echo "API and attachment required 2FA / explicit credential boundaries OK\n";
