<?php
require dirname(__DIR__) . '/includes/api/app-handler.php';
class ReviewResponse extends RuntimeException { public array $data; public function __construct(array $data) { $this->data=$data; } }
function api_error($message,$status=400) { throw new ReviewResponse(['status'=>$status]); }
function api_success($data) { throw new ReviewResponse(['status'=>200,'payload'=>$data]); }
function require_csrf_token($json=false) { if (empty($GLOBALS['csrf'])) api_error('CSRF',403); }
function current_user() { return $GLOBALS['actor']; }
function get_json_input() { return $GLOBALS['input']; }
function notifications_table_exists() { return true; }
function column_exists($table,$column) { return true; }
function notification_is_visible_to_user($notification,$user) { return $notification['workspace']===$user['workspace'] && $notification['visible']; }
function db_fetch_one($sql,$params) { return $params===[42,3] ? $GLOBALS['notification'] : null; }
function db_update($table,$data,$where,$params) { $GLOBALS['writes'][]=compact('table','data','where','params'); }
function review_case($expected) { try { api_app_notification_review(); } catch (ReviewResponse $r) { if ($r->data['status']!==$expected) throw new RuntimeException('Unexpected status'); return; } throw new RuntimeException('Missing response'); }
$_SERVER['REQUEST_METHOD']='POST'; $csrf=true; $actor=['id'=>3,'workspace'=>7];
$input=['notification_id'=>42,'is_resolved'=>true]; $notification=['workspace'=>7,'visible'=>true]; $writes=[];
review_case(200);
if ($writes!==[['table'=>'notifications','data'=>['is_resolved'=>1],'where'=>'id = ? AND user_id = ?','params'=>[42,3]]]) throw new RuntimeException('Review must only change one recipient resolution, not read or ticket state');
$writes=[]; $notification['workspace']=8; review_case(404); // not visible to this recipient
$notification['workspace']=7; $notification['visible']=false; review_case(404);
$notification['visible']=true; $actor['id']=4; review_case(404);
$actor['id']=3; $csrf=false; review_case(403);
$csrf=true; $input['is_resolved']='yes'; review_case(422);
$_SERVER['REQUEST_METHOD']='GET'; review_case(405);
if ($writes) throw new RuntimeException('Rejected requests must not mutate');
echo "Notification review ownership, visibility, CSRF and independent state tests passed\n";
$router = file_get_contents(dirname(__DIR__) . '/includes/api/router.php');
$auth = file_get_contents(dirname(__DIR__) . '/includes/auth.php');
if (!str_contains($router, "'app-notification-review' => 'api_app_notification_review'")) throw new RuntimeException('Review endpoint must be routed');
if (str_contains($auth, "'app-notification-review' =>")) throw new RuntimeException('API tokens must not gain notification review');
$page = file_get_contents(dirname(__DIR__) . '/pages/notifications.php');
if (!str_contains($page, 'app-notification-review') || !str_contains($page, 'data-notif-review')) throw new RuntimeException('Notifications page must offer explicit review');
echo "Notification review wiring passed\n";
