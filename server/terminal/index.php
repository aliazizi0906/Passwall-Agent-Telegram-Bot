<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
if(!(cfg()['telegram']['terminal_enabled'] ?? true)){http_response_code(403);exit('Terminal disabled');}
require_once dirname(__DIR__).'/web_auth.php';
$browserUid=pw_web_auth_user();
$browserCsrf=$browserUid?pw_web_auth_csrf():'';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no"><title>OpenWrt SSH Console · PW Control</title><link rel="stylesheet" href="../assets/vazirmatn.css">
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;background:#0b0f14;color:#e6edf3}.wrap{max-width:900px;margin:auto;padding:14px}.card{background:#111821;border:1px solid #263241;border-radius:14px;padding:14px;margin-bottom:12px}.row{display:flex;gap:8px;flex-wrap:wrap}input,button{font:inherit;border-radius:9px;border:1px solid #334155;padding:10px;background:#0f1720;color:#e6edf3}input{flex:1;min-width:120px}button{cursor:pointer;background:#172554}button:disabled{opacity:.5}.term{min-height:50vh;max-height:62vh;overflow:auto;white-space:pre-wrap;word-break:break-word;background:#020617;padding:12px;border-radius:10px;border:1px solid #1e293b}.cmd{width:100%;margin-top:8px}.muted{color:#94a3b8;font-size:12px}.ok{color:#86efac}.bad{color:#fca5a5}
</style><style>
body{font-family:'Vazirmatn',Tahoma,system-ui,sans-serif;background:#edf3fa;color:#152945}
.wrap{max-width:1150px;padding:22px}.card{background:#fff;color:#152945;border:1px solid #dbe5f1;border-radius:18px;box-shadow:0 10px 35px #173b6510;padding:22px}
input{background:#f3f7fc;color:#152945;border:1px solid #ccdae8}button{background:#175fd0;color:white;border:0;min-width:88px}.term{background:#08162a;color:#bfe7db;direction:ltr;text-align:left;font:13px/1.7 ui-monospace,Consolas,monospace;min-height:300px;max-height:48vh}
.muted{color:#59708e}.bad{color:#d23b57}.ok{color:#098769}
@media(max-width:600px){.wrap{padding:9px}.card{padding:15px}.row>*{flex-basis:100%}.term{min-height:250px}}
</style></head><body data-web-auth="<?= $browserUid?'1':'0' ?>" data-web-csrf="<?=htmlspecialchars($browserCsrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><div class="wrap"><div class="card"><b>OpenWrt SSH Console</b><div class="muted">Authenticated through local Dropbear on the router. Credentials stay in this Mini App memory and are sent only with each command.</div><div class="row" style="margin-top:10px"><input id="user" value="root" placeholder="Username"><input id="pass" type="password" placeholder="OpenWrt password"><button id="connect">Connect</button></div><div id="status" class="muted" style="margin-top:8px">Not connected</div></div><div class="card"><div id="term" class="term">PW Agent SSH Console <?=h(app_version())?>\n</div><div class="row"><input id="cmd" class="cmd" placeholder="Type a command…" autocomplete="off" autocapitalize="off"><button id="run">Run</button></div><div class="muted">Non-interactive SSH console: shell commands work; full-screen TTY apps such as vi/top are not interactive.</div></div></div>
<script>
const tg=window.Telegram?.WebApp;tg?.ready();tg?.expand();
const initData=tg?.initData||'';
const webAuth=document.body.dataset.webAuth==='1';
const webCsrf=document.body.dataset.webCsrf||'';
if(!initData&&!webAuth){window.location.replace('../auth/login.php');}
let connected=false,cwd='/root';
const $=id=>document.getElementById(id), term=$('term'), status=$('status');
function out(s,cls=''){let d=document.createElement('div');if(cls)d.className=cls;d.textContent=s;term.appendChild(d);term.scrollTop=term.scrollHeight}
async function execCmd(command){const username=$('user').value.trim(),password=$('pass').value;if(!username||!password)throw new Error('Enter username and password');let r=await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json','X-PW-CSRF':webCsrf},credentials:'same-origin',cache:'no-store',body:JSON.stringify({init_data:initData,username,password,command,cwd})});let j=await r.json();if(!j.ok)throw new Error(j.error||'Command failed');cwd=(j.result&&j.result.cwd)||cwd;return j.result||{};}
$('connect').onclick=async()=>{try{$('connect').disabled=true;status.textContent='Connecting…';let r=await execCmd('printf __PWTERM_OK__');if(String(r.output||'').includes('__PWTERM_OK__')){connected=true;status.textContent='Connected · '+cwd;out('Connected to OpenWrt via SSH.','ok')}else throw new Error('Authentication check failed')}catch(e){connected=false;status.textContent=e.message;out(e.message,'bad')}finally{$('connect').disabled=false}};
async function run(){let c=$('cmd').value;if(!c.trim())return;if(!connected){out('Connect first.','bad');return}$('cmd').value='';out($('user').value+'@'+(window.parent===window?'OpenWrt':'router')+':'+cwd+'# '+c);$('run').disabled=true;try{let r=await execCmd(c);if(r.output)out(r.output);status.textContent='Connected · '+cwd+' · exit '+(r.exit_code??0)}catch(e){out(e.message,'bad')}finally{$('run').disabled=false;$('cmd').focus()}}
$('run').onclick=run;$('cmd').addEventListener('keydown',e=>{if(e.key==='Enter')run()});
</script></body></html>
