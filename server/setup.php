<?php
declare(strict_types=1);

require __DIR__ . '/setup_core.php';
require __DIR__ . '/release_tools.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if(session_status()!==PHP_SESSION_ACTIVE){
    $secureRequest=(isset($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off'&&(string)$_SERVER['HTTPS']!=='')
        || (isset($_SERVER['REQUEST_SCHEME'])&&strtolower((string)$_SERVER['REQUEST_SCHEME'])==='https')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])&&strtolower(trim(explode(',',(string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]))==='https');
    @ini_set('session.use_strict_mode','1');
    session_name('pw_agent_setup');
    session_set_cookie_params(['httponly'=>true,'secure'=>$secureRequest,'samesite'=>'Strict']);
    session_start();
}
if(empty($_SESSION['pw_setup_csrf']))$_SESSION['pw_setup_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['pw_setup_csrf'];

$root=__DIR__;
$configPath=$root.'/config.php';
$installed=is_file($configPath);
$req=pw_setup_requirements();
$detectedBase=pw_setup_detect_base_url($_SERVER);
$defaults=[
    'base_url'=>str_starts_with($detectedBase,'https://')?$detectedBase:'',
    'db_host'=>'localhost',
    'db_port'=>'3306',
    'db_name'=>'',
    'db_user'=>'',
    'admin_id'=>'',
    'web_username'=>'admin',
    'device_id'=>'router-01',
    'device_name'=>'Main Router',
    'arch'=>'armv7',
    'timezone'=>'UTC',
    'sync_release'=>'1',
];
$form=$defaults;
$errors=[];$warnings=[];$success=null;

function setup_json(array $data,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function setup_csrf_ok(string $csrf): bool {
    return isset($_POST['csrf'])&&is_string($_POST['csrf'])&&hash_equals($csrf,(string)$_POST['csrf']);
}
function setup_h(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&isset($_POST['action'])&&$_POST['action']==='telegram_candidates'){
    if($installed)setup_json(['ok'=>false,'error'=>'Setup is already locked because config.php exists.'],409);
    if(!setup_csrf_ok($csrf))setup_json(['ok'=>false,'error'=>'Session expired. Reload setup.php.'],403);
    $token=trim((string)($_POST['bot_token']??''));
    if(!preg_match('/^[0-9]+:[A-Za-z0-9_-]{20,}$/',$token))setup_json(['ok'=>false,'error'=>'Enter a valid Bot token first.'],400);
    try{
        $me=pw_setup_telegram_call($token,'getMe');
        $users=pw_setup_telegram_candidates($token);
        setup_json(['ok'=>true,'bot'=>$me['result']??[],'users'=>$users]);
    }catch(Throwable $e){setup_json(['ok'=>false,'error'=>$e->getMessage()],400);}
}

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&isset($_POST['install'])){
    foreach(array_keys($defaults) as $k)if(isset($_POST[$k])&&!is_array($_POST[$k]))$form[$k]=(string)$_POST[$k];

    if($installed)$errors[]='Setup is locked because config.php already exists. Delete it from cPanel File Manager only if you intentionally want to reinstall.';
    if(!setup_csrf_ok($csrf))$errors[]='Setup session expired. Reload this page and submit again.';
    if(!$req['php_ok'])$errors[]='PHP 8.1 or newer is required.';
    if($req['missing_extensions'])$errors[]='Missing PHP extensions: '.implode(', ',$req['missing_extensions']);
    if(!is_writable($root))$errors[]='This directory is not writable by PHP: '.$root;

    try{$form['base_url']=pw_setup_validate_base_url((string)$form['base_url']);}catch(Throwable $e){$errors[]=$e->getMessage();}

    $botToken=trim((string)($_POST['bot_token']??''));
    if(!preg_match('/^[0-9]+:[A-Za-z0-9_-]{20,}$/',$botToken))$errors[]='Telegram Bot token format is invalid.';

    $admin=trim((string)$form['admin_id']);
    if(!ctype_digit($admin)||$admin==='0')$errors[]='Telegram Admin ID must be a positive numeric user ID.';
    $webName=trim((string)($_POST['web_username']??''));
    $webPass=(string)($_POST['web_password']??'');
    if($webPass!==(string)($_POST['web_password_confirm']??''))$errors[]='Web passwords do not match.';
    require_once __DIR__.'/web_auth.php';
    try{pw_web_auth_validate_credentials($webName,$webPass);}catch(Throwable $e){$errors[]=$e->getMessage();}

    try{$arch=pw_setup_normalize_arch((string)$form['arch']);$form['arch']=$arch;}catch(Throwable $e){$errors[]=$e->getMessage();}

    $deviceId=trim((string)$form['device_id']);
    if(!pw_setup_valid_device_id($deviceId))$errors[]='Device ID may contain only A-Z, a-z, 0-9, underscore and hyphen.';
    $form['device_id']=$deviceId;

    $deviceName=trim((string)$form['device_name']);
    if($deviceName===''||strlen($deviceName)>128)$errors[]='Device display name must be 1-128 bytes.';
    $form['device_name']=$deviceName;

    $tz=trim((string)$form['timezone']);
    if(!in_array($tz,timezone_identifiers_list(),true))$errors[]='Invalid timezone.';
    $form['timezone']=$tz;

    $db=null;$botInfo=null;
    if(!$errors){
        try{$db=pw_setup_database($_POST);}catch(Throwable $e){$errors[]='Database connection failed: '.$e->getMessage();}
        try{
            $me=pw_setup_telegram_call($botToken,'getMe');
            $botInfo=$me['result']??[];
        }catch(Throwable $e){$errors[]='Telegram Bot validation failed: '.$e->getMessage();}
    }

    if(!$errors&&is_array($db)){
        $lockPath=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'pw-agent-setup-'.sha1($root).'.lock';
        $lock=@fopen($lockPath,'c');
        if($lock===false||!flock($lock,LOCK_EX))$errors[]='Could not acquire installer lock. Retry in a moment.';
        else{
            $createdConfig=false;
            try{
                if(is_file($configPath))throw new RuntimeException('Another setup request already created config.php.');

                /** @var PDO $pdo */
                $pdo=$db['pdo'];
                pw_setup_sql_schema($pdo,$root.'/schema.sql');

                $deviceSecret=bin2hex(random_bytes(32));
                $webhookSecret=bin2hex(random_bytes(32));
                $instanceKey=base64_encode(random_bytes(32));

                $values=[
                    'base_url'=>$form['base_url'],
                    'bot_token'=>$botToken,
                    'admin_id'=>(int)$admin,
                    'device_id'=>$deviceId,
                    'timezone'=>$tz,
                ];
                $config=pw_setup_build_config($values,(string)$db['dsn'],(string)$db['user'],(string)$db['pass'],$deviceSecret,$webhookSecret,$instanceKey);
                pw_setup_write_config($configPath,$config);
                $createdConfig=true;

                // Load the real application only after config.php exists.
                require_once $root.'/lib.php';
                ensure_v060_schema();
                $pdo=db();
                pw_web_auth_install($pdo,$webName,$webPass,(int)$admin);
                $pdo->prepare('UPDATE pw_device_registry SET display_name=? WHERE device_id=?')->execute([$deviceName,$deviceId]);

                $provisionToken=rtrim(strtr(base64_encode(random_bytes(36)),'+/','-_'),'=');
                $pdo->prepare('INSERT INTO pw_provision_tokens(token_hash,device_id,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))')
                    ->execute([hash('sha256',$provisionToken),$deviceId]);

                try{
                    pw_setup_telegram_call($botToken,'setWebhook',[
                        'url'=>rtrim((string)$form['base_url'],'/').'/bot/webhook.php',
                        'secret_token'=>$webhookSecret,
                        'allowed_updates'=>json_encode(['message','callback_query']),
                        'drop_pending_updates'=>'true',
                    ]);
                }catch(Throwable $e){
                    $warnings[]='Backend was installed, but Telegram Webhook registration failed: '.$e->getMessage().'. You can delete config.php in File Manager and rerun setup after fixing outbound HTTPS/cURL connectivity.';
                }

                $release=null;
                if(!empty($_POST['sync_release'])){
                    try{$release=pw_release_sync_arch($root,pw_setup_version(),$arch);}
                    catch(Throwable $e){$warnings[]='Release sync was skipped/failed: '.$e->getMessage().'. This does not remove the installed backend; publish/sync the matching v'.pw_setup_version().' release before using Agent self-update.';}
                }

                $success=[
                    'bot_username'=>(string)($botInfo['username']??''),
                    'base_url'=>(string)$form['base_url'],
                    'root'=>$root,
                    'device_id'=>$deviceId,
                    'device_name'=>$deviceName,
                    'arch'=>$arch,
                    'router_command'=>pw_setup_router_command((string)$form['base_url'],$provisionToken),
                    'cron'=>pw_setup_cron_command($root),
                    'health_url'=>rtrim((string)$form['base_url'],'/').'/health.php',
                    'release'=>$release,
                ];
                unset($webPass);
                $_SESSION['pw_setup_complete']=true;
                $installed=true;
            }catch(Throwable $e){
                // Once this request has written config.php, keep it in place.
                // Database-backed device secrets may already be encrypted with
                // its instance key; deleting/recreating config.php would make a
                // retry unsafe. The error page tells the operator what failed.
                if($createdConfig)$installed=true;
                $errors[]='Setup failed: '.$e->getMessage().($createdConfig?' config.php was preserved to keep generated secrets consistent.':'');
            }finally{
                flock($lock,LOCK_UN);
                fclose($lock);
            }
        }
    }
}

?><!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PW Agent Web Setup</title>
<link rel="stylesheet" href="assets/vazirmatn.css">
<style>
:root{color-scheme:dark;--bg:#0b0f14;--card:#121923;--card2:#182230;--line:#2b3a4d;--text:#edf5ff;--muted:#91a4b8;--accent:#58a6ff;--good:#3fb950;--bad:#f85149;--warn:#d29922}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.65 'Vazirmatn',Tahoma,system-ui,-apple-system,Segoe UI,sans-serif}main{max-width:920px;margin:auto;padding:24px}h1{font-size:27px;margin:0 0 6px}h2{font-size:18px;margin:0 0 14px}.muted{color:var(--muted)}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;margin:14px 0}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.full{grid-column:1/-1}label{display:block;font-weight:650;margin-bottom:5px}input,select{width:100%;background:var(--card2);border:1px solid var(--line);border-radius:10px;padding:11px;color:var(--text)}button{background:var(--accent);border:0;border-radius:10px;padding:11px 16px;color:white;font-weight:700;cursor:pointer}.secondary{background:var(--card2);border:1px solid var(--line)}.ok{border-color:var(--good)}.error{border-color:var(--bad);color:#ffb4ae}.warning{border-color:var(--warn);color:#ffd980}code,pre{background:#080b0f;border:1px solid var(--line);border-radius:9px}code{padding:2px 5px}pre{padding:12px;overflow:auto;white-space:pre-wrap;word-break:break-word}.row{display:flex;gap:9px;align-items:center;flex-wrap:wrap}.check{display:flex;gap:8px;align-items:center}.check input{width:auto}.badge{display:inline-block;border:1px solid var(--line);border-radius:999px;padding:3px 8px;margin-right:5px}.good{color:var(--good)}.bad{color:var(--bad)}
@media(max-width:680px){main{padding:14px}.grid{grid-template-columns:1fr}.full{grid-column:auto}}
</style>
</head>
<body><main>
<h1>PW Agent v<?=setup_h(pw_setup_version())?> Web Setup</h1>
<p class="muted">cPanel-friendly installer. No SSH or cPanel Terminal is required for backend setup.</p>

<div class="card">
<h2>Detected installation</h2>
<div><span class="badge">PHP <?=setup_h((string)$req['php_version'])?></span>
<span class="badge"><?=is_writable($root)?'Directory writable':'Directory not writable'?></span></div>
<p class="muted">The backend can live anywhere under your domain's document root. URLs and runtime paths are calculated from the directory where this file is uploaded.</p>
<?php if(!$req['php_ok']): ?><p class="bad">PHP 8.1+ is required.</p><?php endif; ?>
<?php if($req['missing_extensions']): ?><p class="bad">Missing extensions: <?=setup_h(implode(', ',$req['missing_extensions']))?></p><?php else: ?><p class="good">Required PHP extensions are available.</p><?php endif; ?>
</div>

<?php foreach($errors as $e): ?><div class="card error"><?=setup_h($e)?></div><?php endforeach; ?>
<?php foreach($warnings as $w): ?><div class="card warning"><?=setup_h($w)?></div><?php endforeach; ?>

<?php if($success!==null): ?>
<div class="card ok">
<h2>Installation completed</h2>
<p>Bot: <b>@<?=setup_h($success['bot_username']?:'unknown')?></b><br>
Backend URL: <code><?=setup_h($success['base_url'])?></code><br>
Filesystem path: <code><?=setup_h($success['root'])?></code><br>
Initial router: <code><?=setup_h($success['device_id'])?></code> — <?=setup_h($success['device_name'])?></p>
<p><a href="<?=setup_h($success['health_url'])?>" target="_blank" rel="noopener" style="color:var(--accent)">Open health check</a></p>
</div>

<div class="card">
<h2>1. Install the Agent on OpenWrt</h2>
<p class="muted">This provisioning token expires in 30 minutes and is single-use.</p>
<pre id="routerCommand"><?=setup_h($success['router_command'])?></pre>
<button type="button" onclick="copyText('routerCommand')">Copy router command</button>
</div>

<div class="card">
<h2>2. Add the cPanel Cron Job</h2>
<p>Open <b>cPanel → Cron Jobs</b>, choose <b>Once Per Minute</b>, and paste this exact command. The detected absolute path is used; it does not assume any specific public_html subdirectory.</p>
<pre id="cronCommand"><?=setup_h($success['cron'])?></pre>
<button type="button" onclick="copyText('cronCommand')">Copy cron command</button>
</div>

<div class="card">
<h2>3. Finish in Telegram</h2>
<p>Open your bot and send <code>/start</code>. Fleet, Dashboard, Monitoring, RBAC, Audit, Alerts, Maintenance and Schedules are then available.</p>
<p>Independent web panel: <a href="auth/login.php">Sign in with the web username and password</a>.</p>
<p class="warning">For security, this installer is now locked because <code>config.php</code> exists. Do not leave backup copies of config.php in the public directory.</p>
</div>

<?php elseif($installed): ?>
<div class="card ok">
<h2>PW Agent is already installed</h2>
<p><code>config.php</code> exists, so the browser installer is locked. This prevents a remote user from overwriting your backend configuration.</p>
<p class="muted">To intentionally reinstall, first back up your configuration and then remove <code>config.php</code> using cPanel File Manager.</p>
<a href="health.php" style="color:var(--accent)">Open health check</a>
</div>

<?php else: ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=setup_h($csrf)?>">
<input type="hidden" name="install" value="1">

<div class="card">
<h2>1. Public URL</h2>
<div class="grid">
<div class="full"><label>Public HTTPS Base URL</label>
<input name="base_url" required value="<?=setup_h((string)$form['base_url'])?>" placeholder="https://example.com/any/sub/path">
<div class="muted">This must point to the directory containing this setup.php. Auto-detected request: <?=setup_h($detectedBase?:'unavailable')?></div></div>
<div><label>Timezone</label>
<input name="timezone" list="timezones" value="<?=setup_h((string)$form['timezone'])?>" required>
<datalist id="timezones"><option value="UTC"><option value="Asia/Tehran"><option value="Europe/Berlin"><option value="Europe/London"><option value="America/New_York"><option value="Asia/Dubai"></datalist></div>
</div>
</div>

<div class="card">
<h2>2. MySQL / MariaDB</h2>
<div class="grid">
<div><label>Host</label><input name="db_host" required value="<?=setup_h((string)$form['db_host'])?>"></div>
<div><label>Port</label><input name="db_port" inputmode="numeric" required value="<?=setup_h((string)$form['db_port'])?>"></div>
<div><label>Database</label><input name="db_name" required value="<?=setup_h((string)$form['db_name'])?>" placeholder="cpuser_pwagent"></div>
<div><label>Username</label><input name="db_user" required value="<?=setup_h((string)$form['db_user'])?>" placeholder="cpuser_pwagent"></div>
<div class="full"><label>Password</label><input type="password" name="db_pass" required value=""></div>
</div>
<p class="muted">Create the database/user first in cPanel → MySQL Databases and grant ALL PRIVILEGES.</p>
</div>

<div class="card">
<h2>3. Telegram</h2>
<div class="grid">
<div class="full"><label>Bot Token</label>
<div class="row"><input id="botToken" type="password" name="bot_token" required style="flex:1" placeholder="123456789:AA..."><button class="secondary" type="button" id="detectTelegram">Detect my Telegram ID</button></div>
<div id="telegramDetect" class="muted">Send /start to the new bot first, then use Detect.</div></div>
<div><label>Owner Telegram Numeric ID</label><input id="adminId" name="admin_id" inputmode="numeric" required value="<?=setup_h((string)$form['admin_id'])?>" placeholder="123456789"></div>
</div>
</div>

<div class="card">
<h2>4. Initial router</h2>
<div class="grid">
<div><label>Device ID</label><input name="device_id" required value="<?=setup_h((string)$form['device_id'])?>"></div>
<div><label>Display Name</label><input name="device_name" required value="<?=setup_h((string)$form['device_name'])?>"></div>
<div><label>Architecture</label>
<select name="arch">
<?php foreach(['armv5','armv6','armv7','arm64','amd64','386','mips','mipsle','mips64','mips64le','riscv64','loong64','ppc64le'] as $a): ?><option value="<?=$a?>" <?=$form['arch']===$a?'selected':''?>><?=$a?></option><?php endforeach; ?>
</select></div>
<div class="check"><input id="syncRelease" type="checkbox" name="sync_release" value="1" <?=!empty($form['sync_release'])?'checked':''?>><label for="syncRelease" style="margin:0">Download signed self-update bundle now</label></div>
</div>
</div>

<div class="card">
<h2>5. Independent web login / ورود مستقل پنل وب</h2>
<p class="muted">These credentials are configured now and stored as a one-way password hash in MySQL. They inherit the Telegram Owner's device scope and RBAC permissions.</p>
<div class="grid">
<div class="full"><label>Web username</label><input name="web_username" autocomplete="username" minlength="3" maxlength="32" pattern="[a-z][a-z0-9._-]{2,31}" required value="<?=setup_h((string)$form['web_username'])?>" placeholder="admin"></div>
<div><label>Web password (12+ characters)</label><input type="password" name="web_password" autocomplete="new-password" minlength="12" maxlength="256" required></div>
<div><label>Repeat web password</label><input type="password" name="web_password_confirm" autocomplete="new-password" minlength="12" maxlength="256" required></div>
</div></div>

<div class="card">
<h2>6. Install</h2>
<p class="muted">Setup creates/upgrades the schema, generates secrets, writes config.php, creates the Owner RBAC record, registers the Telegram webhook, creates a 30-minute provisioning token and optionally syncs the signed Agent release.</p>
<button type="submit" <?=(!$req['php_ok']||$req['missing_extensions'])?'disabled':''?>>Install PW Agent</button>
</div>
</form>
<?php endif; ?>

<script>
async function copyText(id){const t=document.getElementById(id)?.innerText||'';if(t)await navigator.clipboard.writeText(t)}
const detect=document.getElementById('detectTelegram');
if(detect)detect.onclick=async()=>{
  const token=document.getElementById('botToken').value.trim(),out=document.getElementById('telegramDetect');
  if(!token){out.textContent='Enter the Bot token first.';return}
  detect.disabled=true;out.textContent='Checking Telegram…';
  try{
    const fd=new FormData();fd.set('action','telegram_candidates');fd.set('csrf',document.querySelector('input[name="csrf"]')?.value||'');fd.set('bot_token',token);
    const r=await fetch('setup.php',{method:'POST',body:fd,credentials:'same-origin'}),j=await r.json();
    if(!j.ok)throw new Error(j.error||'Telegram request failed');
    const users=j.users||[];
    out.innerHTML='Bot: <b>@'+(j.bot?.username||'unknown')+'</b> · '+(users.length?users.map(u=>'<button type="button" class="secondary tgUser" data-id="'+u.id+'">'+u.id+' '+(u.username?'@'+u.username:u.name)+'</button>').join(' '):'No users found. Send /start to the bot and try again.');
    document.querySelectorAll('.tgUser').forEach(b=>b.onclick=()=>{document.getElementById('adminId').value=b.dataset.id});
  }catch(e){out.textContent=e.message}finally{detect.disabled=false}
};
</script>
</main></body></html>
