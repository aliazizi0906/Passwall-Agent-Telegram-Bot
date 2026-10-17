<?php
declare(strict_types=1);

/*
 * Shared installer helpers. This file contains no HTML and is safe to use
 * from both the CLI installer and the browser-based cPanel installer.
 */

function pw_setup_version(): string { return '0.6.0'; }

function pw_setup_valid_device_id(string $v): bool {
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,64}$/',$v);
}

function pw_setup_normalize_arch(string $arch): string {
    $archMap=['armv7l'=>'armv7','armv7'=>'armv7','aarch64'=>'arm64','arm64'=>'arm64','x86_64'=>'amd64','amd64'=>'amd64','i386'=>'386','i686'=>'386','386'=>'386','mips'=>'mips','mipsel'=>'mipsle','mipsle'=>'mipsle'];
    $key=strtolower(trim($arch));
    $out=$archMap[$key]??$key;
    if(!in_array($out,['armv7','arm64','amd64','386','mips','mipsle'],true))throw new RuntimeException('Unsupported router architecture.');
    return $out;
}

function pw_setup_requirements(): array {
    $required=['pdo_mysql','curl','openssl'];
    $missing=[];
    foreach($required as $ext)if(!extension_loaded($ext))$missing[]=$ext;
    return [
        'php_ok'=>version_compare(PHP_VERSION,'8.1.0','>='),
        'php_version'=>PHP_VERSION,
        'missing_extensions'=>$missing,
        'required_extensions'=>$required,
    ];
}

function pw_setup_sql_schema(PDO $pdo,string $path): void {
    $sql=file_get_contents($path);
    if($sql===false)throw new RuntimeException('Could not read schema.sql');
    foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql) as $stmt){
        $stmt=trim((string)$stmt);
        if($stmt!=='')$pdo->exec($stmt);
    }
}

function pw_setup_telegram_call(string $token,string $method,array $payload=[]): array {
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension is required');
    $url='https://api.telegram.org/bot'.$token.'/'.$method;
    $ch=curl_init($url);
    $opts=[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>30,
        CURLOPT_USERAGENT=>'pw-agent-setup/'.pw_setup_version(),
    ];
    if($payload!==[]){
        $opts[CURLOPT_POST]=true;
        $opts[CURLOPT_POSTFIELDS]=$payload;
    }
    curl_setopt_array($ch,$opts);
    $raw=curl_exec($ch);
    if($raw===false){$e=curl_error($ch);curl_close($ch);throw new RuntimeException('Telegram: '.$e);}
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j=json_decode((string)$raw,true);
    if($code<200||$code>=300||!is_array($j)||empty($j['ok'])){
        $desc=is_array($j)?(string)($j['description']??'Telegram API rejected the request'):'Telegram API rejected the request';
        throw new RuntimeException($desc);
    }
    return $j;
}

function pw_setup_telegram_candidates(string $token): array {
    $j=pw_setup_telegram_call($token,'getUpdates',['offset'=>'-100','limit'=>'100','timeout'=>'0']);
    $out=[];
    foreach(($j['result']??[]) as $u){
        if(!is_array($u))continue;
        $from=$u['message']['from']??$u['callback_query']['from']??$u['edited_message']['from']??null;
        if(!is_array($from)||empty($from['id']))continue;
        $id=(int)$from['id'];
        if($id<=0)continue;
        $name=trim((string)($from['first_name']??'').' '.(string)($from['last_name']??''));
        $username=trim((string)($from['username']??''));
        $out[$id]=['id'=>$id,'name'=>$name,'username'=>$username];
    }
    return array_values($out);
}

function pw_setup_detect_base_url(array $server): string {
    $https=false;
    if(isset($server['HTTPS'])&&strtolower((string)$server['HTTPS'])!=='off'&&(string)$server['HTTPS']!=='')$https=true;
    if(isset($server['REQUEST_SCHEME'])&&strtolower((string)$server['REQUEST_SCHEME'])==='https')$https=true;
    // cPanel can sit behind a trusted frontend proxy. Use this only as a URL
    // suggestion; final setup still requires an explicit HTTPS value.
    if(isset($server['HTTP_X_FORWARDED_PROTO'])&&strtolower(trim(explode(',',(string)$server['HTTP_X_FORWARDED_PROTO'])[0]))==='https')$https=true;

    $host=trim((string)($server['HTTP_HOST']??$server['SERVER_NAME']??''));
    if($host==='')return '';
    $script=(string)($server['SCRIPT_NAME']??'/setup.php');
    $dir=str_replace('\\','/',dirname($script));
    if($dir==='/'||$dir==='.')$dir='';
    $dir=rtrim($dir,'/');
    return ($https?'https':'http').'://'.$host.$dir;
}

