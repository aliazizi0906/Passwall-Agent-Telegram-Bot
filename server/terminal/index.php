<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib.php';
require_once dirname(__DIR__).'/web_auth.php';
if(!(cfg()['telegram']['terminal_enabled']??true)){http_response_code(403);exit('Terminal disabled');}
$browserUid=pw_web_auth_user();
$browserCsrf=$browserUid?pw_web_auth_csrf():'';
$router=(string)($_GET['device_id']??'');
if(!preg_match('/^[A-Za-z0-9._:-]{1,96}$/D',$router))$router='';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<title>PW Control · OpenWrt SSH</title>
<link rel="stylesheet" href="../assets/vazirmatn.css">
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
:root{color-scheme:light;--bg:#f4f7fc;--card:#fff;--line:#dbe5f1;--ink:#172d4c;--muted:#647a97;--blue:#2567cb;--green:#0a806b;--red:#c13d57}
:root[data-theme=dark]{color-scheme:dark;--bg:#10233a;--card:#142940;--line:#31506e;--ink:#e9f3ff;--muted:#adc0d7;--blue:#88b3ff;--green:#80e3cf;--red:#ff9bb1}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;width:100%;overflow-x:hidden}body{background:var(--bg);color:var(--ink);font:13px/1.75 'Vazirmatn',Tahoma,system-ui,sans-serif}
button,input{font:inherit}button{cursor:pointer}button:disabled{opacity:.5;cursor:not-allowed}
button:focus-visible,input:focus-visible{outline:3px solid #33b9cc;outline-offset:2px}
.container{max-width:1140px;margin:0 auto;padding:16px;display:grid;gap:13px}
.panel{min-width:0;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;overflow:hidden}
.panel-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:13px}
.heading{display:flex;gap:11px;align-items:center;min-width:0}.heading-icon{display:grid;place-items:center;width:38px;height:38px;flex:0 0 38px;background:#2369d319;color:var(--blue);border-radius:11px}
.heading-icon svg{display:block;width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
h1{font-size:17px;line-height:1.4;margin:0;font-weight:800}.help{font-size:12px;color:var(--muted);margin:3px 0 0;line-height:1.8}
.pill{border:1px solid var(--line);border-radius:100px;padding:5px 10px;display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:11px}
.pill[data-connected=true]{color:var(--green);border-color:var(--green)}
.pill:before{content:"";height:7px;width:7px;border-radius:50%;background:currentColor}
.credential-grid{display:grid;grid-template-columns:minmax(135px,1fr) minmax(160px,1.5fr) auto;gap:10px;align-items:end;min-width:0}
.field{display:grid;gap:5px;min-width:0;font-weight:650;font-size:12px}.field input{height:42px;width:100%;min-width:0;padding:9px 11px;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:var(--ink)}
.field input:focus{border-color:var(--blue)}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;gap:8px;padding:9px 15px;border-radius:10px;background:var(--blue);border:1px solid var(--blue);color:#fff;font-weight:750;white-space:nowrap}
.btn.subtle{background:transparent;color:var(--ink);border-color:var(--line)}.btn:disabled{opacity:.5}
.btn svg{display:block;width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.notice{min-height:20px;color:var(--muted);margin:10px 0 0;font-size:12px;overflow-wrap:anywhere}
.terminal{display:flex;flex-direction:column;min-width:0;overflow:hidden;border:1px solid #2c455c;border-radius:14px;background:#071629}
.terminal-bar{height:43px;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:0 13px;background:#11263e;color:#b5d7ed;direction:ltr;text-align:left;font:12px/1.4 ui-monospace,Consolas,monospace}
.terminal-dots{display:flex;gap:6px}.terminal-dots i{width:9px;height:9px;border-radius:50%;background:#69879d}.terminal-dots i:first-child{background:#fa6f70}.terminal-dots i:nth-child(2){background:#ecc26a}.terminal-dots i:nth-child(3){background:#49b594}
.output{height:clamp(250px,46vh,560px);min-width:0;overflow:auto;padding:16px;white-space:pre-wrap;overflow-wrap:anywhere;direction:ltr;text-align:left;color:#cae8de;font:13px/1.65 ui-monospace,SFMono-Regular,Consolas,monospace;unicode-bidi:plaintext;scrollbar-color:#4d718a transparent}
.output>div{margin-bottom:3px}.output .error{color:#ffa2a9}.output .ok{color:#7ce1bc}
.command-row{display:flex;align-items:stretch;gap:9px;margin-top:12px;min-width:0;direction:ltr}
.command-row input{flex:1;min-width:0;height:44px;background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:9px 12px;color:var(--ink);direction:ltr;text-align:left;font:13px/1.4 ui-monospace,Consolas,monospace}
.command-row .btn{direction:ltr}.footer-note{font-size:11px;line-height:1.8;color:var(--muted);margin:9px 0 0}
.router-tag{direction:ltr;unicode-bidi:plaintext;font:12px/1.3 ui-monospace,Consolas,monospace;color:var(--blue)}
@media(max-width:680px){.container{padding:8px;gap:9px}.panel{padding:13px;border-radius:12px}.panel-head{align-items:flex-start}.credential-grid{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.credential-grid .btn{grid-column:1/-1}.output{height:clamp(270px,49vh,480px);padding:11px;font-size:12px}.command-row{gap:7px}.command-row .btn{padding:9px 11px}}
@media(max-width:410px){.credential-grid{grid-template-columns:1fr}.credential-grid .btn{grid-column:1}.terminal-bar{font-size:10px}.container{padding:6px}}
@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important}}
</style>
</head>
<body data-web-auth="<?= $browserUid?'1':'0' ?>" data-web-csrf="<?=htmlspecialchars($browserCsrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" data-device="<?=htmlspecialchars($router,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>">
<main class="container">
<section class="panel" aria-label="SSH authentication">
 <div class="panel-head"><div class="heading"><span class="heading-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m6 9 4 3-4 3M12 16h5"/></svg></span><div><h1>ترمینال SSH روتر</h1><p class="help">ورود مستقیم با حساب Dropbear روتر؛ بدون ذخیره رمز در سرور به‌صورت متن آشکار</p></div></div><span id="connectionState" class="pill" data-connected="false">قطع</span></div>
 <div class="credential-grid"><label class="field">نام کاربری<input id="user" dir="ltr" value="root" autocomplete="username" maxlength="32"></label><label class="field">رمز عبور SSH<input id="pass" dir="ltr" type="password" autocomplete="off" maxlength="256"></label><button id="connect" type="button" class="btn"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7a8 8 0 0 1 12 5M17 7h2v5M17 17a8 8 0 0 1-12-5M7 17H5v-5"/></svg>اتصال</button></div>
 <p id="status" class="notice" role="status" aria-live="polite">جهت اتصال، رمز SSH را وارد کن.</p>
 <div id="routerLabel" class="router-tag"></div>
</section>
<section class="panel" aria-label="SSH commands">
 <div class="terminal"><div class="terminal-bar"><span class="terminal-dots" aria-hidden="true"><i></i><i></i><i></i></span><span>PW Agent / OpenWrt</span><button id="clear" type="button" class="btn subtle" style="min-height:27px;height:27px;padding:2px 9px;color:#d0e4f7;border-color:#47617c;font:11px ui-monospace,monospace">Clear</button></div><div id="term" class="output" role="log" aria-live="polite" aria-relevant="additions text"><div>PW Agent SSH Console <?=h(app_version())?></div></div></div>
 <div class="command-row"><input id="cmd" placeholder="Type a command…" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="SSH command"><button id="run" type="button" class="btn" disabled>Run</button></div>
 <p class="footer-note">کنسول، فرمان‌ها را غیرتعاملی اجرا می‌کند؛ برنامه‌های نیازمند TTY مانند vi یا top تعاملی پشتیبانی نمی‌شوند.</p>
</section>
</main>
<script>
'use strict';
const tg=window.Telegram?.WebApp;tg?.ready();tg?.expand();
const initData=tg?.initData||'',webAuth=document.body.dataset.webAuth==='1';
const webCsrf=document.body.dataset.webCsrf||'',deviceId=document.body.dataset.device||'';
const $=id=>document.getElementById(id),term=$('term'),status=$('status'),state=$('connectionState');
if(!initData&&!webAuth){window.location.replace('../auth/login.php');}
if(deviceId)$('routerLabel').textContent='Router: '+deviceId;
let connected=false,cwd='/root',running=false;
function output(s,cls=''){const node=document.createElement('div');if(cls)node.className=cls;node.textContent=String(s);term.append(node);term.scrollTop=term.scrollHeight}
function syncButtons(){ $('run').disabled=!connected||running;$('connect').disabled=running;state.dataset.connected=connected?'true':'false';state.textContent=connected?'متصل':'قطع';}
async function execCmd(command){
 const username=$('user').value.trim(),password=$('pass').value;
 if(!username||!password)throw new Error('نام کاربری و رمز SSH الزامی است');
 const response=await fetch('api.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-PW-CSRF':webCsrf},body:JSON.stringify({init_data:initData,device_id:deviceId,username,password,command,cwd})});
 const result=await response.json().catch(()=>({ok:false,error:'پاسخ نامعتبر Backend'}));
 if(!response.ok||!result.ok)throw new Error(result.error||'SSH command failed');
 cwd=result.result?.cwd||cwd;return result.result||{};
}
$('connect').onclick=async()=>{
 if(running)return;running=true;syncButtons();status.textContent='در حال اتصال…';
 try{const value=await execCmd('printf __PWTERM_OK__');if(!String(value.output||'').includes('__PWTERM_OK__'))throw new Error('احراز هویت SSH ناموفق بود');connected=true;status.textContent='اتصال برقرار شد · '+cwd;output('Connected to router.','ok');$('cmd').focus();}
 catch(e){connected=false;status.textContent=e.message;output(e.message,'error');}
 finally{running=false;syncButtons();}
};
async function run(){
 const command=$('cmd').value;if(!command.trim()||running)return;
 if(!connected){output('Connect first.','error');return;}
 running=true;syncButtons();$('cmd').value='';
 output($('user').value+'@OpenWrt:'+cwd+'# '+command);
 try{const v=await execCmd(command);if(v.output)output(v.output);status.textContent='Connected · '+cwd+' · exit '+(v.exit_code??0);}
 catch(e){output(e.message,'error');}
 finally{running=false;syncButtons();$('cmd').focus();}
}
$('run').onclick=run;$('cmd').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();run();}});
$('clear').onclick=()=>{term.textContent='';output('PW Agent SSH Console');};
$('user').addEventListener('input',()=>{connected=false;syncButtons()});
$('pass').addEventListener('input',()=>{connected=false;syncButtons()});
window.addEventListener('pagehide',()=>{$('pass').value='';connected=false;});
syncButtons();
</script></body></html>
