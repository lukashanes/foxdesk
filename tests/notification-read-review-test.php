<?php
require dirname(__DIR__).'/includes/notification-functions.php';
function db_fetch_one($sql,$params=[]) { return ['exists'=>1]; }
function column_exists($table,$column) { return true; }
function db_execute($sql,$params=[]) { $GLOBALS['queries'][]=$sql; }
function db_update($table,$data,$where,$params) { $GLOBALS['updates'][]=$data; }
$queries=[];$updates=[];
foreach (['new_comment','status_changed','ticket_updated'] as $type) {
    if (!is_action_required_notification($type,['action_required'=>true]) || is_action_required_notification($type,[])) throw new RuntimeException('Explicit review state required for work events');
}
mark_ticket_notifications_read(10,2);
mark_notification_read(12,2);
mark_all_notifications_read(2);
foreach($queries as $sql) if(str_contains($sql,'is_resolved')) throw new RuntimeException('Reading must never imply completing the required action');
foreach($updates as $data) if(array_key_exists('is_resolved',$data)) throw new RuntimeException('Reading must not change review state');
echo "Notification reading remains independent of review state\n";
