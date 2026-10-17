<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

$raw=raw_body();$auth=authenticate_device($raw);$device=$auth['device_id'];$secret=$auth['secret'];$body=json_decode_assoc($raw);
$id=(int)($body['command_id']??0);if($id<=0)response_json(['ok'=>false,'error'=>'invalid command_id'],$secret,400);
$success=!empty($body['success']);$result=$body['result']??null;$error=(string)($body['error']??'');

$pdo=db();$pdo->beginTransaction();
try{
    $q=$pdo->prepare('SELECT * FROM pw_commands WHERE id=? AND device_id=? FOR UPDATE');$q->execute([$id,$device]);$row=$q->fetch();
    if(!$row){$pdo->rollBack();response_json(['ok'=>false,'error'=>'command not found'],$secret,404);}
    if(in_array($row['status'],['done','failed'],true)){$pdo->commit();response_json(['ok'=>true,'duplicate'=>true],$secret);}
    $params=json_decode((string)$row['params_json'],true);if(!is_array($params))$params=[];

    $storedResult=$result;
    if((string)$row['action']==='system.backup.create' && is_array($storedResult)) unset($storedResult['data_b64']);
    $sensitiveActions=['system.backup.restore','wifi.set_password','passwall2.node.add_link','passwall2.node.add_manual','passwall2.subscription.add','terminal.exec'];
    $scrubParams=in_array((string)$row['action'],$sensitiveActions,true);

    $u=$pdo->prepare('UPDATE pw_commands SET status=?,finished_at=NOW(),result_json=?,error_text=?,params_json=? WHERE id=?');
    $u->execute([
        $success?'done':'failed',
        json_encode($storedResult,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        $error,
        $scrubParams?'{}':(string)$row['params_json'],
        $id,
    ]);
    $pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();response_json(['ok'=>false,'error'=>'result persistence failed'],$secret,500);}

if(!empty($row['chat_id'])){
    $chatId=(int)$row['chat_id'];$messageId=(int)($params['__message_id']??0);$action=(string)$row['action'];
    try{
        if($action==='system.backup.create' && $success && is_array($result) && !empty($result['data_b64'])){
            $bytes=base64_decode((string)$result['data_b64'],true);if($bytes===false)throw new RuntimeException('Router returned invalid backup data');
            if(strlen($bytes)>5242880)throw new RuntimeException('Backup exceeds the 5 MiB server upload limit');
            $filename=preg_replace('/[^A-Za-z0-9._-]+/','-',(string)($result['filename']??'openwrt-backup.tar.gz')) ?: 'openwrt-backup.tar.gz';
            $tmp=tempnam(sys_get_temp_dir(),'pwbackup_');if($tmp===false)throw new RuntimeException('Could not create temporary backup file');
            try{
                if(file_put_contents($tmp,$bytes)===false)throw new RuntimeException('Could not write temporary backup file');
                $caption="💾 <b>OpenWrt Configuration Backup</b>\n".h($filename)."\nSize: ".h(bytes_human(strlen($bytes)))."\nSHA256: <code>".h((string)($result['sha256']??hash('sha256',$bytes)))."</code>";
                tg_send_document($chatId,$tmp,$filename,$caption,backup_keyboard());
            }finally{ @unlink($tmp); unset($bytes); }
        }
        $f=format_result($action,$success,$result,$error,$params);
        tg_deliver($chatId,$messageId?:null,$f['text'],$f['keyboard']);
    }catch(Throwable $e){error_log('Telegram result delivery error: '.$e->getMessage());}
}

response_json(['ok'=>true],$secret);
