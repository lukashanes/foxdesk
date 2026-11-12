<?php
require dirname(__DIR__) . '/includes/modules/work/time-ledger.php';
function build_ticket_visibility_filters_for_user($user) { return ['user' => $user['id']]; }
function build_ticket_where_clause($filter, &$params) { $params[] = 7; return ' WHERE t.visible_to = ?'; }
function check_ledger($ok,$message) { if (!$ok) { fwrite(STDERR,$message . "\n"); exit(1); } }
$params=[];
$where=time_ledger_where(['id'=>2,'role'=>'agent'],['start'=>'2026-09-01','end'=>'2026-09-30'],['time_scope'=>'team','agent'=>'9','client'=>'0','billable'=>'0','search'=>'invoice'],$params);
check_ledger(str_contains($where,'t.visible_to = ?'),'Ticket visibility must be reused');
check_ledger($params === [7,'2026-09-01','2026-09-30',2,0,0,'%invoice%','%invoice%','invoice'],'Agent cannot request another author or team scope');
check_ledger(str_contains($where,'tte.ended_at IS NOT NULL'),'Ledger excludes running work from saved totals');
check_ledger(time_ledger_page(['page_size'=>'10','p'=>99],21)['page']===3,'Page clamps to the actual last page');
check_ledger(time_ledger_page(['page_size'=>'all'],101)['count']===101,'All retains every record');
check_ledger(time_ledger_csv_value(' =HYPERLINK("bad")')[0]==="'",'CSV formula injection is neutralized');
check_ledger(time_ledger_csv_value('ordinary')==='ordinary','Ordinary CSV values are preserved');
$api=file_get_contents(dirname(__DIR__).'/includes/api/app-handler.php');
check_ledger(str_contains($api,"ticket_time_entry_resolve_update(\$entry, \$input)"),'Time edits preserve precision');
echo "Time ledger contract passed\n";
$router=file_get_contents(dirname(__DIR__).'/includes/api/router.php');
$auth=file_get_contents(dirname(__DIR__).'/includes/auth.php');
check_ledger(str_contains($router,"'app-update-time-entry' => 'api_app_update_time_entry'"),'Time edits must be routed');
check_ledger(str_contains($auth,"'app-update-time-entry' => 'time:write'"),'Time edits require the time:write token scope');
check_ledger(str_contains($api,'can_manage_time_entry($entry, $user)'),'Time edits reuse the shared time entry permission');
check_ledger(str_contains($api,'api_app_require_backdate_permission($input, $user)'),'Explicit historical times are staff-only');
$page=file_get_contents(dirname(__DIR__).'/pages/time.php');
check_ledger(str_contains($page,'time_ledger_model($user, $period, $request)'),'Time page uses the ledger read model');
check_ledger(str_contains($page,"array_map('time_ledger_csv_value'"),'CSV export neutralizes formulas');
check_ledger(!str_contains($page,'tenant'),'Self-hosted time page has no tenant scope');
check_ledger(str_contains(file_get_contents(dirname(__DIR__).'/index.php'),"case 'time':"),'Time route exists');
echo "Time ledger wiring passed\n";