function pw_setup_validate_base_url(string $base): string {
    $base=rtrim(trim($base),'/');
    if($base===''||strlen($base)>2048)throw new RuntimeException('Public Base URL is required.');
    $parts=parse_url($base);
    if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])){
        throw new RuntimeException('Public Base URL must be a valid HTTPS URL.');
    }
    if(isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])){
        throw new RuntimeException('Base URL must not contain credentials, query parameters or fragments.');
    }
    return $base;
}

function pw_setup_database(array $in): array {
    $host=trim((string)($in['db_host']??'localhost'));
    $port=(int)($in['db_port']??3306);
    $name=trim((string)($in['db_name']??''));
    $user=trim((string)($in['db_user']??''));
    $pass=(string)($in['db_pass']??'');
    if($host===''||strlen($host)>255)throw new RuntimeException('Invalid MySQL host.');
    if($port<1||$port>65535)throw new RuntimeException('Invalid MySQL port.');
    if($name===''||strlen($name)>128)throw new RuntimeException('MySQL database name is required.');
    if($user===''||strlen($user)>128)throw new RuntimeException('MySQL username is required.');
    $dsn='mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4';
    $pdo=new PDO($dsn,$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $pdo->query('SELECT 1');
    return ['pdo'=>$pdo,'dsn'=>$dsn,'user'=>$user,'pass'=>$pass];
}

function pw_setup_build_config(array $values,string $dsn,string $dbUser,string $dbPass,string $deviceSecret,string $webhookSecret,string $instanceKey): array {
    return [
        'base_url'=>$values['base_url'],
        'db'=>['dsn'=>$dsn,'user'=>$dbUser,'pass'=>$dbPass],
        'telegram'=>[
            'bot_token'=>$values['bot_token'],
            'webhook_secret'=>$webhookSecret,
            'allowed_user_ids'=>[(int)$values['admin_id']],
            'default_device_id'=>$values['device_id'],
            'alert_chat_id'=>(int)$values['admin_id'],
            'terminal_enabled'=>true,
        ],
        'monitoring'=>['offline_after_seconds'=>300],
        'security'=>['instance_key'=>$instanceKey],
        'scheduling'=>['timezone'=>$values['timezone']],
        'devices'=>[
            $values['device_id']=>['secret'=>$deviceSecret,'enabled'=>true],
        ],
    ];
}

function pw_setup_write_config(string $path,array $config): void {
    $php="<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n";
    $tmp=$path.'.tmp.'.bin2hex(random_bytes(4));
    if(file_put_contents($tmp,$php,LOCK_EX)===false)throw new RuntimeException('Could not write temporary config file.');
    @chmod($tmp,0600);
    if(!@rename($tmp,$path)){
        @unlink($tmp);
        throw new RuntimeException('Could not install config.php. Check directory permissions.');
    }
    @chmod($path,0600);
}

function pw_setup_router_command(string $base,string $token): string {
    $raw='https://raw.githubusercontent.com/aliazizi0906/Passwall-Agent-Telegram-Bot/main/openwrt/install-remote.sh';
    $quote=static fn(string $v): string => "'".str_replace("'","'\\''",$v)."'";
    return 'PW_SERVER_BASE='.$quote($base).' PW_PROVISION_TOKEN='.$quote($token).' sh -c "$(wget -qO- '.$quote($raw).')"';
}

function pw_setup_cron_command(string $root): string {
    $script=$root.'/cron/monitor.php';
    $phpCandidates=[
        '/usr/local/bin/php',
        '/usr/bin/php',
        '/opt/cpanel/ea-php84/root/usr/bin/php',
        '/opt/cpanel/ea-php83/root/usr/bin/php',
        '/opt/cpanel/ea-php82/root/usr/bin/php',
        '/opt/cpanel/ea-php81/root/usr/bin/php',
    ];
    $php='php';
    foreach($phpCandidates as $candidate)if(is_file($candidate)&&is_executable($candidate)){$php=$candidate;break;}
    return '* * * * * '.escapeshellarg($php).' '.escapeshellarg($script).' >/dev/null 2>&1';
}
