<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(1);}

function prompt_value(string $label,string $default=''): string {
    $suffix=$default!==''?' ['.$default.']':'';
    fwrite(STDOUT,$label.$suffix.': ');
    $v=trim((string)fgets(STDIN));
    return $v!==''?$v:$default;
}
function env_or_prompt(string $env,string $label,string $default=''): string {
    $v=getenv($env);
    return ($v!==false&&trim($v)!=='')?trim($v):prompt_value($label,$default);
}
function valid_device_id(string $v): bool { return (bool)preg_match('/^[A-Za-z0-9_-]{1,64}$/',$v); }
function sql_schema(PDO $pdo,string $path): void {
    $sql=file_get_contents($path);if($sql===false)throw new RuntimeException('Could not read schema.sql');
    foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql) as $stmt){
        $stmt=trim((string)$stmt);if($stmt!=='')$pdo->exec($stmt);
    }
}
function telegram_call(string $token,string $method,array $payload): array {
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension is required');
    $ch=curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30]);
    $raw=curl_exec($ch);if($raw===false){$e=curl_error($ch);curl_close($ch);throw new RuntimeException('Telegram: '.$e);}
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$j=json_decode((string)$raw,true);
    if($code<200||$code>=300||!is_array($j)||empty($j['ok']))throw new RuntimeException('Telegram API rejected setup: '.(string)$raw);
    return $j;
}
function sh_quote(string $v): string { return "'".str_replace("'","'\\''",$v)."'"; }

try{
    foreach(['pdo_mysql','curl','openssl'] as $ext)if(!extension_loaded($ext))throw new RuntimeException('Required PHP extension missing: '.$ext);
    $root=dirname(__DIR__);$configPath=$root.'/config.php';
    if(is_file($configPath)&&!in_array('--force',$argv,true))throw new RuntimeException('server/config.php already exists. Use --force only if you intend to replace it.');

    echo "PW Agent public installer v0.6\n\n";
    $base=rtrim(env_or_prompt('PW_BASE_URL','Public HTTPS base URL','https://example.com/routerbot'),'/');
    if(!preg_match('#^https://[A-Za-z0-9.-]+(?::[0-9]+)?(?:/[^\s]*)?$#',$base))throw new RuntimeException('Base URL must be a valid HTTPS URL.');

    $dbHost=env_or_prompt('PW_DB_HOST','MySQL host','localhost');
    $dbPort=env_or_prompt('PW_DB_PORT','MySQL port','3306');
    $dbName=env_or_prompt('PW_DB_NAME','MySQL database');
    $dbUser=env_or_prompt('PW_DB_USER','MySQL username');
    $dbPass=getenv('PW_DB_PASS');if($dbPass===false)$dbPass=prompt_value('MySQL password');
    $botToken=env_or_prompt('PW_TELEGRAM_BOT_TOKEN','Telegram Bot token');
    if(!preg_match('/^[0-9]+:[A-Za-z0-9_-]{20,}$/',$botToken))throw new RuntimeException('Telegram bot token format looks invalid.');
    $admin=env_or_prompt('PW_TELEGRAM_ADMIN_ID','Telegram numeric admin user ID');
    if(!ctype_digit($admin)||$admin==='0')throw new RuntimeException('Telegram admin ID must be numeric.');
    $device=env_or_prompt('PW_DEVICE_ID','Router device ID','router-01');
    if(!valid_device_id($device))throw new RuntimeException('Device ID may contain only A-Z, a-z, 0-9, _ and -.');
    $arch=env_or_prompt('PW_ROUTER_ARCH','Router architecture (armv7/arm64/amd64/386/mips/mipsle)','armv7');
    $archMap=['armv7l'=>'armv7','aarch64'=>'arm64','x86_64'=>'amd64','i686'=>'386'];
    $arch=$archMap[strtolower($arch)]??strtolower($arch);
    if(!in_array($arch,['armv7','arm64','amd64','386','mips','mipsle'],true))throw new RuntimeException('Unsupported architecture.');

    $dsn='mysql:host='.$dbHost.';port='.(int)$dbPort.';dbname='.$dbName.';charset=utf8mb4';
    $pdo=new PDO($dsn,$dbUser,(string)$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    sql_schema($pdo,$root.'/schema.sql');

    $deviceSecret=bin2hex(random_bytes(32));
    $webhookSecret=bin2hex(random_bytes(32));
    $instanceKey=base64_encode(random_bytes(32));
    $config=[
        'base_url'=>$base,
        'db'=>['dsn'=>$dsn,'user'=>$dbUser,'pass'=>(string)$dbPass],
        'telegram'=>[
            'bot_token'=>$botToken,'webhook_secret'=>$webhookSecret,
            'allowed_user_ids'=>[(int)$admin],'default_device_id'=>$device,
            'alert_chat_id'=>(int)$admin,'terminal_enabled'=>true,
        ],
        'monitoring'=>['offline_after_seconds'=>300],
        'security'=>['instance_key'=>$instanceKey],
        'scheduling'=>['timezone'=>'UTC'],
        'devices'=>[$device=>['secret'=>$deviceSecret,'enabled'=>true]],
    ];
    $php="<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n";
    if(file_put_contents($configPath,$php,LOCK_EX)===false)throw new RuntimeException('Could not write server/config.php');
    @chmod($configPath,0600);

    // Load the application after config.php exists so v0.6 can seed the
    // initial Device Registry and owner RBAC row from this configuration.
    require_once $root.'/lib.php';
    ensure_v060_schema();

    $provisionToken=rtrim(strtr(base64_encode(random_bytes(36)),'+/','-_'),'=');
    $st=$pdo->prepare('INSERT INTO pw_provision_tokens(token_hash,device_id,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))');
    $st->execute([hash('sha256',$provisionToken),$device]);

    telegram_call($botToken,'setWebhook',[
        'url'=>$base.'/bot/webhook.php',
        'secret_token'=>$webhookSecret,
        'allowed_updates'=>json_encode(['message','callback_query']),
        'drop_pending_updates'=>'true',
    ]);

    echo "\nDatabase schema installed.\nTelegram webhook configured.\n";
    $sync=$root.'/bin/sync-release.php';
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($sync).' --arch='.escapeshellarg($arch);
    passthru($cmd,$syncCode);
    if($syncCode!==0)echo "\nRelease sync was not completed. You can rerun: php server/bin/sync-release.php --arch={$arch}\n";

    $raw='https://raw.githubusercontent.com/aliazizi0906/Passwall-Agent-Telegram-Bot/main/openwrt/install-remote.sh';
    echo "\n=== ROUTER INSTALL COMMAND (token expires in 30 minutes and is single-use) ===\n";
    echo 'PW_SERVER_BASE='.sh_quote($base).' PW_PROVISION_TOKEN='.sh_quote($provisionToken).' sh -c "$(wget -qO- '.sh_quote($raw).')"'.PHP_EOL;
    echo "\nAfter installation, send /start to your Telegram bot.\n";
}catch(Throwable $e){fwrite(STDERR,"ERROR: ".$e->getMessage()."\n");exit(1);}
