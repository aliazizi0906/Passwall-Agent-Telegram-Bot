<?php
declare(strict_types=1);
require dirname(__DIR__).'/fleet_rollout.php';

function must(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}
function v060_user(int $uid): ?array{return $uid===123?['role'=>'owner']:['role'=>'viewer'];}
function v060_has_permission(int $uid,string $perm): bool{return $uid===123&&$perm==='agent.update';}
function v060_group_devices(int $uid,int $groupId): array {
    if($uid!==123)return [];
    return $groupId===1?[
      ['device_id'=>'test-a','display_name'=>'Test A'],
      ['device_id'=>'test-b','display_name'=>'Test B'],
    ]:[
      ['device_id'=>'test-c','display_name'=>'Test C'],
      ['device_id'=>'test-d','display_name'=>'Test D'],
    ];
}
function v060_can_device(int $uid,string $device): bool{return $uid===123&&str_starts_with($device,'test-');}
function v060_device_enabled(string $device): bool{return str_starts_with($device,'test-');}
function v060_audit_event(string $deviceId,?int $uid,string $source,string $action,string $status,array $params=[]): int{return 1;}
function ensure_v060_schema(): void {}
function app_version(): string{return '1.4.0-alpha.1';}
function db(): PDO {
    static $pdo;
    if($pdo===null) {
        $pdo=new PDO((string)getenv('PW_TEST_MYSQL_DSN'),(string)getenv('PW_TEST_MYSQL_USER'),(string)getenv('PW_TEST_MYSQL_PASSWORD'),[
          PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
        ]);
    }
    return $pdo;
}
function queue_command(string $device,string $action,array $params,?int $chatId,?int $uid,?int $messageId=null,?string $view=null): int {
    $st=db()->prepare("INSERT INTO pw_commands(device_id,action,status,created_at) VALUES(?,?,'queued',NOW())");
    $st->execute([$device,$action]);
    return (int)db()->lastInsertId();
}

must(pw_rollout_action_allowed('agent.update.apply'),'Agent updates permitted');
must(pw_rollout_action_allowed('agent.update.check'),'Update check permitted');
must(!pw_rollout_action_allowed('system.reboot'),'Reboot cannot be rolled out');
must(!pw_rollout_action_allowed('terminal.exec'),'Terminal cannot be rolled out');
foreach([0,6,-1] as $n){try{pw_rollout_wave_size($n);throw new RuntimeException('invalid wave allowed');}catch(RuntimeException $e){must($e->getMessage()==='Wave size must be 1-5 routers','Wave size rejection');}}
must(pw_rollout_wave_size(5)===5,'Valid wave size');
must(pw_rollout_health_ok(['last_seen'=>date('Y-m-d H:i:s'),'agent_version'=>app_version()],['finished_at'=>date('Y-m-d H:i:s')],'agent.update.apply',time()),'Fresh heartbeat');
must(!pw_rollout_health_ok(['last_seen'=>date('Y-m-d H:i:s',time()-500),'agent_version'=>app_version()],['finished_at'=>date('Y-m-d H:i:s')],'agent.update.apply',time()),'Reject stale heartbeat');
must(!pw_rollout_health_ok(['last_seen'=>date('Y-m-d H:i:s'),'agent_version'=>'v0.6.0'],['finished_at'=>date('Y-m-d H:i:s')],'agent.update.apply',time()),'Reject outdated Agent version');

if(!getenv('PW_TEST_MYSQL_DSN')) {echo "Rollout pure checks: OK (DB tests skipped)\n";exit;}
$pdo=db();
foreach(['pw_fleet_rollout_targets','pw_fleet_rollouts','pw_commands','pw_devices'] as $table){
    $pdo->exec("DROP TABLE IF EXISTS ".$table);
}
$pdo->exec("CREATE TABLE pw_devices(device_id VARCHAR(64) PRIMARY KEY,last_seen DATETIME,agent_version VARCHAR(64)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE pw_commands(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,device_id VARCHAR(64) NOT NULL,action VARCHAR(96),status VARCHAR(16) DEFAULT 'queued',finished_at DATETIME,created_at DATETIME) ENGINE=InnoDB");
foreach(['test-a','test-b','test-c','test-d'] as $id){
    $pdo->prepare('INSERT INTO pw_devices(device_id,last_seen,agent_version) VALUES(?,NOW(),?)')
        ->execute([$id,app_version()]);
}
try {
    $created=pw_rollout_create(123,1,'agent.update.check',2);
    $id=(int)$created['id'];must($created['status']==='draft','Rollout draft');
    $d=pw_rollout_detail(123,$id);must(count($d['targets'])===2,'Device snapshot');
    try{pw_rollout_control(888,$id,'start');throw new RuntimeException('Viewer allowed');}
    catch(RuntimeException $e){must(str_contains($e->getMessage(),'Only a verified Owner'),'Owner gate');}
    must(pw_rollout_control(123,$id,'start')['status']==='running','Owner start');
    pw_rollout_tick();$d=pw_rollout_detail(123,$id);
    must(count(array_filter($d['targets'],fn($x)=>$x['status']==='queued'))===1,'First wave must be one canary only');
    $cid=(int)$d['targets'][0]['command_id'];
    $pdo->prepare("UPDATE pw_commands SET status='done',finished_at=NOW() WHERE id=?")->execute([$cid]);
    pw_rollout_tick();$d=pw_rollout_detail(123,$id);
    must($d['rollout']['status']==='awaiting_approval','Canary completion must halt for Owner approval');
    pw_rollout_tick();$d=pw_rollout_detail(123,$id);
    must($d['rollout']['status']==='awaiting_approval','No unapproved next wave');
    pw_rollout_control(123,$id,'approve_next');
    pw_rollout_tick();$d=pw_rollout_detail(123,$id);
    must($d['targets'][1]['status']==='queued'&&(int)$d['targets'][1]['wave_number']===2,'Owner-approved second wave');
    $cid2=(int)$d['targets'][1]['command_id'];
    $pdo->prepare("UPDATE pw_commands SET status='done',finished_at=NOW() WHERE id=?")->execute([$cid2]);
    pw_rollout_tick();$d=pw_rollout_detail(123,$id);
    must($d['rollout']['status']==='completed','Successful rollout completes');

    $fail=pw_rollout_create(123,2,'agent.update.check',2);$rid=(int)$fail['id'];
    pw_rollout_control(123,$rid,'start');pw_rollout_tick();
    $d=pw_rollout_detail(123,$rid);
    $cmd=(int)$d['targets'][0]['command_id'];
    $pdo->prepare("UPDATE pw_commands SET status='failed',finished_at=NOW() WHERE id=?")->execute([$cmd]);
    pw_rollout_tick();$d=pw_rollout_detail(123,$rid);
    must($d['rollout']['status']==='paused','Failure must pause rollout');
    must($d['targets'][1]['status']==='pending','Failed canary must not propagate');

    $cancel=pw_rollout_create(123,2,'agent.update.check',1);
    pw_rollout_control(123,(int)$cancel['id'],'cancel');
    try{pw_rollout_control(123,(int)$cancel['id'],'start');throw new RuntimeException('Cancelled rollout restarted');}
    catch(RuntimeException $e){must(str_contains($e->getMessage(),'Invalid status transition'),'Cancelled rollout immutable');}
}finally {
    foreach(['pw_fleet_rollout_targets','pw_fleet_rollouts','pw_commands','pw_devices'] as $table)$pdo->exec("DROP TABLE IF EXISTS ".$table);
}
echo "Canary rollout MySQL tests: Owner gates, sequential waves, health, stop-on-error, cancellation: OK\n";
