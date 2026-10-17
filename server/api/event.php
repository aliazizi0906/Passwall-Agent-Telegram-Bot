<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
$raw=raw_body();$auth=authenticate_device($raw);$body=json_decode_assoc($raw);
$kind=(string)($body['kind']??'event');$data=is_array($body['data']??null)?$body['data']:[];
v050_record_event($auth['device_id'],$kind,$data);
try{
    $device=$auth['device_id'];
    if(!v060_is_maintenance($device)){
        $text="🔔 <b>PW Agent Event</b>\nDevice: <b>".h(v060_device_label($device))."</b>\n";
        if($kind==='failover.switch'){
            $text="🛟 <b>Smart Failover</b>\nDevice: <b>".h(v060_device_label($device))."</b>\nDefault node was switched automatically.\n\nFrom: <code>".h((string)($data['from']??''))."</code>\nTo: <b>".h((string)($data['to_name']??$data['to']??''))."</b>\nRelay: <b>".number_format((float)($data['relay_ms']??0),1)." ms</b>";
        }elseif($kind==='failover.return'){
            $text="↩️ <b>Preferred Node Recovered</b>\nDevice: <b>".h(v060_device_label($device))."</b>\nReturned Default to <b>".h((string)($data['to_name']??$data['to']??''))."</b>.";
        }elseif($kind==='failover.no_candidate'){
            $text="⚠️ <b>Smart Failover</b>\nDevice: <b>".h(v060_device_label($device))."</b>\nCurrent Default failed and no healthy Relay candidate was found.";
        }else{
            $text.='<code>'.h($kind).'</code>';
        }
        foreach(v060_notification_chats($device) as $chat)tg_send($chat,$text,main_keyboard());
    }
}catch(Throwable $e){error_log('PW Agent event delivery: '.$e->getMessage());}
response_json(['ok'=>true],$auth['secret']);
