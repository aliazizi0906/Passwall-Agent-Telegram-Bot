<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib.php';
require_once dirname(__DIR__).'/web_auth.php';
$browserUid=pw_web_auth_user();
$browserCsrf=$browserUid?pw_web_auth_csrf():'';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if(!str_starts_with(rtrim((string)(cfg()['base_url']??''),'/'),'https://')){
    http_response_code(400);
    exit('PW Control requires HTTPS');
}
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<title>PW Control Center — OpenWrt Management</title>
<link rel="stylesheet" href="../assets/vazirmatn.css">
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
:root{--bg:#f7f8fc;--panel:#fff;--soft:#f0f4fa;--border:#dde5ef;--ink:#10233c;--muted:#72829a;--nav:#101f3b;--accent:#2666da;--accent2:#39a6a0;--good:#00856d;--bad:#c64154;--warn:#ac742a;--shadow:0 14px 35px rgba(23,46,85,.05);color-scheme:light}
:root[data-theme=dark]{--bg:#0d1523;--panel:#142237;--soft:#1b2e48;--border:#2a3b55;--ink:#e8effb;--muted:#9cafc8;--nav:#08101f;--accent:#75a6ff;--accent2:#6fddcf;--good:#70d7b8;--bad:#ff9cab;--warn:#ffce86;--shadow:none;color-scheme:dark}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;font:14px/1.75 'Vazirmatn',Tahoma,'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--ink)}
button,select{font:inherit}button{cursor:pointer}button:disabled{cursor:default;opacity:.5}
.shell{display:flex;min-height:100vh}.sidebar{width:250px;flex:none;background:var(--nav);color:#f2f6ff;padding:25px 15px;position:sticky;top:0;height:100vh;overflow:auto}
.brand{display:flex;gap:13px;align-items:center;padding:7px 12px 28px;border-bottom:1px solid #ffffff16;margin-bottom:19px}.brand-logo{width:45px;height:45px;display:grid;place-items:center;background:linear-gradient(140deg,#407eff,#23b6c0);border-radius:15px;font-weight:900;font-size:18px}.brand b{display:block;font-size:17px;letter-spacing:.2px}.brand small{color:#adbfda;font-size:11px}
.nav-title{padding:11px 12px;color:#8a9eba;font-size:11px;letter-spacing:1.1px}
.nav-btn{display:flex;align-items:center;gap:11px;width:100%;padding:12px 11px;border:0;border-radius:12px;color:#b8c8de;background:transparent;text-align:start;margin-bottom:4px;font-weight:550}
.nav-btn:hover,.nav-btn:focus-visible{background:#ffffff0b;color:white}.nav-btn.selected{background:#315b9d66;color:white;box-shadow:inset -3px 0 #7db3ff}
.nav-symbol{width:31px;height:31px;flex:none;border:1px solid #ffffff20;border-radius:10px;display:grid;place-items:center;color:#c7deff}
.nav-symbol svg{width:19px;height:19px;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round;fill:none}
.nav-btn.selected .nav-symbol{background:#ffffff22;border-color:#ffffff4a;color:#fff}
.nav-foot{border-top:1px solid #ffffff16;margin-top:20px;padding:18px 12px 6px;color:#8ea4c5;font-size:11px}
.main{flex:1;min-width:0}.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px clamp(18px,3.5vw,46px);background:var(--panel);border-bottom:1px solid var(--border)}
.topbar h1{font-size:18px;margin:0}.topbar small{color:var(--muted)}.topbar-actions{display:flex;gap:8px;align-items:center}
.chip-btn,.select{background:var(--panel);color:var(--ink);border:1px solid var(--border);border-radius:10px;padding:8px 12px;min-height:38px}
.content{max-width:1420px;padding:28px clamp(18px,3.5vw,46px) 58px;margin:auto}
.headline{display:flex;justify-content:space-between;gap:14px;align-items:end;margin-bottom:21px}.headline h2{font-size:27px;margin:0 0 2px;letter-spacing:-.4px}.muted,.headline p{color:var(--muted);margin:0}
.card{background:var(--panel);border:1px solid var(--border);border-radius:19px;box-shadow:var(--shadow);padding:22px;min-width:0}.surface{background:var(--soft);border:1px solid var(--border);border-radius:12px;padding:13px}
.hero{display:flex;justify-content:space-between;gap:22px;align-items:center;background:linear-gradient(115deg,var(--panel),var(--soft))}
.hero h3{font-size:23px;margin:6px 0}.eyebrow{font-size:12px;letter-spacing:1px;text-transform:uppercase;color:var(--accent);font-weight:800}
.hero-status{width:77px;height:77px;border-radius:22px;background:var(--soft);display:grid;place-items:center;font-size:26px;color:var(--accent);font-weight:900;flex:none}
.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin:15px 0}
.stat{display:flex;flex-direction:column;gap:6px}.stat .value{font-size:25px;font-weight:800;line-height:1.1;overflow-wrap:anywhere}.stat .label{font-size:12px;color:var(--muted)}.stat .detail{font-size:11px;color:var(--muted)}
.two{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:16px}.section-title{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:17px;font-size:16px;font-weight:800}
.status-row{display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-bottom:1px solid var(--border);gap:12px}.status-row:last-child{border:0}.status-row span:last-child{font-weight:700}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-inline-end:8px;background:var(--muted)}.dot.ok{background:var(--good)}.dot.bad{background:var(--bad)}.dot.warn{background:var(--warn)}
.tags{display:flex;flex-wrap:wrap;gap:9px}.tag{padding:6px 10px;border:1px solid var(--border);background:var(--soft);border-radius:9px;color:var(--muted);font-size:12px}
.actions{display:flex;flex-wrap:wrap;gap:9px}.action{background:var(--soft);color:var(--ink);border:1px solid var(--border);border-radius:11px;padding:10px 15px;text-align:start}
.action.primary{background:var(--accent);border-color:var(--accent);color:white}.action.danger{color:var(--bad)}.action:hover:not(:disabled){border-color:var(--accent)}
.chart{width:100%;height:175px;display:block}.empty{padding:30px 14px;text-align:center;color:var(--muted);background:var(--soft);border-radius:12px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.stack{display:grid;gap:15px}.info{display:flex;flex-direction:column;gap:8px}.info h3{margin:0;font-size:18px}.info p{margin:0;color:var(--muted)}
.pill{border:1px solid var(--border);border-radius:50px;padding:4px 9px;font-size:11px;color:var(--muted);display:inline-block}.pill.ok{border-color:#00856d55;color:var(--good)}.pill.bad{border-color:#c6415455;color:var(--bad)}
.device-row{display:flex;align-items:center;justify-content:space-between;gap:11px;border-bottom:1px solid var(--border);padding:13px 0}.device-row:last-child{border:0}.device-row strong{display:block}.device-row small{color:var(--muted)}
#feedback{white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.8 ui-monospace,Consolas,monospace;max-height:270px;overflow:auto;direction:ltr;text-align:left;background:var(--soft);padding:14px;border-radius:12px}
#notice{margin-bottom:16px;padding:14px 16px;border:1px solid var(--border);border-inline-start:4px solid var(--accent);background:var(--panel);border-radius:10px}
#notice:empty{display:none}.warn-note{border-inline-start:3px solid var(--warn);padding:12px 14px;background:var(--soft);border-radius:9px;color:var(--muted)}
footer{font-size:12px;color:var(--muted);margin-top:30px;text-align:center}
a{color:var(--accent)}
@media(max-width:1100px){.sidebar{width:202px}.summary{grid-template-columns:repeat(2,minmax(0,1fr))}.two{grid-template-columns:1fr}}
@media(max-width:740px){.shell{display:block}.sidebar{position:sticky;top:0;width:100%;height:auto;z-index:4;padding:9px 12px;overflow-x:auto;display:flex;align-items:center;gap:10px}.brand{padding:0;margin:0;border:0;white-space:nowrap}.brand-logo{width:32px;height:32px;border-radius:10px;font-size:12px}.brand small,.nav-title,.nav-foot{display:none}.nav{display:flex;flex:none;gap:3px}.nav-btn{white-space:nowrap;width:auto;margin:0;font-size:12px;padding:7px 9px}.nav-symbol{display:none}.topbar{padding:12px 16px}.topbar h1{font-size:15px}.content{padding:20px 15px 35px}.headline h2{font-size:23px}.hero h3{font-size:18px}.hero-status{width:55px;height:55px;border-radius:15px;font-size:22px}.grid{grid-template-columns:1fr}.summary{gap:10px}.card{padding:16px}}
@media(prefers-reduced-motion:no-preference){button,.nav-btn{transition:background .15s,border-color .15s}}

/* Original PW Agent design system: network operations control deck. */
:root{--bg:#f2f5fb;--nav:#0b1a32;--accent:#2b66df;--accent2:#14a6a1;--shadow:0 12px 38px rgba(16,35,60,.065)}
:root[data-theme=dark]{--bg:#071326;--nav:#09152a;--panel:#10233a;--soft:#182e47;--accent:#79a7ff;--shadow:0 12px 32px #0003}
body{letter-spacing:0;-webkit-font-smoothing:antialiased}
.sidebar{background:radial-gradient(ellipse at 0% 0%,#203d6a 0%,transparent 44%),linear-gradient(170deg,var(--nav),#081429);box-shadow:12px 0 40px #0a204014}
.brand-logo{box-shadow:0 9px 22px #204dbc44;border:1px solid #ffffff5c}
.nav-btn{font-weight:600;border:1px solid transparent}.nav-btn.selected{background:linear-gradient(115deg,#2f6be1,#2256aa);border-color:#ffffff20;box-shadow:0 5px 15px #0b224522;color:#fff}
.card{border:1px solid var(--border);box-shadow:var(--shadow);background:var(--panel);border-radius:17px}
.hero{border:1px solid #cbd8ec;background:linear-gradient(115deg,var(--panel),var(--soft));box-shadow:var(--shadow)}
.topbar{backdrop-filter:blur(16px);background:color-mix(in srgb,var(--panel),transparent 6%)}
.summary .stat{position:relative;overflow:hidden}.summary .stat:before{content:"";position:absolute;inset-block:0;inset-inline-start:0;width:3px;background:var(--accent2)}
.action:not(.danger),.chip-btn{transition:box-shadow .15s,transform .15s,background .15s}
.action:hover:not(:disabled),.chip-btn:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 6px 17px #1b56b31c}
.content{max-width:1550px}input,textarea,select{font-family:inherit}
input[name=username],input[name=password]{background:var(--soft);color:var(--ink);border:1px solid var(--border);border-radius:11px;padding:12px;width:100%;max-width:500px}
@media(prefers-reduced-motion:reduce){.action,.chip-btn{transition:none}}
</style>
</head>
<body data-web-auth="<?= $browserUid?'1':'0' ?>" data-web-csrf="<?=htmlspecialchars($browserCsrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>">
<div class="shell">
<aside class="sidebar">
 <div class="brand"><div class="brand-logo">PW</div><div><b>PW Control</b><small>Network Control Center</small></div></div>
 <div class="nav-title">CONTROL PANEL</div>
 <nav class="nav" id="navigation" aria-label="Primary navigation"></nav>
 <div class="nav-foot">PW Control Center · Release candidate<br>Local LuCI available offline</div>
</aside>
<div class="main">
 <header class="topbar">
  <div><h1 id="topTitle">PW Control Center</h1><small id="identity">Telegram authentication required</small></div>
  <div class="topbar-actions">
   <?php if($browserUid): ?><form method="post" action="../auth/login.php" style="margin:0"><input type="hidden" name="csrf" value="<?=htmlspecialchars($browserCsrf,ENT_QUOTES,'UTF-8')?>"><button class="chip-btn" name="logout" value="1" type="submit" title="خروج از پنل">خروج</button></form><?php else: ?><a class="chip-btn" href="../auth/login.php" title="ورود مستقل پنل وب">ورود وب</a><?php endif; ?>
   <button type="button" class="chip-btn" id="refreshBtn" aria-label="Refresh">↻</button>
   <button type="button" class="chip-btn" id="langBtn">EN</button>
   <button type="button" class="chip-btn" id="themeBtn" aria-label="Toggle color theme">◐</button>
  </div>
 </header>
 <main class="content">
  <div id="notice" role="status" aria-live="polite"></div>
  <div class="headline"><div><h2 id="pageTitle"></h2><p id="pageDesc"></p></div><select id="devicePicker" class="select" aria-label="Router" hidden></select></div>
  <section id="page" aria-live="polite"><div class="empty">Connecting…</div></section>
  <footer>PW Control Center · v1.4 development preview · Legacy dashboard also available</footer>
 </main>
</div></div>
<script>
(function(){
'use strict';
var tg=window.Telegram && window.Telegram.WebApp;
if(tg){tg.ready();tg.expand();}
var init=tg && tg.initData ? tg.initData : '';
var webAuth=document.body.dataset.webAuth==='1';
var webCsrf=document.body.dataset.webCsrf||'';
if(!init&&!webAuth){window.location.replace('../auth/login.php');return;}
var state={fleet:null,active:'',role:'viewer',permissions:[],detail:null,page:'home',lang:'fa',theme:'light',busy:false};
try{state.lang=localStorage.getItem('pwcontrol-lang')==='en'?'en':'fa';state.theme=localStorage.getItem('pwcontrol-theme')==='dark'?'dark':'light';}catch(e){}
var menu=[
 ['home','01','خانه','Home','وضعیت لحظه‌ای شبکه و روتر','Router and network status'],
 ['internet','02','اینترنت','Internet','کیفیت WAN، DNS و اتصال','WAN, DNS and connectivity'],
 ['proxy','03','فیلترشکن','Proxy','Passwall2 و عملیات مجاز','Passwall2 and supported operations'],
 ['routers','04','روترها','Fleet','مدیریت روترهای ثبت‌شده','Manage registered routers'],
 ['clients','05','دستگاه‌ها','Clients','موبایل‌ها و تجهیزات LAN','LAN clients and their policies'],
 ['wifi','06','وای‌فای','Wi-Fi','رادیوها، رمز و شبکه مهمان','Radios, password and guest network'],
 ['cellular','07','سیم‌کارت و دکل','Cellular','ویژه مودم‌های دارای سخت‌افزار سازگار','Requires supported cellular modem'],
 ['dns','08','DNS و امنیت','DNS & Security','سیاست‌های DNS و محافظت','DNS and protection rules'],
 ['gaming','09','بازی و کیفیت','Gaming','تأخیر، NAT و اولویت ترافیک','Latency, NAT and traffic priority'],
 ['system','10','سیستم','System','سلامت Agent و ابزارهای موجود','Agent health and available operations'],
 ['led','12','چراغ روتر','Router LED','کنترل LED برای سخت‌افزارهای سازگار','LED features on supported hardware'],
 ['automation','11','اتوماسیون','Automation','هشدار و زمان‌بندی','Alerts and schedules']
];
var $=function(id){return document.getElementById(id);};
var esc=function(v){return String(v==null?'':v).replace(/[&<>"']/g,function(x){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[x];});};
var say=function(fa,en){return state.lang==='fa'?fa:en;};
var safeNum=function(v,d){return v===null||v===undefined||v===''?'—':Number.isFinite(Number(v))?Number(v).toFixed(d||0):'—';};
var unit=function(v,s){return safeNum(v,0)+(v==null||v===''?'':' '+s);};
var status=function(v){return v===null||v===undefined||v===''?['—','warn']:Number(v)?[say('فعال','Up'),'ok']:[say('قطع','Down'),'bad'];};
var metric=function(r,k){return r && Object.prototype.hasOwnProperty.call(r,k)?r[k]:null;};
var has=function(p){return state.permissions.includes('*')||state.permissions.includes(p);};
var current=function(){return menu.find(function(m){return m[0]===state.page;})||menu[0];};
function applyTheme(){document.documentElement.dataset.theme=state.theme;document.documentElement.dir=state.lang==='fa'?'rtl':'ltr';document.documentElement.lang=state.lang;$('langBtn').textContent=state.lang==='fa'?'EN':'فا';}
var navArt={
 home:'<path d="m3 10 9-7 9 7v11H3z"/><path d="M9 21v-8h6v8"/>',
 internet:'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c5 5 5 13 0 18M12 3c-5 5-5 13 0 18"/>',
 proxy:'<path d="M12 2 20 6v6c0 5-3 8-8 10-5-2-8-5-8-10V6z"/><path d="m9 12 2 2 4-4"/>',
 routers:'<rect x="3" y="7" width="18" height="11" rx="2"/><path d="M7 12h.01M11 12h.01M7 18v3M17 18v3"/>',
 clients:'<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
 wifi:'<path d="M2 9a16 16 0 0 1 20 0M5 12a11 11 0 0 1 14 0M8 15a6 6 0 0 1 8 0"/><circle cx="12" cy="19" r="1"/>',
 cellular:'<path d="M12 20V4M7 20h10M7 7a7 7 0 0 0 0 10M17 7a7 7 0 0 1 0 10M3 3a13 13 0 0 0 0 18M21 3a13 13 0 0 1 0 18"/>',
 dns:'<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
 gaming:'<path d="M6 8h12c2 0 3 2 4 7l-1 4c-1 2-3 1-5-1H8c-2 2-4 3-5 1l-1-4c1-5 2-7 4-7z"/><path d="M7 12v5M4.5 14.5h5M16 13h.01M19 15h.01"/>',
 system:'<circle cx="12" cy="12" r="3"/><path d="M10 2h4l1 3 2 1 3-1 2 4-2 2v2l2 2-2 4-3-1-2 1-1 3h-4l-1-3-2-1-3 1-2-4 2-2v-2L2 9l2-4 3 1 2-1z"/>',
 led:'<path d="M9 18h6M10 21h4M9 15c-5-3-5-10 0-12a7 7 0 0 1 6 12l-1 3h-4z"/>',
 automation:'<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>'
};
function navIcon(key){return '<svg viewBox="0 0 24 24" aria-hidden="true">'+(navArt[key]||navArt.system)+'</svg>';}
function nav(){
 $('navigation').innerHTML=menu.map(function(m){return '<button type="button" class="nav-btn '+(m[0]===state.page?'selected':'')+'" data-nav="'+esc(m[0])+'" '+(m[0]===state.page?'aria-current="page"':'')+'><span class="nav-symbol">'+navIcon(m[0])+'</span>'+esc(say(m[2],m[3]))+'</button>';}).join('');
 var p=current();$('pageTitle').textContent=say(p[2],p[3]);$('pageDesc').textContent=say(p[4],p[5]);$('topTitle').textContent=say('مرکز کنترل شبکه','Network Control Center');
 $('identity').textContent=(state.fleet?say('نقش کاربری: ','Role: ')+state.role:say('ورود به پنل الزامی است','Authentication required'))+(webAuth?' · Web':'');
 var select=$('devicePicker');var ds=state.fleet && state.fleet.devices || [];
 select.hidden=!ds.length;
 select.innerHTML=ds.map(function(d){return '<option value="'+esc(d.device_id)+'" '+(d.device_id===state.active?'selected':'')+'>'+esc(d.display_name||d.device_id)+'</option>';}).join('');
}
function statCard(label,value,detail){return '<article class="card stat"><div class="label">'+esc(label)+'</div><div class="value">'+esc(value)+'</div><div class="detail">'+esc(detail||'')+'</div></article>';}
function row(label,v){var x=status(v);return '<div class="status-row"><span>'+esc(label)+'</span><span><i class="dot '+x[1]+'"></i>'+esc(x[0])+'</span></div>';}
function formatDate(s){return s?String(s).replace('T',' ').slice(0,19):'—';}
function latest(){var m=state.detail&&state.detail.metrics||[];return m.length?m[m.length-1]:{};}
function chartHtml(id,label){return '<section class="card"><div class="section-title">'+esc(label)+'</div><canvas class="chart" id="'+id+'" width="900" height="180"></canvas><div class="muted" style="font-size:11px">'+say('حداکثر ۶۰ نمونه ثبت‌شده، بدون داده ساختگی','Up to 60 recorded samples, no simulated data')+'</div></section>';}
function drawChart(id,key){var el=$(id);if(!el)return;var entries=state.detail&&state.detail.metrics||[];var a=entries.map(function(e){return e[key];}).filter(function(v){return v!==null&&v!==undefined&&v!=='';}).map(Number).filter(Number.isFinite);if(a.length<2){el.replaceWith(document.createTextNode(say('داده کافی برای رسم نمودار وجود ندارد.','Not enough samples to draw a chart.')));return;}
 var c=el.getContext('2d'),w=el.width,h=el.height,lo=Math.min.apply(null,a),hi=Math.max.apply(null,a),range=Math.max(1,hi-lo);c.clearRect(0,0,w,h);c.strokeStyle=getComputedStyle(document.documentElement).getPropertyValue('--border').trim();c.lineWidth=1;
 for(var i=0;i<4;i++){c.beginPath();c.moveTo(12,17+i*46);c.lineTo(w-12,17+i*46);c.stroke();}
 c.strokeStyle=getComputedStyle(document.documentElement).getPropertyValue('--accent').trim();c.lineWidth=3;c.lineJoin='round';c.beginPath();
 a.forEach(function(v,i){var x=12+i*(w-24)/(a.length-1),y=h-18-(v-lo)/range*(h-36);if(i)c.lineTo(x,y);else c.moveTo(x,y);});c.stroke();
}
function action(id,title,permission,needsConfirm){if(!has(permission))return '';return '<button class="action '+(needsConfirm?'danger':'')+'" type="button" data-command="'+id+'" data-confirm="'+(needsConfirm?'1':'0')+'">'+esc(title)+'</button>';}
function hero(){
 var d=state.detail&&state.detail.device||{},m=latest(),wan=status(metric(m,'wan_up'));
 return '<article class="card hero"><div><div class="eyebrow">PW CONTROL CENTER / '+esc(d.agent_version||'—')+'</div><h3>'+esc(say('روتر ','Router ')+(d.display_name||state.active||'—'))+'</h3><p class="muted">'+esc(say('ارتباط WAN: ','WAN: ')+wan[0]+' · '+say('آخرین اتصال: ','Last seen: ')+formatDate(d.last_seen))+'</p></div><div class="hero-status">'+(wan[1]==='ok'?'✓':'—')+'</div></article>';
}
function home(){
 var m=latest(),d=state.detail&&state.detail.device||{},ov=state.detail&&state.detail.overview||{},h=ov['24h']||{};
 $('page').innerHTML=hero()+'<div class="summary">'+
 statCard(say('پردازنده','CPU'),unit(metric(m,'cpu_percent'),'%'),say('مصرف لحظه‌ای گزارش‌شده','Last reported usage'))+
 statCard(say('حافظه','Memory'),unit(metric(m,'memory_used_percent'),'%'),say('مصرف رم روتر','Router memory'))+
 statCard(say('بسته‌های از دست‌رفته','Packet loss'),unit(metric(m,'packet_loss'),'%'),say('تست اتصال Agent','Agent connectivity probe'))+
 statCard(say('Latency نود','Node latency'),unit(metric(m,'node_relay_ms'),'ms'),say('آخرین Relay Probe','Last relay probe'))+'</div>'+
 '<div class="two"><section class="card"><div class="section-title">'+say('وضعیت سرویس‌ها','Service health')+'</div>'+
 row('WAN',metric(m,'wan_up'))+row('DNS',metric(m,'dns_ok'))+row(say('اینترنت خارجی','Internet probe'),metric(m,'internet_ok'))+row('Passwall2',metric(m,'passwall_running'))+
 '<div class="tags" style="margin-top:17px"><span class="tag">WAN IP: '+esc(m.wan_ip||'—')+'</span><span class="tag">'+esc(say('نود فعال: ','Active node: ')+(m.active_node_name||m.active_node_id||'—'))+'</span></div></section>'+
 '<section class="card"><div class="section-title">'+say('ابزارهای پرکاربرد','Quick actions')+'</div><div class="actions">'+
 action('system.status',say('وضعیت روتر','Router status'),'system.read',false)+
 action('agent.diagnose',say('عیب‌یابی Agent','Agent diagnostics'),'system.read',false)+
 action('network.doctor',say('عیب‌یابی شبکه','Network doctor'),'network.read',false)+
 action('passwall2.status',say('وضعیت Passwall2','Passwall2 status'),'passwall.read',false)+
 action('passwall2.restart',say('راه‌اندازی مجدد Passwall2','Restart Passwall2'),'passwall.modify',true)+
 '</div><p class="muted" style="margin-top:17px">'+esc(say('آمار ۲۴ ساعت گذشته: ','Last 24h: '))+esc(safeNum(h.heartbeat_coverage,1))+'% '+say('پوشش Heartbeat','heartbeat coverage')+'</p></section></div>'+
 '<div style="margin-top:15px">'+chartHtml('cpu-chart',say('تاریخچه CPU','CPU sample history'))+'</div><div style="margin-top:15px">'+resultBox()+'</div>';
 drawChart('cpu-chart','cpu_percent');
}
function resultBox(){return '<section class="card"><div class="section-title">'+say('نتیجه دستور','Command result')+'</div><pre id="feedback">'+say('یک عملیات مجاز انتخاب کنید.','Select an allowed operation.')+'</pre></section>';}
function internet(){
 var m=latest(),h=state.detail&&state.detail.overview&&state.detail.overview['24h']||{};
 $('page').innerHTML='<div class="summary">'+statCard('WAN',status(metric(m,'wan_up'))[0],esc(m.wan_ip||'—'))+
 statCard('DNS',status(metric(m,'dns_ok'))[0],unit(metric(m,'dns_ms'),'ms'))+
 statCard(say('اینترنت خارجی','Internet'),status(metric(m,'internet_ok'))[0],unit(metric(m,'internet_tcp_ms'),'ms'))+
 statCard(say('پایداری ۲۴ساعته','24h heartbeat'),safeNum(h.heartbeat_coverage,1)+'%',say('از داده‌های ثبت‌شده','From recorded samples'))+'</div>'+
 '<div class="stack">'+chartHtml('latency-chart',say('تأخیر Relay (نمونه‌های موجود)','Relay latency (recorded samples)'))+
 chartHtml('loss-chart',say('افت بسته (نمونه‌های موجود)','Packet loss (recorded samples)'))+
 '<section class="card"><div class="section-title">'+say('آزمون محدود دانلود','Capped router download probe')+'</div><p class="muted">'+say('اندازه‌گیری اختیاری ۲ مگابایت از Cloudflare. IP عمومی روتر با Cloudflare به اشتراک گذاشته می‌شود؛ این نتیجه سرعت نهایی ISP یا Bufferbloat نیست. حداکثر هر ۱۰ دقیقه یک بار.','Optional 2 MiB Cloudflare download. Your router public IP is disclosed to Cloudflare; result is not line-rate or bufferbloat. At most once per 10 minutes.')+'</p><div class="actions">'+action('network.speed_probe',say('اجرای تست ۲ مگابایتی','Run 2 MiB probe'),'network.read',true)+'</div></section>'+resultBox()+
 '<div class="card"><p class="warn-note">'+say('سنجش مستقل ایران/خارج و تست Bufferbloat همچنان به پیاده‌سازی و تست نیاز دارند.','Independent regional speed measurements and bufferbloat testing remain unimplemented.')+'</p></div></div>';
 drawChart('latency-chart','node_relay_ms');drawChart('loss-chart','packet_loss');
}
function proxy(){
 var m=latest();
 var editor=has('passwall.modify')?'<section class="card"><div class="section-title">'+say('افزودن نود و اشتراک','Add node links or subscriptions')+'</div>'+
 '<form data-passwall-form="passwall2.node.add_link" class="stack" style="margin-bottom:18px"><label>'+say('لینک‌های کانفیگ (یک یا چند لینک)','Configuration links (one or more)')+'<textarea name="link" required rows="3" maxlength="12000" placeholder="vless://..." style="width:100%;resize:vertical;background:var(--soft);color:var(--ink);padding:10px;border:1px solid var(--border);border-radius:9px"></textarea></label><button type="submit" class="action primary">'+say('وارد کردن لینک‌ها','Import node links')+'</button></form>'+
 '<form data-passwall-form="passwall2.subscription.add" class="stack"><label>'+say('نام اشتراک','Subscription name')+'<input name="remark" required maxlength="80" style="display:block;width:100%;background:var(--soft);color:var(--ink);padding:10px;border:1px solid var(--border);border-radius:9px" placeholder="My Subscription"></label><label>Subscription HTTPS URL<input name="url" type="url" required maxlength="4096" pattern="https://.*" placeholder="https://…" style="display:block;width:100%;background:var(--soft);color:var(--ink);padding:10px;border:1px solid var(--border);border-radius:9px"></label><button class="action primary" type="submit">'+say('افزودن اشتراک','Add subscription')+'</button></form></section>':'';
 $('page').innerHTML='<div class="stack">'+hero()+'<section class="card"><div class="section-title">'+say('مدیریت Passwall2','Passwall2 management')+' <span class="pill '+status(metric(m,'passwall_running'))[1]+'">'+status(metric(m,'passwall_running'))[0]+'</span></div><div class="actions">'+
 action('passwall2.status',say('وضعیت سرویس','Service status'),'passwall.read',false)+
 action('passwall2.nodes.list',say('نودها و انتخاب سرور','Nodes & selection'),'passwall.read',false)+
 action('passwall2.subscriptions.list',say('اشتراک‌ها','Subscriptions'),'passwall.read',false)+
 action('passwall2.shunt.list',say('قوانین Shunt','Shunt rules'),'passwall.read',false)+
 action('passwall2.nodes.relay_ranking',say('رتبه‌بندی Relay','Rank relay nodes'),'passwall.read',false)+
 action('passwall2.failover.list',say('وضعیت Failover','Failover status'),'passwall.read',false)+
 action('passwall2.restart',say('راه‌اندازی مجدد سرویس','Restart Passwall2'),'passwall.modify',true)+'</div></section>'+
 editor+resultBox()+
 '<section class="card"><div class="section-title">'+say('محافظت پیشرفته (در حال توسعه)','Advanced protection (planned)')+'</div><div class="tags">'+['Smart Failover 2.0','Iran split routing','Kill Switch','IPv6 leak protection','WireGuard/OpenVPN adapters'].map(function(v){return '<span class="tag">'+esc(v)+'</span>';}).join('')+'</div><p class="muted" style="margin-top:15px">'+say('قابلیت‌های حفاظت از مسیر ترافیک بدون تست واقعی و مسیر Rollback فعال نمی‌شوند.','Packet-policy features remain disabled until path validation and recovery tests are complete.')+'</p></section></div>';
}
function routers(){
 var devices=state.fleet&&state.fleet.devices||[];
 $('page').innerHTML='<section class="card"><div class="section-title">'+say('روترهای ثبت‌شده','Registered routers')+' <span class="pill">'+devices.length+'</span></div>'+
 (devices.map(function(d){return '<div class="device-row"><div><strong>'+esc(d.display_name||d.device_id)+' '+(d.device_id===state.active?'●':'')+'</strong><small>'+esc(d.device_id)+' · '+esc(d.hostname||'—')+' · Agent '+esc(d.agent_version||'—')+'</small></div><button type="button" class="action" data-router="'+esc(d.device_id)+'" '+(d.device_id===state.active?'disabled':'')+'>'+say('انتخاب','Select')+'</button></div>';}).join('')||'<div class="empty">'+say('روتری ثبت نشده است.','No registered routers.')+'</div>')+
 '</section><p class="muted" style="margin-top:14px">'+say('این بخش مربوط به Fleet روترها است؛ دستگاه‌های متصل به وای‌فای/LAN در بخش «دستگاه‌ها» قرار می‌گیرند.','This section manages routers; Wi-Fi/LAN clients have a separate page.')+'</p>'+
 (state.role==='owner'?'<section class="card" style="margin-top:18px" id="rolloutBoard"><div class="section-title">'+say('انتشار مرحله‌ای با تأیید Owner','Owner-approved canary rollout')+'</div><p class="muted">'+say('هر موج پس از اتمام و تأیید سلامت دستگاه‌ها متوقف می‌شود و برای موج بعدی به تأیید Owner نیاز دارد.','Each wave halts for fresh health verification and explicit Owner approval before the next wave.')+'</p><div class="empty">'+say('در حال دریافت برنامه‌ها…','Loading rollouts…')+'</div></section>':'');
 if(state.role==='owner')loadRollouts();
}
async function loadRollouts(){
 if(state.page!=='routers'||state.role!=='owner')return;
 var target=$('rolloutBoard');if(!target)return;
 try {
  var [g,r]=await Promise.all([api('groups'),api('rollout_list')]);
  if(state.page!=='routers'||!target.isConnected)return;
  var groups=Array.isArray(g.groups)?g.groups:[];
  var rollouts=Array.isArray(r.rollouts)?r.rollouts:[];
  var options=groups.map(function(x){return '<option value="'+Number(x.id)+'">'+esc(x.name||('Group '+x.id))+'</option>';}).join('');
  var create=groups.length?'<form data-rollout-create class="stack"><label>'+say('گروه روترها','Router group')+' <select name="group_id" required class="select">'+options+'</select></label>'+
   '<label>'+say('فرمان مرحله‌ای','Staged action')+' <select name="command_action" class="select"><option value="agent.update.check">'+say('بررسی آپدیت','Check updates')+'</option><option value="agent.update.apply">'+say('نصب آپدیت امضاشده','Apply signed update')+'</option></select></label>'+
   '<label>'+say('تعداد روتر هر موج بعد از Canary','Subsequent wave size')+' <input name="wave_size" type="number" min="1" max="5" value="2" required style="width:85px"></label>'+
   '<button class="action primary" type="submit">'+say('ساخت برنامه (بدون اجرا)','Create rollout draft')+'</button></form>':'<p class="warn-note">'+say('ابتدا یک گروه روتر در Dashboard ایجاد کن.','Create a router group in the Fleet Dashboard first.')+'</p>';
  var rows=rollouts.map(function(x){
    var status=String(x.status||''),id=Number(x.id);
    var ops=[];
    if(status==='draft')ops.push(['start',say('شروع Canary','Start canary')]);
    if(status==='awaiting_approval')ops.push(['approve_next',say('تأیید موج بعد','Approve next wave')]);
    if(status==='running'||status==='awaiting_approval')ops.push(['pause',say('توقف','Pause')]);
    if(status==='paused'&&Number(x.failed_routers||0)===0)ops.push(['resume',say('ادامه پس از بررسی','Resume after review')]);
    if(['draft','running','awaiting_approval','paused'].includes(status))ops.push(['cancel',say('لغو برنامه','Cancel rollout')]);
    return '<div class="device-row"><div><strong>#'+id+' '+esc(x.command_action||'')+'</strong><small>'+say('وضعیت: ','Status: ')+esc(status)+' · '+esc(x.completed_routers||0)+' / '+esc(x.total_routers||0)+' · '+esc(x.last_error||'')+'</small></div><div class="actions">'+
       ops.map(function(op){return '<button type="button" class="action" data-rollout-control="'+op[0]+'" data-rollout-id="'+id+'">'+op[1]+'</button>';}).join('')+'</div></div>';
  }).join('');
  target.innerHTML='<div class="section-title">'+say('انتشار مرحله‌ای و کنترل موج‌ها','Staged router rollouts')+'</div>'+
   '<p class="warn-note">'+say('نسخه توسعه: ابتدا روی روتر آزمایشی. توقف برنامه، فرمانی را که قبلاً در صف قرار گرفته لغو نمی‌کند.','Development: use test routers only. Cancelling a rollout cannot undo already queued commands.')+'</p>'+create+
   '<div class="section-title" style="margin-top:23px">'+say('برنامه‌های قبلی','Recent rollouts')+'</div>'+(rows||'<p class="muted">—</p>');
 }catch(e){if(target.isConnected)target.innerHTML='<p class="warn-note">'+esc(e.message)+'</p>';}
}


function readPanel(title,desc,items,planned){
 $('page').innerHTML='<div class="stack"><section class="card"><div class="section-title">'+esc(title)+'</div><p class="muted" style="margin-bottom:14px">'+esc(desc)+'</p><div class="actions">'+
 items.map(function(item){return action(item[0],say(item[1],item[2]),item[3],false);}).join('')+
 '</div></section>'+resultBox()+
 (planned?'<section class="card"><div class="section-title">'+say('در حال توسعه','Upcoming capabilities')+'</div><p class="warn-note">'+esc(planned)+'</p></section>':'')+'</div>';
}
function showClients(){
 readPanel(say('دستگاه‌های شبکه','LAN clients'),say('مشاهده DHCP و جدول همسایه‌های شبکه از خود روتر. فقط دستگاه شناسایی‌شده نمایش داده می‌شود؛ فعال‌بودن همه آن‌ها تضمین نیست.','Read-only DHCP and neighbor-table discovery. A detected client is not necessarily online.'),[
  ['system.clients','دریافت لیست دستگاه‌ها','List clients','system.read'],
  ['system.interfaces','اینترفیس‌های شبکه','Network interfaces','system.read'],
  ['network.dhcp_leases','اجاره‌های DHCP','DHCP leases','network.read']
 ],say('مسدودسازی دستگاه، محدودیت سرعت، زمان‌بندی و اولویت‌دهی به قوانین nftables/tc آزمایش‌شده روی روتر نیاز دارند. فعلاً غیرفعال‌اند.','Blocking, limits, schedules and priorities require tested nftables/tc policies; not active yet.'));
 if(has('system.read')){
  $('page').innerHTML+='<section class="card"><div class="section-title">'+say('پیش‌نمایش سیاست دستگاه (بدون اجرا)','Client policy dry run (no changes)')+'</div><p class="muted">'+say('MAC دستگاه و نوع سیاست را وارد کن. این فرم هیچ اتصال یا تنظیمی را تغییر نمی‌دهد.','Enter a client MAC and policy. No firewall, connection or configuration is changed.')+'</p><form class="stack" data-client-policy><input name="mac" maxlength="24" required placeholder="02:11:22:33:44:55" dir="ltr" aria-label="Client MAC"><select name="mode" aria-label="Policy"><option value="block">Block</option><option value="unblock">Unblock</option><option value="limit">Speed limit (kbit/s)</option></select><input name="limit_kbit" type="number" min="64" max="1000000" step="1" placeholder="1024 (only for limit)" aria-label="Speed limit"><button class="action" type="submit">'+say('نمایش پیش‌نمایش','Preview policy')+'</button></form></section>';
 }
}
function showWifi(){
 readPanel(say('مدیریت وای‌فای','Wi-Fi control'),say('مشاهده SSID و رادیوها و کلاینت‌های وای‌فای. رمزهای فعلی هیچ‌وقت در خروجی نمایش داده نمی‌شوند.','Discover SSIDs, radios and stations. Existing Wi-Fi passwords are never returned.'),[
 ['wifi.list','شبکه‌های وای‌فای','Wi-Fi networks','wifi.read'],
 ['wifi.radios','رادیوهای ۲٫۴/۵/۶','Wi-Fi radios','wifi.read'],
 ['wifi.stations','دستگاه‌های وای‌فای','Wi-Fi stations','wifi.read']
 ],say('اعمال تغییرات شبکه فقط پس از پیاده‌سازی Rollback زمان‌دار فعال می‌شود؛ پیش‌نمایش SSID هیچ تغییری ایجاد نمی‌کند.','Changes will be enabled only after timed safe-apply rollback; an SSID preview never changes the router.'));
 if(has('wifi.read')){
  $('page').innerHTML+='<section class="card"><div class="section-title">'+say('پیش‌نمایش تغییر نام شبکه','SSID change preview')+'</div><p class="muted">'+say('ابتدا از «شبکه‌های وای‌فای» شناسه Section را پیدا کن. این فرم فقط تفاوت مقدار فعلی و پیشنهادی را نشان می‌دهد.','Retrieve a named Wi-Fi section from Wi-Fi networks first. This form only compares the current and desired SSID.')+'</p><form data-ssid-plan class="stack"><label>'+say('نام Section','UCI section')+'<input name="section" required pattern="[A-Za-z0-9_-]{1,64}" maxlength="64" autocomplete="off" placeholder="default_radio0"></label><label>'+say('نام جدید Wi-Fi','Desired SSID')+'<input name="ssid" required maxlength="32" autocomplete="off" placeholder="My-WiFi"></label><button class="action primary" type="submit">'+say('پیش‌نمایش بدون تغییر','Preview only (no changes)')+'</button></form></section>';
 }
}
function showDNS(){
 readPanel(say('DNS و امنیت','DNS & security'),say('مشاهده Resolver و Route و اجرای آزمایش اتصال بدون تغییر تنظیمات فعلی.','Read resolver configuration and routing; test connectivity without altering settings.'),[
 ['network.dns','مشاهده DNS روتر','Read DNS','network.read'],
 ['network.routes','جدول مسیریابی','Routing table','network.read'],
 ['network.path_audit','ممیزی نشانه‌های IPv6 و DNS','IPv6 and DNS egress indicators','network.read'],
 ['network.services','سرویس‌های DNS، Adblock، DDNS، MultiWAN و VLAN','DNS, Adblock, DDNS, MultiWAN and VLAN','network.read']
 ['network.internet_test','تست اینترنت','Internet probes','network.read'],
 ['network.doctor','عیب‌یابی مرحله‌ای','Network doctor','network.read']
 ],say('DNS رمزنگاری‌شده، تفکیک ترافیک ایران، نشت DNS/IPv6 و Kill Switch تا زمان تست بسته‌ها و مسیرها فعلاً فقط در برنامه توسعه هستند.','Encrypted/split DNS, IPv6 leak blocking and kill switch need validated packet-path tests.'));
}
function showLED(){
 readPanel(say('چراغ‌های روتر','Router LEDs'),say('نمایش چراغ‌های عمومی sysfs در همه مدل‌های دارای LED و شناسایی اختصاصی GoogleWifiLED؛ بدون تغییر در کنترلر سخت‌افزار.','Read-only sysfs LED discovery on any OpenWrt board, plus GoogleWifiLED detection. No write operations.'),[
  ['system.leds','چراغ‌های sysfs','Read sysfs LEDs','system.read'],
  ['led.status','وضعیت GoogleWifiLED','GoogleWifiLED status','system.read'],
  ['agent.capabilities','شناسایی سخت‌افزار','Hardware capabilities','system.read']
 ],say('تغییر رنگ، روشنایی، الگوها و زمان‌بندی فقط پس از ساخت Adapter ایمن برای سخت‌افزار مربوطه فعال می‌شود.','RGB, brightness, patterns and scheduling require model-specific verified drivers before being enabled.'));
}
function showCellular(){
 readPanel(say('مودم و سیم‌کارت','Cellular modem'),say('کشف مودم‌های جانبی با ModemManager یا uqmi و نمایش فقط شدت سیگنال؛ شناسه سیم‌کارت، IMSI و IMEI ارسال نمی‌شوند.','Read-only ModemManager/uqmi discovery and whitelisted signal metrics. No subscriber identifiers are sent.'),[
  ['cellular.status','بررسی مودم و سیگنال','Check modem and signal','network.read'],
  ['agent.capabilities','قابلیت‌های سخت‌افزار','Hardware capabilities','system.read']
 ],say('قفل باند، Cell Lock، SMS و USSD نیازمند Adapter تست‌شده مخصوص مودم‌اند؛ در دستگاه‌های بدون مودم این کنترل‌ها غیرفعال‌اند.','Band/cell locking and SMS/USSD need tested modem-specific adapters and remain disabled without them.'));
}
function showGaming(){
 readPanel(say('بازی و کیفیت اتصال','Gaming & quality'),say('تست‌های تشخیصی شبکه برای بررسی کیفیت پایه؛ مقادیر فعلی جایگزین تست واقعی Bufferbloat نیستند.','Network diagnostics are available; these do not replace a true bufferbloat test.'),[
 ['network.internet_test','آزمایش اتصال','Connectivity test','network.read'],
 ['network.doctor','عیب‌یابی شبکه','Network doctor','network.read'],
 ['network.policy_status','وضعیت SQM و UPnP','SQM and UPnP inventory','network.read'],
 ['system.interfaces','وضعیت اینترفیس‌ها','Interfaces','system.read']
 ],say('SQM، QoS، UPnP و اولویت دستگاه‌ها پس از تشخیص قابلیت واقعی سخت‌افزار و طراحی تنظیمات برگشت‌پذیر فعال می‌شوند.','SQM/QoS, UPnP and prioritization require capability discovery and recoverable configuration.'));
}
function renderCommandOutput(actionName,result){
 var keysensitive=/(^|_)(password|secret|token|private|credential|psk|auth|key|data_b64|sealed|url|link)(_|$)/i;
 function clean(x,depth){
  if(depth>5)return '[depth limit]';
  if(Array.isArray(x))return x.slice(0,100).map(function(v){return clean(v,depth+1);});
  if(x&&typeof x==='object'){
   var out={};Object.keys(x).slice(0,100).forEach(function(k){out[k]=keysensitive.test(k)?'[redacted]':clean(x[k],depth+1);});return out;
  }
  return typeof x==='string'?x.slice(0,2048):x;
 }
 var safe=clean(result,0);
 if(actionName==='system.leds'&&safe&&Array.isArray(safe.leds)){
   return '<div class="section-title">'+say('چراغ‌های شناسایی‌شده','Discovered sysfs LEDs')+' ('+safe.leds.length+')</div>'+
     (safe.leds.map(function(item){
       var name=String(item.name||'');
       var valid=/^[A-Za-z0-9:_+.-]{1,96}$/.test(name)&&name!=='.'&&name!=='..';
       return '<div class="device-row"><div><strong>'+esc(name)+'</strong><small>'+say('شدت فعلی: ','Brightness: ')+esc(item.brightness||0)+' / '+esc(item.max_brightness||0)+'</small></div>'+
        (valid&&has('system.modify')?'<button class="action" data-led-identify="'+esc(name)+'">'+say('شناسایی چراغ (۱ ثانیه)','Identify (1 sec)')+'</button>':'')+'</div>';
     }).join('')||'<div class="empty">'+say('چراغ قابل شناسایی نیست','No LEDs discovered')+'</div>');
 }
 if(actionName==='system.clients'&&safe&&Array.isArray(safe.clients)){
  var clients=safe.clients;
  return '<div class="section-title">'+say('کلاینت‌های کشف‌شده','Discovered clients')+' ('+clients.length+')</div>'+
   (clients.map(function(c){return '<div class="device-row"><div><strong>'+esc(c.hostname||say('بدون نام','Unknown'))+'</strong><small>'+esc(c.ip||'—')+' · '+esc(c.mac||'—')+'</small></div><span class="pill">'+esc(c.interface||'—')+'</span></div>';}).join('')||'<div class="empty">'+say('دستگاهی شناسایی نشد.','No devices found.')+'</div>');
 }
 if((actionName==='passwall2.nodes.list'||actionName==='passwall2.node.add_link')&&safe&&Array.isArray(safe.nodes)){
  return '<div class="section-title">'+say('نودهای Passwall2','Passwall2 nodes')+' ('+safe.nodes.length+')</div>'+
    (safe.nodes.map(function(n){
      var id=String(n.id||'');var ok=/^[A-Za-z0-9_-]{1,96}$/.test(id);
      var buttons=ok&&has('passwall.modify')?'<div class="actions">'+
       (!n.selected?'<button class="action" data-action-param="passwall2.node.select" data-param-id="'+esc(id)+'">'+say('انتخاب','Select')+'</button>':'')+
       (!n.is_shunt?'<button class="action danger" data-action-param="passwall2.node.delete" data-param-id="'+esc(id)+'">'+say('حذف','Delete')+'</button>':'')+'</div>':'';
      var test=ok?'<button class="action" data-action-param="passwall2.node.test" data-param-id="'+esc(id)+'">'+say('تست','Test')+'</button>':'';
      return '<div class="device-row"><div><strong>'+esc(n.remarks||id)+(n.selected?' ●':'')+'</strong><small>'+esc(n.protocol||n.type||'—')+' · '+esc(n.address||'—')+'</small></div>'+test+buttons+'</div>';
    }).join('')||'<div class="empty">'+say('نودی یافت نشد.','No nodes found.')+'</div>');
 }
 if((actionName==='passwall2.subscriptions.list'||actionName==='passwall2.subscription.add'||actionName==='passwall2.subscription.update'||actionName==='passwall2.subscription.delete')&&safe&&Array.isArray(safe.subscriptions)){
   return '<div class="section-title">'+say('اشتراک‌های ثبت‌شده','Subscriptions')+' ('+safe.subscriptions.length+')</div>'+
   (safe.subscriptions.map(function(n){
    var id=String(n.id||'');var ok=/^[A-Za-z0-9_-]{1,96}$/.test(id);
    return '<div class="device-row"><div><strong>'+esc(n.remark||id)+'</strong><small>'+esc(n.node_count||0)+' '+say('نود','nodes')+'</small></div>'+
    (ok&&has('passwall.modify')?'<div class="actions"><button class="action" data-action-param="passwall2.subscription.update" data-param-id="'+esc(id)+'">'+say('به‌روزرسانی','Update')+'</button><button class="action danger" data-action-param="passwall2.subscription.delete" data-param-id="'+esc(id)+'">'+say('حذف','Delete')+'</button></div>':'')+'</div>';
   }).join('')||'<div class="empty">'+say('اشتراکی ثبت نشده است.','No subscriptions registered.')+'</div>');
 }
 if(actionName==='wifi.list'&&safe&&Array.isArray(safe.wifi)){
  return '<div class="section-title">'+say('شبکه‌های تعریف‌شده','Configured SSIDs')+'</div>'+
   (safe.wifi.map(function(w){return '<div class="device-row"><div><strong>'+esc(w.ssid||'—')+'</strong><small>'+esc(w.section||'—')+' · '+esc(w.encryption||'—')+'</small></div><span class="pill">'+(w.disabled?say('خاموش','Disabled'):say('روشن','Enabled'))+'</span></div>';}).join('')||'<div class="empty">'+say('شبکه‌ای یافت نشد.','No Wi-Fi interfaces detected.')+'</div>');
 }
 if(actionName==='network.doctor'&&safe&&Array.isArray(safe.checks)){
  return '<div class="section-title">'+say('گزارش عیب‌یابی','Network doctor report')+' <span class="pill">'+esc(safe.status||'—')+'</span></div>'+
   '<p class="muted">'+say('اولین مرحله ناموفق: ','First failed check: ')+esc(safe.first_failed||'—')+'</p>'+
   safe.checks.map(function(v){return '<div class="device-row"><div><strong>'+esc(v.id||'—')+'</strong><small>'+esc(v.detail||'')+'</small></div><span class="pill '+(v.status==='pass'?'ok':v.status==='fail'?'bad':'')+'">'+esc(v.status||'—')+' · '+esc(v.latency_ms||0)+'ms</span></div>';}).join('');
 }
 if(actionName==='agent.capabilities'&&safe&&safe.capabilities){
  return '<div class="section-title">'+say('قابلیت‌های شناسایی‌شده','Discovered capabilities')+'</div><p class="muted">'+esc(safe.hardware&&safe.hardware.model||'—')+' · OpenWrt '+esc(safe.openwrt_version||'—')+'</p>'+
  Object.entries(safe.capabilities).map(function(entry){return '<div class="status-row"><span>'+esc(entry[0])+'</span><span>'+esc(Array.isArray(entry[1])?entry[1].join(', ')||'—':entry[1]?'✓':'—')+'</span></div>';}).join('');
 }
 return '<pre id="feedback">'+esc(JSON.stringify(safe,null,2).slice(0,24000))+'</pre>';
}
function pending(title,summary,bullets){
 $('page').innerHTML='<div class="grid"><section class="card info"><span class="pill">'+say('در دست توسعه · فعلاً غیرفعال','Planned · Not yet implemented')+'</span><h3>'+esc(title)+'</h3><p>'+esc(summary)+'</p><div class="tags">'+bullets.map(function(b){return '<span class="tag">'+esc(b)+'</span>';}).join('')+'</div></section><section class="card"><div class="section-title">'+say('سازگاری و امنیت','Compatibility & safety')+'</div><p class="muted">'+say('تا وقتی Agent قابلیت سخت‌افزاری مربوط را اعلام نکند و API عملیات با RBAC و Rollback آزمایش نشود، کنترل تغییردهنده‌ای فعال نمی‌شود.','No mutating controls are enabled until the agent advertises the required capability and the API action passes RBAC and rollback testing.')+'</p><p class="warn-note" style="margin-top:16px">'+say('برای دستگاه‌های ناسازگار نباید تنظیمات ساختگی یا موفقیت ظاهری نمایش داده شود.','Unsupported hardware must not show fake settings or simulated success.')+'</p></section></div>';
}
function system(){
 $('page').innerHTML='<div class="stack">'+hero()+'<section class="card"><div class="section-title">'+say('ابزارهای پشتیبانی‌شده','Supported tools')+'</div><div class="actions">'+
 action('system.status',say('وضعیت سیستم','System status'),'system.read',false)+
 action('agent.diagnose',say('تست ارتباط Agent','Agent diagnostics'),'system.read',false)+
 action('agent.capabilities',say('شناسایی سخت‌افزار','Hardware capabilities'),'system.read',false)+
 action('system.interfaces',say('اینترفیس‌های شبکه','Network interfaces'),'system.read',false)+
 action('system.backup.create',say('ارسال بکاپ در تلگرام','Send backup to Telegram'),'backup.create',true)+
 action('agent.update.check',say('بررسی آپدیت','Check updates'),'system.read',false)+
 action('agent.update.apply',say('نصب آپدیت Agent','Update agent'),'agent.update',true)+'</div></section>'+resultBox()+
 (state.role==='owner'&&init?'<section class="card"><div class="section-title">'+say('مهاجرت دیتابیس','Database migrations')+'</div><p class="muted">'+say('مهاجرت‌های افزودنی بدون حذف داده؛ ابتدا بکاپ دیتابیس تهیه کن. اجرای تغییر فقط با تأیید Owner تلگرام مجاز است.','Additive, non-destructive migrations. Back up MySQL first; only Telegram Owner may apply.')+'</p><div class="actions"><button class="action" data-migration="status">'+say('نمایش وضعیت','Show status')+'</button><button class="action danger" data-migration="apply">'+say('اجرای مهاجرت تأییدشده','Apply confirmed migrations')+'</button></div><div id="migrationInfo" class="muted" aria-live="polite"></div></section>':'')+
 (state.role==='owner'&&init?'<section class="card"><div class="section-title">'+say('تنظیم ورود مستقل وب','Configure browser login')+'</div><p class="muted">'+say('فقط Owner از داخل Telegram Mini App می‌تواند نام کاربری و رمز پنل را ایجاد یا بازنشانی کند.','Only a Telegram Owner can create or reset the web login credentials.')+'</p><form data-web-admin class="stack"><input required name="username" placeholder="admin" autocomplete="username" pattern="[a-z][a-z0-9._-]{2,31}" minlength="3" maxlength="32"><input required type="password" name="password" autocomplete="new-password" minlength="12" maxlength="256" placeholder="Password (12+ characters)"><button type="submit" class="action primary">'+say('ثبت نام کاربری و رمز','Save login credentials')+'</button></form></section>':'')+
 '<section class="card"><div class="section-title">'+say('بخش‌های آماده توسعه','Planned integration')+'</div><p class="muted">'+say('مدیریت LED، سرویس‌ها، آپدیت PHP Backend و کنسول پیشرفته بعد از توسعه Adapter و مسیر بازیابی اضافه می‌شوند.','LED control, service operations, staged backend updates and advanced console will require adapters and recovery paths.')+'</p></section></div>';
}
async function automation(){
 $('page').innerHTML='<div class="empty">'+say('در حال خواندن هشدارها و برنامه‌ها…','Reading schedules and alerts…')+'</div>';
 var parts=[];
 try{if(has('monitor.read')){var a=await api('alerts',{device_id:state.active});parts.push('<section class="card"><div class="section-title">'+say('قوانین هشدار','Alert rules')+' <span class="pill">'+(a.alerts||[]).length+'</span></div>'+
 (a.alerts||[]).slice(0,15).map(function(x){return '<div class="device-row"><div><strong>'+esc(x.metric)+'</strong><small>'+esc(x.operator)+' '+esc(x.threshold)+' · '+esc(x.duration_seconds)+' sec</small></div><span class="pill">'+(Number(x.enabled)?say('فعال','Enabled'):say('غیرفعال','Disabled'))+'</span></div>';}).join('')+'</section>');}}
 catch(e){parts.push('<div class="warn-note">'+esc(e.message)+'</div>');}
 try{if(has('device.read')){var s=await api('schedules',{device_id:state.active});parts.push('<section class="card"><div class="section-title">'+say('زمان‌بندی‌ها','Schedules')+' <span class="pill">'+(s.schedules||[]).length+'</span></div>'+
 (s.schedules||[]).slice(0,15).map(function(x){return '<div class="device-row"><div><strong>'+esc(x.name)+'</strong><small>'+esc(x.action)+' · '+esc(x.next_run_at||'—')+'</small></div><span class="pill">'+(Number(x.enabled)?say('فعال','Enabled'):say('غیرفعال','Disabled'))+'</span></div>';}).join('')+'</section>');}}
 catch(e){parts.push('<div class="warn-note">'+esc(e.message)+'</div>');}
 parts.push('<section class="card"><p class="muted">'+say('برای ویرایش کامل از داشبورد قبلی استفاده کن؛ موتور قوانین شرطی پیشرفته در نسخه 1.3 توسعه می‌یابد.','Use the legacy dashboard for full editing; advanced conditional automation is planned for v1.3.')+'</p><a href="../dashboard/">'+say('داشبورد قبلی','Legacy dashboard')+'</a></section>');
 $('page').innerHTML='<div class="stack">'+parts.join('')+'</div>';
}
function render(){
 nav();
 if(!state.fleet){$('page').innerHTML='<div class="empty">'+say('برای ورود از حساب وب یا مینی‌اپ تلگرام استفاده کن.','Sign in with your web account or Telegram Mini App.')+'</div>';return;}
 if(!state.active){$('page').innerHTML='<div class="empty">'+say('هیچ روتر فعالی وجود ندارد.','No active router.')+'</div>';return;}
 if(state.page==='home')home();else if(state.page==='internet')internet();else if(state.page==='proxy')proxy();else if(state.page==='routers')routers();else if(state.page==='clients')showClients();else if(state.page==='wifi')showWifi();else if(state.page==='dns')showDNS();else if(state.page==='gaming')showGaming();else if(state.page==='led')showLED();else if(state.page==='cellular')showCellular();else if(state.page==='system')system();else if(state.page==='automation'){automation();}else{
 var plans={
  clients:[say('مدیریت دستگاه‌های شبکه','LAN client management'),say('فهرست کلاینت‌ها، محدودیت سرعت، مسدودسازی، زمان‌بندی و اولویت‌بندی پس از توسعه API مجاز نمایش داده می‌شود.','Live client list, rate limits, blocking, schedules and priorities require an authorized agent API.'),['DHCP / ARP','MAC identity','Live RX/TX','Rate limit','Block / unblock','Parental schedules']],
  wifi:[say('مدیریت Wi-Fi','Wi-Fi management'),say('مدیریت SSID، رمز، ۲٫۴/۵ گیگاهرتز، شبکه مهمان، QR و انتخاب کانال ایمن همراه با Rollback.','SSID, password, 2.4/5 GHz, guest Wi-Fi, QR, safe channel selection and rollback.'),['SSID & WPA2/WPA3','Guest isolation','Channel scan','QR codes','Safe rollback']],
  cellular:[say('سیم‌کارت و دکل','Cellular & towers'),say('فقط برای مودم‌هایی که Adapter سخت‌افزاری معتبر دارند؛ Google WiFi این قابلیت را ندارد.','Only modems with verified hardware adapters; Google WiFi does not have cellular capabilities.'),['LTE/5G signal','Cell scanning','Band lock','Cell lock','Fallback timer','SMS / USSD']],
  dns:[say('DNS و امنیت','DNS & security'),say('DNS مستقیم و رمزنگاری‌شده، تشخیص نشت، فیلتر تبلیغات، دسترسی پنل و تفکیک ترافیک.','Direct/encrypted DNS, leak detection, ad filtering, panel access and split routing.'),['DNS upstreams','Encrypted DNS','Iran direct routing','Ad-block','IPv6 leaks','Kill switch']],
  led:[say('چراغ هوشمند روتر','Router LED'),say('نمایش وضعیت اتصال، رنگ دلخواه، پینگ، خاموشی شبانه و تنظیم شدت نور تنها در سخت‌افزار سازگار.','Status colors, custom RGB, latency indication, night schedule and brightness on supported LED hardware only.'),['Google WiFi LED adapter','RGB / brightness','Latency colors','Night quiet hours']],
  gaming:[say('بازی و کیفیت اتصال','Gaming & quality'),say('تست Bufferbloat، نوع NAT، QoS/SQM، اولویت‌بندی دستگاه و UPnP اختیاری.','Bufferbloat, NAT type, SQM/QoS, client priorities and opt-in UPnP.'),['Latency','Bufferbloat','SQM','NAT type','UPnP opt-in']]
 };
 var p=plans[state.page];if(p)pending(p[0],p[1],p[2]);
 }
}
async function api(action,data){
 if(!init&&!webAuth)throw new Error(say('ورود امن لازم است','Authentication required'));
 var r=await fetch('../dashboard/api.php',{method:'POST',cache:'no-store',credentials:'same-origin',headers:{'Content-Type':'application/json','X-PW-CSRF':webCsrf},body:JSON.stringify(Object.assign({init_data:init,action:action},data||{}))});
 var payload=await r.json().catch(function(){return {ok:false,error:'Invalid API response'};});
 if(!r.ok||!payload.ok)throw new Error(payload.error||'API '+r.status);
 return payload;
}
async function refresh(){
 if(!init&&!webAuth){window.location.replace('../auth/login.php');return;}
 $('notice').textContent=say('در حال همگام‌سازی اطلاعات روتر…','Syncing router data…');
 try{
  var r=await api('summary');state.fleet=r.fleet;state.active=r.active_device_id||'';state.role=r.role||'viewer';state.permissions=r.permissions||[];
  state.detail=state.active?await api('device_detail',{device_id:state.active}):null;
  $('notice').textContent='';render();
 }catch(e){state.fleet=null;state.detail=null;$('notice').textContent=say('اتصال برقرار نشد: ','Connection failed: ')+e.message;render();}
}
async function command(id,confirmNeeded,params){
 if(state.busy)return;
 if(confirmNeeded&&!window.confirm(id==='network.speed_probe'?say('این آزمون حدود ۲ مگابایت داده از Cloudflare دانلود می‌کند و IP عمومی روتر قابل مشاهده خواهد بود. اجرا شود؟','This probe downloads 2 MiB from Cloudflare, exposing your router public IP. Continue?'):id==='led.identify'?say('چراغ حدود یک ثانیه تغییر کرده و سپس برمی‌گردد. ادامه؟','The LED changes for one second and then returns to its original state. Continue?'):say('این عملیات ممکن است ارتباط روتر را تغییر دهد. مطمئنی؟','This action may affect router connectivity. Continue?')))return;
 state.busy=true;var buttons=document.querySelectorAll('[data-command]');buttons.forEach(function(b){b.disabled=true;});
 var out=$('feedback');if(out)out.textContent=say('در حال ارسال فرمان…','Submitting command…');
 try{
  var ret=await api('command',{device_id:state.active,command_action:id,params:params||{},confirmed:!!confirmNeeded});
  if(out)out.textContent='Queued command #'+ret.command_id;
  var finished=false;
  for(var i=0;i<35;i++){
   await new Promise(function(resolve){setTimeout(resolve,1400);});
   var j=await api('command_status',{device_id:state.active,command_id:ret.command_id});
   if(j.command && ['done','failed'].includes(j.command.status)){
     if(out){var box=out.parentElement; if(j.command.status==='done'&&j.command.result){box.innerHTML='<div class="section-title">'+esc(id)+'</div>'+renderCommandOutput(id,j.command.result); if(!box.querySelector('#feedback')){var nextOut=document.createElement('pre');nextOut.id='feedback';nextOut.style.display='none';box.appendChild(nextOut);}}else{out.textContent=JSON.stringify({status:j.command.status,error:j.command.error},null,2).slice(0,20000);}}
     finished=true;break;
   }
  }
  if(!finished&&out)out.textContent=say('فرمان هنوز در صف/اجرا است؛ در داشبورد قبلی وضعیت آن را بررسی کن.','Command is still pending; check status in the legacy dashboard.');
 }catch(e){if(out)out.textContent=e.message;else $('notice').textContent=e.message;}
 finally{state.busy=false;buttons.forEach(function(b){b.disabled=false;});}
}
document.addEventListener('submit',function(ev){
 var form=ev.target.closest('form[data-rollout-create]');if(!form)return;
 ev.preventDefault();if(state.role!=='owner')return;
 var fd=new FormData(form),group=Number(fd.get('group_id')),wave=Number(fd.get('wave_size')),commandAction=String(fd.get('command_action')||'');
 if(!Number.isInteger(group)||group<=0||!Number.isInteger(wave)||wave<1||wave>5)return;
 if(!['agent.update.check','agent.update.apply'].includes(commandAction))return;
 if(!window.confirm(say('برنامه ساخته شود؟ اجرای آن بعداً نیازمند تأیید مستقل است.','Create this rollout? Start requires separate approval.')))return;
 api('rollout_create',{group_id:group,wave_size:wave,command_action:commandAction,confirmed:true})
 .then(loadRollouts).catch(function(e){$('notice').textContent=e.message;});
});
document.addEventListener('click',function(ev){
 var button=ev.target.closest('[data-rollout-control]');if(!button)return;
 if(state.role!=='owner')return;
 var id=Number(button.dataset.rolloutId),operation=String(button.dataset.rolloutControl||'');
 if(!Number.isInteger(id)||id<=0||!['start','approve_next','pause','resume','cancel'].includes(operation))return;
 if(!window.confirm(say('این عملیات ممکن است روی روترهای گروه اثر بگذارد. ادامه می‌دهی؟','This may affect routers in your group. Continue?')))return;
 button.disabled=true;
 api('rollout_control',{id:id,operation:operation,confirmed:true}).then(loadRollouts)
 .catch(function(e){$('notice').textContent=e.message;button.disabled=false;});
});
document.addEventListener('click',function(ev){
 var b=ev.target.closest('[data-migration]');if(!b)return;
 if(!init||state.role!=='owner')return;
 var mode=String(b.dataset.migration||'');
 if(!['status','apply'].includes(mode))return;
 if(mode==='apply'&&!window.confirm(say('از دیتابیس بکاپ گرفته‌ای؟ مهاجرت جداول جدید را ایجاد می‌کند. ادامه؟','Do you have a MySQL backup? This creates new tables. Continue?')))return;
 b.disabled=true;
 api(mode==='apply'?'migration_apply':'migration_status',mode==='apply'?{confirmed:true}:{}).then(function(result){
  var el=$('migrationInfo');if(el)el.textContent=(result.applied?('Applied: '+result.applied.join(', ')+' · '):'')+
    'Pending: '+((result.migration&&result.migration.pending)||[]).join(', ');
 }).catch(function(e){$('notice').textContent=e.message;}).finally(function(){b.disabled=false;});
});
document.addEventListener('click',function(ev){
 var b=ev.target.closest('[data-led-identify]');if(!b)return;
 var name=String(b.dataset.ledIdentify||'');
 if(!/^[A-Za-z0-9:_+.-]{1,96}$/.test(name)||name==='.'||name==='..'||!has('system.modify'))return;
 command('led.identify',true,{name:name}).catch(function(e){$('notice').textContent=e.message;});
});
document.addEventListener('submit',function(ev){
 var form=ev.target.closest('form[data-web-admin]');if(!form)return;
 ev.preventDefault();if(!init||state.role!=='owner')return;
 var fd=new FormData(form),username=String(fd.get('username')||''),password=String(fd.get('password')||'');
 api('web_credentials_set',{username:username,password:password}).then(function(){
   $('notice').textContent=say('ورود وب فعال شد.','Browser login configured.');
   form.reset();
 }).catch(function(e){$('notice').textContent=e.message;});
});
document.addEventListener('submit',function(ev){
 var f=ev.target.closest('form[data-client-policy]');if(!f)return;
 ev.preventDefault();if(!has('system.read'))return;
 var fd=new FormData(f),mac=String(fd.get('mac')||'').trim(),mode=String(fd.get('mode')||'');
 var raw=String(fd.get('limit_kbit')||'');
 if(!/^[a-fA-F0-9:-]{11,24}$/.test(mac)||!['block','unblock','limit'].includes(mode))return;
 var params={mac:mac,mode:mode};
 if(mode==='limit'){
  var limit=Number(raw);
  if(!Number.isInteger(limit)||limit<64||limit>1000000){$('notice').textContent='Invalid kbit/s limit';return;}
  params.limit_kbit=limit;
 }
 command('clients.policy_plan',false,params).catch(function(e){$('notice').textContent=e.message;});
});
document.addEventListener('submit',function(ev){
 var f=ev.target.closest('form[data-ssid-plan]');if(!f)return;
 ev.preventDefault();
 if(!has('wifi.read'))return;
 var fd=new FormData(f),section=String(fd.get('section')||''),ssid=String(fd.get('ssid')||'');
 if(!/^[A-Za-z0-9_-]{1,64}$/.test(section)||!ssid||new TextEncoder().encode(ssid).length>32){
   $('notice').textContent=say('Section یا SSID معتبر نیست.','Invalid section or SSID.');return;
 }
 command('wifi.plan_ssid',false,{section:section,ssid:ssid}).catch(function(e){$('notice').textContent=e.message;});
});
document.addEventListener('submit',function(ev){
 var f=ev.target.closest('form[data-passwall-form]');if(!f)return;
 ev.preventDefault();
 var actionName=f.dataset.passwallForm;
 if(!has('passwall.modify'))return;
 if(actionName!=='passwall2.node.add_link'&&actionName!=='passwall2.subscription.add')return;
 var fd=new FormData(f),params={};
 if(actionName==='passwall2.node.add_link')params.link=String(fd.get('link')||'').trim();
 else {params.url=String(fd.get('url')||'').trim();params.remark=String(fd.get('remark')||'').trim();}
 if(!params.link&&!params.url)return;
 command(actionName,true,params).then(function(){f.reset();}).catch(function(e){$('notice').textContent=e.message;});
});
document.addEventListener('click',function(event){
 var b=event.target.closest('[data-nav],[data-router],[data-command],[data-action-param]');
 if(!b)return;
 if(b.dataset.actionParam){var id=b.dataset.paramId||'';if(!/^[A-Za-z0-9_-]{1,96}$/.test(id))return;var mutation=b.dataset.actionParam!=='passwall2.node.test';command(b.dataset.actionParam,mutation,{id:id});return;}
 if(b.dataset.nav){state.page=b.dataset.nav;render();window.scrollTo(0,0);}
 else if(b.dataset.router){api('select_device',{device_id:b.dataset.router}).then(refresh).catch(function(e){$('notice').textContent=e.message;});}
 else if(b.dataset.command){command(b.dataset.command,b.dataset.confirm==='1');}
});
$('devicePicker').addEventListener('change',function(e){api('select_device',{device_id:e.target.value}).then(refresh).catch(function(err){$('notice').textContent=err.message;});});
$('refreshBtn').onclick=refresh;
$('langBtn').onclick=function(){state.lang=state.lang==='fa'?'en':'fa';try{localStorage.setItem('pwcontrol-lang',state.lang);}catch(e){}applyTheme();render();};
$('themeBtn').onclick=function(){state.theme=state.theme==='light'?'dark':'light';try{localStorage.setItem('pwcontrol-theme',state.theme);}catch(e){}applyTheme();render();};
applyTheme();refresh();
})();
</script>
</body>
</html>
