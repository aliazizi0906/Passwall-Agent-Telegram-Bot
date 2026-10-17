<?php
declare(strict_types=1);
require_once __DIR__ . '/hardening_v050.php';
require_once __DIR__ . '/platform_v060.php';

function cfg(): array {
    static $cfg;
    if ($cfg === null) {
        $path = __DIR__ . '/config.php';
        if (!is_file($path)) { http_response_code(500); exit('Missing server/config.php'); }
        $cfg = require $path;
    }
    return $cfg;
}

function app_version(): string { return '0.6.0'; }

function tg_validate_webapp_init_data(string $initData, int $maxAgeSeconds = 600): array {
    if ($initData === '' || strlen($initData) > 16384) throw new RuntimeException('Missing or invalid Telegram Mini App authentication data');
    parse_str($initData, $data);
    if (!is_array($data) || empty($data['hash']) || empty($data['auth_date']) || empty($data['user'])) throw new RuntimeException('Incomplete Telegram Mini App authentication data');
    $hash = strtolower((string)$data['hash']);
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) throw new RuntimeException('Invalid Telegram Mini App hash');
    $authDate = (int)$data['auth_date'];
    if ($authDate <= 0 || abs(time() - $authDate) > $maxAgeSeconds) throw new RuntimeException('Telegram Mini App session expired');
    $token = (string)(cfg()['telegram']['bot_token'] ?? '');
    if ($token === '') throw new RuntimeException('Telegram bot token is not configured');
    $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);

    // Telegram added the optional third-party `signature` field after the original HMAC flow.
    // Accept both official field sets for compatibility while always excluding `hash` itself.
    $valid = false;
    foreach ([false, true] as $excludeSignature) {
        $check = $data;
        unset($check['hash']);
        if ($excludeSignature) unset($check['signature']);
        ksort($check, SORT_STRING);
        $parts=[];
        foreach ($check as $k=>$v) {
            if (is_array($v)) $v=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $parts[]=$k.'='.(string)$v;
        }
        $calc=hash_hmac('sha256',implode("\n",$parts),$secretKey);
        if(hash_equals($calc,$hash)){ $valid=true; break; }
    }
    if(!$valid) throw new RuntimeException('Telegram Mini App signature validation failed');
    $user=json_decode((string)$data['user'],true);
    if(!is_array($user)||empty($user['id'])) throw new RuntimeException('Telegram Mini App user is invalid');
    return $user;
}

