<?php
require dirname(__DIR__).'/includes/modules/clients/client-directory.php';
$clients=[]; $counts=[]; $minutes=[];
for($i=1;$i<=101;$i++){ $clients[]=['id'=>$i,'name'=>'Client '.$i,'is_active'=>$i%2,'contact_email'=>'','ico'=>'']; $counts[$i]=$i; $minutes[$i]=102-$i; }
$assert=static function($ok,$message){ if(!$ok)throw new RuntimeException($message); };
$first=client_directory_page($clients,['page_size'=>'20'],$counts,$minutes);
$assert(count($first['rows'])===20 && $first['count']===101 && $first['pages']===6,'101 clients must page without losing any');
$assert($first['rows'][0]['id']===101,'Highest activity must come first');
$last=client_directory_page($clients,['page_size'=>'20','p'=>99],$counts,$minutes);
$assert($last['page']===6 && count($last['rows'])===1 && $last['rows'][0]['id']===1,'Last page must clamp');
$assert(count(client_directory_page($clients,['page_size'=>'all'])['rows'])===101,'All option must include every client');
$assert(client_directory_page($clients,['search'=>'Client 101'])['count']===1,'Search must happen before pagination');
$assert(client_directory_page($clients,['state'=>'inactive'])['count']===50,'Inactive filter');
$assert(client_directory_page($clients,['sort'=>'time'],$counts,$minutes)['rows'][0]['id']===1,'Time ordering');
echo "Client directory 101-row pagination, search, filters and sorting passed\n";
