<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(1);}
require dirname(__DIR__) . '/lib.php';

$checks=[];
function check_row(string $name,bool $ok,string $detail=''): void {
    global $checks;$checks[]=$ok;
    echo ($ok?'[OK]   ':'[FAIL] ').$name.($detail!==''?' - '.$detail:'')."\n";
}
try{
    check_row('PHP >= 8.1',version_compare(PHP_VERSION,'8.1.0','>='),PHP_VERSION);
    foreach(['pdo_mysql','curl','openssl','sodium','zip'] as $ext)check_row('PHP extension '.$ext,extension_loaded($ext));
    check_row('HTTPS base_url',str_starts_with((string)(cfg()['base_url']??''),'https://'),(string)(cfg()['base_url']??''));
    try{db()->query('SELECT 1');ensure_v070_schema();check_row('Database / v0.7 schema',true);}catch(Throwable $e){check_row('Database / v0.7 schema',false,$e->getMessage());}
    $device=(string)(cfg()['telegram']['default_device_id']??'');
    check_row('Default device configured',$device!==''&&v060_device_enabled($device),$device);
    $fleetCount=(int)db()->query('SELECT COUNT(*) FROM pw_device_registry WHERE enabled=1')->fetchColumn();
    check_row('Fleet registry',$fleetCount>0,$fleetCount.' enabled device(s)');
    $ownerCount=(int)db()->query("SELECT COUNT(*) FROM pw_users WHERE enabled=1 AND role='owner'")->fetchColumn();
    check_row('RBAC owner',$ownerCount>0,$ownerCount.' owner(s)');
    try{
        $me=tg_call('getMe',[]);
        $username=(string)($me['result']['username']??'');
        check_row('Telegram bot API',true,$username!==''?'@'.$username:'connected');
    }catch(Throwable $e){check_row('Telegram bot API',false,$e->getMessage());}
    try{
        $wh=tg_call('getWebhookInfo',[]);
        $url=(string)($wh['result']['url']??'');
        $expected=rtrim((string)cfg()['base_url'],'/').'/bot/webhook.php';
        check_row('Telegram webhook',$url===$expected,$url);
    }catch(Throwable $e){check_row('Telegram webhook',false,$e->getMessage());}
    $releaseDir=dirname(__DIR__).'/releases';
    $bundles=glob($releaseDir.'/pw-agent-openwrt-'.app_version().'-*.tar.gz')?:[];
    check_row('Signed update bundle available',count($bundles)>0,count($bundles).' architecture(s)');
}catch(Throwable $e){
    check_row('Runtime',false,$e->getMessage());
}
$ok=!in_array(false,$checks,true);
echo "\n".($ok?'All required checks passed.':'One or more checks failed.')."\n";
exit($ok?0:1);
