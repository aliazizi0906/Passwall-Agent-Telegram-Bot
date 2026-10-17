<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
ensure_v060_schema();
$pdo=db();$threshold=max(120,(int)(cfg()['monitoring']['offline_after_seconds']??300));
$rows=$pdo->query('SELECT device_id,last_seen,hostname FROM pw_devices')->fetchAll();
$alertChat=(int)(cfg()['telegram']['alert_chat_id']??(cfg()['telegram']['allowed_user_ids'][0]??0));
foreach($rows as $r){
    $device=(string)$r['device_id'];
    if(!v060_device_enabled($device))continue;$last=$r['last_seen']?strtotime((string)$r['last_seen']):0;
    $offline=$last===0 || time()-$last>$threshold;
    $s=$pdo->prepare('SELECT offline_alerted_at FROM pw_alert_state WHERE device_id=?');$s->execute([$device]);$state=$s->fetch();
    $alerted=!empty($state['offline_alerted_at']);
    $maintenance=v060_is_maintenance($device);
    if($offline&&!$alerted&&!$maintenance){
        if($alertChat>0)tg_send($alertChat,"🔴 <b>Router Offline</b>
Device: <code>".h($device)."</code>
Last seen: <code>".h((string)($r['last_seen']??'never'))."</code>",main_keyboard());
        $pdo->prepare('INSERT INTO pw_alert_state(device_id,offline_alerted_at) VALUES(?,NOW()) ON DUPLICATE KEY UPDATE offline_alerted_at=NOW()')->execute([$device]);
    }elseif(!$offline&&$alerted){
        if($alertChat>0)tg_send($alertChat,"🟢 <b>Router Recovered</b>
Device: <code>".h($device)."</code>",main_keyboard());
        $pdo->prepare('UPDATE pw_alert_state SET offline_alerted_at=NULL,recovered_alerted_at=NOW() WHERE device_id=?')->execute([$device]);
    }
}
$pdo->exec("DELETE FROM pw_metrics WHERE captured_at < (NOW()-INTERVAL 30 DAY)");
$pdo->exec("DELETE FROM pw_events WHERE created_at < (NOW()-INTERVAL 90 DAY)");
echo "ok
";
