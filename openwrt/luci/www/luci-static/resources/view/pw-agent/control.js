'use strict';
'require view';
'require fs';
'require ui';

var COLOR = { bg:'#f6f8fc', paper:'#fff', line:'#dce4ef', navy:'#152d4b', blue:'#2467d1', muted:'#61738f', green:'#09866a', red:'#af4152' };
var OPERATIONS = {
    home: ['status-json', 'System'],
    clients: ['clients-json', 'Clients'],
    wifi: ['wifi-json', 'Wi-Fi'],
    doctor: ['network-doctor-json', 'Network doctor'],
    capability: ['capabilities-json', 'Hardware']
};

function card(label, children) {
    return E('div', { 'style':'border:1px solid '+COLOR.line+';background:'+COLOR.paper+';border-radius:14px;padding:19px;margin-bottom:14px' }, [
        E('h3',{'style':'margin:0 0 14px;color:'+COLOR.navy},[label])
    ].concat(children || []));
}
function kv(label, value) {
    return E('div',{'style':'display:flex;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid '+COLOR.line+';flex-wrap:wrap'}, [
        E('span',{'style':'color:'+COLOR.muted},[label]),
        E('strong',{'style':'direction:ltr;text-align:left;overflow-wrap:anywhere;color:'+COLOR.navy}, [String(value === undefined || value === null || value === '' ? '—' : value)])
    ]);
}
function label(value) {
    if (value === true) return 'Available';
    if (value === false) return 'Not available';
    if (Array.isArray(value)) return value.length ? value.join(', ') : 'No radios';
    return String(value == null ? '—' : value);
}
function parse(res) {
    try {
        var obj = JSON.parse((res && res.stdout) || '{}');
        if (res.code !== 0) return { ok:false, error:res.stderr || 'Command exited '+res.code };
        return obj;
    } catch (e) {
        return { ok:false, error:'Invalid JSON response from local agent' };
    }
}
function renderSystem(data) {
    var m = data.memory || {};
    var s = data.storage || {};
    return [
        card('Router status', [
            kv('Hostname', data.hostname),
            kv('Uptime (seconds)', data.uptime_seconds),
            kv('CPU load', data.load1),
            kv('CPU usage (%)', data.cpu_percent),
            kv('Passwall2 running', label(data.passwall_running))
        ]),
        card('Memory and storage', [
            kv('Memory total', m.total || m.MemTotal),
            kv('Memory free', m.available || m.MemAvailable),
            kv('Storage total', s.total),
            kv('Storage free', s.free)
        ])
    ];
}
function renderClients(data) {
    var items = Array.isArray(data.clients) ? data.clients : [];
    var contents = [E('p',{'style':'color:'+COLOR.muted},['DHCP leases and neighbor entries; a discovered device is not necessarily online.'])];
    if (!items.length) contents.push(E('p',{},['No clients discovered.']));
    items.slice(0,100).forEach(function(c) {
        contents.push(kv(c.hostname || 'Unknown device', (c.ip || '—')+' · '+(c.mac || '—')+' · '+(c.interface || '—')));
    });
    return [card('Network clients · '+items.length, contents)];
}
function renderWiFi(data) {
    var items = Array.isArray(data.wifi) ? data.wifi : [];
    return [card('Configured wireless networks', items.length ? items.map(function(w) {
        return kv(w.ssid || 'Unnamed network', (w.disabled?'Disabled':'Enabled')+' · '+(w.encryption||'—')+' · '+(w.network||'—'));
    }) : [E('p', {}, ['No wireless interfaces configured.'])])];
}
function renderDoctor(data) {
    var items = Array.isArray(data.checks) ? data.checks : [];
    var body = [
        kv('Overall', data.status || 'Unknown'),
        kv('First failing stage', data.first_failed || 'None'),
        E('p', {'style':'color:'+COLOR.muted},['A check covers one point in time, not persistent reachability or leak guarantees.'])
    ];
    items.forEach(function(x) {
        body.push(kv(x.id, (x.status || 'unknown')+' · '+(x.latency_ms||0)+' ms · '+(x.detail||'')));
    });
    return [card('Connection diagnostics', body)];
}
function renderCapability(data) {
    var caps = data.capabilities || {}, hw = data.hardware || {};
    var body = [kv('OpenWrt', data.openwrt_version), kv('Hardware model', hw.model), kv('Board', hw.board)];
    Object.keys(caps).sort().forEach(function(k){ body.push(kv(k,label(caps[k]))); });
    body.push(E('p',{'style':'color:'+COLOR.muted},['Cell lock, SMS and RGB controls stay unavailable without separately verified hardware providers.']));
    return [card('Discovered capabilities', body)];
}
return view.extend({
    current: 'home',
    load: function() {
        // Initial load is local, read-only and completely independent of Telegram.
        return fs.exec('/usr/bin/pw-agent', [OPERATIONS.home[0]]).catch(function(e){return {code:1,stderr:String(e)};});
    },
    content: function(name, data) {
        if (data.ok === false && data.error) return [card('Operation unavailable',[E('p',{},[String(data.error).slice(0,350)])])];
        if (name === 'home') return renderSystem(data);
        if (name === 'clients') return renderClients(data);
        if (name === 'wifi') return renderWiFi(data);
        if (name === 'doctor') return renderDoctor(data);
        return renderCapability(data);
    },
    refresh: function(name, btn) {
        var command = OPERATIONS[name];
        if (!command) return Promise.resolve();
        if (btn) btn.disabled = true;
        var out = document.getElementById('pw-control-local-results');
        if (out) out.replaceChildren(E('p',{},['Reading local router state…']));
        return fs.exec('/usr/bin/pw-agent', [command[0]]).then(L.bind(function(res){
            if(out)out.replaceChildren.apply(out,this.content(name,parse(res)));
        }, this)).catch(function(err){
            if(out)out.replaceChildren(card('Error',[E('p',{},[String(err).slice(0,350)])]));
        }).finally(function(){if(btn)btn.disabled=false;});
    },
    render: function(res) {
        var nav = Object.keys(OPERATIONS).map(L.bind(function(name){
            return E('button',{
                'class':'btn',
                'style':'margin:0 8px 8px 0;background:'+(name===this.current?COLOR.blue:COLOR.paper)+';color:'+(name===this.current?'#fff':COLOR.navy)+';border:1px solid '+COLOR.line,
                'click':L.bind(function(ev){
                    this.current=name;
                    var tabs=ev.currentTarget.parentNode.querySelectorAll('button');
                    Array.prototype.forEach.call(tabs,function(tab){var active=(tab===ev.currentTarget);tab.style.background=active?COLOR.blue:COLOR.paper;tab.style.color=active?'#fff':COLOR.navy;});
                    this.refresh(name,ev.currentTarget);
                },this)
            },[OPERATIONS[name][1]]);
        },this));
        return E('div',{'style':'max-width:1110px;margin:auto;padding:14px;direction:ltr'},[
            E('h2', {'style':'color:'+COLOR.navy},['PW Control Center · Local']),
            E('p', {'style':'color:'+COLOR.muted},['This read-only panel runs on OpenWrt via LuCI. It remains available without the Telegram/PHP backend. Changes to LAN, WAN, Wi-Fi, firewall, proxy and modem policies must be performed via authenticated, recoverable control flows.']),
            E('div',{'style':'margin:14px 0'},nav),
            E('div',{'id':'pw-control-local-results'},this.content('home',parse(res))),
            E('p',{'style':'font-size:12px;color:'+COLOR.muted},['PW Agent development preview · Read-only safety boundary'])
        ]);
    },
    handleSaveApply:null,
    handleSave:null,
    handleReset:null
});