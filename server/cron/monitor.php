<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
ensure_v060_schema();
$pdo=db();$threshold=max(120,(int)(cfg()['monitoring']['offline_after_seconds']??300));
$rows=$pdo->query('SELECT device_id,last_seen,hostname FROM pw_devices')->fetchAll();
foreach($rows as $r){
    $device=(string)$r['device_id'];
    if(!v060_device_enabled($device))continue;$last=$r['last_seen']?strtotime((string)$r['last_seen']):0;
    $offline=$last===0 || time()-$last>$threshold;
    $s=$pdo->prepare('SELECT offline_alerted_at FROM pw_alert_state WHERE device_id=?');$s->execute([$device]);$state=$s->fetch();
    $alerted=!empty($state['offline_alerted_at']);
    $maintenance=v060_is_maintenance($device);
    if($maintenance){
        if(!$offline&&$alerted)$pdo->prepare('UPDATE pw_alert_state SET offline_alerted_at=NULL,recovered_alerted_at=NOW() WHERE device_id=?')->execute([$device]);
        continue;
    }
    if($offline&&!$alerted){
        foreach(v060_notification_chats($device) as $chat)tg_send($chat,"🔴 <b>Router Offline</b>\nDevice: <b>".h(v060_device_label($device))."</b>\nLast seen: <code>".h((string)($r['last_seen']??'never'))."</code>",main_keyboard());
        $pdo->prepare('INSERT INTO pw_alert_state(device_id,offline_alerted_at) VALUES(?,NOW()) ON DUPLICATE KEY UPDATE offline_alerted_at=NOW()')->execute([$device]);
    }elseif(!$offline&&$alerted){
        foreach(v060_notification_chats($device) as $chat)tg_send($chat,"🟢 <b>Router Recovered</b>\nDevice: <b>".h(v060_device_label($device))."</b>",main_keyboard());
        $pdo->prepare('UPDATE pw_alert_state SET offline_alerted_at=NULL,recovered_alerted_at=NOW() WHERE device_id=?')->execute([$device]);
    }
}
$pdo->exec("DELETE FROM pw_metrics WHERE captured_at < (NOW()-INTERVAL 30 DAY)");
$pdo->exec("DELETE FROM pw_events WHERE created_at < (NOW()-INTERVAL 90 DAY)");
v060_evaluate_alerts();
v060_run_due_schedules();
require_once dirname(__DIR__).'/fleet_rollout.php';
pw_rollout_tick();
echo "ok\n";
