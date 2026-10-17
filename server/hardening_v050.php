<?php
declare(strict_types=1);

function v050_sensitive_action(string $action): bool {
    static $set = [
        'system.backup.restore'=>1,'wifi.set_password'=>1,'passwall2.node.add_link'=>1,
        'passwall2.node.add_manual'=>1,'passwall2.subscription.add'=>1,'terminal.exec'=>1,
    ];
    return isset($set[$action]);
}

function v050_queue_key(string $deviceId): string {
    $secret=(string)(cfg()['devices'][$deviceId]['secret']??'');
    if($secret==='') throw new RuntimeException('Device secret missing for queue encryption');
    return hash_hmac('sha256','pw-agent-db-queue-v1',$secret,true);
}

function v050_seal_params(string $deviceId,string $action,string $json): string {
    if(!v050_sensitive_action($action)) return $json;
    if(!function_exists('openssl_encrypt')) throw new RuntimeException('PHP OpenSSL extension is required');
    $nonce=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($json,'aes-256-gcm',v050_queue_key($deviceId),OPENSSL_RAW_DATA,$nonce,$tag,$action,16);
    if($cipher===false||strlen($tag)!==16) throw new RuntimeException('Could not encrypt command parameters');
    return json_encode(['__sealed_v1'=>base64_encode($nonce.$tag.$cipher)],JSON_UNESCAPED_SLASHES);
}

function v050_open_params(string $deviceId,string $action,string $stored): string {
    $v=json_decode($stored,true);
    if(!is_array($v)||empty($v['__sealed_v1'])) return $stored;
    $packed=base64_decode((string)$v['__sealed_v1'],true);
    if($packed===false||strlen($packed)<29) throw new RuntimeException('Invalid sealed command parameters');
    $nonce=substr($packed,0,12);$tag=substr($packed,12,16);$cipher=substr($packed,28);
    $plain=openssl_decrypt($cipher,'aes-256-gcm',v050_queue_key($deviceId),OPENSSL_RAW_DATA,$nonce,$tag,$action);
    if($plain===false) throw new RuntimeException('Command parameter authentication failed');
    return $plain;
}

function ensure_v050_schema(): void {
    static $done=false;if($done)return;$done=true;$pdo=db();
    $cols=[];foreach($pdo->query('SHOW COLUMNS FROM pw_commands')->fetchAll() as $r)$cols[(string)$r['Field']]=true;
    $adds=[
        'lease_token'=>"ADD COLUMN lease_token CHAR(32) NULL AFTER status",
        'idempotency_key'=>"ADD COLUMN idempotency_key CHAR(32) NULL AFTER lease_token",
        'attempt_count'=>"ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER idempotency_key",
        'max_attempts'=>"ADD COLUMN max_attempts INT UNSIGNED NOT NULL DEFAULT 3 AFTER attempt_count",
        'expires_at'=>"ADD COLUMN expires_at DATETIME NULL AFTER leased_at",
    ];
    foreach($adds as $c=>$sql)if(!isset($cols[$c]))$pdo->exec('ALTER TABLE pw_commands '.$sql);
    try{$pdo->exec('CREATE UNIQUE INDEX ux_device_idempotency ON pw_commands(device_id,idempotency_key)');}catch(Throwable $e){}
    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_metrics (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, device_id VARCHAR(64) NOT NULL,
      captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, uptime_seconds BIGINT NULL,
      load1 DOUBLE NULL, memory_json LONGTEXT NULL, passwall_running TINYINT(1) NULL,
      INDEX idx_metric_device_time(device_id,captured_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, device_id VARCHAR(64) NOT NULL,
      kind VARCHAR(96) NOT NULL, data_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_event_device_time(device_id,created_at), INDEX idx_event_kind(kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_alert_state (
      device_id VARCHAR(64) PRIMARY KEY, offline_alerted_at DATETIME NULL, recovered_alerted_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function v050_record_metric(string $deviceId,array $body): void {
    ensure_v050_schema();$pdo=db();
    $q=$pdo->prepare("SELECT id FROM pw_metrics WHERE device_id=? AND captured_at > (NOW()-INTERVAL 60 SECOND) LIMIT 1");
    $q->execute([$deviceId]);if($q->fetch())return;
    $mem=is_array($body['memory']??null)?json_encode($body['memory'],JSON_UNESCAPED_SLASHES):null;
    $st=$pdo->prepare('INSERT INTO pw_metrics(device_id,uptime_seconds,load1,memory_json,passwall_running) VALUES(?,?,?,?,?)');
    $st->execute([$deviceId,(int)($body['uptime_seconds']??0),(float)($body['load1']??0),$mem,!empty($body['passwall_running'])?1:0]);
}

function v050_record_event(string $deviceId,string $kind,array $data): void {
    ensure_v050_schema();
    $st=db()->prepare('INSERT INTO pw_events(device_id,kind,data_json) VALUES(?,?,?)');
    $st->execute([$deviceId,$kind,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}
