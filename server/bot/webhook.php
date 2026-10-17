<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

$expectedSecret=(string)(cfg()['telegram']['webhook_secret']??'');
$gotSecret=header_value('X-Telegram-Bot-Api-Secret-Token');
if($expectedSecret===''||!hash_equals($expectedSecret,$gotSecret)){http_response_code(403);exit('forbidden');}

$update=json_decode_assoc(raw_body());
$tg=cfg()['telegram'];$allowed=array_map('intval',$tg['allowed_user_ids']??[]);$device=(string)$tg['default_device_id'];
$userId=0;$chatId=0;$text='';$cb=null;$messageId=0;$document=null;
if(isset($update['message'])){
    $m=$update['message'];$userId=(int)($m['from']['id']??0);$chatId=(int)($m['chat']['id']??0);$messageId=(int)($m['message_id']??0);$text=trim((string)($m['text']??''));$document=$m['document']??null;
}elseif(isset($update['callback_query'])){
    $cb=$update['callback_query'];$userId=(int)($cb['from']['id']??0);$chatId=(int)($cb['message']['chat']['id']??0);$messageId=(int)($cb['message']['message_id']??0);
}
if(!in_array($userId,$allowed,true)){if($chatId){try{tg_send($chatId,'⛔ Unauthorized user.');}catch(Throwable $e){}}http_response_code(200);exit('ok');}

function qcmd(string $device,string $action,array $params,int $chatId,int $userId,?int $messageId=null,?string $view=null): void {
    queue_command($device,$action,$params,$chatId,$userId,$messageId,$view);
}
function edit_home(int $chatId,int $messageId): void { tg_edit($chatId,$messageId,"🏠 <b>PW Agent</b>\nOpenWrt + Passwall2 Remote Management",main_keyboard()); }

