<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
header('Cache-Control: no-store');
if(!str_starts_with(rtrim((string)(cfg()['base_url']??''),'/'),'https://')){http_response_code(400);exit('Dashboard requires HTTPS');}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>PW Agent Dashboard</title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
:root{color-scheme:dark;--bg:#0b0f14;--card:#131a22;--card2:#18212c;--text:#eef4fb;--muted:#8fa3b8;--line:#283747;--accent:#58a6ff;--good:#3fb950;--bad:#f85149;--warn:#d29922}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,-apple-system,Segoe UI,sans-serif}
header{position:sticky;top:0;z-index:5;background:rgba(11,15,20,.94);backdrop-filter:blur(12px);border-bottom:1px solid var(--line);padding:14px}
h1{font-size:19px;margin:0}small,.muted{color:var(--muted)}
main{padding:14px;max-width:980px;margin:auto}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:13px;margin-bottom:10px}.stat{font-size:25px;font-weight:700}
nav{display:flex;gap:7px;overflow:auto;padding:10px 0}button,input,select,textarea{background:var(--card2);color:var(--text);border:1px solid var(--line);border-radius:9px;padding:9px}button{cursor:pointer}.primary{border-color:var(--accent);color:#fff}.danger{border-color:var(--bad)}.good{color:var(--good)}.bad{color:var(--bad)}.warn{color:var(--warn)}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.grow{flex:1}.device{cursor:pointer}.device.active{border-color:var(--accent)}pre{white-space:pre-wrap;word-break:break-word;background:#080b0f;border-radius:9px;padding:10px;max-height:260px;overflow:auto}
table{width:100%;border-collapse:collapse;font-size:12px}th,td{text-align:left;border-bottom:1px solid var(--line);padding:7px;vertical-align:top}
.hidden{display:none}.pill{display:inline-block;border:1px solid var(--line);border-radius:999px;padding:2px 7px;margin:2px;font-size:11px}
canvas{width:100%;height:150px}
</style>
</head>
<body>
<header><h1>PW Agent <span class="muted">Fleet Dashboard</span></h1><small id="identity">Authenticating…</small></header>
<main>
<div id="error" class="card hidden bad"></div>
<div class="grid" id="stats"></div>
<nav id="tabs">
<button data-tab="devices" class="primary">Devices</button>
<button data-tab="monitor">Monitoring</button>
<button data-tab="audit">Audit & Rollback</button>
<button data-tab="alerts">Alerts</button>
<button data-tab="maintenance">Maintenance</button>
<button data-tab="schedules">Schedules</button>
<button data-tab="access">Access</button>
</nav>
<section id="devices"></section>
<section id="monitor" class="hidden"></section>
<section id="audit" class="hidden"></section>
<section id="alerts" class="hidden"></section>
<section id="maintenance" class="hidden"></section>
<section id="schedules" class="hidden"></section>
<section id="access" class="hidden"></section>
</main>
<script>
const tg=window.Telegram?.WebApp; tg?.ready(); tg?.expand();
const initData=tg?.initData||''; let state={summary:null,active:'',role:'viewer'};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function api(action,data={}){const r=await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({init_data:initData,action,...data})});const j=await r.json().catch(()=>({ok:false,error:'Invalid server response'}));if(!j.ok)throw new Error(j.error||'Request failed');return j}
function err(e){const x=document.querySelector('#error');x.textContent=e.message||e;x.classList.remove('hidden')}
function fmtTime(s){return s?new Date(s.replace(' ','T')+'Z').toLocaleString():'Never'}
function tab(name){document.querySelectorAll('main>section').forEach(x=>x.classList.add('hidden'));document.querySelector('#'+name).classList.remove('hidden');document.querySelectorAll('#tabs button').forEach(x=>x.classList.toggle('primary',x.dataset.tab===name));loadTab(name).catch(err)}
document.querySelectorAll('#tabs button').forEach(b=>b.onclick=()=>tab(b.dataset.tab));
async function refresh(){
 const j=await api('summary');state.summary=j.fleet;state.active=j.active_device_id;state.role=j.role;
 document.querySelector('#identity').textContent='Role: '+state.role+' · Active: '+(state.active||'none');
 document.querySelector('#stats').innerHTML=[
  ['Routers',j.fleet.total],['Online',j.fleet.online],['Offline',j.fleet.offline]
 ].map(x=>`<div class="card"><div class="muted">${x[0]}</div><div class="stat">${x[1]}</div></div>`).join('');
 await renderDevices();
}
async function renderDevices(){
 const rows=state.summary?.devices||[];
 document.querySelector('#devices').innerHTML=`<div class="card"><div class="row"><b>Fleet</b><span class="grow"></span>${['owner','admin'].includes(state.role)?'<button id="addDev">+ Add device</button>':''}</div></div>`+
 rows.map(d=>`<div class="card device ${d.device_id===state.active?'active':''}" data-id="${esc(d.device_id)}"><div class="row"><b>${d.online?'🟢':'🔴'} ${esc(d.display_name)}</b><span class="grow"></span>${d.maintenance?'<span class="pill warn">Maintenance</span>':''}</div><div class="muted">${esc(d.hostname||d.device_id)} · Agent ${esc(d.agent_version||'-')} · ${fmtTime(d.last_seen)}</div><div>${(JSON.parse(d.tags_json||'[]')).map(t=>'<span class="pill">'+esc(t)+'</span>').join('')}</div></div>`).join('');
 document.querySelectorAll('.device').forEach(x=>x.onclick=async()=>{await api('select_device',{device_id:x.dataset.id});await refresh();});
 const add=document.querySelector('#addDev');if(add)add.onclick=async()=>{const name=prompt('Device name');if(!name)return;const j=await api('device_create',{name});prompt('Run this command on the new OpenWrt router within 30 minutes:',j.install_command);await refresh()};
}
async function loadTab(name){
 if(name==='devices')return renderDevices();
 if(!state.active){document.querySelector('#'+name).innerHTML='<div class="card">No active device.</div>';return}
 if(name==='monitor'){
  const j=await api('device_detail',{device_id:state.active}),d=j.device,m=j.metrics||[];
  const last=m[m.length-1]||{};
  document.querySelector('#monitor').innerHTML=`<div class="grid"><div class="card"><div class="muted">CPU</div><div class="stat">${Number(last.cpu_percent||0).toFixed(1)}%</div></div><div class="card"><div class="muted">Memory</div><div class="stat">${Number(last.memory_used_percent||0).toFixed(1)}%</div></div><div class="card"><div class="muted">WAN</div><div class="stat ${Number(last.wan_up)?'good':'bad'}">${Number(last.wan_up)?'UP':'DOWN'}</div></div><div class="card"><div class="muted">Passwall2</div><div class="stat ${Number(last.passwall_running)?'good':'bad'}">${Number(last.passwall_running)?'UP':'DOWN'}</div></div></div><div class="card"><b>${esc(d.display_name)}</b><div class="muted">WAN IP: ${esc(last.wan_ip||'-')} · Last seen: ${fmtTime(d.last_seen)}</div><canvas id="chart" width="900" height="180"></canvas></div>`;
  draw(m);return;
 }
 if(name==='audit'){
  const j=await api('audit',{device_id:state.active});
  document.querySelector('#audit').innerHTML='<div class="card"><b>Recent audit</b><div style="overflow:auto"><table><tr><th>Time</th><th>User</th><th>Action</th><th>Status</th></tr>'+j.audit.map(a=>`<tr><td>${esc(a.created_at)}</td><td>${esc(a.telegram_user_id||a.source)}</td><td>${esc(a.action)}</td><td>${esc(a.status)}</td></tr>`).join('')+'</table></div></div><div class="card"><b>Configuration changes</b>'+j.changes.map(c=>{let diff=[];try{diff=JSON.parse(c.diff_json||'[]')}catch(e){}return `<div class="card"><b>#${c.id} ${esc(c.action)}</b><div class="muted">${esc(c.created_at)}</div><pre>${esc(diff.join('\n')||'No UCI option change detected')}</pre>${['owner','admin'].includes(state.role)?`<button class="danger rb" data-id="${c.id}">Rollback</button>`:''}</div>`}).join('')+'</div>';
  document.querySelectorAll('.rb').forEach(b=>b.onclick=async()=>{if(!confirm('Rollback this configuration change?'))return;await api('rollback',{device_id:state.active,change_id:Number(b.dataset.id)});alert('Rollback command queued')});return;
 }
 if(name==='alerts'){
  const j=await api('alerts',{device_id:state.active});
  document.querySelector('#alerts').innerHTML=`<div class="card"><b>Alert rules</b>${j.alerts.map(a=>`<div class="row card"><span class="grow">${esc(a.metric)} ${esc(a.operator)} ${esc(a.threshold)} · ${Number(a.enabled)?'enabled':'disabled'}</span><button class="at" data-id="${a.id}" data-on="${Number(a.enabled)?0:1}">${Number(a.enabled)?'Disable':'Enable'}</button></div>`).join('')}<button id="newAlert">+ Alert rule</button></div>`;
  document.querySelectorAll('.at').forEach(b=>b.onclick=async()=>{await api('alert_toggle',{device_id:state.active,id:Number(b.dataset.id),enabled:Number(b.dataset.on)});loadTab('alerts')});
  const na=document.querySelector('#newAlert');if(na)na.onclick=async()=>{const metric=prompt('Metric: cpu_percent, memory_percent, load1, passwall_down, wan_down','cpu_percent');if(!metric)return;const threshold=Number(prompt('Threshold','90'));await api('alert_create',{device_id:state.active,metric,operator:'>',threshold,duration_seconds:300,cooldown_seconds:900});loadTab('alerts')};return;
 }
 if(name==='maintenance'){
  const j=await api('device_detail',{device_id:state.active});document.querySelector('#maintenance').innerHTML=`<div class="card"><b>Maintenance mode</b><p class="muted">${j.maintenance?'Active until '+esc(j.maintenance.until_at):'Inactive'}</p><div class="row"><button data-m="60">1 hour</button><button data-m="120">2 hours</button><button data-m="480">8 hours</button><button data-m="0">Disable</button></div></div>`;document.querySelectorAll('#maintenance [data-m]').forEach(b=>b.onclick=async()=>{await api('maintenance_set',{device_id:state.active,minutes:Number(b.dataset.m),reason:'Dashboard maintenance'});loadTab('maintenance')});return;
 }
 if(name==='schedules'){
  const j=await api('schedules',{device_id:state.active});document.querySelector('#schedules').innerHTML='<div class="card"><b>Scheduled actions</b>'+j.schedules.map(s=>`<div class="card"><div class="row"><b class="grow">${esc(s.name)}</b><span class="pill">${esc(s.schedule_type)}</span></div><div class="muted">${esc(s.action)} · next: ${esc(s.next_run_at)} · ${Number(s.enabled)?'enabled':'disabled'}</div><button class="st" data-id="${s.id}" data-on="${Number(s.enabled)?0:1}">${Number(s.enabled)?'Disable':'Enable'}</button></div>`).join('')+'<button id="newSchedule">+ Schedule</button></div>';
  document.querySelectorAll('.st').forEach(b=>b.onclick=async()=>{await api('schedule_toggle',{device_id:state.active,id:Number(b.dataset.id),enabled:Number(b.dataset.on)});loadTab('schedules')});const ns=document.querySelector('#newSchedule');if(ns)ns.onclick=async()=>{const action=prompt('Action','passwall2.restart');if(!action)return;const mins=Number(prompt('Run every N minutes','360'));await api('schedule_create',{device_id:state.active,name:action,command_action:action,params:action==='passwall2.subscription.update'?{id:'all'}:{},schedule_type:'interval',schedule_spec:{every_minutes:mins}});loadTab('schedules')};return;
 }
 if(name==='access'){
  if(!['owner','admin'].includes(state.role)){document.querySelector('#access').innerHTML='<div class="card">RBAC management requires Admin or Owner.</div>';return}
  const j=await api('users');document.querySelector('#access').innerHTML='<div class="card"><b>RBAC users</b>'+j.users.map(u=>`<div class="card"><b>${esc(u.telegram_user_id)}</b> · ${esc(u.role)} · ${Number(u.enabled)?'enabled':'disabled'}</div>`).join('')+'<button id="newUser">+ User</button></div>';document.querySelector('#newUser').onclick=async()=>{const id=Number(prompt('Telegram numeric user ID'));if(!id)return;const role=prompt('Role: admin, operator, viewer','viewer');await api('user_upsert',{telegram_user_id:id,role,enabled:true,device_scope:['*']});loadTab('access')};return;
 }
}
function draw(rows){const c=document.querySelector('#chart');if(!c||!rows.length)return;const x=c.getContext('2d'),w=c.width,h=c.height;x.clearRect(0,0,w,h);x.strokeStyle='#283747';for(let i=0;i<5;i++){const y=10+i*(h-20)/4;x.beginPath();x.moveTo(0,y);x.lineTo(w,y);x.stroke()}function line(key,color,max=100){x.strokeStyle=color;x.lineWidth=2;x.beginPath();rows.forEach((r,i)=>{const px=i*(w/(Math.max(1,rows.length-1))),py=h-10-Math.min(max,Number(r[key]||0))/max*(h-20);i?x.lineTo(px,py):x.moveTo(px,py)});x.stroke()}line('cpu_percent','#58a6ff');line('memory_used_percent','#3fb950')}
refresh().then(()=>tab('devices')).catch(err);
</script>
</body>
</html>
