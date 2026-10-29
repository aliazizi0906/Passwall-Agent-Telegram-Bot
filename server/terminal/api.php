<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
header('Cache-Control: no-store');
if(!(cfg()['telegram']['terminal_enabled'] ?? true))response_json(['ok'=>false,'error'=>'Terminal is disabled'],null,403);
$in=json_decode_assoc(raw_body());$init=(string)($in['init_data']??'');
if($init!==''){
    try{$user=tg_validate_webapp_init_data($init);}catch(Throwable $e){response_json(['ok'=>false,'error'=>'Telegram verification failed'],null,403);}
    $uid=(int)($user['id']??0);
}else{
    // Browser sessions are permission-scoped and use the exact same CSRF
    // validation as the other PW Control APIs. No anonymous shell access.
    require_once dirname(__DIR__).'/web_auth.php';
    $uid=pw_web_auth_user()??0;
    $csrf=(string)($_SERVER['HTTP_X_PW_CSRF']??'');
    if($uid<=0||!pw_web_auth_csrf_ok($csrf))
        response_json(['ok'=>false,'error'=>'Web login required or CSRF mismatch'],null,403);
}
if($uid<=0)response_json(['ok'=>false,'error'=>'Terminal authentication required'],null,403);
ensure_v060_schema();if(!v060_user_allowed($uid)||!v060_has_permission($uid,'terminal.execute'))response_json(['ok'=>false,'error'=>'Terminal permission denied'],null,403);
$username=trim((string)($in['username']??''));$password=(string)($in['password']??'');$command=(string)($in['command']??'');$cwd=(string)($in['cwd']??'/root');
if(!preg_match('/^[A-Za-z0-9._-]{1,32}$/',$username))response_json(['ok'=>false,'error'=>'Invalid username']);
if($password===''||strlen($password)>256)response_json(['ok'=>false,'error'=>'Password is required']);
if(trim($command)===''||strlen($command)>4096)response_json(['ok'=>false,'error'=>'Command must be 1-4096 bytes']);
if(!str_starts_with($cwd,'/')||strlen($cwd)>512)$cwd='/root';
$requestedDevice=trim((string)($in['device_id']??''));
$device=$requestedDevice!==''?$requestedDevice:v060_active_device($uid);
if($device===''||!v060_can_device($uid,$device))response_json(['ok'=>false,'error'=>'Device access denied'],null,403);
try{$secret=v060_device_secret($device);}catch(Throwable $e){response_json(['ok'=>false,'error'=>'Device secret is not configured'],null,500);}
if(!function_exists('openssl_encrypt'))response_json(['ok'=>false,'error'=>'PHP OpenSSL extension is required for terminal credential protection'],null,500);
$plain=json_encode(['username'=>$username,'password'=>$password,'command'=>$command,'cwd'=>$cwd],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$key=hash('sha256',$secret,true);$nonce=random_bytes(12);$tag='';
$cipher=openssl_encrypt((string)$plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,'',16);
if($cipher===false||strlen($tag)!==16)response_json(['ok'=>false,'error'=>'Could not protect terminal credentials'],null,500);
$sealed=base64_encode($nonce.$tag.$cipher);unset($password,$plain,$cipher,$key,$secret);
$id=queue_command($device,'terminal.exec',['sealed'=>$sealed],null,$uid,null,'terminal');
$pdo=db();$deadline=microtime(true)+24.0;$row=null;
do{
    $q=$pdo->prepare('SELECT status,result_json,error_text FROM pw_commands WHERE id=?');$q->execute([$id]);$row=$q->fetch();
    if($row&&in_array($row['status'],['done','failed'],true))break;
    usleep(250000);
}while(microtime(true)<$deadline);
if(!$row||!in_array($row['status'],['done','failed'],true))response_json(['ok'=>false,'error'=>'Router response timeout','command_id'=>$id],null,504);
$result=json_decode((string)($row['result_json']??''),true);if(!is_array($result))$result=[];
$ok=$row['status']==='done';$err=(string)($row['error_text']??'');
$pdo->prepare('DELETE FROM pw_commands WHERE id=?')->execute([$id]);
response_json(['ok'=>$ok,'result'=>$result,'error'=>$err]);
