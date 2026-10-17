<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
$raw=raw_body();$auth=authenticate_device($raw);$body=json_decode_assoc($raw);
$kind=(string)($body['kind']??'event');$data=is_array($body['data']??null)?$body['data']:[];
v050_record_event($auth['device_id'],$kind,$data);
try{
    $tg=cfg()['telegram']??[];
    $chat=(int)($tg['alert_chat_id']??(($tg['allowed_user_ids'][0]??0)));
    if($chat>0){
        $text="🔔 <b>PW Agent Event</b>\n";
        if($kind==='failover.switch'){
            $text="🛟 <b>Smart Failover</b>\nDefault node was switched automatically.\n\nFrom: <code>".h((string)($data['from']??''))."</code>\nTo: <b>".h((string)($data['to_name']??$data['to']??''))."</b>\nRelay: <b>".number_format((float)($data['relay_ms']??0),1)." ms</b>";
        }elseif($kind==='failover.return'){
            $text="↩️ <b>Preferred Node Recovered</b>\nSmart Failover returned Default to <b>".h((string)($data['to_name']??$data['to']??''))."</b>.";
        }elseif($kind==='failover.no_candidate'){
            $text="⚠️ <b>Smart Failover</b>\nCurrent Default node failed and no healthy HTTP Relay candidate was found.";
        }else{
            $text.='<code>'.h($kind).'</code>';
        }
        tg_send($chat,$text,main_keyboard());
    }
}catch(Throwable $e){error_log('PW Agent event delivery: '.$e->getMessage());}
response_json(['ok'=>true],$auth['secret']);