function db(): PDO {
    static $pdo;
    if ($pdo === null) {
        $c = cfg()['db'];
        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function ensure_session_table(): void {
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS pw_sessions (
        chat_id BIGINT NOT NULL,
        user_id BIGINT NOT NULL,
        device_id VARCHAR(64) NOT NULL,
        state VARCHAR(64) NOT NULL,
        context_json LONGTEXT NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (chat_id,user_id,device_id),
        INDEX idx_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function session_set(int $chatId, int $userId, string $deviceId, string $state, array $context = []): void {
    ensure_session_table();
    $st = db()->prepare('INSERT INTO pw_sessions (chat_id,user_id,device_id,state,context_json,updated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE state=VALUES(state),context_json=VALUES(context_json),updated_at=NOW()');
    $st->execute([$chatId,$userId,$deviceId,$state,json_encode($context, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}

function session_get(int $chatId, int $userId, string $deviceId): ?array {
    ensure_session_table();
    $st = db()->prepare('SELECT state,context_json,updated_at FROM pw_sessions WHERE chat_id=? AND user_id=? AND device_id=?');
    $st->execute([$chatId,$userId,$deviceId]);
    $row = $st->fetch();
    if (!$row) return null;
    $ctx = json_decode((string)$row['context_json'], true);
    return ['state'=>(string)$row['state'], 'context'=>is_array($ctx)?$ctx:[], 'updated_at'=>$row['updated_at']];
}

function session_clear(int $chatId, int $userId, string $deviceId): void {
    ensure_session_table();
    $st = db()->prepare('DELETE FROM pw_sessions WHERE chat_id=? AND user_id=? AND device_id=?');
    $st->execute([$chatId,$userId,$deviceId]);
}

function raw_body(): string {
    static $raw;
    if ($raw === null) $raw = file_get_contents('php://input') ?: '';
    return $raw;
}
function header_value(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$key]) ? trim((string)$_SERVER[$key]) : '';
}
function json_decode_assoc(string $raw): array {
    $v = json_decode($raw, true);
    return is_array($v) ? $v : [];
}
function response_json(array $data, ?string $secret = null, int $status = 200): never {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) $json = '{"ok":false,"error":"json_encode failed"}';
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($secret !== null) header('X-PW-Response-Signature: ' . hash_hmac('sha256', $json, $secret));
    echo $json; exit;
}

function authenticate_device(string $raw): array {
    $device = header_value('X-PW-Device');
    $ts = header_value('X-PW-Timestamp');
    $sig = strtolower(header_value('X-PW-Signature'));
    if ($device === '' || $ts === '' || $sig === '') response_json(['ok'=>false,'error'=>'unauthorized'], null, 401);
    try {
        ensure_v060_schema();
        if (!v060_device_enabled($device)) response_json(['ok'=>false,'error'=>'device disabled'], null, 403);
        $secret = v060_device_secret($device);
    } catch (Throwable $e) {
        response_json(['ok'=>false,'error'=>'unauthorized'], null, 401);
    }
    if (!ctype_digit($ts) || abs(time()-(int)$ts)>120) response_json(['ok'=>false,'error'=>'stale timestamp'], null, 401);
    $expected = hash_hmac('sha256', $device."\n".$ts."\n".$raw, $secret);
    if (!hash_equals($expected,$sig)) response_json(['ok'=>false,'error'=>'bad signature'], null, 401);
    return ['device_id'=>$device,'secret'=>$secret];
}

function queue_command(string $deviceId, string $action, array $params, ?int $chatId, ?int $userId, ?int $messageId = null, ?string $view = null): int {
    ensure_v060_schema();
    if (!v060_device_enabled($deviceId)) throw new RuntimeException('Unknown or disabled device');
    v060_authorize_command($userId,$deviceId,$action);
    if ($messageId !== null && $messageId > 0) $params['__message_id'] = $messageId;
    if ($view !== null && $view !== '') $params['__view'] = $view;
    $paramsJson = ($params === []) ? '{}' : json_encode($params, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($paramsJson === false) throw new RuntimeException('Could not encode command parameters');
    $storedParams = v050_seal_params($deviceId,$action,$paramsJson);
    $idempotencyKey = bin2hex(random_bytes(16));
    $st = db()->prepare('INSERT INTO pw_commands (device_id,action,params_json,chat_id,user_id,idempotency_key,max_attempts) VALUES (?,?,?,?,?,?,3)');
    $st->execute([$deviceId,$action,$storedParams,$chatId,$userId,$idempotencyKey]);
    $id=(int)db()->lastInsertId();
    v060_audit_command_queued($id,$deviceId,$userId,$action,$params,$userId?'telegram':'system');
    return $id;
}

function tg_call(string $method, array $payload): array {
    $token = (string)cfg()['telegram']['bot_token'];
    $url = 'https://api.telegram.org/bot'.$token.'/'.$method;
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>45]);
    $raw = curl_exec($ch);
    if ($raw === false) { $e=curl_error($ch); curl_close($ch); throw new RuntimeException('Telegram: '.$e); }
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $data=json_decode((string)$raw,true);
    if ($code<200 || $code>=300 || !is_array($data) || empty($data['ok'])) throw new RuntimeException('Telegram HTTP '.$code.': '.$raw);
    return $data;
}

function reply_markup(?array $keyboard): ?string {
    if ($keyboard === null) return null;
    return json_encode(['inline_keyboard'=>$keyboard], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function tg_send(int $chatId, string $text, ?array $keyboard=null): int {
    $p=['chat_id'=>(string)$chatId,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>'true'];
    if (($rm=reply_markup($keyboard)) !== null) $p['reply_markup']=$rm;
    $r=tg_call('sendMessage',$p);
    return (int)($r['result']['message_id']??0);
}
function tg_edit(int $chatId, int $messageId, string $text, ?array $keyboard=null): void {
    $p=['chat_id'=>(string)$chatId,'message_id'=>(string)$messageId,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>'true'];
    if (($rm=reply_markup($keyboard)) !== null) $p['reply_markup']=$rm;
    try { tg_call('editMessageText',$p); } catch (Throwable $e) {
        if (!str_contains(strtolower($e->getMessage()), 'message is not modified')) throw $e;
    }
}
function tg_deliver(int $chatId, ?int $messageId, string $text, ?array $keyboard=null): void {
    if ($messageId && $messageId>0) {
        try { tg_edit($chatId,$messageId,$text,$keyboard); return; } catch (Throwable $e) {}
    }
    tg_send($chatId,$text,$keyboard);
}
function tg_answer_callback(string $id, string $text=''): void {
    $p=['callback_query_id'=>$id]; if ($text!=='') $p['text']=$text;
    try { tg_call('answerCallbackQuery',$p); } catch (Throwable $e) {}
}
function tg_delete(int $chatId, int $messageId): void {
    try { tg_call('deleteMessage',['chat_id'=>(string)$chatId,'message_id'=>(string)$messageId]); } catch (Throwable $e) {}
}
function tg_send_document(int $chatId, string $path, string $filename, string $caption='', ?array $keyboard=null): void {
    $p=['chat_id'=>(string)$chatId,'document'=>new CURLFile($path,'application/gzip',$filename),'caption'=>$caption,'parse_mode'=>'HTML'];
    if (($rm=reply_markup($keyboard)) !== null) $p['reply_markup']=$rm;
    tg_call('sendDocument',$p);
}
function tg_download_document(string $fileId, int $maxBytes=8388608): array {
    $r=tg_call('getFile',['file_id'=>$fileId]);
    $filePath=(string)($r['result']['file_path']??'');
    if ($filePath==='') throw new RuntimeException('Telegram did not return a file path');
    $url='https://api.telegram.org/file/bot'.cfg()['telegram']['bot_token'].'/'.$filePath;
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>60]);
    $raw=curl_exec($ch);
    if ($raw===false) { $e=curl_error($ch); curl_close($ch); throw new RuntimeException('Telegram download: '.$e); }
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code<200 || $code>=300) throw new RuntimeException('Telegram file download failed');
    if (strlen((string)$raw)>$maxBytes) throw new RuntimeException('Backup file exceeds the 8 MiB limit');
    return ['bytes'=>(string)$raw,'file_path'=>$filePath];
}

function h(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function short_text(string $s, int $n=30): string { return function_exists('mb_substr') ? mb_substr($s,0,$n) : substr($s,0,$n); }
function bytes_human($n): string {
    $n=(float)$n; if($n>=1073741824)return number_format($n/1073741824,2).' GiB'; if($n>=1048576)return number_format($n/1048576,1).' MiB'; if($n>=1024)return number_format($n/1024,1).' KiB'; return number_format($n,0).' B';
}
function pct($used,$total): string { $total=(float)$total; return $total<=0?'0%':number_format(((float)$used/$total)*100,1).'%'; }
function uptime_human(int $s): string { $d=intdiv($s,86400);$h=intdiv($s%86400,3600);$m=intdiv($s%3600,60); return ($d?"{$d}d ":'')."{$h}h {$m}m"; }
function nav_row(string $back='home'): array { return [[['text'=>'⬅️ Back','callback_data'=>$back],['text'=>'🏠 Home','callback_data'=>'home']]]; }
function append_nav(array $kb,string $back='home'): array { return array_merge($kb,nav_row($back)); }

function main_keyboard(): array {
    $kb=[
        [['text'=>'📊 Status','callback_data'=>'status'],['text'=>'🧱 Passwall2','callback_data'=>'pw']],
        [['text'=>'🌐 Nodes','callback_data'=>'nodes'],['text'=>'🧭 Shunt','callback_data'=>'shunt']],
        [['text'=>'🔌 Network','callback_data'=>'network'],['text'=>'📶 Wi-Fi','callback_data'=>'wifimenu']],
        [['text'=>'🛡 Firewall','callback_data'=>'firewall'],['text'=>'⚙️ System','callback_data'=>'systemmenu']],
        [['text'=>'🧪 Diagnostics','callback_data'=>'diag'],['text'=>'💾 Backup','callback_data'=>'backup']],
        [['text'=>'📈 Monitoring','callback_data'=>'monitoring']],
    ];
    $base=rtrim((string)(cfg()['base_url']??''),'/');
    if((cfg()['telegram']['terminal_enabled'] ?? true) && str_starts_with($base,'https://')) {
        $kb[]=[['text'=>'⌨️ SSH Console','web_app'=>['url'=>$base.'/terminal/']]];
    }
    return $kb;
}
function network_keyboard(): array {
    return append_nav([
        [['text'=>'🌍 WAN','callback_data'=>'wan'],['text'=>'✅ Internet Test','callback_data'=>'inettest']],
        [['text'=>'🔌 Interfaces','callback_data'=>'ifaces'],['text'=>'👥 Connected Devices','callback_data'=>'clients']],
        [['text'=>'🧾 DHCP Leases','callback_data'=>'leases'],['text'=>'🧭 Routes','callback_data'=>'routes']],
        [['text'=>'🔎 DNS','callback_data'=>'dnsinfo'],['text'=>'🔐 WireGuard','callback_data'=>'wireguard']],
        [['text'=>'🔄 Reconnect WAN','callback_data'=>'wanreconnect']],
    ],'home');
}
function wifi_keyboard(): array {
    return append_nav([
        [['text'=>'📡 Wi-Fi Networks','callback_data'=>'wifi'],['text'=>'📻 Radios','callback_data'=>'wfradios']],
        [['text'=>'👤 Wi-Fi Stations','callback_data'=>'wfstations'],['text'=>'🔄 Reload Wi-Fi','callback_data'=>'wfreload']],
    ],'home');
}
function firewall_keyboard(): array {
    return append_nav([
        [['text'=>'🧱 Zones','callback_data'=>'fwzones'],['text'=>'↪️ Port Forwards','callback_data'=>'fwredirects']],
    ],'home');
}
function system_keyboard(): array {
    return append_nav([
        [['text'=>'🕒 Time / NTP','callback_data'=>'systime'],['text'=>'📜 System Log','callback_data'=>'syslog']],
        [['text'=>'🧠 Kernel Log','callback_data'=>'klog'],['text'=>'📈 Processes','callback_data'=>'procs']],
        [['text'=>'🧩 Services','callback_data'=>'services'],['text'=>'📦 Packages','callback_data'=>'packages']],
        [['text'=>'⬆️ Package Updates','callback_data'=>'pkgupdates'],['text'=>'🔄 PW Agent Update','callback_data'=>'agentupdate']],
        [['text'=>'♻️ Reboot Router','callback_data'=>'rebootq']],
    ],'home');
}
function diag_keyboard(): array {
    return append_nav([
        [['text'=>'🔐 Agent Connection','callback_data'=>'agentdiag']],
        [['text'=>'📍 Ping Host','callback_data'=>'diagping'],['text'=>'🛰 Traceroute','callback_data'=>'diagtrace']],
    ],'home');
}
function backup_keyboard(): array {
    return append_nav([
        [['text'=>'📤 Create & Send Backup','callback_data'=>'bkcreate']],
        [['text'=>'📥 Restore Backup','callback_data'=>'bkrestore']],
    ],'home');
}
function passwall_keyboard(): array {
    return append_nav([
        [['text'=>'🔀 Change Main Node','callback_data'=>'pwchange'],['text'=>'🧭 Shunt Rules','callback_data'=>'shunt']],
        [['text'=>'🛟 Smart Failover','callback_data'=>'failover'],['text'=>'📡 Subscriptions','callback_data'=>'subs']],
        [['text'=>'✅ Enable','callback_data'=>'pwon'],['text'=>'⛔ Disable','callback_data'=>'pwoff']],
        [['text'=>'🔄 Restart','callback_data'=>'pwrestart'],['text'=>'📜 Recent Log','callback_data'=>'pwlog']],
    ],'home');
}

function metric_text($m): string {
    if (!is_array($m) || empty($m['ok'])) return '❌ '.h((string)($m['detail']??'failed'));
    if (isset($m['ms'])) return '✅ <b>'.number_format((float)$m['ms'],1).' ms</b>';
    return '✅ '.h((string)($m['detail']??'OK'));
}
function node_name_map(array $result): array { $m=$result['node_names']??[]; return is_array($m)?$m:[]; }
function target_name(string $id,array $map): string { if($id===''||$id==='_close')return 'Close'; return (string)($map[$id]??$id); }

function format_result(string $action, bool $ok, $result, string $error, array $params=[]): array {
    $view=(string)($params['__view']??'');
    if (!$ok) {
        $back = str_starts_with($action,'wifi.') ? 'wifimenu' : (str_starts_with($action,'firewall.')?'firewall':(str_starts_with($action,'network.')?'network':(str_starts_with($action,'diag.')||$action==='agent.diagnose'?'diag':(str_starts_with($action,'passwall2.failover')?'failover':(str_starts_with($action,'passwall2.subscription')||str_starts_with($action,'passwall2.subscriptions')?'subs':(str_starts_with($action,'passwall2.shunt')?'shunt':(str_starts_with($action,'passwall2.node')||str_starts_with($action,'passwall2.nodes')?'nodes':(str_starts_with($action,'system.')||str_starts_with($action,'agent.update')?'systemmenu':'home'))))))));
        return ['text'=>"❌ <b>Operation failed</b>\n\n".h($error),'keyboard'=>nav_row($back)];
    }

    if ($action==='system.status' && is_array($result)) {
        $mem=$result['memory']??[];$sto=$result['storage']??[];$board=$result['board']??[];$release=is_array($board['release']??null)?$board['release']:[];
        $wan=$result['wan']??[];$wanIp='';
        foreach (($wan['ipv4-address']??[]) as $ip) { if(is_array($ip)&&!empty($ip['address'])){$wanIp=(string)$ip['address'];break;} }
        $text="📊 <b>OpenWrt System Status</b>\n\n";
        $text.="CPU: <b>".number_format((float)($result['cpu_percent']??0),1)."%</b>\n";
        $text.="Load 1m: <code>".h((string)($result['load1']??0))."</code>\n";
        $text.="RAM: <b>".h(pct($mem['used_bytes']??0,$mem['total_bytes']??0))."</b> (".h(bytes_human($mem['used_bytes']??0))." / ".h(bytes_human($mem['total_bytes']??0)).")\n";
        $text.="Storage: <b>".h(pct($sto['used_bytes']??0,$sto['total_bytes']??0))."</b> (".h(bytes_human($sto['used_bytes']??0))." / ".h(bytes_human($sto['total_bytes']??0)).")\n";
        $text.="Uptime: <b>".h(uptime_human((int)($result['uptime_seconds']??0)))."</b>\n";
        if($wanIp!=='')$text.="WAN IPv4: <code>".h($wanIp)."</code>\n";
        $text.="\nOpenWrt: <code>".h((string)($release['version']??$release['description']??'Unknown'))."</code>\n";
        $text.="Kernel: <code>".h((string)($board['kernel']??'Unknown'))."</code>\n";
        $text.="Model: ".h((string)($board['model']??'Unknown'))."\n";
        $text.="Agent: <code>".h((string)($result['agent_version']??''))."</code>";
        return ['text'=>$text,'keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>'status']]],'home')];
    }

    if (in_array($action,['passwall2.status','passwall2.enable','passwall2.disable','passwall2.restart','passwall2.node.select'],true) && is_array($result)) {
        $enabled=!empty($result['enabled'])?'✅ Enabled':'⛔ Disabled';$running=!empty($result['running'])?'🟢 Running':'🔴 Stopped';
        $node=(string)($result['selected_remarks']??$result['selected_node']??'Unknown');
        return ['text'=>"🧱 <b>Passwall2</b>\n\n{$enabled}\n{$running}\nMain Node: <b>".h($node).'</b>','keyboard'=>passwall_keyboard()];
    }

    if ($action==='passwall2.log' && is_array($result)) {
        $lines=$result['lines']??[];$body=implode("\n",array_slice(is_array($lines)?$lines:[],-40));
        if($body==='')$body='No recent Passwall2 log entries.';
        if(strlen($body)>3400)$body=substr($body,-3400);
        return ['text'=>"📜 <b>Passwall2 Recent Log</b>\n<pre>".h($body).'</pre>','keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>'pwlog']]],'pw')];
    }

    if ($action==='passwall2.nodes.list' && is_array($result)) {
        $nodes=array_values(array_filter($result['nodes']??[],fn($n)=>is_array($n)&&empty($n['is_shunt'])));
        if ($view==='select_main') {
            $kb=[];$all=$result['nodes']??[];
            foreach($all as $n){ if(!is_array($n))continue;$id=(string)($n['id']??'');$name=(string)($n['remarks']??$id);if($id==='')continue;$label=(!empty($n['selected'])?'✅ ':'').short_text($name,34);$kb[]=[['text'=>$label,'callback_data'=>'pwsel|'.$id]]; }
            return ['text'=>"🔀 <b>Select Main Passwall2 Node</b>\n\nChoose a node or the Shunt routing node:",'keyboard'=>append_nav($kb,'pw')];
        }
        if ($view==='delete_select') {
            $selected=is_array($params['selected_ids']??null)?array_values(array_map('strval',$params['selected_ids'])):[];
            $selectedSet=array_fill_keys($selected,true);$kb=[];$lines=["🗑 <b>Select Nodes to Delete</b>",'Selected: <b>'.count($selected).'</b>',''];
            foreach($nodes as $n){if(!is_array($n))continue;$id=(string)($n['id']??'');$name=(string)($n['remarks']??$id);if($id==='')continue;$on=isset($selectedSet[$id]);$kb[]=[['text'=>($on?'✅ ':'☐ ').short_text($name,34),'callback_data'=>'ndpick|'.$id]];}
            if(!$nodes)$lines[]='No proxy nodes found.';
            if($selected)$kb[]=[['text'=>'🗑 Delete Selected ('.count($selected).')','callback_data'=>'nddelselectedq']];
            $kb[]=[['text'=>'✖️ Cancel Selection','callback_data'=>'nodes']];
            return ['text'=>implode("\n",$lines),'keyboard'=>append_nav($kb,'nodes')];
        }
        if ($view==='node_detail') {
            $target=(string)($params['target']??'');$found=null;
            foreach($nodes as $n){if((string)($n['id']??'')===$target){$found=$n;break;}}
            if(!$found)return ['text'=>'❌ Node no longer exists.','keyboard'=>nav_row('nodes')];
            $name=(string)($found['remarks']??$target);$active=!empty($found['selected'])?"\nStatus: ⭐ <b>Active Main Node</b>":'';
            $text="🌐 <b>".h($name)."</b>\nProtocol: <code>".h((string)($found['protocol']??'Unknown'))."</code>{$active}";
            $kb=[[['text'=>'🧪 Ping + TCP + Relay','callback_data'=>'nt|'.$target]],[['text'=>'⭐ Set as Main Node','callback_data'=>'ndsel|'.$target],['text'=>'🗑 Delete','callback_data'=>'nddelq|'.$target]]];
            return ['text'=>$text,'keyboard'=>append_nav($kb,'nodes')];
        }
        $lines=["🌐 <b>Passwall2 Nodes</b>",''];$kb=[];
        foreach($nodes as $n){$id=(string)($n['id']??'');$name=(string)($n['remarks']??$id);$star=!empty($n['selected'])?' ⭐':'';$lines[]='• <b>'.h($name).'</b>'.$star;if($id!=='')$kb[]=[['text'=>'⚙️ '.short_text($name,35),'callback_data'=>'nd|'.$id]];}
        if(count($nodes)===0)$lines[]='No proxy nodes found.';
        $kb[]=[['text'=>'➕ Add Link(s)','callback_data'=>'nadd'],['text'=>'📡 Subscriptions','callback_data'=>'subs']];
        $kb[]=[['text'=>'🧪 Test All','callback_data'=>'nall'],['text'=>'🏆 Relay Ranking','callback_data'=>'nrank']];
        $kb[]=[['text'=>'🧹 Dead Relay Scan','callback_data'=>'nddead'],['text'=>'☑️ Select & Delete','callback_data'=>'ndmulti']];
        $kb[]=[['text'=>'🗑 Delete All (Safe)','callback_data'=>'nddelallq']];
        return ['text'=>implode("\n",$lines),'keyboard'=>append_nav($kb,'home')];
    }

    if ($action==='passwall2.node.test' && is_array($result)) {
        $id=(string)($result['id']??'');$name=(string)($result['remarks']??$id);
        $text="🧪 <b>Node Test</b>\n<b>".h($name)."</b>\n\nICMP Ping: ".metric_text($result['ping']??null)."\nTCP Connect: ".metric_text($result['tcp']??null)."\nRelay / URL Test: ".metric_text($result['relay']??null);
        $kb=[[['text'=>'🔄 Test Again','callback_data'=>'nt|'.$id]],[['text'=>'⭐ Set as Main Node','callback_data'=>'ndsel|'.$id]]];
        return ['text'=>$text,'keyboard'=>append_nav($kb,'nd|'.$id)];
    }

    if ($action==='passwall2.nodes.test_all' && is_array($result)) {
        $lines=["🧪 <b>All Node Tests</b>",''];
        foreach(($result['results']??[]) as $r){if(!is_array($r))continue;$name=(string)($r['remarks']??$r['id']??'Node');if(!empty($r['error'])){$lines[]='• <b>'.h($name).'</b> — ❌ '.h((string)$r['error']);continue;}$p=(!empty($r['ping']['ok'])&&isset($r['ping']['ms']))?number_format((float)$r['ping']['ms'],0).'ms':'×';$t=(!empty($r['tcp']['ok'])&&isset($r['tcp']['ms']))?number_format((float)$r['tcp']['ms'],0).'ms':'×';$u=(!empty($r['relay']['ok'])&&isset($r['relay']['ms']))?number_format((float)$r['relay']['ms'],0).'ms':'×';$lines[]='• <b>'.h($name).'</b> — P '.$p.' · T '.$t.' · R '.$u;}
        $text=implode("\n",$lines);if(strlen($text)>3800)$text=substr($text,0,3800)."\n…";
        return ['text'=>$text,'keyboard'=>append_nav([[['text'=>'🔄 Run Again','callback_data'=>'nall']]],'nodes')];
    }

    if ($action==='passwall2.node.add_link' && is_array($result)) {
        $lines=["✅ <b>Node import completed</b>",'Added: <b>'.(int)($result['added_count']??0).'</b>'];
        foreach(($result['added']??[]) as $n)$lines[]='• '.h((string)($n['remarks']??$n['id']??'Node'));
        return ['text'=>implode("\n",$lines),'keyboard'=>append_nav([[['text'=>'➕ Add Another','callback_data'=>'nadd']]],'nodes')];
    }
    if ($action==='passwall2.node.delete') return ['text'=>'✅ <b>Node deleted.</b>','keyboard'=>append_nav([], 'nodes')];

    if (($action==='passwall2.shunt.list'||$action==='passwall2.shunt.set'||$action==='passwall2.shunt.set_default') && is_array($result)) {
        $names=node_name_map($result);$lines=["🧭 <b>Shunt Rules</b>",''];$kb=[];
        foreach(($result['shunts']??[]) as $sh){
            if(!is_array($sh))continue;$sid=(string)($sh['id']??'');$sname=(string)($sh['remarks']??$sid);$group=(string)($sh['shunt_group']??'');
            $lines[]='<b>'.h($sname).'</b>'.($group!==''?' · Group: <code>'.h($group).'</code>':'');
            $def=(string)($sh['default_node']??'_direct');$defName=(string)($sh['default_name']??target_name($def,$names));
            $lines[]='• Default → <b>'.h($defName).'</b>';
            $kb[]=[['text'=>'Default · '.short_text($defName,28),'callback_data'=>'shr|'.$sid.'|__default__']];
            $kb[]=[['text'=>'🛟 Smart Failover · '.short_text($sname,24),'callback_data'=>'fo|'.$sid]];
            foreach(($sh['routes']??[]) as $route){
                if(!is_array($route))continue;$rid=(string)($route['rule_id']??'');$label=(string)($route['rule_name']??$rid);$target=(string)($route['target']??'');$tname=(string)($route['target_name']??target_name($target,$names));
                $lines[]='• '.h($label).' → <b>'.h($tname).'</b>';
                if($rid!=='')$kb[]=[['text'=>short_text($label,22).' · '.short_text($tname,18),'callback_data'=>'shr|'.$sid.'|'.$rid]];
            }
            $lines[]='';
        }
        if(empty($result['shunts']))$lines[]='No Shunt node found.';
        return ['text'=>implode("\n",$lines),'keyboard'=>append_nav($kb,'pw')];
    }

    if ($action==='passwall2.shunt.options' && is_array($result)) {
        $sid=(string)$result['shunt_id'];$rid=(string)$result['rule_id'];$current=(string)($result['current']??'');$currentName=(string)($result['current_name']??target_name($current,[]));$text="🧭 <b>".h((string)($result['rule_name']??'Rule'))."</b>\nCurrent target: <b>".h($currentName)."</b>\n\nSelect a new target:";$kb=[];$row=[];
        foreach(($result['options']??[]) as $o){if(!is_array($o))continue;$id=(string)($o['id']??'');$name=(string)($o['name']??$id);$label=($id===$current?'✅ ':'').short_text($name,25);$row[]=['text'=>$label,'callback_data'=>'shv|'.$sid.'|'.$rid.'|'.$id];if(count($row)===2){$kb[]=$row;$row=[];}}
        if($row)$kb[]=$row;
        return ['text'=>$text,'keyboard'=>append_nav($kb,'shunt')];
    }

    if (in_array($action,['passwall2.failover.list','passwall2.failover.configure'],true) && is_array($result)) {
        $policies=is_array($result['policies']??null)?$result['policies']:[];$focus=(string)($params['focus']??'');
        if($view==='failover_detail'||$focus!==''){
            $p=null;foreach($policies as $x){if(is_array($x)&&(string)($x['shunt_id']??'')===$focus){$p=$x;break;}}
            if(!$p)return ['text'=>'❌ Shunt Failover policy no longer exists.','keyboard'=>nav_row('failover')];
            $enabled=!empty($p['enabled']);$ar=!empty($p['auto_return']);$sid=(string)$p['shunt_id'];
            $cur=(string)($p['current_name']??$p['current_node']??'Unknown');$pref=(string)($p['preferred_name']??$p['preferred_node']??'Not set');
            $text="🛟 <b>Smart Failover · ".h((string)($p['shunt_name']??$sid))."</b>\n\n";
            $text.="Status: ".($enabled?'🟢 Enabled':'⚪ Disabled')."\nCurrent Default: <b>".h($cur)."</b>\nPreferred: <b>".h($pref)."</b>\n";
            $text.="Check interval: <b>".(int)($p['interval_seconds']??60)."s</b>\nFailure threshold: <b>".(int)($p['failure_threshold']??2)."</b>\nCooldown: <b>".(int)($p['cooldown_seconds']??180)."s</b>\nAuto-return: ".($ar?'✅ On':'⛔ Off');
            if((int)($p['consecutive_failures']??0)>0)$text.="\nConsecutive failures: <b>".(int)$p['consecutive_failures']."</b>";
            if((float)($p['last_relay_ms']??0)>0)$text.="\nLast Relay: <b>".number_format((float)$p['last_relay_ms'],1)." ms</b>";
            if((string)($p['last_error']??'')!=='')$text.="\nLast error: <code>".h((string)$p['last_error'])."</code>";
            $kb=[
                [['text'=>$enabled?'⛔ Disable Failover':'✅ Enable Failover','callback_data'=>'fotoggle|'.$sid.'|'.($enabled?'0':'1')]],
                [['text'=>$ar?'↩️ Auto-return: ON':'↩️ Auto-return: OFF','callback_data'=>'foar|'.$sid.'|'.($ar?'0':'1')]],
                [['text'=>'🧪 Run Check Now','callback_data'=>'forun|'.$sid]],
            ];
            return ['text'=>$text,'keyboard'=>append_nav($kb,'failover')];
        }
        $lines=["🛟 <b>Smart Shunt Failover</b>","When a Shunt Default node loses HTTP Relay, PW Agent selects the healthy node with the lowest Relay latency.",''];$kb=[];
        foreach($policies as $p){if(!is_array($p))continue;$sid=(string)($p['shunt_id']??'');$name=(string)($p['shunt_name']??$sid);$cur=(string)($p['current_name']??$p['current_node']??'Unknown');$en=!empty($p['enabled']);$lines[]=($en?'🟢':'⚪').' <b>'.h($name).'</b> · Default → <b>'.h($cur).'</b>';if($sid!=='')$kb[]=[['text'=>($en?'🟢 ':'⚪ ').short_text($name,34),'callback_data'=>'fo|'.$sid]];}
        if(!$policies)$lines[]='No Shunt nodes found.';
        return ['text'=>implode("\n",$lines),'keyboard'=>append_nav($kb,'pw')];
    }

    if ($action==='passwall2.failover.run' && is_array($result)) {
        $sid=(string)($params['shunt_id']??'');$text="🧪 <b>Smart Failover Check</b>\n\n";
        if(!empty($result['switched'])){$to=(string)($result['to_name']??$result['to']??'');$text.="✅ Default switched to <b>".h($to)."</b>";if(isset($result['relay_ms']))$text.="\nRelay: <b>".number_format((float)$result['relay_ms'],1)." ms</b>";}
        elseif(!empty($result['healthy'])){$relay=is_array($result['relay']??null)?$result['relay']:[];$text.="✅ Current Default node is healthy.";if(isset($relay['ms']))$text.="\nRelay: <b>".number_format((float)$relay['ms'],1)." ms</b>";}
        else{$text.="⚠️ Current Default is unhealthy; no switch was made.";if(!empty($result['reason']))$text.="\nReason: <code>".h((string)$result['reason'])."</code>";}
        return ['text'=>$text,'keyboard'=>append_nav([[['text'=>'🔄 Refresh Policy','callback_data'=>'fo|'.$sid]]],'failover')];
    }

    if (in_array($action,['passwall2.subscriptions.list','passwall2.subscription.add','passwall2.subscription.update','passwall2.subscription.delete'],true) && is_array($result)) {
        $subs=is_array($result['subscriptions']??null)?$result['subscriptions']:[];$lines=["📡 <b>Passwall2 Subscriptions</b>",''];$kb=[];
        if(isset($result['removed_nodes']))$lines[]='Removed nodes: <b>'.(int)$result['removed_nodes'].'</b> · Preserved referenced nodes: <b>'.(int)($result['skipped_referenced_nodes']??0).'</b>';
        foreach($subs as $x){if(!is_array($x))continue;$id=(string)($x['id']??'');$name=(string)($x['remark']??$id);$count=(int)($x['node_count']??0);$lines[]='• <b>'.h($name).'</b> · '.$count.' nodes';if((string)($x['expired_date']??'')!=='')$lines[]='  Expires: '.h((string)$x['expired_date']);if($id!=='')$kb[]=[['text'=>'🔄 '.short_text($name,21),'callback_data'=>'subup|'.$id],['text'=>'🗑','callback_data'=>'subdelq|'.$id]];}
        if(!$subs)$lines[]='No subscriptions configured.';
        array_unshift($kb,[['text'=>'➕ Add Subscription','callback_data'=>'subadd'],['text'=>'🔄 Update All','callback_data'=>'subupall']]);
        return ['text'=>implode("\n",$lines),'keyboard'=>append_nav($kb,'nodes')];
    }

    if ($action==='passwall2.nodes.relay_ranking' && is_array($result)) {
        $lines=["🏆 <b>HTTP Relay Ranking</b>","Healthy nodes: <b>".(int)($result['healthy_count']??0)."</b>",''];$i=0;
        foreach(($result['ranking']??[]) as $r){if(!is_array($r))continue;$i++;$lines[]=$i.'. <b>'.h((string)($r['remarks']??$r['id']??'Node')).'</b> — '.number_format((float)($r['relay_ms']??0),1).' ms';if($i>=40)break;}
        if($i===0)$lines[]='No node passed the HTTP Relay test.';
        return ['text'=>implode("\n",$lines),'keyboard'=>append_nav([[['text'=>'🔄 Rank Again','callback_data'=>'nrank'],['text'=>'🧹 Dead Relay Scan','callback_data'=>'nddead']]],'nodes')];
    }

    if ($action==='passwall2.nodes.dead_candidates' && is_array($result)) {
        $dead=is_array($result['dead']??null)?$result['dead']:[];$lines=["🧹 <b>Dead HTTP Relay Scan</b>","Alive: <b>".(int)($result['alive_count']??0)."</b> · Dead: <b>".(int)($result['dead_count']??0)."</b>",''];
        foreach(array_slice($dead,0,50) as $x){if(is_array($x))$lines[]='• ❌ <b>'.h((string)($x['remarks']??$x['id']??'Node')).'</b>';}
        $kb=[];if($dead)$kb[]=[['text'=>'🗑 Delete Relay-Failed Nodes (Safe)','callback_data'=>'ndpruneq']];$kb[]=[['text'=>'🔄 Scan Again','callback_data'=>'nddead']];
        return ['text'=>implode("\n",$lines),'keyboard'=>append_nav($kb,'nodes')];
    }

    if (in_array($action,['passwall2.nodes.prune_dead','passwall2.nodes.delete_many','passwall2.nodes.delete_all'],true) && is_array($result)) {
        $text="🗑 <b>Node Cleanup Completed</b>\n\nDeleted: <b>".(int)($result['deleted_count']??0)."</b>\nSkipped / still referenced: <b>".(int)($result['skipped_count']??0)."</b>";
        $sk=$result['skipped']??[];foreach(array_slice(is_array($sk)?$sk:[],0,12) as $x){if(is_array($x))$text.="\n• ".h((string)($x['id']??''))." — ".h((string)($x['error']??'protected'));}
        return ['text'=>$text,'keyboard'=>append_nav([[['text'=>'🌐 Refresh Nodes','callback_data'=>'nodes']]],'nodes')];
    }

    if ($action==='agent.update.check' && is_array($result)) {
        $current=(string)($result['current_version']??app_version());$latest=(string)($result['version']??app_version());$available=!empty($result['update_available']);
        $text="🔄 <b>PW Agent Update</b>\n\nInstalled: <code>".h($current)."</code>\nServer release: <code>".h($latest)."</code>\nStatus: ".($available?'🆕 Update available':'✅ Up to date');if(isset($result['size']))$text.="\nPackage: ".h(bytes_human($result['size']));
        $kb=[[['text'=>'🔄 Check Again','callback_data'=>'agentupdate']]];if($available)$kb[]=[['text'=>'⬇️ Install Update','callback_data'=>'agentupq']];
        return ['text'=>$text,'keyboard'=>append_nav($kb,'systemmenu')];
    }
    if ($action==='agent.update.apply' && is_array($result)) {
        return ['text'=>"✅ <b>PW Agent update staged.</b>\n\nVersion: <code>".h((string)($result['version']??''))."</code>\nThe Agent and LuCI add-on will restart automatically. Your existing configuration is preserved.",'keyboard'=>append_nav([[['text'=>'🔄 Check Version','callback_data'=>'agentupdate']]],'systemmenu')];
    }
    if ($action==='agent.diagnose' && is_array($result)) {
        $srv=is_array($result['server']??null)?$result['server']:[];$ok=!empty($result['ok']);$text="🔐 <b>PW Agent Connection</b>\n\nOverall: ".($ok?'✅ Healthy':'❌ Failed')."\nDevice ID: <code>".h((string)($result['device_id']??''))."</code>\nAgent: <code>".h((string)($result['agent_version']??''))."</code>\nServer: <code>".h((string)($srv['version']??'Unknown'))."</code>\nHMAC auth: ".(!empty($result['auth_ok'])?'✅':'❌')."\nSigned response: ".(!empty($result['response_signature_ok'])?'✅':'❌')."\nRound trip: <b>".number_format((float)($result['latency_ms']??0),1)." ms</b>";if(!$ok&&isset($result['error']))$text.="\nError: <code>".h((string)$result['error'])."</code>";
        return ['text'=>$text,'keyboard'=>append_nav([[['text'=>'🔄 Test Again','callback_data'=>'agentdiag']]],'diag')];
    }

    if ($action==='system.interfaces' && is_array($result)) {
        $lines=["🔌 <b>Network Interfaces</b>",''];foreach(($result['interfaces']??[]) as $i){if(!is_array($i))continue;$status=!empty($i['up'])?'🟢':'🔴';$lines[]=$status.' <b>'.h((string)($i['name']??'')).'</b> · '.h((string)($i['proto']??''));$dev=(string)($i['device']??'');if($dev!=='')$lines[]='   Device: <code>'.h($dev).'</code>';if(!empty($i['addresses']))$lines[]='   IP: <code>'.h(implode(', ',$i['addresses'])).'</code>';$lines[]='   RX '.h(bytes_human($i['rx_bytes']??0)).' · TX '.h(bytes_human($i['tx_bytes']??0));}
        return ['text'=>implode("\n",$lines),'keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>'ifaces']]],'network')];
    }
    if ($action==='system.clients' && is_array($result)) {
        $lines=["👥 <b>Connected Devices</b>",''];foreach(($result['clients']??[]) as $c){if(!is_array($c))continue;$name=(string)($c['hostname']??'');if($name==='')$name='Unknown device';$lines[]='• <b>'.h($name).'</b> — <code>'.h((string)($c['ip']??'')).'</code>';$lines[]='  '.h((string)($c['mac']??'')).(!empty($c['interface'])?' · '.h((string)$c['interface']):'');}if((int)($result['count']??0)===0)$lines[]='No devices found in DHCP/neighbor tables.';
        return ['text'=>implode("\n",$lines),'keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>'clients']]],'network')];
    }

    if (in_array($action,['wifi.list','wifi.set_ssid','wifi.set_password','wifi.reload'],true) && is_array($result)) {
        $items=$result['wifi']??[];
        if ($view==='wifi_detail') {
            $target=(string)($params['target']??'');$w=null;foreach($items as $x){if(is_array($x)&&(string)($x['section']??'')===$target){$w=$x;break;}}
            if(!$w)return ['text'=>'❌ Wi-Fi interface no longer exists.','keyboard'=>nav_row('wifimenu')];
            $text="📶 <b>".h((string)($w['ssid']??'Unnamed Wi-Fi'))."</b>\nMode: <code>".h((string)($w['mode']??''))."</code>\nNetwork: <code>".h((string)($w['network']??''))."</code>\nSecurity: <code>".h((string)($w['encryption']??''))."</code>";
            $kb=[[['text'=>'✏️ Change SSID','callback_data'=>'wfssid|'.$target]],[['text'=>'🔐 Change Password','callback_data'=>'wfpw|'.$target]]];
            return ['text'=>$text,'keyboard'=>append_nav($kb,'wifimenu')];
        }
        $lines=["📶 <b>Wi-Fi Interfaces</b>",''];$kb=[];foreach($items as $w){if(!is_array($w))continue;$sec=(string)($w['section']??'');$ssid=(string)($w['ssid']??'Unnamed Wi-Fi');$state=!empty($w['disabled'])?'⛔':'🟢';$lines[]=$state.' <b>'.h($ssid).'</b> · '.h((string)($w['encryption']??''));if($sec!=='')$kb[]=[['text'=>'⚙️ '.short_text($ssid,35),'callback_data'=>'wf|'.$sec]];}
        return ['text'=>implode("\n",$lines),'keyboard'=>append_nav($kb,'wifimenu')];
    }

    if ($action==='system.time' && is_array($result)) {
        $servers=is_array($result['ntp_servers']??null)?implode(', ',$result['ntp_servers']):'';
        $text="🕒 <b>Router Time / NTP</b>\n\nLocal: <code>".h((string)($result['local']??''))."</code>\nZone: <b>".h((string)($result['zonename']??''))."</b>\nTZ: <code>".h((string)($result['timezone']??''))."</code>\nNTP: ".(!empty($result['ntp_enabled'])?'✅ Enabled':'⛔ Disabled').($servers!==''?"\nServers: <code>".h($servers)."</code>":'');
        return ['text'=>$text,'keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>'systime']]],'systemmenu')];
    }
    if (in_array($action,['system.log','system.klog'],true) && is_array($result)) {
        $title=$action==='system.log'?'System Log':'Kernel Log';$body=implode("\n",$result['lines']??[]);if(strlen($body)>3400)$body=substr($body,-3400);
        return ['text'=>'📜 <b>'.$title."</b>\n<pre>".h($body?:'No log output.').'</pre>','keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>$action==='system.log'?'syslog':'klog']]],'systemmenu')];
    }
    if ($action==='system.processes' && is_array($result)) {
        $lines=["📈 <b>Top Processes by RAM</b>",''];foreach(($result['processes']??[]) as $p){if(!is_array($p))continue;$lines[]='<code>'.h((string)($p['pid']??'')).'</code> · <b>'.h((string)($p['name']??'')).'</b> · '.h(bytes_human($p['rss_bytes']??0));}
        return ['text'=>implode("\n",$lines),'keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>'procs']]],'systemmenu')];
    }
    if (in_array($action,['system.services','system.service.restart'],true) && is_array($result)) {
        $lines=["🧩 <b>Services</b>",''];$kb=[];foreach(($result['services']??[]) as $x){if(!is_array($x)||empty($x['installed']))continue;$name=(string)($x['name']??'');$state=(string)($x['status']??'unknown');$icon=$state==='running'?'🟢':($state==='stopped'?'🔴':'🟡');$lines[]=$icon.' <b>'.h($name).'</b> · '.h($state);$kb[]=[['text'=>'🔄 Restart '.short_text($name,20),'callback_data'=>'svcr|'.$name]];}
        return ['text'=>implode("\n",$lines),'keyboard'=>append_nav($kb,'systemmenu')];
    }
    if (in_array($action,['system.packages','system.package_updates'],true) && is_array($result)) {
        $updates=$action==='system.package_updates';$lines=[$updates?'⬆️ <b>Available Package Updates</b>':'📦 <b>Installed Packages</b>','Count: <b>'.(int)($result['count']??0).'</b>',''];foreach(($result['packages']??[]) as $x)$lines[]='• '.h((string)$x);if(!empty($result['limited']))$lines[]='… output limited to 120 entries';$text=implode("\n",$lines);if(strlen($text)>3900)$text=substr($text,0,3900)."\n…";
        return ['text'=>$text,'keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>$updates?'pkgupdates':'packages']]],'systemmenu')];
    }
    if (in_array($action,['network.wan','network.wan.reconnect'],true) && is_array($result)) {
        $ips=[];foreach(($result['ipv4-address']??[]) as $i){if(is_array($i)&&isset($i['address']))$ips[]=$i['address'];}$dns=[];foreach(($result['dns-server']??[]) as $d)$dns[]=(string)$d;
        $text="🌍 <b>WAN</b>\n\nState: ".(!empty($result['up'])?'🟢 Up':'🔴 Down')."\nProtocol: <code>".h((string)($result['proto']??''))."</code>\nDevice: <code>".h((string)($result['device']??''))."</code>".($ips?"\nIPv4: <code>".h(implode(', ',$ips))."</code>":'').($dns?"\nDNS: <code>".h(implode(', ',$dns))."</code>":'');
        return ['text'=>$text,'keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>'wan'],['text'=>'♻️ Reconnect','callback_data'=>'wanreconnect']]],'network')];
    }
    if ($action==='network.internet_test' && is_array($result)) {
        $dns=$result['dns']??[];$text="✅ <b>Internet Health</b>\n\nICMP 1.1.1.1: ".metric_text($result['ping']??null)."\nTCP 443: ".metric_text($result['tcp443']??null)."\nDNS openwrt.org: ".(!empty($dns['ok'])?'✅ OK':'❌ Failed');
        return ['text'=>$text,'keyboard'=>append_nav([[['text'=>'🔄 Test Again','callback_data'=>'inettest']]],'network')];
    }
    if ($action==='network.routes' && is_array($result)) {
        $body="IPv4:\n".implode("\n",$result['ipv4']??[])."\n\nIPv6:\n".implode("\n",$result['ipv6']??[]);if(strlen($body)>3400)$body=substr($body,0,3400)."\n…";return ['text'=>"🧭 <b>Routes</b>\n<pre>".h($body).'</pre>','keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>'routes']]],'network')];
    }
    if ($action==='network.dns' && is_array($result)) {
        $lines=["🔎 <b>DNS Configuration</b>",''];foreach(($result['files']??[]) as $path=>$ls){$lines[]='<b>'.h((string)$path).'</b>';foreach((array)$ls as $l)$lines[]='  '.h((string)$l);}$text=implode("\n",$lines);return ['text'=>$text,'keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>'dnsinfo']]],'network')];
    }
    if ($action==='network.dhcp_leases' && is_array($result)) {
        $lines=["🧾 <b>DHCP Leases</b>",''];foreach(($result['leases']??[]) as $l){if(!is_array($l))continue;$host=(string)($l['hostname']??'*');$lines[]='• <b>'.h($host).'</b> · <code>'.h((string)($l['ip']??'')).'</code> · '.h((string)($l['mac']??''));}if(count($lines)===2)$lines[]='No active leases.';return ['text'=>implode("\n",$lines),'keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>'leases']]],'network')];
    }
    if ($action==='network.wireguard' && is_array($result)) {
        $body=implode("\n",$result['lines']??[]);if(empty($result['available']))$body='WireGuard tools are not installed.';if(strlen($body)>3400)$body=substr($body,0,3400)."\n…";return ['text'=>"🔐 <b>WireGuard</b>\n<pre>".h($body).'</pre>','keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>'wireguard']]],'network')];
    }
    if (in_array($action,['wifi.radios','wifi.radio.toggle','wifi.radio.channel'],true) && is_array($result)) {
        $lines=["📻 <b>Wi-Fi Radios</b>",''];$kb=[];foreach(($result['radios']??[]) as $r){if(!is_array($r))continue;$sec=(string)($r['section']??'');$disabled=!empty($r['disabled']);$lines[]=($disabled?'🔴':'🟢').' <b>'.h($sec).'</b> · '.h((string)($r['band']??'')).' · CH '.h((string)($r['channel']??'auto')).' · '.h((string)($r['htmode']??''));$kb[]=[['text'=>$disabled?'✅ Enable '.$sec:'⛔ Disable '.$sec,'callback_data'=>'wfrtoggle|'.$sec.'|'.($disabled?'1':'0')],['text'=>'📶 Channel','callback_data'=>'wfrchan|'.$sec]];}
        return ['text'=>implode("\n",$lines),'keyboard'=>append_nav($kb,'wifimenu')];
    }
    if ($action==='wifi.stations' && is_array($result)) {
        $lines=["👤 <b>Wi-Fi Stations</b>",''];foreach(($result['stations']??[]) as $st){if(!is_array($st))continue;$sig=isset($st['signal'])?' · '.(int)$st['signal'].' dBm':'';$lines[]='• <code>'.h((string)($st['mac']??'')).'</code> · '.h((string)($st['interface']??'')).$sig;}if((int)($result['count']??0)===0)$lines[]='No associated Wi-Fi clients found.';return ['text'=>implode("\n",$lines),'keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>'wfstations']]],'wifimenu')];
    }
    if ($action==='firewall.zones' && is_array($result)) {
        $lines=["🧱 <b>Firewall Zones</b>",''];foreach(($result['zones']??[]) as $z){if(!is_array($z))continue;$lines[]='• <b>'.h((string)($z['name']??'')).'</b> · IN '.h((string)($z['input']??'')).' · OUT '.h((string)($z['output']??'')).' · FWD '.h((string)($z['forward']??''));}return ['text'=>implode("\n",$lines),'keyboard'=>append_nav([[['text'=>'🔄 Refresh','callback_data'=>'fwzones']]],'firewall')];
    }
    if (in_array($action,['firewall.redirects','firewall.redirect.toggle'],true) && is_array($result)) {
        $lines=["↪️ <b>Port Forwards</b>",''];$kb=[];foreach(($result['redirects']??[]) as $r){if(!is_array($r))continue;$sec=(string)($r['section']??'');$en=!empty($r['enabled']);$name=(string)($r['name']??$sec);$lines[]=($en?'🟢':'🔴').' <b>'.h($name).'</b> · '.h((string)($r['proto']??'')).' '.h((string)($r['src_dport']??'')).' → '.h((string)($r['dest_ip']??'')).':'.h((string)($r['dest_port']??''));$kb[]=[['text'=>$en?'⛔ Disable '.short_text($name,24):'✅ Enable '.short_text($name,24),'callback_data'=>'fwrt|'.$sec.'|'.($en?'0':'1')]];}if(count($lines)===2)$lines[]='No port forwards configured.';return ['text'=>implode("\n",$lines),'keyboard'=>append_nav($kb,'firewall')];
    }
    if (in_array($action,['diag.ping','diag.traceroute'],true) && is_array($result)) {
        $title=$action==='diag.ping'?'Ping':'Traceroute';$body=(string)($result['output']??'');if(strlen($body)>3400)$body=substr($body,0,3400)."\n…";return ['text'=>'🧪 <b>'.$title.' · '.h((string)($result['target']??''))."</b>\n<pre>".h($body).'</pre>','keyboard'=>append_nav([], 'diag')];
    }

    if ($action==='system.backup.restore' && is_array($result)) {
        return ['text'=>"✅ <b>Backup restored.</b>\n\nA reboot is recommended so all restored network and service settings are loaded cleanly.",'keyboard'=>append_nav([[['text'=>'♻️ Reboot Router','callback_data'=>'rebootq']]],'backup')];
    }
    if ($action==='system.reboot') return ['text'=>"♻️ <b>Router reboot scheduled.</b>\nThe agent should reconnect automatically after the router comes back online.",'keyboard'=>main_keyboard()];
    if ($action==='system.backup.create') return ['text'=>"✅ <b>Backup created and sent below.</b>\nNo backup copy is retained on the router or web server.",'keyboard'=>backup_keyboard()];

    $json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);if(strlen((string)$json)>3400)$json=substr((string)$json,0,3400)."\n…";
    return ['text'=>"✅ <b>Completed</b>\n<pre>".h((string)$json).'</pre>','keyboard'=>main_keyboard()];
}
