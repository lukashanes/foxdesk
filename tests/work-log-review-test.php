<?php
require dirname(__DIR__) . '/includes/ticket-time-functions.php';
function db_fetch_one($sql, $params = []) { return $GLOBALS['entry']; }
function get_ticket($id) { return ['id'=>8, 'title'=>'Fixture']; }
function get_ticket_participants($id, $actor) { return [2,3,4]; }
function get_staff_user_ids($actor) { return [2,4,5]; }
function get_user($id) { return ['first_name'=>'Test', 'last_name'=>'Agent']; }
function format_duration_minutes($n) { return "$n min"; }
function create_notifications_for_users($ids,$type,$ticket,$actor,$data=[],$push=true) { $GLOBALS['writes'][]=compact('ids','type','ticket','actor','data','push'); }
$writes=[]; $entry=['id'=>12,'ticket_id'=>8,'ended_at'=>null,'duration_minutes'=>12];
notify_saved_time_entry(12,1);
if ($writes) throw new RuntimeException('Running work must not request review');
$entry['ended_at']='2026-09-16 10:00:00'; $entry['duration_minutes']=0;
notify_saved_time_entry(12,1);
if ($writes) throw new RuntimeException('Zero work must not request review');
$entry['duration_minutes']=12;
notify_saved_time_entry(12,1);
if (count($writes)!==1 || $writes[0]['ids']!==[2,4] || $writes[0]['push']!==false
    || $writes[0]['data']['time_entry_id']!==12 || !$writes[0]['data']['action_required']) {
    throw new RuntimeException('Only participating staff may receive exact work-log review without precommit external dispatch');
}
echo "Saved-work review recipient, precision and transaction-side-effect contracts passed\n";
