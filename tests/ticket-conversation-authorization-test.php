<?php
define('BASE_PATH', dirname(__DIR__));
$ticket = ['id' => 1, 'user_id' => 10, 'assignee_id' => 12];
$users = [10 => ['id' => 10, 'role' => 'user', 'is_active' => 1],
    11 => ['id' => 11, 'role' => 'user', 'is_active' => 1],
    12 => ['id' => 12, 'role' => 'agent', 'is_active' => 1],
    13 => ['id' => 13, 'role' => 'admin', 'is_active' => 0],
    14 => ['id' => 14, 'role' => 'user', 'is_active' => 1]];
$writes = [];
function table_exists($table) { return true; }
function get_user($id) { return $GLOBALS['users'][$id] ?? null; }
function db_fetch_one($sql, $params = []) {
    if (!empty($GLOBALS['broken'])) throw new RuntimeException('TEST-lookup failure');
    if (str_contains($sql, 'FROM tickets')) return $GLOBALS['ticket'];
    if (str_contains($sql, 'FROM users')) return get_user($params[0]);
    if (str_contains($sql, 'FROM ticket_access')) return $params === [1, 14] ? ['id' => 1] : null;
    return null;
}
function db_insert($table, $data) { $GLOBALS['writes'][] = [$table, $data]; return 20; }
function db_update(...$args) { return 1; }
function get_ticket_comment_user_ids(...$args) { return [11, 13]; }
require_once BASE_PATH . '/includes/email-ingest-functions.php';
require_once BASE_PATH . '/includes/mailer.php';
$assert = static function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
try {
    email_ingest_add_inbound_comment(1, 11, 'TEST-unauthorized reply');
    throw new LogicException('Unrelated sender appended to ticket.');
} catch (RuntimeException $e) {
    $assert($writes === [], 'Rejected sender wrote a comment.');
}
email_ingest_add_inbound_comment(1, 10, 'TEST-owner reply');
$assert(count($writes) === 1, 'Owner reply should work.');
$recipients = ticket_comment_notification_recipients($ticket, $users[12], [11, 13, 14]);
$assert(array_keys($recipients) === [10, 14], 'Comment history and CC cannot grant access; explicit shares must work.');
$GLOBALS['broken'] = true;
$assert(!ticket_conversation_user_can_access(1, 10), 'Lookup failure must deny access.');
echo "Inbound and outbound conversation authorization regression OK\n";
