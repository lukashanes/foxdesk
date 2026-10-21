<?php
require dirname(__DIR__).'/includes/modules/work/time-ledger.php';
require dirname(__DIR__).'/includes/modules/reports/report-history.php';
$calls=[];
function report_template_column_exists($name) { return true; }
function db_fetch_one($sql,$params) { $GLOBALS['calls'][]=[$sql,$params];return ['count'=>101]; }
function db_fetch_all($sql,$params) { $GLOBALS['calls'][]=[$sql,$params];return [['id'=>3,'is_draft'=>0,'snapshot_kpis'=>'{"total_minutes":1572}'],['id'=>4,'is_draft'=>1,'snapshot_kpis'=>'{"total_minutes":60}']]; }
function get_active_report_template_share($id) { $GLOBALS['shares'][]=$id;return null; }
function create_report_template_share() { throw new RuntimeException('A read must not create a public share'); }
$model=report_history_model(['search'=>'Atlas','draft'=>'0','page_size'=>'10','p'=>11]);
foreach($calls as [$sql,$params]) {
    if(str_contains($sql,'tenant') || $params!==['%Atlas%','%Atlas%',0]) throw new RuntimeException('Count and rows must use identical filter constraints');
}
if(!str_contains($calls[1][0],'LIMIT 10 OFFSET 100')) throw new RuntimeException('101 reports must page without dropping the last record');
if($model['paging']['pages']!==11 || $model['rows'][0]['share_token']!==null || $GLOBALS['shares']!==[3]) throw new RuntimeException('Missing shares remain absent; drafts are not published');
$source=file_get_contents(dirname(__DIR__).'/pages/admin/reports-list.php');
if($model['rows'][0]['saved_minutes']!==1572 || $model['rows'][1]['saved_minutes']!==null) throw new RuntimeException('Only published snapshot totals may be shown as saved report time');
$afterPost=substr($source,strpos($source,"require_once BASE_PATH . '/includes/modules/work/time-ledger.php'"));
if(str_contains($afterPost,'create_report_template_share(')) throw new RuntimeException('GET must not write public links');
echo "Report history filters, 101-row paging and read-only sharing passed\n";
