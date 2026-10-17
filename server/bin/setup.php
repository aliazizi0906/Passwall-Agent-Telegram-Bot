<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only. For cPanel without Terminal, open setup.php in a browser.\n");exit(1);}

require dirname(__DIR__).'/setup_core.php';
require dirname(__DIR__).'/release_tools.php';

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

try{
    $req=pw_setup_requirements();
    if(!$req['php_ok'])throw new RuntimeException('PHP 8.1 or newer is required');
    if($req['missing_extensions'])throw new RuntimeException('Required PHP extension(s) missing: '.implode(', ',$req['missing_extensions']));

    $root=dirname(__DIR__);
    $configPath=$root.'/config.php';
    if(is_file($configPath)&&!in_array('--force',$argv,true))throw new RuntimeException('config.php already exists. Use the browser/Dashboard for normal administration.');

    echo "PW Agent public installer v".pw_setup_version()."\n";
    echo "Browser installer is also available at <backend URL>/setup.php\n\n";

    $base=pw_setup_validate_base_url(env_or_prompt('PW_BASE_URL','Public HTTPS base URL','https://example.com/routerbot'));
    $dbHost=env_or_prompt('PW_DB_HOST','MySQL host','localhost');
    $dbPort=env_or_prompt('PW_DB_PORT','MySQL port','3306');
    $dbName=env_or_prompt('PW_DB_NAME','MySQL database');
    $dbUser=env_or_prompt('PW_DB_USER','MySQL username');
    $dbPass=getenv('PW_DB_PASS');if($dbPass===false)$dbPass=prompt_value('MySQL password');
    $botToken=env_or_prompt('PW_TELEGRAM_BOT_TOKEN','Telegram Bot token');
    if(!preg_match('/^[0-9]+:[A-Za-z0-9_-]{20,}$/',$botToken))throw new RuntimeException('Telegram bot token format looks invalid');
    $admin=env_or_prompt('PW_TELEGRAM_ADMIN_ID','Telegram numeric Owner user ID');
    if(!ctype_digit($admin)||$admin==='0')throw new RuntimeException('Telegram Owner ID must be numeric');
    $device=env_or_prompt('PW_DEVICE_ID','Router device ID','router-01');
    if(!pw_setup_valid_device_id($device))throw new RuntimeException('Invalid Device ID');
    $deviceName=env_or_prompt('PW_DEVICE_NAME','Router display name','Main Router');
    $arch=pw_setup_normalize_arch(env_or_prompt('PW_ROUTER_ARCH','Router architecture','armv7'));
    $timezone=env_or_prompt('PW_TIMEZONE','Scheduling timezone','UTC');
    if(!in_array($timezone,timezone_identifiers_list(),true))throw new RuntimeException('Invalid timezone');

    $db=pw_setup_database([
        'db_host'=>$dbHost,'db_port'=>$dbPort,'db_name'=>$dbName,'db_user'=>$dbUser,'db_pass'=>$dbPass,
    ]);
    /** @var PDO $pdo */
    $pdo=$db['pdo'];
    pw_setup_sql_schema($pdo,$root.'/schema.sql');
    $me=pw_setup_telegram_call($botToken,'getMe');

    $deviceSecret=bin2hex(random_bytes(32));
    $webhookSecret=bin2hex(random_bytes(32));
    $instanceKey=base64_encode(random_bytes(32));
    $config=pw_setup_build_config([
        'base_url'=>$base,'bot_token'=>$botToken,'admin_id'=>(int)$admin,'device_id'=>$device,'timezone'=>$timezone,
    ],(string)$db['dsn'],(string)$db['user'],(string)$db['pass'],$deviceSecret,$webhookSecret,$instanceKey);
    pw_setup_write_config($configPath,$config);

    require_once $root.'/lib.php';
    ensure_v060_schema();
    db()->prepare('UPDATE pw_device_registry SET display_name=? WHERE device_id=?')->execute([$deviceName,$device]);

    $token=rtrim(strtr(base64_encode(random_bytes(36)),'+/','-_'),'=');
    db()->prepare('INSERT INTO pw_provision_tokens(token_hash,device_id,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([hash('sha256',$token),$device]);

    pw_setup_telegram_call($botToken,'setWebhook',[
        'url'=>$base.'/bot/webhook.php',
        'secret_token'=>$webhookSecret,
        'allowed_updates'=>json_encode(['message','callback_query']),
        'drop_pending_updates'=>'true',
    ]);

    try{
        $release=pw_release_sync_arch($root,pw_setup_version(),$arch);
        echo "Release cache: ".$release['asset']." ".$release['sha256']."\n";
    }catch(Throwable $e){
        fwrite(STDERR,"WARNING: release sync failed: ".$e->getMessage()."\n");
    }

    echo "\nDatabase schema installed.\n";
    echo "Telegram bot: @".(string)($me['result']['username']??'unknown')."\n";
    echo "Webhook configured.\n";
    echo "Backend directory: ".$root."\n";
    echo "\n=== ROUTER INSTALL COMMAND ===\n";
    echo pw_setup_router_command($base,$token)."\n";
    echo "\n=== CPANEL CRON JOB ===\n";
    echo pw_setup_cron_command($root)."\n";
    echo "\nAfter installation, send /start to your Telegram bot.\n";
}catch(Throwable $e){
    fwrite(STDERR,"ERROR: ".$e->getMessage()."\n");
    exit(1);
}
