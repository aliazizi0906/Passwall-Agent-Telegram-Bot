<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(1);}
require dirname(__DIR__) . '/lib.php';
try{
    ensure_v050_schema();
    ensure_v060_schema();
    $device=$argv[1]??(string)(cfg()['telegram']['default_device_id']??'');
    if($device===''||!v060_device_enabled($device))throw new RuntimeException('Unknown or disabled device ID');
    $token=rtrim(strtr(base64_encode(random_bytes(36)),'+/','-_'),'=');
    $st=db()->prepare('INSERT INTO pw_provision_tokens(token_hash,device_id,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))');
    $st->execute([hash('sha256',$token),$device]);
    $base=rtrim((string)cfg()['base_url'],'/');
    $raw='https://raw.githubusercontent.com/aliazizi0906/Passwall-Agent-Telegram-Bot/main/openwrt/install-remote.sh';
    echo "Single-use token (30 minutes):\n".$token."\n\n";
    echo "Router install command:\nPW_SERVER_BASE='".str_replace("'","'\\''",$base)."' PW_PROVISION_TOKEN='".$token."' sh -c \"\$(wget -qO- '".$raw."')\"\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
