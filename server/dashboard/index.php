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
<button data-tab="groups">Groups</button>
<button data-tab="monitor">Monitoring</button>
<button data-tab="operations">Operations</button>
<button data-tab="audit">Audit & Rollback</button>
<button data-tab="alerts">Alerts</button>
<button data-tab="maintenance">Maintenance</button>
<button data-tab="schedules">Schedules</button>
<button data-tab="access">Access</button>
</nav>
<section id="devices"></section>
<section id="groups" class="hidden"></section>
<section id="monitor" class="hidden"></section>
<section id="operations" class="hidden"></section>
<section id="audit" class="hidden"></section>
<section id="alerts" class="hidden"></section>
<section id="maintenance" class="hidden"></section>
<section id="schedules" class="hidden"></section>
<section id="access" class="hidden"></section>
</main>
<script>
const tg=window.Telegram?.WebApp; tg?.ready(); tg?.expand();
const initData=tg?.initData||''; let state={summary:null,active:'',role:'viewer',permissions:[]};
const has=p=>state.permissions.includes('*')||state.permissions.includes(p);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function api(action,data={}){const r=await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({init_data:initData,action,...data})});const j=await r.json().catch(()=>({ok:false,error:'Invalid server response'}));if(!j.ok)throw new Error(j.error||'Request failed');return j}
function err(e){const x=document.querySelector('#error');x.textContent=e.message||e;x.classList.remove('hidden')}
function fmtTime(s){return s?new Date(s.replace(' ','T')+'Z').toLocaleString():'Never'}
function bytes(n){n=Number(n||0);if(n>=1073741824)return (n/1073741824).toFixed(2)+' GiB';if(n>=1048576)return (n/1048576).toFixed(1)+' MiB';if(n>=1024)return (n/1024).toFixed(1)+' KiB';return n+' B'}
function healthState(v){if(v===null||v===undefined||v==='')return ['UNKNOWN','warn'];return Number(v)?['UP','good']:['DOWN','bad']}
function num(v,d=1,suffix=''){return v===null||v===undefined||v===''?'—':Number(v).toFixed(d)+suffix}
function tab(name){document.querySelectorAll('main>section').forEach(x=>x.classList.add('hidden'));document.querySelector('#'+name).classList.remove('hidden');document.querySelectorAll('#tabs button').forEach(x=>x.classList.toggle('primary',x.dataset.tab===name));loadTab(name).catch(err)}
document.querySelectorAll('#tabs button').forEach(b=>b.onclick=()=>tab(b.dataset.tab));
async function refresh(){
 const j=await api('summary');state.summary=j.fleet;state.active=j.active_device_id;state.role=j.role;state.permissions=j.permissions||[];
 document.querySelector('#identity').textContent='Role: '+state.role+' · Active: '+(state.active||'none');
 document.querySelector('#stats').innerHTML=[
  ['Routers',j.fleet.total],['Online',j.fleet.online],['Offline',j.fleet.offline]
 ].map(x=>`<div class="card"><div class="muted">${x[0]}</div><div class="stat">${x[1]}</div></div>`).join('');
 await renderDevices();
}
async function renderDevices(){
 const rows=state.summary?.devices||[];
 document.querySelector('#devices').innerHTML=`<div class="card"><div class="row"><b>Fleet</b><span class="grow"></span>${has('device.manage')?'<button id="groupsBtn">Groups</button><button id="editDev">Edit active</button><button id="addDev">+ Add device</button>':''}</div></div>`+
 rows.map(d=>`<div class="card device ${d.device_id===state.active?'active':''}" data-id="${esc(d.device_id)}"><div class="row"><b>${d.online?'🟢':'🔴'} ${esc(d.display_name)}</b><span class="grow"></span>${d.group_name?'<span class="pill">'+esc(d.group_name)+'</span>':''}${d.maintenance?'<span class="pill warn">Maintenance</span>':''}</div><div class="muted">${esc(d.hostname||d.device_id)} · ${esc(d.location||'No location')} · Agent ${esc(d.agent_version||'-')} · ${fmtTime(d.last_seen)}</div><div>${(JSON.parse(d.tags_json||'[]')).map(t=>'<span class="pill">'+esc(t)+'</span>').join('')}</div></div>`).join('');
 document.querySelectorAll('.device').forEach(x=>x.onclick=async()=>{await api('select_device',{device_id:x.dataset.id});await refresh();});
 const add=document.querySelector('#addDev');if(add)add.onclick=async()=>{const name=prompt('Device name');if(!name)return;const j=await api('device_create',{name});prompt('Run this command on the new OpenWrt router within 30 minutes:',j.install_command);await refresh()};
 const groups=document.querySelector('#groupsBtn');if(groups)groups.onclick=()=>tab('groups');
 const edit=document.querySelector('#editDev');if(edit)edit.onclick=async()=>{if(!state.active)return;const d=(state.summary?.devices||[]).find(x=>x.device_id===state.active);if(!d)return;const groupsData=await api('groups');const groupList=(groupsData.groups||[]).map(x=>x.id+':'+x.name).join(', ');const display_name=prompt('Display name',d.display_name||state.active);if(display_name===null)return;const location=prompt('Location',d.location||'')??'';const tagsRaw=prompt('Tags (comma separated)',(JSON.parse(d.tags_json||'[]')).join(', '))??'';const gid=prompt('Group ID (blank = none). Available: '+groupList,String(d.group_id||''));await api('device_update',{device_id:state.active,display_name,location,tags:tagsRaw.split(',').map(x=>x.trim()).filter(Boolean),group_id:gid||null});await refresh()};
}
async function loadTab(name){
 if(name==='devices')return renderDevices();
 if(!state.active){document.querySelector('#'+name).innerHTML='<div class="card">No active device.</div>';return}
 if(name==='groups'){
  const j=await api('groups');
  const canManage=has('group.manage');
  document.querySelector('#groups').innerHTML=`<div class="card"><div class="row"><b>Device Groups</b><span class="grow"></span>${canManage?'<button id="newGroup">+ Group</button>':''}</div><p class="muted">Bulk mutations require explicit confirmation and still pass RBAC on every router.</p></div>`+
  (j.groups||[]).map(g=>`<div class="card"><div class="row"><b class="grow">${esc(g.name)}</b><span class="pill">${Number(g.accessible_device_count||0)} accessible</span></div><div class="muted">${esc(g.description||'')}</div><div class="row"><button class="gop" data-p="system.read" data-g="${g.id}" data-a="system.status">Status</button><button class="gop" data-p="system.read" data-g="${g.id}" data-a="agent.diagnose">Diagnostics</button><button class="gop" data-p="system.read" data-g="${g.id}" data-a="agent.update.check">Update check</button><button class="gop" data-p="passwall.read" data-g="${g.id}" data-a="passwall2.status">Passwall</button><button class="gop" data-p="passwall.modify" data-g="${g.id}" data-a="passwall2.restart" data-mut="1">Restart Passwall</button><button class="gop danger" data-p="agent.update" data-g="${g.id}" data-a="agent.update.apply" data-mut="1">Install Agent update</button></div><pre id="groupOut-${g.id}">Devices: ${esc((g.device_ids||[]).join(', ')||'none')}</pre></div>`).join('') || '<div class="card">No groups are visible in your scope.</div>';
  const ng=document.querySelector('#newGroup');if(ng)ng.onclick=async()=>{const name=prompt('Group name');if(!name)return;const description=prompt('Description','')||'';await api('group_create',{name,description});loadTab('groups')};
  document.querySelectorAll('.gop').forEach(b=>{if(!has(b.dataset.p)){b.remove();return}b.onclick=async()=>{const mut=Number(b.dataset.mut||0)===1;if(mut&&!confirm('Run '+b.dataset.a+' on every accessible router in this group?'))return;const out=document.querySelector('#groupOut-'+b.dataset.g);out.textContent='Queuing…';try{const x=await api('group_command',{group_id:Number(b.dataset.g),command_action:b.dataset.a,confirmed:mut});out.textContent=JSON.stringify(x,null,2)}catch(e){out.textContent=e.message}}});
  return;
 }
 if(name==='monitor'){
  const j=await api('device_detail',{device_id:state.active}),d=j.device,m=j.metrics||[];
  const last=m[m.length-1]||{},wan=healthState(last.wan_up),pw=healthState(last.passwall_running),dns=healthState(last.dns_ok),internet=healthState(last.internet_ok),relay=healthState(last.node_relay_ok);
  const o=j.overview||{},h=o['24h']||{},w=o['7d']||{},m30=o['30d']||{};
  document.querySelector('#monitor').innerHTML=\`<div class="grid">
    <div class="card"><div class="muted">CPU</div><div class="stat">\${num(last.cpu_percent,1,'%')}</div></div>
    <div class="card"><div class="muted">Memory</div><div class="stat">\${num(last.memory_used_percent,1,'%')}</div></div>
    <div class="card"><div class="muted">WAN</div><div class="stat \${wan[1]}">\${wan[0]}</div></div>
    <div class="card"><div class="muted">Passwall2</div><div class="stat \${pw[1]}">\${pw[0]}</div></div>
    <div class="card"><div class="muted">DNS</div><div class="stat \${dns[1]}">\${dns[0]}</div><div class="muted">\${num(last.dns_ms,1,' ms')}</div></div>
    <div class="card"><div class="muted">Internet TCP</div><div class="stat \${internet[1]}">\${internet[0]}</div><div class="muted">\${num(last.internet_tcp_ms,1,' ms')}</div></div>
    <div class="card"><div class="muted">Packet loss</div><div class="stat">\${num(last.packet_loss,1,'%')}</div></div>
    <div class="card"><div class="muted">Active Relay</div><div class="stat \${relay[1]}">\${num(last.node_relay_ms,0,' ms')}</div></div>
  </div>
  <div class="grid">
    <div class="card"><div class="muted">Heartbeat 24h</div><div class="stat">\${Number(h.heartbeat_coverage||0).toFixed(1)}%</div></div>
    <div class="card"><div class="muted">Heartbeat 7d</div><div class="stat">\${Number(w.heartbeat_coverage||0).toFixed(1)}%</div></div>
    <div class="card"><div class="muted">Heartbeat 30d</div><div class="stat">\${Number(m30.heartbeat_coverage||0).toFixed(1)}%</div></div>
    <div class="card"><div class="muted">Failover 24h</div><div class="stat">\${Number(h.failover_events||0)}</div></div>
  </div>
  <div class="card"><b>\${esc(d.display_name)}</b>
    <div class="muted">WAN IP: \${esc(last.wan_ip||'-')} · Active node: \${esc(last.active_node_name||last.active_node_id||'-')} · Last seen: \${fmtTime(d.last_seen)}</div>
    <div class="muted">24h RX: \${bytes(h.rx_bytes||0)} · TX: \${bytes(h.tx_bytes||0)} · WAN IP changes: \${Number(h.wan_ip_changes||0)} · Reconnects: \${Number(h.agent_reconnects||0)}</div>
    <div class="muted">24h avg packet loss: \${num(h.avg_packet_loss,1,'%')} · avg node relay: \${num(h.avg_node_relay_ms,0,' ms')} · DNS failures: \${Number(h.dns_down_samples||0)} · Internet failures: \${Number(h.internet_down_samples||0)}</div>
    <canvas id="chart" width="900" height="180"></canvas>
  </div>\`;
  draw(m);return;
 }
 if(name==='operations'){
  const ops=[
    ['system.status','System status','system.read'],
    ['agent.diagnose','Agent diagnostics','system.read'],
    ['passwall2.status','Passwall2 status','passwall.read'],
    ['passwall2.nodes.list','Nodes','passwall.read'],
    ['passwall2.nodes.relay_ranking','Relay ranking','passwall.read'],
    ['passwall2.failover.list','Failover','passwall.read'],
    ['passwall2.restart','Restart Passwall','passwall.modify'],
    ['system.backup.create','Send backup to Telegram','backup.create'],
    ['agent.update.check','Check update','system.read'],
    ['agent.update.apply','Install update','agent.update']
  ].filter(x=>has(x[2]));
  document.querySelector('#operations').innerHTML=\`<div class="card"><b>Router Operations</b><p class="muted">All actions use the same RBAC, queue idempotency and audit pipeline as Telegram.</p><div class="row">\${ops.map(x=>\`<button data-op="\${x[0]}">\${x[1]}</button>\`).join('')}\${has('terminal.execute')?'<button id="terminalBtn">SSH Console</button>':''}</div><pre id="opOut">Select an operation.</pre></div><div class="card"><div class="row"><b>Recent commands</b><span class="grow"></span><button id="refreshCmds">Refresh</button></div><div id="cmdList"></div></div>\`;
  async function commands(){const j=await api('commands',{device_id:state.active});document.querySelector('#cmdList').innerHTML='<table><tr><th>ID</th><th>Action</th><th>Status</th><th>Created</th></tr>'+j.commands.map(c=>\`<tr><td>\${c.id}</td><td>\${esc(c.action)}</td><td>\${esc(c.status)}</td><td>\${esc(c.created_at)}</td></tr>\`).join('')+'</table>'}
  async function run(action){let params={};if(action==='passwall2.failover.run'){const shunt=prompt('Shunt ID');if(!shunt)return;params={shunt_id:shunt}}const mutation=['passwall2.restart','agent.update.apply'].includes(action);if(mutation&&!confirm('Run '+action+' on '+state.active+'?'))return;const j=await api('command',{device_id:state.active,command_action:action,params});const out=document.querySelector('#opOut');out.textContent='Queued command #'+j.command_id+'…';for(let i=0;i<45;i++){await new Promise(r=>setTimeout(r,500));const q=await api('command_status',{device_id:state.active,command_id:j.command_id});if(['done','failed'].includes(q.command.status)){out.textContent=JSON.stringify(q.command,null,2);await commands();return}}out.textContent='Command is still running. Check Recent commands.'}
  document.querySelectorAll('#operations [data-op]').forEach(b=>b.onclick=()=>run(b.dataset.op).catch(err));
  const term=document.querySelector('#terminalBtn');if(term)term.onclick=()=>location.href='../terminal/';
  document.querySelector('#refreshCmds').onclick=()=>commands().catch(err);
  await commands();return;
 }
 if(name==='audit'){
  const j=await api('audit',{device_id:state.active});
  document.querySelector('#audit').innerHTML='<div class="card"><b>Recent audit</b><div style="overflow:auto"><table><tr><th>Time</th><th>User</th><th>Action</th><th>Status</th></tr>'+j.audit.map(a=>`<tr><td>${esc(a.created_at)}</td><td>${esc(a.telegram_user_id||a.source)}</td><td>${esc(a.action)}</td><td>${esc(a.status)}</td></tr>`).join('')+'</table></div></div><div class="card"><b>Configuration changes</b>'+j.changes.map(c=>{let diff=[];try{diff=JSON.parse(c.diff_json||'[]')}catch(e){}return `<div class="card"><b>#${c.id} ${esc(c.action)}</b><div class="muted">${esc(c.created_at)}</div><pre>${esc(diff.join('\n')||'No UCI option change detected')}</pre>${has('rollback')?`<button class="danger rb" data-id="${c.id}">Rollback</button>`:''}</div>`}).join('')+'</div>';
  document.querySelectorAll('.rb').forEach(b=>b.onclick=async()=>{if(!confirm('Rollback this configuration change?'))return;await api('rollback',{device_id:state.active,change_id:Number(b.dataset.id)});alert('Rollback command queued')});return;
 }
 if(name==='alerts'){
  const j=await api('alerts',{device_id:state.active}),canManage=has('alert.manage');
  const templates=[
    ['CPU > 90%','cpu_percent','>',90,300],
    ['RAM > 90%','memory_percent','>',90,300],
    ['Packet loss > 30%','packet_loss','>',30,120],
    ['Relay > 500ms','node_relay_ms','>',500,180],
    ['DNS down','dns_down','==',1,120],
    ['Internet down','internet_down','==',1,120],
    ['WAN IP changed','wan_ip_changed','==',1,0],
    ['Agent reconnected','agent_reconnected','==',1,0],
    ['WAN down','wan_down','==',1,180],
    ['Passwall down','passwall_down','==',1,180]
  ];
  document.querySelector('#alerts').innerHTML=\`<div class="card"><div class="row"><b>Alert rules</b><span class="grow"></span>\${canManage?'<button id="newAlert">+ Custom rule</button>':'<span class="pill">read-only</span>'}</div>\${j.alerts.map(a=>\`<div class="row card"><span class="grow">\${esc(a.metric)} \${esc(a.operator)} \${esc(a.threshold)} · \${Number(a.duration_seconds||0)}s · \${Number(a.enabled)?'enabled':'disabled'}</span>\${canManage?\`<button class="at" data-id="\${a.id}" data-on="\${Number(a.enabled)?0:1}">\${Number(a.enabled)?'Disable':'Enable'}</button>\`:''}</div>\`).join('')}\${canManage?'<div class="row" id="alertTemplates"></div>':''}</div>\`;
  if(canManage){
    document.querySelector('#alertTemplates').innerHTML=templates.map((x,i)=>\`<button data-tpl="\${i}">\${x[0]}</button>\`).join('');
    document.querySelectorAll('[data-tpl]').forEach(b=>b.onclick=async()=>{const x=templates[Number(b.dataset.tpl)];await api('alert_create',{device_id:state.active,metric:x[1],operator:x[2],threshold:x[3],duration_seconds:x[4],cooldown_seconds:900});loadTab('alerts')});
    document.querySelectorAll('.at').forEach(b=>b.onclick=async()=>{await api('alert_toggle',{device_id:state.active,id:Number(b.dataset.id),enabled:Number(b.dataset.on)});loadTab('alerts')});
    const na=document.querySelector('#newAlert');if(na)na.onclick=async()=>{const metric=prompt('Metric: cpu_percent, memory_percent, load1, packet_loss, node_relay_ms, passwall_down, wan_down, dns_down, internet_down, wan_ip_changed, agent_reconnected','cpu_percent');if(!metric)return;const operator=prompt('Operator: >, >=, <, <=, ==','>')||'>';const threshold=Number(prompt('Threshold','90'));const duration=Number(prompt('Required breach duration in seconds','300'));const cooldown=Number(prompt('Notification cooldown in seconds','900'));await api('alert_create',{device_id:state.active,metric,operator,threshold,duration_seconds:duration,cooldown_seconds:cooldown});loadTab('alerts')};
  }
  return;
 }
 if(name==='maintenance'){
  const j=await api('device_detail',{device_id:state.active}),canManage=has('maintenance.manage');
  document.querySelector('#maintenance').innerHTML=\`<div class="card"><div class="row"><b>Maintenance mode</b><span class="grow"></span>\${canManage?'':'<span class="pill">read-only</span>'}</div><p class="muted">\${j.maintenance?'Active until '+esc(j.maintenance.until_at)+(j.maintenance.reason?' · '+esc(j.maintenance.reason):''):'Inactive'}</p>\${canManage?'<div class="row"><button data-m="60">1 hour</button><button data-m="120">2 hours</button><button data-m="480">8 hours</button><button data-m="0">Disable</button></div>':''}</div>\`;
  if(canManage)document.querySelectorAll('#maintenance [data-m]').forEach(b=>b.onclick=async()=>{const minutes=Number(b.dataset.m),reason=minutes?prompt('Maintenance reason','Planned maintenance')||'Planned maintenance':'';await api('maintenance_set',{device_id:state.active,minutes,reason});loadTab('maintenance')});
  return;
 }
 if(name==='schedules'){
  const j=await api('schedules',{device_id:state.active}),canManage=has('schedule.manage');
  document.querySelector('#schedules').innerHTML='<div class="card"><div class="row"><b>Scheduled actions</b><span class="grow"></span>'+(!canManage?'<span class="pill">read-only</span>':'')+'</div>'+j.schedules.map(s=>\`<div class="card"><div class="row"><b class="grow">\${esc(s.name)}</b><span class="pill">\${esc(s.schedule_type)}</span></div><div class="muted">\${esc(s.action)} · next: \${esc(s.next_run_at)} · \${Number(s.enabled)?'enabled':'disabled'}</div>\${s.last_error?\`<div class="bad">Last error: \${esc(s.last_error)}</div>\`:''}\${canManage?\`<button class="st" data-id="\${s.id}" data-on="\${Number(s.enabled)?0:1}">\${Number(s.enabled)?'Disable':'Enable'}</button>\`:''}</div>\`).join('')+(canManage?'<button id="newSchedule">+ Schedule</button>':'')+'</div>';
  if(canManage){
    document.querySelectorAll('.st').forEach(b=>b.onclick=async()=>{await api('schedule_toggle',{device_id:state.active,id:Number(b.dataset.id),enabled:Number(b.dataset.on)});loadTab('schedules')});
    const ns=document.querySelector('#newSchedule');if(ns)ns.onclick=async()=>{const action=prompt('Action: passwall2.restart, passwall2.subscription.update, system.backup.create, system.reboot, agent.update.apply, passwall2.failover.run','passwall2.restart');if(!action)return;let params={};if(action==='passwall2.subscription.update')params={id:'all'};if(action==='passwall2.failover.run'){const shunt=prompt('Shunt ID');if(!shunt)return;params={shunt_id:shunt}}const kind=prompt('Schedule type: interval, daily, weekly','interval')||'interval';let spec={};if(kind==='interval')spec={every_minutes:Number(prompt('Every N minutes','360'))};else if(kind==='daily')spec={time:prompt('HH:MM','04:00')||'04:00'};else if(kind==='weekly')spec={weekday:Number(prompt('Weekday 0=Sun … 6=Sat','0')),time:prompt('HH:MM','04:00')||'04:00'};else return alert('Unsupported schedule type in this form');await api('schedule_create',{device_id:state.active,name:action,command_action:action,params,schedule_type:kind,schedule_spec:spec});loadTab('schedules')};
  }
  return;
 }
 if(name==='access'){
  if(!has('rbac.read')){document.querySelector('#access').innerHTML='<div class="card">RBAC visibility is not available for this role.</div>';return}
  const j=await api('users');
  const canEdit=has('rbac.manage');
  document.querySelector('#access').innerHTML='<div class="card"><div class="row"><b>RBAC users</b><span class="grow"></span>'+(!canEdit?'<span class="pill">read-only</span>':'')+'</div>'+j.users.map(u=>{let scope=[];try{scope=JSON.parse(u.device_scope_json||'[]')}catch(e){}return `<div class="card"><div class="row"><b class="grow">${esc(u.telegram_user_id)}</b><span class="pill">${esc(u.role)}</span><span class="pill">${Number(u.enabled)?'enabled':'disabled'}</span>${canEdit?`<button class="editUser" data-id="${u.telegram_user_id}" data-role="${esc(u.role)}" data-on="${Number(u.enabled)?1:0}" data-scope="${esc(scope.join(','))}">Edit</button>`:''}</div><div class="muted">Scope: ${esc(scope.join(', ')||'none')}</div></div>`}).join('')+(canEdit?'<button id="newUser">+ User</button>':'')+'</div>';
  if(canEdit){
    async function editUser(id,role='viewer',enabled=1,scope='*'){const r=prompt('Role: owner, admin, operator, viewer',role);if(!r)return;const sc=prompt('Device scope: *, device IDs, prefix-* or group:ID',scope);if(sc===null)return;const on=confirm('Enable this user? OK = enabled, Cancel = disabled');await api('user_upsert',{telegram_user_id:Number(id),role:r,enabled:on,device_scope:sc.split(',').map(x=>x.trim()).filter(Boolean)});loadTab('access')}
    document.querySelectorAll('.editUser').forEach(b=>b.onclick=()=>editUser(b.dataset.id,b.dataset.role,Number(b.dataset.on),b.dataset.scope));
    document.querySelector('#newUser').onclick=async()=>{const id=Number(prompt('Telegram numeric user ID'));if(!id)return;await editUser(id,'viewer',1,'*')};
  }
  return;
 }
}
function draw(rows){const c=document.querySelector('#chart');if(!c||!rows.length)return;const x=c.getContext('2d'),w=c.width,h=c.height;x.clearRect(0,0,w,h);x.strokeStyle='#283747';for(let i=0;i<5;i++){const y=10+i*(h-20)/4;x.beginPath();x.moveTo(0,y);x.lineTo(w,y);x.stroke()}function line(key,color,max=100){x.strokeStyle=color;x.lineWidth=2;x.beginPath();rows.forEach((r,i)=>{const px=i*(w/(Math.max(1,rows.length-1))),py=h-10-Math.min(max,Number(r[key]||0))/max*(h-20);i?x.lineTo(px,py):x.moveTo(px,py)});x.stroke()}line('cpu_percent','#58a6ff');line('memory_used_percent','#3fb950')}
refresh().then(()=>tab('devices')).catch(err);
</script>
</body>
</html>
