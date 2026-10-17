<?php
declare(strict_types=1);

/*
 * PW Agent v0.6 platform services:
 * fleet registry, RBAC, audit/history, alert rules, maintenance and schedules.
 * Functions are intentionally backend-only; router secrets never leave the
 * authenticated provisioning/device paths.
 */

function ensure_v060_schema(): void {
    static $done=false;
    if($done)return;
    ensure_v050_schema();
    $pdo=db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_device_groups (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(96) NOT NULL UNIQUE,
      description VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_device_registry (
      device_id VARCHAR(64) PRIMARY KEY,
      display_name VARCHAR(128) NOT NULL,
      secret_sealed TEXT NOT NULL,
      enabled TINYINT(1) NOT NULL DEFAULT 1,
      group_id BIGINT UNSIGNED NULL,
      location VARCHAR(128) NULL,
      tags_json LONGTEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_registry_group(group_id),
      INDEX idx_registry_enabled(enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_users (
      telegram_user_id BIGINT PRIMARY KEY,
      role ENUM('owner','admin','operator','viewer') NOT NULL DEFAULT 'viewer',
      enabled TINYINT(1) NOT NULL DEFAULT 1,
      device_scope_json LONGTEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_user_state (
      telegram_user_id BIGINT PRIMARY KEY,
      active_device_id VARCHAR(64) NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_audit_log (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      command_id BIGINT UNSIGNED NULL,
      device_id VARCHAR(64) NOT NULL,
      telegram_user_id BIGINT NULL,
      source VARCHAR(32) NOT NULL DEFAULT 'telegram',
      action VARCHAR(96) NOT NULL,
      status VARCHAR(24) NOT NULL,
      params_redacted_json LONGTEXT NOT NULL,
      result_summary_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      finished_at DATETIME NULL,
      UNIQUE KEY ux_audit_command(command_id),
      INDEX idx_audit_device_time(device_id,created_at),
      INDEX idx_audit_user_time(telegram_user_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_config_changes (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      command_id BIGINT UNSIGNED NOT NULL UNIQUE,
      device_id VARCHAR(64) NOT NULL,
      telegram_user_id BIGINT NULL,
      action VARCHAR(96) NOT NULL,
      packages_json LONGTEXT NOT NULL,
      diff_json LONGTEXT NOT NULL,
      before_sealed LONGTEXT NOT NULL,
      before_hashes_json LONGTEXT NOT NULL,
      after_hashes_json LONGTEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_change_device_time(device_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_alert_rules (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      device_id VARCHAR(64) NOT NULL,
      metric VARCHAR(48) NOT NULL,
      operator VARCHAR(4) NOT NULL,
      threshold DOUBLE NOT NULL,
      duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
      cooldown_seconds INT UNSIGNED NOT NULL DEFAULT 900,
      notify_chat_id BIGINT NOT NULL,
      enabled TINYINT(1) NOT NULL DEFAULT 1,
      breach_since DATETIME NULL,
      last_triggered_at DATETIME NULL,
      created_by BIGINT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_alert_device_enabled(device_id,enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_maintenance (
      device_id VARCHAR(64) PRIMARY KEY,
      until_at DATETIME NOT NULL,
      reason VARCHAR(255) NULL,
      created_by BIGINT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_schedules (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      device_id VARCHAR(64) NOT NULL,
      name VARCHAR(128) NOT NULL,
      action VARCHAR(96) NOT NULL,
      params_json LONGTEXT NOT NULL,
      schedule_type ENUM('one_time','interval','daily','weekly') NOT NULL,
      schedule_spec_json LONGTEXT NOT NULL,
      next_run_at DATETIME NOT NULL,
      enabled TINYINT(1) NOT NULL DEFAULT 1,
      created_by BIGINT NULL,
      last_run_at DATETIME NULL,
      last_command_id BIGINT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_schedule_due(enabled,next_run_at),
      INDEX idx_schedule_device(device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // v0.6 metric columns are added lazily for existing installations.
    $cols=[];
    foreach($pdo->query('SHOW COLUMNS FROM pw_metrics')->fetchAll() as $r)$cols[(string)$r['Field']]=true;
    $metricAdds=[
      'cpu_percent'=>"ADD COLUMN cpu_percent DOUBLE NULL AFTER load1",
      'memory_used_percent'=>"ADD COLUMN memory_used_percent DOUBLE NULL AFTER memory_json",
      'wan_up'=>"ADD COLUMN wan_up TINYINT(1) NULL AFTER passwall_running",
      'wan_ip'=>"ADD COLUMN wan_ip VARCHAR(64) NULL AFTER wan_up",
      'rx_bytes'=>"ADD COLUMN rx_bytes BIGINT UNSIGNED NULL AFTER wan_ip",
      'tx_bytes'=>"ADD COLUMN tx_bytes BIGINT UNSIGNED NULL AFTER rx_bytes",
    ];
    foreach($metricAdds as $c=>$sql)if(!isset($cols[$c]))$pdo->exec('ALTER TABLE pw_metrics '.$sql);

    v060_seed_from_config();
    $done=true;
}

function v060_instance_key(): string {
    $configured=(string)(cfg()['security']['instance_key']??'');
    if($configured!==''){
        $raw=base64_decode($configured,true);
        if($raw!==false&&strlen($raw)>=32)return hash('sha256',$raw,true);
        if(strlen($configured)>=32)return hash('sha256',$configured,true);
    }
    // Backward-compatible fallback for v0.5 installs. New setup.php writes instance_key.
    $material=(string)(cfg()['telegram']['webhook_secret']??'').'|'.(string)(cfg()['db']['dsn']??'').'|'.(string)(cfg()['db']['user']??'');
    return hash('sha256',$material,true);
}

function v060_seal_secret(string $plain): string {
    $nonce=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',v060_instance_key(),OPENSSL_RAW_DATA,$nonce,$tag,'pw-device-secret-v1',16);
    if($cipher===false||strlen($tag)!==16)throw new RuntimeException('Could not encrypt device secret');
    return base64_encode($nonce.$tag.$cipher);
}
function v060_open_secret(string $sealed): string {
    $raw=base64_decode($sealed,true);
    if($raw===false||strlen($raw)<29)throw new RuntimeException('Invalid device secret envelope');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',v060_instance_key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'pw-device-secret-v1');
    if($plain===false)throw new RuntimeException('Could not decrypt device secret');
    return $plain;
}

function v060_seed_from_config(): void {
    static $seeded=false;if($seeded)return;$seeded=true;
    $pdo=db();
    foreach((cfg()['devices']??[]) as $id=>$d){
        if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/',(string)$id)||empty($d['secret']))continue;
        $q=$pdo->prepare('SELECT device_id FROM pw_device_registry WHERE device_id=?');$q->execute([(string)$id]);
        if(!$q->fetch()){
            $st=$pdo->prepare('INSERT INTO pw_device_registry(device_id,display_name,secret_sealed,enabled,tags_json) VALUES(?,?,?,?,?)');
            $st->execute([(string)$id,(string)$id,v060_seal_secret((string)$d['secret']),!empty($d['enabled'])?1:0,'[]']);
        }
    }
    $ids=array_values(array_unique(array_map('intval',cfg()['telegram']['allowed_user_ids']??[])));
    foreach($ids as $i=>$uid){
        if($uid<=0)continue;
        $st=$pdo->prepare('INSERT IGNORE INTO pw_users(telegram_user_id,role,enabled,device_scope_json) VALUES(?,?,1,?)');
        $st->execute([$uid,$i===0?'owner':'admin','["*"]']);
    }
}

function v060_device_row(string $deviceId): ?array {
    ensure_v060_schema();
    $st=db()->prepare('SELECT r.*,g.name group_name,d.last_seen,d.last_ip,d.agent_version,d.hostname,d.meta_json FROM pw_device_registry r LEFT JOIN pw_device_groups g ON g.id=r.group_id LEFT JOIN pw_devices d ON d.device_id=r.device_id WHERE r.device_id=?');
    $st->execute([$deviceId]);$r=$st->fetch();return $r?:null;
}
function v060_device_secret(string $deviceId): string {
    ensure_v060_schema();
    $st=db()->prepare('SELECT secret_sealed,enabled FROM pw_device_registry WHERE device_id=?');$st->execute([$deviceId]);$r=$st->fetch();
    if($r){if(empty($r['enabled']))throw new RuntimeException('Device disabled');return v060_open_secret((string)$r['secret_sealed']);}
    $legacy=cfg()['devices'][$deviceId]['secret']??'';
    if($legacy==='')throw new RuntimeException('Unknown device');
    return (string)$legacy;
}
function v060_device_enabled(string $deviceId): bool {
    $r=v060_device_row($deviceId);return $r!==null&&!empty($r['enabled']);
}
function v060_register_device(string $name,?int $createdBy=null): array {
    ensure_v060_schema();
    $name=trim($name);if($name===''||strlen($name)>128)throw new RuntimeException('Device name must be 1-128 bytes');
    $base=strtolower((string)preg_replace('/[^A-Za-z0-9]+/','-',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name)?:$name));
    $base=trim($base,'-');if($base==='')$base='router';
    $base=substr($base,0,48);$id=$base.'-'.substr(bin2hex(random_bytes(5)),0,8);
    $secret=bin2hex(random_bytes(32));
    $st=db()->prepare("INSERT INTO pw_device_registry(device_id,display_name,secret_sealed,enabled,tags_json) VALUES(?,?,?,1,'[]')");
    $st->execute([$id,$name,v060_seal_secret($secret)]);
    $token=rtrim(strtr(base64_encode(random_bytes(36)),'+/','-_'),'=');
    db()->prepare('INSERT INTO pw_provision_tokens(token_hash,device_id,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([hash('sha256',$token),$id]);
    v060_audit_event($id,$createdBy,'telegram','device.provision','done',['display_name'=>$name],['token_expires_minutes'=>30]);
    return ['device_id'=>$id,'display_name'=>$name,'token'=>$token,'secret'=>$secret];
}
function v060_provision_command(string $token): string {
    $base=rtrim((string)cfg()['base_url'],'/');
    $raw='https://raw.githubusercontent.com/aliazizi0906/Passwall-Agent-Telegram-Bot/main/openwrt/install-remote.sh';
    return 'PW_SERVER_BASE='.escapeshellarg($base).' PW_PROVISION_TOKEN='.escapeshellarg($token).' sh -c "$(wget -qO- '.escapeshellarg($raw).')"';
}

function v060_user(int $uid): ?array {
    ensure_v060_schema();$st=db()->prepare('SELECT * FROM pw_users WHERE telegram_user_id=?');$st->execute([$uid]);$r=$st->fetch();return $r?:null;
}
function v060_user_allowed(int $uid): bool {$u=v060_user($uid);return $u!==null&&!empty($u['enabled']);}
function v060_role_permissions(string $role): array {
    if($role==='owner')return ['*'];
    if($role==='admin')return ['device.read','device.manage','group.manage','rbac.read','rbac.manage','system.read','system.modify','network.read','network.modify','wifi.read','wifi.modify','firewall.read','firewall.modify','passwall.read','passwall.modify','terminal.execute','backup.create','backup.restore','agent.update','audit.read','rollback','monitor.read','alert.manage','maintenance.manage','schedule.manage'];
    if($role==='operator')return ['device.read','system.read','system.modify','network.read','network.modify','wifi.read','wifi.modify','firewall.read','passwall.read','passwall.modify','backup.create','audit.read','monitor.read','maintenance.manage','schedule.manage'];
    return ['device.read','system.read','network.read','wifi.read','firewall.read','passwall.read','audit.read','monitor.read'];
}
function v060_has_permission(int $uid,string $perm): bool {
    $u=v060_user($uid);if(!$u||empty($u['enabled']))return false;
    $p=v060_role_permissions((string)$u['role']);return in_array('*',$p,true)||in_array($perm,$p,true);
}
function v060_scope(int $uid): array {
    $u=v060_user($uid);if(!$u)return [];
    $s=json_decode((string)$u['device_scope_json'],true);return is_array($s)?array_values(array_map('strval',$s)):[];
}
function v060_can_device(int $uid,string $deviceId): bool {
    $u=v060_user($uid);if(!$u||empty($u['enabled']))return false;
    if(in_array((string)$u['role'],['owner','admin'],true))return true;
    $scope=v060_scope($uid);return in_array('*',$scope,true)||in_array($deviceId,$scope,true);
}
function v060_action_permission(string $action): string {
    if($action==='terminal.exec')return 'terminal.execute';
    if($action==='system.backup.create')return 'backup.create';
    if($action==='system.backup.restore')return 'backup.restore';
    if(str_starts_with($action,'agent.update'))return $action==='agent.update.check'?'system.read':'agent.update';
    if(str_starts_with($action,'passwall2.'))return isMutationActionPHPV6($action)?'passwall.modify':'passwall.read';
    if(str_starts_with($action,'wifi.'))return isMutationActionPHPV6($action)?'wifi.modify':'wifi.read';
    if(str_starts_with($action,'firewall.'))return isMutationActionPHPV6($action)?'firewall.modify':'firewall.read';
    if(str_starts_with($action,'network.'))return isMutationActionPHPV6($action)?'network.modify':'network.read';
    if(str_starts_with($action,'diag.'))return 'network.read';
    if(str_starts_with($action,'config.rollback'))return 'rollback';
    return isMutationActionPHPV6($action)?'system.modify':'system.read';
}
function isMutationActionPHPV6(string $a): bool {
    $read=[
      'system.status','system.interfaces','system.clients','wifi.list','passwall2.status','passwall2.log',
      'passwall2.nodes.list','passwall2.node.test','passwall2.nodes.test_all','passwall2.shunt.list',
      'passwall2.shunt.options','system.time','system.log','system.klog','system.processes','system.services',
      'system.packages','system.package_updates','network.wan','network.internet_test','network.routes','network.dns',
      'network.dhcp_leases','network.wireguard','wifi.radios','wifi.stations','firewall.zones','firewall.redirects',
      'diag.ping','diag.traceroute','passwall2.failover.list','passwall2.subscriptions.list',
      'passwall2.nodes.relay_ranking','passwall2.nodes.dead_candidates','agent.update.check','agent.diagnose'
    ];
    return !in_array($a,$read,true);
}
function v060_authorize_command(?int $uid,string $deviceId,string $action): void {
    if($uid===null||$uid<=0)return;
    if(!v060_can_device($uid,$deviceId))throw new RuntimeException('You do not have access to this device');
    $perm=v060_action_permission($action);
    if(!v060_has_permission($uid,$perm))throw new RuntimeException('Permission denied: '.$perm);
}
function v060_accessible_devices(int $uid): array {
    ensure_v060_schema();
    $rows=db()->query('SELECT r.device_id,r.display_name,r.location,r.tags_json,r.enabled,r.group_id,g.name group_name,d.last_seen,d.agent_version,d.hostname FROM pw_device_registry r LEFT JOIN pw_device_groups g ON g.id=r.group_id LEFT JOIN pw_devices d ON d.device_id=r.device_id WHERE r.enabled=1 ORDER BY r.display_name')->fetchAll();
    return array_values(array_filter($rows,fn($r)=>v060_can_device($uid,(string)$r['device_id'])));
}
function v060_active_device(int $uid): string {
    ensure_v060_schema();
    $st=db()->prepare('SELECT active_device_id FROM pw_user_state WHERE telegram_user_id=?');$st->execute([$uid]);$id=(string)($st->fetchColumn()?:'');
    if($id!==''&&v060_can_device($uid,$id)&&v060_device_enabled($id))return $id;
    $preferred=(string)(cfg()['telegram']['default_device_id']??'');
    if($preferred!==''&&v060_can_device($uid,$preferred)&&v060_device_enabled($preferred))return $preferred;
    $rows=v060_accessible_devices($uid);return $rows?(string)$rows[0]['device_id']:'';
}
function v060_set_active_device(int $uid,string $deviceId): void {
    if(!v060_can_device($uid,$deviceId)||!v060_device_enabled($deviceId))throw new RuntimeException('Device is not available');
    db()->prepare('INSERT INTO pw_user_state(telegram_user_id,active_device_id) VALUES(?,?) ON DUPLICATE KEY UPDATE active_device_id=VALUES(active_device_id),updated_at=NOW()')->execute([$uid,$deviceId]);
}
function v060_device_label(string $deviceId): string {
    $r=v060_device_row($deviceId);return $r?(string)$r['display_name']:$deviceId;
}

function v060_redact_params(array $params): array {
    $secretKeys=['password','key','data_b64','sealed','url','link','device_secret'];
    $out=$params;foreach($secretKeys as $k)if(array_key_exists($k,$out))$out[$k]='<redacted>';
    unset($out['__message_id']);return $out;
}
function v060_audit_event(string $deviceId,?int $uid,string $source,string $action,string $status,array $params=[],?array $result=null,?int $commandId=null): int {
    ensure_v060_schema();
    $st=db()->prepare('INSERT INTO pw_audit_log(command_id,device_id,telegram_user_id,source,action,status,params_redacted_json,result_summary_json,finished_at) VALUES(?,?,?,?,?,?,?,?,?)');
    $finished=in_array($status,['done','failed'],true)?date('Y-m-d H:i:s'):null;
    $st->execute([$commandId,$deviceId,$uid,$source,$action,$status,json_encode(v060_redact_params($params),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$result===null?null:json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$finished]);
    return (int)db()->lastInsertId();
}
function v060_audit_command_queued(int $commandId,string $deviceId,?int $uid,string $action,array $params,string $source='telegram'): void {
    try{v060_audit_event($deviceId,$uid,$source,$action,'queued',$params,null,$commandId);}catch(Throwable $e){error_log('audit queue: '.$e->getMessage());}
}
function v060_audit_command_result(int $commandId,bool $ok,$result,string $error): void {
    ensure_v060_schema();
    $summary=['ok'=>$ok];if(!$ok)$summary['error']=short_text($error,300);
    if(is_array($result)){
        foreach(['restored','scheduled','enabled','node','selected','reboot_recommended'] as $k)if(array_key_exists($k,$result))$summary[$k]=$result[$k];
    }
    db()->prepare('UPDATE pw_audit_log SET status=?,result_summary_json=?,finished_at=NOW() WHERE command_id=?')->execute([$ok?'done':'failed',json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$commandId]);
}
function v060_audit_recent(int $uid,string $deviceId,int $limit=20): array {
    if(!v060_has_permission($uid,'audit.read')||!v060_can_device($uid,$deviceId))throw new RuntimeException('Permission denied');
    $limit=max(1,min(100,$limit));
    $st=db()->prepare("SELECT * FROM pw_audit_log WHERE device_id=? ORDER BY id DESC LIMIT {$limit}");$st->execute([$deviceId]);return $st->fetchAll();
}

function v060_store_config_change(int $commandId,string $deviceId,?int $uid,string $action,array $change): void {
    if(empty($change['before_sealed']))return;
    ensure_v060_schema();
    $st=db()->prepare('INSERT INTO pw_config_changes(command_id,device_id,telegram_user_id,action,packages_json,diff_json,before_sealed,before_hashes_json,after_hashes_json) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE diff_json=VALUES(diff_json),after_hashes_json=VALUES(after_hashes_json)');
    $st->execute([
      $commandId,$deviceId,$uid,$action,
      json_encode($change['packages']??[],JSON_UNESCAPED_SLASHES),
      json_encode($change['diff']??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
      (string)$change['before_sealed'],
      json_encode($change['before_hashes']??[],JSON_UNESCAPED_SLASHES),
      json_encode($change['after_hashes']??[],JSON_UNESCAPED_SLASHES)
    ]);
}
function v060_config_changes(int $uid,string $deviceId,int $limit=15): array {
    if(!v060_has_permission($uid,'audit.read')||!v060_can_device($uid,$deviceId))throw new RuntimeException('Permission denied');
    $limit=max(1,min(50,$limit));$st=db()->prepare("SELECT id,command_id,device_id,telegram_user_id,action,packages_json,diff_json,before_hashes_json,after_hashes_json,created_at FROM pw_config_changes WHERE device_id=? ORDER BY id DESC LIMIT {$limit}");$st->execute([$deviceId]);return $st->fetchAll();
}
function v060_queue_rollback(int $uid,int $changeId,?int $chatId=null,?int $messageId=null): int {
    if(!v060_has_permission($uid,'rollback'))throw new RuntimeException('Rollback permission required');
    $st=db()->prepare('SELECT * FROM pw_config_changes WHERE id=?');$st->execute([$changeId]);$r=$st->fetch();if(!$r)throw new RuntimeException('Change record not found');
    $dev=(string)$r['device_id'];if(!v060_can_device($uid,$dev))throw new RuntimeException('Device access denied');
    return queue_command($dev,'config.rollback_snapshot',['snapshot'=>(string)$r['before_sealed'],'change_id'=>$changeId],$chatId,$uid,$messageId,'history');
}

function v060_is_maintenance(string $deviceId): bool {
    ensure_v060_schema();$st=db()->prepare('SELECT until_at FROM pw_maintenance WHERE device_id=? AND until_at>NOW()');$st->execute([$deviceId]);return (bool)$st->fetchColumn();
}
function v060_maintenance_row(string $deviceId): ?array {
    ensure_v060_schema();$st=db()->prepare('SELECT * FROM pw_maintenance WHERE device_id=? AND until_at>NOW()');$st->execute([$deviceId]);$r=$st->fetch();return $r?:null;
}
function v060_set_maintenance(int $uid,string $deviceId,int $minutes,string $reason=''): void {
    if(!v060_has_permission($uid,'maintenance.manage')||!v060_can_device($uid,$deviceId))throw new RuntimeException('Permission denied');
    if($minutes<=0){db()->prepare('DELETE FROM pw_maintenance WHERE device_id=?')->execute([$deviceId]);v060_audit_event($deviceId,$uid,'telegram','maintenance.clear','done');return;}
    $minutes=min($minutes,10080);
    db()->prepare('INSERT INTO pw_maintenance(device_id,until_at,reason,created_by) VALUES(?,DATE_ADD(NOW(),INTERVAL ? MINUTE),?,?) ON DUPLICATE KEY UPDATE until_at=VALUES(until_at),reason=VALUES(reason),created_by=VALUES(created_by),created_at=NOW()')->execute([$deviceId,$minutes,$reason,$uid]);
    v060_audit_event($deviceId,$uid,'telegram','maintenance.set','done',['minutes'=>$minutes,'reason'=>$reason]);
}


function v060_notification_chats(string $deviceId): array {
    ensure_v060_schema();$rows=db()->query("SELECT telegram_user_id,role,device_scope_json FROM pw_users WHERE enabled=1 AND role IN ('owner','admin')")->fetchAll();$out=[];
    foreach($rows as $u){$uid=(int)$u['telegram_user_id'];if($uid>0&&v060_can_device($uid,$deviceId))$out[]=$uid;}
    if(!$out){$fallback=(int)(cfg()['telegram']['alert_chat_id']??0);if($fallback>0)$out[]=$fallback;}
    return array_values(array_unique($out));
}

function v060_record_metric(string $deviceId,array $body): void {
    ensure_v060_schema();$pdo=db();
    $q=$pdo->prepare("SELECT id FROM pw_metrics WHERE device_id=? AND captured_at > (NOW()-INTERVAL 50 SECOND) LIMIT 1");$q->execute([$deviceId]);if($q->fetch())return;
    $mem=is_array($body['memory']??null)?$body['memory']:[];
    $total=(float)($mem['total_bytes']??0);$used=(float)($mem['used_bytes']??0);$memPct=$total>0?($used/$total)*100:null;
    $wan=is_array($body['wan']??null)?$body['wan']:[];
    $st=$pdo->prepare('INSERT INTO pw_metrics(device_id,uptime_seconds,load1,cpu_percent,memory_json,memory_used_percent,passwall_running,wan_up,wan_ip,rx_bytes,tx_bytes) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$deviceId,(int)($body['uptime_seconds']??0),(float)($body['load1']??0),isset($body['cpu_percent'])?(float)$body['cpu_percent']:null,json_encode($mem,JSON_UNESCAPED_SLASHES),$memPct,!empty($body['passwall_running'])?1:0,!empty($wan['up'])?1:0,(string)($wan['ip']??''),(int)($wan['rx_bytes']??0),(int)($wan['tx_bytes']??0)]);
}
function v060_metric_value(array $row,string $metric): ?float {
    return match($metric){
      'cpu_percent'=>isset($row['cpu_percent'])?(float)$row['cpu_percent']:null,
      'memory_percent'=>isset($row['memory_used_percent'])?(float)$row['memory_used_percent']:null,
      'load1'=>isset($row['load1'])?(float)$row['load1']:null,
      'passwall_down'=>isset($row['passwall_running'])?(!((bool)$row['passwall_running'])?1.0:0.0):null,
      'wan_down'=>isset($row['wan_up'])?(!((bool)$row['wan_up'])?1.0:0.0):null,
      default=>null
    };
}
function v060_compare(float $v,string $op,float $t): bool {
    return match($op){'>'=>$v>$t,'>='=>$v>=$t,'<'=>$v<$t,'<='=>$v<=$t,'=='=>abs($v-$t)<0.000001,default=>false};
}
function v060_evaluate_alerts(): void {
    ensure_v060_schema();$pdo=db();
    $rules=$pdo->query('SELECT * FROM pw_alert_rules WHERE enabled=1')->fetchAll();
    foreach($rules as $r){
        $dev=(string)$r['device_id'];
        if(v060_is_maintenance($dev)){
            if(!empty($r['breach_since']))$pdo->prepare('UPDATE pw_alert_rules SET breach_since=NULL WHERE id=?')->execute([(int)$r['id']]);
            continue;
        }
        $m=$pdo->prepare('SELECT * FROM pw_metrics WHERE device_id=? ORDER BY captured_at DESC LIMIT 1');$m->execute([$dev]);$row=$m->fetch();if(!$row)continue;
        $v=v060_metric_value($row,(string)$r['metric']);if($v===null)continue;
        $breach=v060_compare($v,(string)$r['operator'],(float)$r['threshold']);
        if(!$breach){if(!empty($r['breach_since']))$pdo->prepare('UPDATE pw_alert_rules SET breach_since=NULL WHERE id=?')->execute([(int)$r['id']]);continue;}
        if(empty($r['breach_since'])){$pdo->prepare('UPDATE pw_alert_rules SET breach_since=NOW() WHERE id=?')->execute([(int)$r['id']]);$r['breach_since']=date('Y-m-d H:i:s');}
        $since=strtotime((string)$r['breach_since']);if(time()-$since<(int)$r['duration_seconds'])continue;
        $last=!empty($r['last_triggered_at'])?strtotime((string)$r['last_triggered_at']):0;if($last&&time()-$last<(int)$r['cooldown_seconds'])continue;
        $label=v060_device_label($dev);$txt="🚨 <b>PW Agent Alert</b>\nDevice: <b>".h($label)."</b>\nMetric: <code>".h((string)$r['metric'])."</code>\nValue: <b>".h((string)round($v,2))."</b> ".h((string)$r['operator'])." <b>".h((string)$r['threshold'])."</b>";
        try{tg_send((int)$r['notify_chat_id'],$txt,v060_fleet_keyboard((int)$r['notify_chat_id'],$dev));}catch(Throwable $e){error_log('alert telegram: '.$e->getMessage());}
        $pdo->prepare('UPDATE pw_alert_rules SET last_triggered_at=NOW() WHERE id=?')->execute([(int)$r['id']]);
        v060_audit_event($dev,null,'alert','alert.trigger','done',['rule_id'=>(int)$r['id'],'metric'=>$r['metric']],['value'=>$v]);
    }
}
function v060_add_alert(int $uid,string $deviceId,string $metric,string $op,float $threshold,int $duration,int $cooldown,?int $chatId=null): int {
    if(!v060_has_permission($uid,'alert.manage')||!v060_can_device($uid,$deviceId))throw new RuntimeException('Permission denied');
    if(!in_array($metric,['cpu_percent','memory_percent','load1','passwall_down','wan_down'],true))throw new RuntimeException('Unsupported metric');
    if(!in_array($op,['>','>=','<','<=','=='],true))throw new RuntimeException('Unsupported operator');
    $st=db()->prepare('INSERT INTO pw_alert_rules(device_id,metric,operator,threshold,duration_seconds,cooldown_seconds,notify_chat_id,created_by) VALUES(?,?,?,?,?,?,?,?)');
    $st->execute([$deviceId,$metric,$op,$threshold,max(0,$duration),max(60,$cooldown),$chatId?:$uid,$uid]);return (int)db()->lastInsertId();
}

function v060_dashboard_command_allowed(string $action): bool {
    return in_array($action,[
      'system.status','passwall2.status','passwall2.nodes.list','passwall2.nodes.relay_ranking',
      'passwall2.failover.list','passwall2.failover.run','passwall2.restart',
      'system.backup.create','agent.update.check','agent.update.apply'
    ],true);
}

function v060_schedule_allowed_action(string $action): bool {
    return in_array($action,['passwall2.restart','passwall2.subscription.update','system.backup.create','system.reboot','agent.update.apply','passwall2.failover.run'],true);
}
function v060_next_run(string $type,array $spec,?DateTimeImmutable $from=null): DateTimeImmutable {
    $tz=new DateTimeZone((string)(cfg()['scheduling']['timezone']??date_default_timezone_get()));
    $from=($from??new DateTimeImmutable('now',$tz))->setTimezone($tz);
    if($type==='one_time'){
        $x=new DateTimeImmutable((string)($spec['run_at']??''),$tz);if($x<=$from)throw new RuntimeException('One-time schedule must be in the future');return $x;
    }
    if($type==='interval'){
        $mins=max(1,min(10080,(int)($spec['every_minutes']??0)));return $from->modify('+'.$mins.' minutes');
    }
    $time=(string)($spec['time']??'');if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time))throw new RuntimeException('Invalid schedule time');
    [$h,$m]=array_map('intval',explode(':',$time));$candidate=$from->setTime($h,$m,0);
    if($type==='daily'){if($candidate<=$from)$candidate=$candidate->modify('+1 day');return $candidate;}
    if($type==='weekly'){
        $weekday=max(0,min(6,(int)($spec['weekday']??0))); // 0=Sunday
        for($i=0;$i<8;$i++){if((int)$candidate->format('w')===$weekday&&$candidate>$from)return $candidate;$candidate=$candidate->modify('+1 day');}
    }
    throw new RuntimeException('Unsupported schedule type');
}
function v060_add_schedule(int $uid,string $deviceId,string $name,string $action,array $params,string $type,array $spec): int {
    if(!v060_has_permission($uid,'schedule.manage')||!v060_can_device($uid,$deviceId))throw new RuntimeException('Permission denied');
    if(!v060_schedule_allowed_action($action))throw new RuntimeException('Action is not allowed for scheduling');
    v060_authorize_command($uid,$deviceId,$action);
    $next=v060_next_run($type,$spec);
    $st=db()->prepare('INSERT INTO pw_schedules(device_id,name,action,params_json,schedule_type,schedule_spec_json,next_run_at,created_by) VALUES(?,?,?,?,?,?,?,?)');
    $st->execute([$deviceId,substr(trim($name),0,128),$action,json_encode($params,JSON_UNESCAPED_SLASHES),$type,json_encode($spec,JSON_UNESCAPED_SLASHES),$next->format('Y-m-d H:i:s'),$uid]);return (int)db()->lastInsertId();
}
function v060_run_due_schedules(): void {
    ensure_v060_schema();$pdo=db();
    $rows=$pdo->query('SELECT * FROM pw_schedules WHERE enabled=1 AND next_run_at<=NOW() ORDER BY next_run_at LIMIT 25')->fetchAll();
    foreach($rows as $r){
        $id=(int)$r['id'];$dev=(string)$r['device_id'];if(v060_is_maintenance($dev))continue;
        $params=json_decode((string)$r['params_json'],true);if(!is_array($params))$params=[];
        try{
            $uid=(int)($r['created_by']??0);
            $chat=((string)$r['action']==='system.backup.create'&&$uid>0)?$uid:null;
            $cid=queue_command($dev,(string)$r['action'],$params,$chat,$uid?:null,null,'scheduled');
            $spec=json_decode((string)$r['schedule_spec_json'],true);if(!is_array($spec))$spec=[];
            if((string)$r['schedule_type']==='one_time'){$pdo->prepare('UPDATE pw_schedules SET enabled=0,last_run_at=NOW(),last_command_id=? WHERE id=?')->execute([$cid,$id]);}
            else{$next=v060_next_run((string)$r['schedule_type'],$spec);$pdo->prepare('UPDATE pw_schedules SET next_run_at=?,last_run_at=NOW(),last_command_id=? WHERE id=?')->execute([$next->format('Y-m-d H:i:s'),$cid,$id]);}
            $pdo->prepare("UPDATE pw_audit_log SET source='schedule' WHERE command_id=?")->execute([$cid]);
        }catch(Throwable $e){error_log('schedule '.$id.': '.$e->getMessage());}
    }
}


function v060_monitoring_overview(int $uid,string $deviceId): array {
    if(!v060_has_permission($uid,'monitor.read')||!v060_can_device($uid,$deviceId))throw new RuntimeException('Monitoring permission denied');
    ensure_v060_schema();$pdo=db();
    $windows=['24h'=>['hours'=>24,'expected'=>1440],'7d'=>['hours'=>168,'expected'=>10080],'30d'=>['hours'=>720,'expected'=>43200]];
    $availability=[];
    foreach($windows as $name=>$w){
        $st=$pdo->prepare('SELECT COUNT(*) samples,AVG(cpu_percent) avg_cpu,MAX(cpu_percent) max_cpu,AVG(memory_used_percent) avg_memory,SUM(CASE WHEN wan_up=0 THEN 1 ELSE 0 END) wan_down_samples,SUM(CASE WHEN passwall_running=0 THEN 1 ELSE 0 END) passwall_down_samples,MIN(rx_bytes) min_rx,MAX(rx_bytes) max_rx,MIN(tx_bytes) min_tx,MAX(tx_bytes) max_tx,COUNT(DISTINCT NULLIF(wan_ip,\'\')) wan_ip_count FROM pw_metrics WHERE device_id=? AND captured_at >= (NOW()-INTERVAL '.$w['hours'].' HOUR)');
        $st->execute([$deviceId]);$r=$st->fetch()?:[];$samples=(int)($r['samples']??0);
        $coverage=min(100.0,$w['expected']>0?($samples/$w['expected'])*100.0:0.0);
        $availability[$name]=[
          'heartbeat_coverage'=>round($coverage,2),
          'samples'=>$samples,
          'avg_cpu'=>round((float)($r['avg_cpu']??0),2),
          'max_cpu'=>round((float)($r['max_cpu']??0),2),
          'avg_memory'=>round((float)($r['avg_memory']??0),2),
          'wan_down_samples'=>(int)($r['wan_down_samples']??0),
          'passwall_down_samples'=>(int)($r['passwall_down_samples']??0),
          'rx_bytes'=>max(0,(int)($r['max_rx']??0)-(int)($r['min_rx']??0)),
          'tx_bytes'=>max(0,(int)($r['max_tx']??0)-(int)($r['min_tx']??0)),
          'wan_ip_changes'=>max(0,(int)($r['wan_ip_count']??0)-1),
        ];
    }
    $e=$pdo->prepare("SELECT COUNT(*) FROM pw_events WHERE device_id=? AND kind IN ('failover.switch','failover.return') AND created_at >= (NOW()-INTERVAL 24 HOUR)");$e->execute([$deviceId]);
    $availability['24h']['failover_events']=(int)$e->fetchColumn();
    return $availability;
}

function v060_fleet_summary(int $uid): array {
    $rows=v060_accessible_devices($uid);$now=time();$online=0;$offline=0;
    foreach($rows as &$r){$last=!empty($r['last_seen'])?strtotime((string)$r['last_seen']):0;$r['online']=$last&&$now-$last<=180;if($r['online'])$online++;else$offline++;$r['maintenance']=v060_is_maintenance((string)$r['device_id']);}
    return ['total'=>count($rows),'online'=>$online,'offline'=>$offline,'devices'=>$rows];
}
function v060_device_token(string $deviceId): string { return substr(hash('sha256',$deviceId),0,16); }
function v060_device_from_token(int $uid,string $token): string {
    foreach(v060_accessible_devices($uid) as $r){$id=(string)$r['device_id'];if(hash_equals(v060_device_token($id),$token))return $id;}
    throw new RuntimeException('Device selector expired or is unavailable');
}

function v060_fleet_keyboard(int $uid,string $active=''): array {
    $sum=v060_fleet_summary($uid);$kb=[];
    foreach(array_slice($sum['devices'],0,12) as $r){
        $id=(string)$r['device_id'];$icon=$r['maintenance']?'🟡':($r['online']?'🟢':'🔴');$check=$id===$active?' ✓':'';
        $kb[]=[['text'=>$icon.' '.short_text((string)$r['display_name'],28).$check,'callback_data'=>'dev|'.v060_device_token($id)]];
    }
    if(v060_has_permission($uid,'device.manage'))$kb[]=[['text'=>'➕ Add Device','callback_data'=>'devadd'],['text'=>'🗂 Groups','callback_data'=>'groups']];
    $kb[]=[['text'=>'📊 Fleet Health','callback_data'=>'fleethealth'],['text'=>'📜 Audit','callback_data'=>'audit']];
    $kb[]=[['text'=>'🏠 Home','callback_data'=>'home']];
    return $kb;
}
function v060_home_keyboard(int $uid,string $deviceId): array {
    $kb=main_keyboard();
    $kb[]=[['text'=>'🧾 History','callback_data'=>'history'],['text'=>'🛠 Maintenance','callback_data'=>'maint']];
    $kb[]=[['text'=>'⏱ Schedules','callback_data'=>'schedules'],['text'=>'🚨 Alerts','callback_data'=>'alerts']];
    if(v060_has_permission($uid,'rbac.read'))$kb[]=[['text'=>'👥 Access / RBAC','callback_data'=>'access']];
    return $kb;
}
function v060_home_text(int $uid,string $deviceId): string {
    $label=$deviceId!==''?v060_device_label($deviceId):'No device';$role=(string)(v060_user($uid)['role']??'viewer');
    $row=$deviceId!==''?v060_device_row($deviceId):null;$online=$row&&!empty($row['last_seen'])&&time()-strtotime((string)$row['last_seen'])<=180;
    return "🏠 <b>PW Agent v0.6</b>\nActive: <b>".h($label)."</b> ".($online?'🟢':'🔴')."\nRole: <code>".h($role)."</code>";
}