try{
    if($text==='/start'){
        session_clear($chatId,$userId,$device);
        tg_send($chatId,"🏠 <b>PW Agent</b>\nOpenWrt + Passwall2 Remote Management",main_keyboard());
        http_response_code(200);exit('ok');
    }

    if($cb!==null){
        $data=(string)($cb['data']??'');tg_answer_callback((string)$cb['id']);
        $preserveSession=str_starts_with($data,'ndpick|')||in_array($data,['nddelselectedq','nddelselected'],true);
        if(!$preserveSession)session_clear($chatId,$userId,$device);

        if($data==='home'){edit_home($chatId,$messageId);}
        elseif($data==='status'){qcmd($device,'system.status',[],$chatId,$userId,$messageId,'status');}
        elseif($data==='monitoring'){tg_edit($chatId,$messageId,v050_monitoring_summary($device),nav_row('home'));}
        elseif($data==='pw'){qcmd($device,'passwall2.status',[],$chatId,$userId,$messageId,'passwall');}
        elseif($data==='pwon'){qcmd($device,'passwall2.enable',[],$chatId,$userId,$messageId,'passwall');}
        elseif($data==='pwoff'){qcmd($device,'passwall2.disable',[],$chatId,$userId,$messageId,'passwall');}
        elseif($data==='pwrestart'){qcmd($device,'passwall2.restart',[],$chatId,$userId,$messageId,'passwall');}
        elseif($data==='pwchange'){qcmd($device,'passwall2.nodes.list',[],$chatId,$userId,$messageId,'select_main');}
        elseif($data==='pwlog'){qcmd($device,'passwall2.log',[],$chatId,$userId,$messageId,'log');}
        elseif(str_starts_with($data,'pwsel|')){
            $id=substr($data,6);if(!preg_match('/^[A-Za-z0-9_-]+$/',$id))throw new RuntimeException('Invalid node');
            qcmd($device,'passwall2.node.select',['id'=>$id],$chatId,$userId,$messageId,'passwall');
        }

        elseif($data==='nodes'){qcmd($device,'passwall2.nodes.list',[],$chatId,$userId,$messageId,'nodes');}
        elseif(str_starts_with($data,'nd|')){
            $id=substr($data,3);if(!preg_match('/^[A-Za-z0-9_-]+$/',$id))throw new RuntimeException('Invalid node');
            qcmd($device,'passwall2.nodes.list',['target'=>$id],$chatId,$userId,$messageId,'node_detail');
        }
        elseif(str_starts_with($data,'nt|')){
            $id=substr($data,3);if(!preg_match('/^[A-Za-z0-9_-]+$/',$id))throw new RuntimeException('Invalid node');
            qcmd($device,'passwall2.node.test',['id'=>$id],$chatId,$userId,$messageId,'node_test');
        }
        elseif($data==='nall'){qcmd($device,'passwall2.nodes.test_all',[],$chatId,$userId,$messageId,'nodes_test_all');}
        elseif($data==='nrank'){qcmd($device,'passwall2.nodes.relay_ranking',[],$chatId,$userId,$messageId,'relay_ranking');}
        elseif($data==='nddead'){qcmd($device,'passwall2.nodes.dead_candidates',[],$chatId,$userId,$messageId,'dead_candidates');}
        elseif($data==='ndpruneq'){
            $kb=[[['text'=>'🗑 Yes, Delete Relay-Failed','callback_data'=>'ndprune']],[['text'=>'⬅️ Cancel','callback_data'=>'nodes'],['text'=>'🏠 Home','callback_data'=>'home']]];
            tg_edit($chatId,$messageId,"⚠️ <b>Delete all nodes that fail HTTP Relay?</b>\n\nNodes still referenced by Shunt or other configuration are protected and will be skipped.",$kb);
        }
        elseif($data==='ndprune'){qcmd($device,'passwall2.nodes.prune_dead',[],$chatId,$userId,$messageId,'node_cleanup');}
        elseif($data==='ndmulti'){
            session_set($chatId,$userId,$device,'node_delete_select',['menu_message_id'=>$messageId,'selected'=>[]]);
            qcmd($device,'passwall2.nodes.list',['selected_ids'=>[]],$chatId,$userId,$messageId,'delete_select');
        }
        elseif(str_starts_with($data,'ndpick|')){
            $id=substr($data,7);if(!preg_match('/^[A-Za-z0-9_-]+$/',$id))throw new RuntimeException('Invalid node');
            $ss=session_get($chatId,$userId,$device);$selected=[];
            if(is_array($ss)&&($ss['state']??'')==='node_delete_select')$selected=is_array($ss['context']['selected']??null)?array_values(array_map('strval',$ss['context']['selected'])):[];
            if(in_array($id,$selected,true))$selected=array_values(array_filter($selected,fn($x)=>$x!==$id));else $selected[]=$id;
            session_set($chatId,$userId,$device,'node_delete_select',['menu_message_id'=>$messageId,'selected'=>$selected]);
            qcmd($device,'passwall2.nodes.list',['selected_ids'=>$selected],$chatId,$userId,$messageId,'delete_select');
        }
        elseif($data==='nddelselectedq'){
            $ss=session_get($chatId,$userId,$device);$selected=is_array($ss['context']['selected']??null)?array_values(array_map('strval',$ss['context']['selected'])):[];
            if(!$selected)throw new RuntimeException('No nodes selected');
            $kb=[[['text'=>'🗑 Delete '.count($selected).' Selected Nodes','callback_data'=>'nddelselected']],[['text'=>'⬅️ Cancel','callback_data'=>'nodes'],['text'=>'🏠 Home','callback_data'=>'home']]];
            tg_edit($chatId,$messageId,"⚠️ <b>Delete selected nodes?</b>\n\nSelected: <b>".count($selected)."</b>\nReferenced nodes are protected automatically.",$kb);
        }
        elseif($data==='nddelselected'){
            $ss=session_get($chatId,$userId,$device);$selected=is_array($ss['context']['selected']??null)?array_values(array_map('strval',$ss['context']['selected'])):[];
            if(!$selected)throw new RuntimeException('No nodes selected');session_clear($chatId,$userId,$device);
            qcmd($device,'passwall2.nodes.delete_many',['ids'=>$selected],$chatId,$userId,$messageId,'node_cleanup');
        }
        elseif($data==='nddelallq'){
            $kb=[[['text'=>'🗑 Yes, Delete All Proxy Nodes','callback_data'=>'nddelall']],[['text'=>'⬅️ Cancel','callback_data'=>'nodes'],['text'=>'🏠 Home','callback_data'=>'home']]];
            tg_edit($chatId,$messageId,"⚠️ <b>Delete all proxy nodes?</b>\n\nShunt nodes are never included. Proxy nodes that are still referenced are protected and skipped.",$kb);
        }
        elseif($data==='nddelall'){qcmd($device,'passwall2.nodes.delete_all',[],$chatId,$userId,$messageId,'node_cleanup');}
        elseif($data==='nadd'){
            session_set($chatId,$userId,$device,'await_node_link',['menu_message_id'=>$messageId]);
            tg_edit($chatId,$messageId,"➕ <b>Add Node Link(s)</b>\n\nSend one node link, or paste multiple Passwall2-supported links with one link per line.\n\nVLESS, VMess, Trojan, Shadowsocks, Hysteria2, TUIC and other formats understood by Passwall2 are accepted. The message will be deleted after it is queued.",nav_row('nodes'));
        }
        elseif($data==='subs'){qcmd($device,'passwall2.subscriptions.list',[],$chatId,$userId,$messageId,'subscriptions');}
        elseif($data==='subadd'){
            session_set($chatId,$userId,$device,'await_subscription_name',['menu_message_id'=>$messageId]);
            tg_edit($chatId,$messageId,"📡 <b>Add V2Ray Subscription</b>\n\nSend a short name for this subscription (1-80 characters).",nav_row('subs'));
        }
        elseif($data==='subupall'){qcmd($device,'passwall2.subscription.update',['id'=>'all'],$chatId,$userId,$messageId,'subscriptions');}
        elseif(str_starts_with($data,'subup|')){
            $id=substr($data,6);if(!preg_match('/^[A-Za-z0-9_-]+$/',$id))throw new RuntimeException('Invalid subscription');
            qcmd($device,'passwall2.subscription.update',['id'=>$id],$chatId,$userId,$messageId,'subscriptions');
        }
        elseif(str_starts_with($data,'subdelq|')){
            $id=substr($data,8);if(!preg_match('/^[A-Za-z0-9_-]+$/',$id))throw new RuntimeException('Invalid subscription');
            $kb=[[['text'=>'🗑 Delete Subscription','callback_data'=>'subdel|'.$id]],[['text'=>'⬅️ Cancel','callback_data'=>'subs'],['text'=>'🏠 Home','callback_data'=>'home']]];
            tg_edit($chatId,$messageId,"⚠️ <b>Delete subscription?</b>\n\nIts nodes are also removed when safe. Nodes referenced elsewhere are preserved.",$kb);
        }
        elseif(str_starts_with($data,'subdel|')){
            $id=substr($data,7);if(!preg_match('/^[A-Za-z0-9_-]+$/',$id))throw new RuntimeException('Invalid subscription');
            qcmd($device,'passwall2.subscription.delete',['id'=>$id],$chatId,$userId,$messageId,'subscriptions');
        }
        elseif(str_starts_with($data,'ndsel|')){
            $id=substr($data,6);if(!preg_match('/^[A-Za-z0-9_-]+$/',$id))throw new RuntimeException('Invalid node');
            qcmd($device,'passwall2.node.select',['id'=>$id],$chatId,$userId,$messageId,'passwall');
        }
        elseif(str_starts_with($data,'nddelq|')){
            $id=substr($data,7);if(!preg_match('/^[A-Za-z0-9_-]+$/',$id))throw new RuntimeException('Invalid node');
            $kb=[[['text'=>'🗑 Yes, Delete','callback_data'=>'nddel|'.$id]],[['text'=>'⬅️ Cancel','callback_data'=>'nd|'.$id],['text'=>'🏠 Home','callback_data'=>'home']]];
            tg_edit($chatId,$messageId,"⚠️ <b>Delete Node?</b>\n\nDeletion is refused automatically if the node is still referenced by Shunt or another configuration.",$kb);
        }
        elseif(str_starts_with($data,'nddel|')){
            $id=substr($data,6);if(!preg_match('/^[A-Za-z0-9_-]+$/',$id))throw new RuntimeException('Invalid node');
            qcmd($device,'passwall2.node.delete',['id'=>$id],$chatId,$userId,$messageId,'node_delete');
        }

        elseif($data==='shunt'){qcmd($device,'passwall2.shunt.list',[],$chatId,$userId,$messageId,'shunt');}
        elseif(str_starts_with($data,'shr|')){
            $p=explode('|',$data,3);if(count($p)!==3)throw new RuntimeException('Invalid Shunt selection');[$x,$sid,$rid]=$p;
            if(!preg_match('/^[A-Za-z0-9_-]+$/',$sid)||($rid!=='__default__'&&!preg_match('/^[A-Za-z0-9_-]+$/',$rid)))throw new RuntimeException('Invalid Shunt selection');
            qcmd($device,'passwall2.shunt.options',['shunt_id'=>$sid,'rule_id'=>$rid],$chatId,$userId,$messageId,'shunt_options');
        }
        elseif(str_starts_with($data,'shv|')){
            $p=explode('|',$data,4);if(count($p)!==4)throw new RuntimeException('Invalid Shunt target');[$x,$sid,$rid,$target]=$p;
            foreach([$sid,$target] as $v){if(!preg_match('/^[A-Za-z0-9_-]+$/',$v))throw new RuntimeException('Invalid Shunt target');}
            if($rid==='__default__')qcmd($device,'passwall2.shunt.set_default',['shunt_id'=>$sid,'target'=>$target],$chatId,$userId,$messageId,'shunt');
            else {if(!preg_match('/^[A-Za-z0-9_-]+$/',$rid))throw new RuntimeException('Invalid Shunt rule');qcmd($device,'passwall2.shunt.set',['shunt_id'=>$sid,'rule_id'=>$rid,'target'=>$target],$chatId,$userId,$messageId,'shunt');}
        }

        elseif($data==='failover'){qcmd($device,'passwall2.failover.list',[],$chatId,$userId,$messageId,'failover');}
        elseif(str_starts_with($data,'fo|')){
            $sid=substr($data,3);if(!preg_match('/^[A-Za-z0-9_-]+$/',$sid))throw new RuntimeException('Invalid Shunt');
            qcmd($device,'passwall2.failover.list',['focus'=>$sid],$chatId,$userId,$messageId,'failover_detail');
        }
        elseif(str_starts_with($data,'fotoggle|')){
            $p=explode('|',$data,3);if(count($p)!==3)throw new RuntimeException('Invalid Failover action');[$x,$sid,$en]=$p;
            if(!preg_match('/^[A-Za-z0-9_-]+$/',$sid)||!in_array($en,['0','1'],true))throw new RuntimeException('Invalid Failover action');
            qcmd($device,'passwall2.failover.configure',['shunt_id'=>$sid,'enabled'=>$en==='1','focus'=>$sid],$chatId,$userId,$messageId,'failover_detail');
        }
        elseif(str_starts_with($data,'foar|')){
            $p=explode('|',$data,3);if(count($p)!==3)throw new RuntimeException('Invalid Auto-return action');[$x,$sid,$en]=$p;
            if(!preg_match('/^[A-Za-z0-9_-]+$/',$sid)||!in_array($en,['0','1'],true))throw new RuntimeException('Invalid Auto-return action');
            qcmd($device,'passwall2.failover.configure',['shunt_id'=>$sid,'auto_return'=>$en==='1','focus'=>$sid],$chatId,$userId,$messageId,'failover_detail');
        }
        elseif(str_starts_with($data,'forun|')){
            $sid=substr($data,6);if(!preg_match('/^[A-Za-z0-9_-]+$/',$sid))throw new RuntimeException('Invalid Shunt');
            qcmd($device,'passwall2.failover.run',['shunt_id'=>$sid],$chatId,$userId,$messageId,'failover_run');
        }

        elseif($data==='network'){tg_edit($chatId,$messageId,"🔌 <b>Network</b>\nChoose what you want to manage:",network_keyboard());}
        elseif($data==='wan'){qcmd($device,'network.wan',[],$chatId,$userId,$messageId,'wan');}
        elseif($data==='wanreconnect'){qcmd($device,'network.wan.reconnect',[],$chatId,$userId,$messageId,'wan');}
        elseif($data==='inettest'){qcmd($device,'network.internet_test',[],$chatId,$userId,$messageId,'internet_test');}
        elseif($data==='routes'){qcmd($device,'network.routes',[],$chatId,$userId,$messageId,'routes');}
        elseif($data==='dnsinfo'){qcmd($device,'network.dns',[],$chatId,$userId,$messageId,'dns');}
        elseif($data==='leases'){qcmd($device,'network.dhcp_leases',[],$chatId,$userId,$messageId,'leases');}
        elseif($data==='wireguard'){qcmd($device,'network.wireguard',[],$chatId,$userId,$messageId,'wireguard');}
        elseif($data==='ifaces'){qcmd($device,'system.interfaces',[],$chatId,$userId,$messageId,'interfaces');}
        elseif($data==='clients'){qcmd($device,'system.clients',[],$chatId,$userId,$messageId,'clients');}
        elseif($data==='wifimenu'){tg_edit($chatId,$messageId,"📶 <b>Wi-Fi</b>\nManage SSIDs, radios, channels and associated stations:",wifi_keyboard());}
        elseif($data==='wifi'){qcmd($device,'wifi.list',[],$chatId,$userId,$messageId,'wifi');}
        elseif($data==='wfradios'){qcmd($device,'wifi.radios',[],$chatId,$userId,$messageId,'wifi_radios');}
        elseif($data==='wfstations'){qcmd($device,'wifi.stations',[],$chatId,$userId,$messageId,'wifi_stations');}
        elseif(str_starts_with($data,'wfrtoggle|')){
            $p=explode('|',$data,3);if(count($p)!==3)throw new RuntimeException('Invalid radio action');[$x,$sec,$en]=$p;
            if(!preg_match('/^(?:[A-Za-z0-9_-]+|@[A-Za-z0-9_-]+\[[0-9]+\])$/',$sec)||!in_array($en,['0','1'],true))throw new RuntimeException('Invalid radio action');
            qcmd($device,'wifi.radio.toggle',['section'=>$sec,'enable'=>$en==='1'],$chatId,$userId,$messageId,'wifi_radios');
        }
        elseif(str_starts_with($data,'wfrchan|')){
            $sec=substr($data,8);if(!preg_match('/^(?:[A-Za-z0-9_-]+|@[A-Za-z0-9_-]+\[[0-9]+\])$/',$sec))throw new RuntimeException('Invalid radio');
            session_set($chatId,$userId,$device,'await_wifi_channel',['section'=>$sec,'menu_message_id'=>$messageId]);
            tg_edit($chatId,$messageId,"📶 <b>Change Wi-Fi Channel</b>\n\nSend a channel number, or <code>auto</code>.",nav_row('wfradios'));
        }
        elseif(str_starts_with($data,'wf|')){
            $sec=substr($data,3);if(!preg_match('/^(?:[A-Za-z0-9_-]+|@[A-Za-z0-9_-]+\[[0-9]+\])$/',$sec))throw new RuntimeException('Invalid Wi-Fi interface');
            qcmd($device,'wifi.list',['target'=>$sec],$chatId,$userId,$messageId,'wifi_detail');
        }
        elseif(str_starts_with($data,'wfssid|')){
            $sec=substr($data,7);if(!preg_match('/^(?:[A-Za-z0-9_-]+|@[A-Za-z0-9_-]+\[[0-9]+\])$/',$sec))throw new RuntimeException('Invalid Wi-Fi interface');
            session_set($chatId,$userId,$device,'await_wifi_ssid',['section'=>$sec,'menu_message_id'=>$messageId]);
            tg_edit($chatId,$messageId,"✏️ <b>Change Wi-Fi SSID</b>\n\nSend the new Wi-Fi name (1-32 bytes).",nav_row('wf|'.$sec));
        }
        elseif(str_starts_with($data,'wfpw|')){
            $sec=substr($data,5);if(!preg_match('/^(?:[A-Za-z0-9_-]+|@[A-Za-z0-9_-]+\[[0-9]+\])$/',$sec))throw new RuntimeException('Invalid Wi-Fi interface');
            session_set($chatId,$userId,$device,'await_wifi_password',['section'=>$sec,'menu_message_id'=>$messageId]);
            tg_edit($chatId,$messageId,"🔐 <b>Change Wi-Fi Password</b>\n\nSend the new password (8-63 characters).\nThe password message will be deleted after it is queued.",nav_row('wf|'.$sec));
        }
        elseif($data==='wfreload'){qcmd($device,'wifi.reload',[],$chatId,$userId,$messageId,'wifi');}

        elseif($data==='firewall'){tg_edit($chatId,$messageId,"🛡 <b>Firewall</b>\nView zones and control configured port forwards:",firewall_keyboard());}
        elseif($data==='fwzones'){qcmd($device,'firewall.zones',[],$chatId,$userId,$messageId,'firewall_zones');}
        elseif($data==='fwredirects'){qcmd($device,'firewall.redirects',[],$chatId,$userId,$messageId,'firewall_redirects');}
        elseif(str_starts_with($data,'fwrt|')){
            $p=explode('|',$data,3);if(count($p)!==3)throw new RuntimeException('Invalid port forward action');[$x,$sec,$en]=$p;
            if(!preg_match('/^(?:[A-Za-z0-9_-]+|@[A-Za-z0-9_-]+\[[0-9]+\])$/',$sec)||!in_array($en,['0','1'],true))throw new RuntimeException('Invalid port forward action');
            qcmd($device,'firewall.redirect.toggle',['section'=>$sec,'enable'=>$en==='1'],$chatId,$userId,$messageId,'firewall_redirects');
        }

        elseif($data==='systemmenu'){tg_edit($chatId,$messageId,"⚙️ <b>System</b>\nOpenWrt system tools and diagnostics:",system_keyboard());}
        elseif($data==='systime'){qcmd($device,'system.time',[],$chatId,$userId,$messageId,'system_time');}
        elseif($data==='syslog'){qcmd($device,'system.log',[],$chatId,$userId,$messageId,'system_log');}
        elseif($data==='klog'){qcmd($device,'system.klog',[],$chatId,$userId,$messageId,'kernel_log');}
        elseif($data==='procs'){qcmd($device,'system.processes',[],$chatId,$userId,$messageId,'processes');}
        elseif($data==='services'){qcmd($device,'system.services',[],$chatId,$userId,$messageId,'services');}
        elseif($data==='packages'){qcmd($device,'system.packages',[],$chatId,$userId,$messageId,'packages');}
        elseif($data==='pkgupdates'){qcmd($device,'system.package_updates',[],$chatId,$userId,$messageId,'package_updates');}
        elseif($data==='agentupdate'){qcmd($device,'agent.update.check',[],$chatId,$userId,$messageId,'agent_update');}
        elseif($data==='agentupq'){
            $kb=[[['text'=>'⬇️ Yes, Install Update','callback_data'=>'agentupdateapply']],[['text'=>'⬅️ Cancel','callback_data'=>'systemmenu'],['text'=>'🏠 Home','callback_data'=>'home']]];
            tg_edit($chatId,$messageId,"⚠️ <b>Update PW Agent?</b>\n\nThe signed connection stays unchanged. The Agent binary and LuCI integration will be replaced and the Agent restarted automatically.",$kb);
        }
        elseif($data==='agentupdateapply'){qcmd($device,'agent.update.apply',[],$chatId,$userId,$messageId,'agent_update_apply');}
        elseif(str_starts_with($data,'svcr|')){
            $name=substr($data,5);if(!preg_match('/^[A-Za-z0-9_-]+$/',$name))throw new RuntimeException('Invalid service');
            qcmd($device,'system.service.restart',['name'=>$name],$chatId,$userId,$messageId,'services');
        }

        elseif($data==='diag'){tg_edit($chatId,$messageId,"🧪 <b>Diagnostics</b>\nRun safe network diagnostics from the router:",diag_keyboard());}
        elseif($data==='agentdiag'){qcmd($device,'agent.diagnose',[],$chatId,$userId,$messageId,'agent_diagnose');}
        elseif($data==='diagping'){
            session_set($chatId,$userId,$device,'await_diag_ping',['menu_message_id'=>$messageId]);
            tg_edit($chatId,$messageId,"📍 <b>Ping Host</b>\n\nSend a hostname or IP address.",nav_row('diag'));
        }
        elseif($data==='diagtrace'){
            session_set($chatId,$userId,$device,'await_diag_trace',['menu_message_id'=>$messageId]);
            tg_edit($chatId,$messageId,"🛰 <b>Traceroute</b>\n\nSend a hostname or IP address.",nav_row('diag'));
        }
        elseif($data==='rebootq'){
            $kb=[[['text'=>'♻️ Yes, Reboot','callback_data'=>'reboot']],[['text'=>'⬅️ Cancel','callback_data'=>'network'],['text'=>'🏠 Home','callback_data'=>'home']]];
            tg_edit($chatId,$messageId,"⚠️ <b>Reboot Router?</b>\n\nThe router and Wi-Fi will be unavailable briefly.",$kb);
        }
        elseif($data==='reboot'){qcmd($device,'system.reboot',[],$chatId,$userId,$messageId,'reboot');}

        elseif($data==='backup'){tg_edit($chatId,$messageId,"💾 <b>OpenWrt Backup</b>\n\nCreate a standard OpenWrt configuration backup and receive the file directly in Telegram, or restore a backup you already have.",backup_keyboard());}
        elseif($data==='bkcreate'){qcmd($device,'system.backup.create',[],$chatId,$userId,$messageId,'backup_create');}
        elseif($data==='bkrestore'){
            session_set($chatId,$userId,$device,'await_backup_file',['menu_message_id'=>$messageId]);
            tg_edit($chatId,$messageId,"📥 <b>Restore OpenWrt Backup</b>\n\nSend the <code>.tar.gz</code> backup file as a Telegram document.\nMaximum size: 8 MiB.\n\nThe router validates the archive before restoring it.",nav_row('backup'));
        }
        else { edit_home($chatId,$messageId); }
        http_response_code(200);exit('ok');
    }

    $session=session_get($chatId,$userId,$device);
    if($session!==null){
        $state=$session['state'];$ctx=$session['context'];$menuId=(int)($ctx['menu_message_id']??0);
        if($state==='await_node_link'){
            $lines=preg_split('/\r?\n/',trim($text))?:[];$links=[];$valid=true;
            foreach($lines as $line){$line=trim($line);if($line==='')continue;if(!preg_match('/^(vless|vmess|trojan|ss|ssr|socks|socks5|hysteria2|hy2|tuic|naive|http|https|anytls|ssd):\/\//i',$line)){$valid=false;break;}$links[]=$line;}
            if(!$valid||!$links){tg_send($chatId,'❌ Every non-empty line must be a supported Passwall2 node link.',nav_row('nodes'));}
            else{session_clear($chatId,$userId,$device);qcmd($device,'passwall2.node.add_link',['link'=>implode("\n",$links)],$chatId,$userId,$menuId?:null,'nodes');tg_delete($chatId,$messageId);}
        }
        elseif($state==='await_subscription_name'){
            $name=trim($text);if($name===''||strlen($name)>80){tg_send($chatId,'❌ Subscription name must be 1-80 bytes.',nav_row('subs'));}
            else{session_set($chatId,$userId,$device,'await_subscription_url',['menu_message_id'=>$menuId,'remark'=>$name]);tg_edit($chatId,$menuId,"📡 <b>Add V2Ray Subscription</b>\nName: <b>".h($name)."</b>\n\nNow send the HTTP/HTTPS subscription URL. The URL message will be deleted after it is queued.",nav_row('subs'));}
        }
        elseif($state==='await_subscription_url'){
            $url=trim($text);$remark=(string)($ctx['remark']??'');
            if(!preg_match('#^https?://#i',$url)||strlen($url)>4096){tg_send($chatId,'❌ Send a valid HTTP/HTTPS subscription URL.',nav_row('subs'));}
            else{session_clear($chatId,$userId,$device);qcmd($device,'passwall2.subscription.add',['remark'=>$remark,'url'=>$url],$chatId,$userId,$menuId?:null,'subscriptions');tg_delete($chatId,$messageId);}
        }
        elseif($state==='await_wifi_ssid'){
            $sec=(string)($ctx['section']??'');session_clear($chatId,$userId,$device);qcmd($device,'wifi.set_ssid',['section'=>$sec,'ssid'=>$text,'target'=>$sec],$chatId,$userId,$menuId?:null,'wifi_detail');tg_delete($chatId,$messageId);
        }
        elseif($state==='await_wifi_password'){
            $sec=(string)($ctx['section']??'');session_clear($chatId,$userId,$device);qcmd($device,'wifi.set_password',['section'=>$sec,'password'=>$text,'target'=>$sec],$chatId,$userId,$menuId?:null,'wifi_detail');tg_delete($chatId,$messageId);
        }
        elseif($state==='await_wifi_channel'){
            $sec=(string)($ctx['section']??'');$channel=strtolower(trim($text));
            if($channel!=='auto'&&!preg_match('/^[0-9]{1,3}$/',$channel)){tg_send($chatId,'❌ Send a channel number or auto.',nav_row('wfradios'));}
            else{session_clear($chatId,$userId,$device);qcmd($device,'wifi.radio.channel',['section'=>$sec,'channel'=>$channel],$chatId,$userId,$menuId?:null,'wifi_radios');tg_delete($chatId,$messageId);}
        }
        elseif($state==='await_diag_ping'){
            $target=trim($text);if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,252}$/',$target)){tg_send($chatId,'❌ Invalid hostname or IP.',nav_row('diag'));}
            else{session_clear($chatId,$userId,$device);qcmd($device,'diag.ping',['target'=>$target],$chatId,$userId,$menuId?:null,'diag_ping');tg_delete($chatId,$messageId);}
        }
        elseif($state==='await_diag_trace'){
            $target=trim($text);if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,252}$/',$target)){tg_send($chatId,'❌ Invalid hostname or IP.',nav_row('diag'));}
            else{session_clear($chatId,$userId,$device);qcmd($device,'diag.traceroute',['target'=>$target],$chatId,$userId,$menuId?:null,'diag_trace');tg_delete($chatId,$messageId);}
        }
        elseif($state==='await_backup_file'){
            if(!is_array($document)){
                tg_send($chatId,'📎 Please send the OpenWrt backup as a Telegram document.',nav_row('backup'));
            }else{
                $size=(int)($document['file_size']??0);if($size<=0||$size>8388608)throw new RuntimeException('Backup must be between 1 byte and 8 MiB');
                $fileId=(string)($document['file_id']??'');$name=(string)($document['file_name']??'backup.tar.gz');if($fileId==='')throw new RuntimeException('Invalid Telegram document');
                $download=tg_download_document($fileId,8388608);$raw=$download['bytes'];$sha=hash('sha256',$raw);
                session_clear($chatId,$userId,$device);
                qcmd($device,'system.backup.restore',['filename'=>$name,'sha256'=>$sha,'data_b64'=>base64_encode($raw)],$chatId,$userId,$menuId?:null,'backup_restore');
                unset($raw,$download);
            }
        }
        http_response_code(200);exit('ok');
    }

    if($text!=='' && preg_match('/^(vless|vmess|trojan|ss|ssr|socks|socks5|hysteria2|hy2|tuic|naive|http|https):\/\//i',$text)){
        qcmd($device,'passwall2.node.add_link',['link'=>$text],$chatId,$userId,null,'nodes');tg_delete($chatId,$messageId);
    }else{
        tg_send($chatId,"Use <code>/start</code> and the inline buttons to manage the router.\nNo other text commands are required.",main_keyboard());
    }
}catch(Throwable $e){
    if($chatId){try{tg_send($chatId,'❌ '.h($e->getMessage()),main_keyboard());}catch(Throwable $ignored){}}
}
http_response_code(200);echo 'ok';
