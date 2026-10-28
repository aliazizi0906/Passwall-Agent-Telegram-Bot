<?php
declare(strict_types=1);

/**
 * Operator-approved canary rollout. No bulk "fire and forget" updates:
 * each wave is explicitly approved and next wave waits for command completion
 * plus a fresh router heartbeat at the expected Agent version.
 * The scheduler never uses user-supplied command parameters.
 */

function pw_rollout_action_allowed(string $action): bool {
    return in_array($action,['agent.update.check','agent.update.apply'],true);
}
function pw_rollout_wave_size(int $size): int {
    if($size<1||$size>5)throw new RuntimeException('Wave size must be 1-5 routers');
    return $size;
}
function pw_rollout_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_fleet_rollouts(
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      group_id BIGINT UNSIGNED NOT NULL,
      created_by BIGINT NOT NULL,
      command_action VARCHAR(48) NOT NULL,
      wave_size TINYINT UNSIGNED NOT NULL DEFAULT 1,
      approved_wave SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      status ENUM('draft','running','awaiting_approval','paused','failed','completed','cancelled') NOT NULL DEFAULT 'draft',
      last_error VARCHAR(255) NULL,
      started_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_rollout_status(status,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_fleet_rollout_targets(
      rollout_id BIGINT UNSIGNED NOT NULL,
      device_id VARCHAR(64) NOT NULL,
      sequence_number SMALLINT UNSIGNED NOT NULL,
      wave_number SMALLINT UNSIGNED NULL,
      status ENUM('pending','queued','verifying','done','failed') NOT NULL DEFAULT 'pending',
      command_id BIGINT UNSIGNED NULL,
      error_message VARCHAR(255) NULL,
      queued_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (rollout_id,device_id),
      UNIQUE KEY ux_rollout_sequence(rollout_id,sequence_number),
      INDEX idx_rollout_target_status(rollout_id,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function pw_rollout_actor(int $uid): void {
    // Keep release changes owner-only even if an Operator can restart services.
    $user=v060_user($uid);
    if(!$user||($user['role']??'')!=='owner'||!v060_has_permission($uid,'agent.update')){
        throw new RuntimeException('Only a verified Owner with Agent update permission may manage rollouts');
    }
}
function pw_rollout_create(int $uid,int $groupId,string $action,int $waveSize): array {
    pw_rollout_actor($uid);
    if($groupId<=0||!pw_rollout_action_allowed($action))throw new RuntimeException('Unsupported rollout target/action');
    pw_rollout_wave_size($waveSize);
    ensure_v060_schema();
    $pdo=db();pw_rollout_schema($pdo);
    $devices=v060_group_devices($uid,$groupId);
    if(count($devices)<1||count($devices)>50)throw new RuntimeException('Group must have 1-50 accessible enabled routers');
    // Queue registration and target snapshot together; never call Schema DDL in a transaction.
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare("INSERT INTO pw_fleet_rollouts(group_id,created_by,command_action,wave_size)
                             VALUES(?,?,?,?)");
        $st->execute([$groupId,$uid,$action,$waveSize]);
        $id=(int)$pdo->lastInsertId();
        $st=$pdo->prepare('INSERT INTO pw_fleet_rollout_targets(rollout_id,device_id,sequence_number) VALUES(?,?,?)');
        foreach($devices as $i=>$row)$st->execute([$id,(string)$row['device_id'],$i+1]);
        $pdo->commit();
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    v060_audit_event('fleet',$uid,'rollout','fleet.rollout.create','done',[
      'id'=>$id,'group_id'=>$groupId,'command_action'=>$action,'router_count'=>count($devices)
    ]);
    return ['id'=>$id,'routers'=>count($devices),'status'=>'draft','first_wave'=>1,'wave_size'=>$waveSize];
}
function pw_rollout_list(int $uid): array {
    pw_rollout_actor($uid);
    pw_rollout_schema(db());
    $st=db()->prepare("SELECT r.id,r.group_id,r.command_action,r.wave_size,r.status,
     r.last_error,r.created_at,r.updated_at,
     COUNT(t.device_id) AS total_routers,
     COALESCE(SUM(t.status='done'),0) AS completed_routers,
     COALESCE(SUM(t.status='failed'),0) AS failed_routers
     FROM pw_fleet_rollouts r JOIN pw_fleet_rollout_targets t ON t.rollout_id=r.id
     WHERE r.created_by=? GROUP BY r.id ORDER BY r.id DESC LIMIT 25");
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function pw_rollout_detail(int $uid,int $id): array {
    pw_rollout_actor($uid);
    $st=db()->prepare('SELECT * FROM pw_fleet_rollouts WHERE id=? AND created_by=?');
    $st->execute([$id,$uid]);$rollout=$st->fetch(PDO::FETCH_ASSOC);
    if(!$rollout)throw new RuntimeException('Rollout unavailable');
    $st=db()->prepare('SELECT device_id,sequence_number,wave_number,status,command_id,error_message,queued_at
                        FROM pw_fleet_rollout_targets WHERE rollout_id=? ORDER BY sequence_number');
    $st->execute([$id]);
    return ['rollout'=>$rollout,'targets'=>$st->fetchAll(PDO::FETCH_ASSOC)];
}
function pw_rollout_control(int $uid,int $id,string $action): array {
    pw_rollout_actor($uid);
    if($id<=0||!in_array($action,['start','approve_next','pause','resume','cancel'],true))throw new RuntimeException('Invalid rollout operation');
    // Share an advisory lock with Cron: pause/cancel must never race a new wave.
    $pdo=db();
    $got=$pdo->query("SELECT GET_LOCK('pw_agent_fleet_rollout_v14',5)")->fetchColumn();
    if((int)$got!==1)throw new RuntimeException('Rollout scheduler is busy; retry in a moment');
    try {
      $pdo->beginTransaction();
      try {
        $st=$pdo->prepare('SELECT status FROM pw_fleet_rollouts WHERE id=? AND created_by=? FOR UPDATE');
        $st->execute([$id,$uid]);$status=$st->fetchColumn();
        if($status===false)throw new RuntimeException('Rollout unavailable');
        if($action==='start'&&$status==='draft')$next='running';
        elseif($action==='approve_next'&&$status==='awaiting_approval')$next='running';
        elseif($action==='pause'&&in_array($status,['running','awaiting_approval'],true))$next='paused';
        elseif($action==='resume'&&$status==='paused'){
            $ck=$pdo->prepare("SELECT COUNT(*) FROM pw_fleet_rollout_targets WHERE rollout_id=? AND status='failed'");
            $ck->execute([$id]);
            if((int)$ck->fetchColumn()>0)throw new RuntimeException('Failed router commands require a new rollout, not resume');
            $next='running';
        }
        elseif($action==='cancel'&&in_array($status,['draft','running','awaiting_approval','paused'],true))$next='cancelled';
        else throw new RuntimeException('Invalid status transition: '.$status.' -> '.$action);
        $st=$pdo->prepare('UPDATE pw_fleet_rollouts SET status=?, approved_wave=approved_wave+?,last_error=NULL,started_at=COALESCE(started_at,NOW()) WHERE id=?');
        $st->execute([$next,in_array($action,['start','approve_next'],true)?1:0,$id]);
        $pdo->commit();
      }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }finally{$pdo->query("SELECT RELEASE_LOCK('pw_agent_fleet_rollout_v14')");}
    v060_audit_event('fleet',$uid,'rollout','fleet.rollout.'.$action,'done',['id'=>$id,'status'=>$next]);
    return ['id'=>$id,'status'=>$next,
      'warning'=>$action==='cancel'?'Already queued router commands cannot be revoked here.':null];
}
function pw_rollout_health_ok(array $device,array $cmd,string $commandAction,int $now): bool {
    $seen=!empty($device['last_seen'])?strtotime((string)$device['last_seen']):0;
    $done=!empty($cmd['finished_at'])?strtotime((string)$cmd['finished_at']):0;
    if($seen<=0||$done<=0||$seen<$done||$now-$seen>180)return false;
    if($commandAction==='agent.update.apply'){
        return (string)($device['agent_version']??'')===app_version();
    }
    return true;
}
function pw_rollout_tick(): void {
    ensure_v060_schema();
    $pdo=db();pw_rollout_schema($pdo);
    // One scheduler worker globally; no concurrent double-dispatch.
    $got=$pdo->query("SELECT GET_LOCK('pw_agent_fleet_rollout_v14',0)")->fetchColumn();
    if((int)$got!==1)return;
    try {
        $rollouts=$pdo->query("SELECT id,created_by,command_action,wave_size,approved_wave
                              FROM pw_fleet_rollouts WHERE status='running' ORDER BY id LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rollouts as $rollout) {
            $rid=(int)$rollout['id'];$uid=(int)$rollout['created_by'];
            try{pw_rollout_tick_one($rid,$uid,(string)$rollout['command_action'],(int)$rollout['wave_size'],(int)$rollout['approved_wave']);}
            catch(Throwable $e){
                $st=$pdo->prepare("UPDATE pw_fleet_rollouts SET status='paused',last_error=? WHERE id=? AND status='running'");
                $st->execute([substr($e->getMessage(),0,255),$rid]);
            }
        }
    }finally{$pdo->query("SELECT RELEASE_LOCK('pw_agent_fleet_rollout_v14')");}
}
function pw_rollout_tick_one(int $id,int $uid,string $action,int $waveSize,int $approvedWave): void {
    $pdo=db();
    pw_rollout_actor($uid);
    if(!pw_rollout_action_allowed($action))throw new RuntimeException('Rollout action was revoked');
    $st=$pdo->prepare("SELECT t.*,c.status command_status,c.finished_at,d.last_seen,d.agent_version
       FROM pw_fleet_rollout_targets t
       LEFT JOIN pw_commands c ON c.id=t.command_id AND c.device_id=t.device_id
       LEFT JOIN pw_devices d ON d.device_id=t.device_id WHERE t.rollout_id=? ORDER BY t.sequence_number");
    $st->execute([$id]);$targets=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$targets)throw new RuntimeException('Rollout has no target rows');
    $now=time();$active=0;$done=0;$pending=[];$highestQueuedWave=0;
    foreach($targets as $target){
        $state=(string)$target['status'];$device=(string)$target['device_id'];
        $highestQueuedWave=max($highestQueuedWave,(int)($target['wave_number']??0));
        if($state==='done'){$done++;continue;}
        if($state==='failed')throw new RuntimeException('Router '.$device.' failed; approval required');
        if($state==='pending'){$pending[]=$target;continue;}
        if($state==='queued'||$state==='verifying'){
            $stt=(string)($target['command_status']??'');
            if($stt==='failed'||$stt===''){
                $pdo->prepare("UPDATE pw_fleet_rollout_targets SET status='failed',error_message='Command failed or missing' WHERE rollout_id=? AND device_id=?")->execute([$id,$device]);
                throw new RuntimeException('Router '.$device.' failed; rollout halted');
            }
            if($stt==='done'&&pw_rollout_health_ok($target,$target,$action,$now)){
                $pdo->prepare("UPDATE pw_fleet_rollout_targets SET status='done' WHERE rollout_id=? AND device_id=?")->execute([$id,$device]);
                $done++;continue;
            }
            if($stt==='done'&&$state==='queued'){
                $pdo->prepare("UPDATE pw_fleet_rollout_targets SET status='verifying' WHERE rollout_id=? AND device_id=?")->execute([$id,$device]);
            }
            if(!empty($target['queued_at'])&&$now-strtotime((string)$target['queued_at'])>1200){
                throw new RuntimeException('Router '.$device.' did not complete/verify within 20 minutes');
            }
            $active++;
        }
    }
    if($active>0)return;
    if(count($pending)===0){
        $pdo->prepare("UPDATE pw_fleet_rollouts SET status='completed' WHERE id=? AND status='running'")->execute([$id]);
        return;
    }
    if($highestQueuedWave >= $approvedWave){
        // Never dispatch the next group without an explicit owner approval.
        $pdo->prepare("UPDATE pw_fleet_rollouts SET status='awaiting_approval' WHERE id=? AND status='running'")->execute([$id]);
        return;
    }
    if($approvedWave<=0)throw new RuntimeException('A wave must be approved by the Owner');
    $batch=array_slice($pending,0,$approvedWave===1?1:pw_rollout_wave_size($waveSize));
    // Every wave requires fresh heartbeat and per-router RBAC recheck.
    foreach($batch as $target){
        $device=(string)$target['device_id'];
        if(!v060_device_enabled($device)||!v060_can_device($uid,$device)){
            throw new RuntimeException('Router '.$device.' is disabled or no longer in owner scope');
        }
        $st=$pdo->prepare('SELECT last_seen FROM pw_devices WHERE device_id=?');
        $st->execute([$device]);$last=$st->fetchColumn();
        if(!$last||$now-strtotime((string)$last)>180){
            throw new RuntimeException('Router '.$device.' is offline; rollout halted');
        }
    }
    foreach($batch as $target){
        $device=(string)$target['device_id'];
        $pdo->beginTransaction();
        try {
            $cid=queue_command($device,$action,[],null,$uid,null,'fleet-rollout');
            $st=$pdo->prepare("UPDATE pw_fleet_rollout_targets
              SET status='queued',command_id=?,queued_at=NOW(),wave_number=?
              WHERE rollout_id=? AND device_id=? AND status='pending'");
            $st->execute([$cid,$approvedWave,$id,$device]);
            if($st->rowCount()!==1)throw new RuntimeException('Rollout state conflict');
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
