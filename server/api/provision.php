<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

header('Cache-Control: no-store');
$body=json_decode_assoc(raw_body());
$token=trim((string)($body['token']??''));
if(!preg_match('/^[A-Za-z0-9_-]{32,128}$/',$token)) response_json(['ok'=>false,'error'=>'invalid provisioning token'],null,400);

// Initialize/migrate the schema before starting a transaction.
// MySQL DDL (including CREATE TABLE IF NOT EXISTS) implicitly commits an active transaction.
// v060_device_secret() invokes ensure_v060_schema(), so it must not be the first caller
// after the provisioning transaction has begun.
ensure_v060_schema();
$pdo=db();$pdo->beginTransaction();
try{
    $hash=hash('sha256',$token);
    $q=$pdo->prepare('SELECT id,device_id,expires_at,used_at FROM pw_provision_tokens WHERE token_hash=? FOR UPDATE');
    $q->execute([$hash]);$row=$q->fetch();
    if(!$row||!empty($row['used_at'])||strtotime((string)$row['expires_at'])<time()){
        $pdo->rollBack();response_json(['ok'=>false,'error'=>'provisioning token is invalid, expired, or already used'],null,403);
    }
    $device=(string)$row['device_id'];
    try{$secret=v060_device_secret($device);}catch(Throwable $e){$pdo->rollBack();response_json(['ok'=>false,'error'=>'device credentials are not configured'],null,500);}
    if($secret===''){$pdo->rollBack();response_json(['ok'=>false,'error'=>'device credentials are not configured'],null,500);}
    $u=$pdo->prepare('UPDATE pw_provision_tokens SET used_at=NOW() WHERE id=? AND used_at IS NULL');
    $u->execute([(int)$row['id']]);
    if($u->rowCount()!==1){$pdo->rollBack();response_json(['ok'=>false,'error'=>'provisioning token already consumed'],null,409);}
    $pdo->commit();
    response_json([
        'ok'=>true,
        'server_url'=>rtrim((string)cfg()['base_url'],'/').'/api',
        'device_id'=>$device,
        'device_secret'=>$secret,
    ]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    response_json(['ok'=>false,'error'=>'provisioning failed'],null,500);
}
