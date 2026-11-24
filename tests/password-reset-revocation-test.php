<?php
require dirname(__DIR__) . '/includes/security-helpers.php';
$queries = [];
$locked = true;
class TestResetTransaction {
    public bool $active = false;
    public int $commits = 0;
    public int $rollbacks = 0;
    function inTransaction() { return $this->active; }
    function beginTransaction() { $this->active = true; }
    function commit() { $this->active = false; $this->commits++; }
    function rollBack() { $this->active = false; $this->rollbacks++; }
}
$db = new TestResetTransaction();
function get_db() { return $GLOBALS['db']; }
function table_exists($table) { return $table === 'api_tokens'; }
function column_exists($table, $column) { return true; }
function schema_require(...$args) {}
function foxdesk_db_datetime() { return '2026-11-24 12:00:00'; }
function db_fetch_one($sql, $params = []) {
    $GLOBALS['queries'][] = [$sql, $params];
    return $GLOBALS['locked'] ? ['id' => 17, 'auth_version' => 3] : null;
}
function db_query($sql, $params = []) {
    $GLOBALS['queries'][] = [$sql, $params];
    if (!empty($GLOBALS['fail_revoke']) && str_contains($sql, 'UPDATE api_tokens')) throw new RuntimeException('TEST-write failure');
}
$assert = static function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
$assert(function_exists('password_reset_complete'), 'Atomic reset and credential revocation missing.');
$assert(password_reset_complete(['id' => 17], 'TEST-hash', 'TEST-token'), 'Valid one-time link should reset.');
$sql = implode("\n", array_column($queries, 0));
$assert(str_contains($sql, 'FOR UPDATE') && str_contains($sql, 'reset_token_expires > ?'), 'Token must be rechecked under lock.');
$assert(str_contains($sql, 'auth_version = auth_version + 1') && str_contains($sql, 'remember_token = NULL'), 'Reset must revoke old sessions and persistent login.');
$assert(str_contains($sql, 'UPDATE api_tokens') && str_contains($sql, 'is_active = 0'), 'API credentials must be revoked in the reset transaction.');
$assert(!str_contains($sql, 'tenant_id') && !str_contains($sql, 'mobile_'), 'Self-hosted reset must not depend on SaaS tables.');
$assert($db->commits === 1, 'Reset must commit once.');
$locked = false;
$queries = [];
$assert(!password_reset_complete(['id' => 17], 'TEST-hash', 'TEST-used'), 'Consumed token must fail.');
$assert(count($queries) === 1 && $db->rollbacks === 1, 'Invalid token must not write.');
$locked = true;
$GLOBALS['fail_revoke'] = true;
try { password_reset_complete(['id' => 17], 'TEST-hash', 'TEST-token'); throw new LogicException('Revocation failure was ignored.'); }
catch (RuntimeException $e) { $assert($e->getMessage() === 'TEST-write failure', 'Unexpected reset exception.'); }
$assert($db->commits === 1 && $db->rollbacks === 2, 'Revocation failure must roll back the whole reset.');
echo "Atomic password reset and revocation regression OK (database substitute)\n";
