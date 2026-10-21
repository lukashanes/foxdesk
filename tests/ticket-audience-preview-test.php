<?php
require __DIR__ . '/../includes/modules/tickets/ticket-audience-preview.php';
function can_see_ticket($ticket, $user) { return !empty($user['visible']); }
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
$ticket = ['id'=>9, 'tenant_id'=>1];
$actor = ['id'=>1, 'tenant_id'=>1, 'role'=>'agent', 'visible'=>true];
$client = ['id'=>2, 'tenant_id'=>1, 'role'=>'user', 'visible'=>true, 'is_active'=>1];
check(ticket_audience_preview_allowed($ticket, $actor, $client), 'Allowed client');
check(!ticket_audience_preview_allowed($ticket, $client, $actor), 'Client cannot preview');
check(!ticket_audience_preview_allowed($ticket, $actor, array_replace($client,['visible'=>false])), 'Ticket visibility');
check(!ticket_audience_preview_allowed($ticket, $actor, array_replace($client,['is_active'=>0])), 'Inactive target');
$comments = [['id'=>1,'is_internal'=>0], ['id'=>2,'is_internal'=>1]];
$attachments = [['id'=>1,'comment_id'=>null],['id'=>2,'comment_id'=>1],['id'=>3,'comment_id'=>2],['id'=>4,'comment_id'=>999]];
$model = ticket_audience_preview_filter($client,$comments,$attachments,[['id'=>1]]);
check(array_column($model['comments'],'id') === [1], 'Hide internal comments');
check(array_column($model['attachments'],'id') === [1,2], 'Hide internal and orphan attachments');
check($model['time_entries'] === [], 'Hide work from client');
$model = ticket_audience_preview_filter($actor,$comments,$attachments,[['id'=>1]]);
check(count($model['comments'])===2 && count($model['time_entries'])===1, 'Team context');
$page = file_get_contents(__DIR__.'/../pages/ticket-detail.php');
check(strpos($page, 'ticket-audience-preview-page.php') < strpos($page, 'mark_ticket_notifications_read'), 'Preview before notification writes');
$view = file_get_contents(__DIR__.'/../includes/components/ticket-audience-preview-page.php');
check(!str_contains($view, '<form') && !str_contains($view, '$_SESSION['), 'No mutation form or impersonation');
echo "Ticket audience preview: 10 checks passed\n";
