'use strict';
'require view';
'require fs';
'require ui';

function esc(v) { return String(v == null ? '' : v); }

return view.extend({
    load: function() {
        return fs.exec('/usr/bin/pw-agent', ['diagnose-json']).catch(function(e) {
            return { code: 1, stdout: '', stderr: String(e) };
        });
    },

    parseResult: function(res) {
        try {
            return JSON.parse((res && res.stdout) || '{}');
        } catch (e) {
            return { ok: false, error: 'Invalid diagnostic output: ' + ((res && res.stderr) || e) };
        }
    },

    renderDiag: function(data) {
        var ok = !!data.ok;
        var srv = data.server || {};
        return E('div', { 'class': 'cbi-section' }, [
            E('h3', {}, [ ok ? '🟢 Server connection healthy' : '🔴 Server connection failed' ]),
            E('div', { 'class': 'table' }, [
                E('div', { 'class': 'tr' }, [E('div', {'class':'td left','width':'30%'}, ['Agent version']), E('div', {'class':'td left'}, [esc(data.agent_version)])]),
                E('div', { 'class': 'tr' }, [E('div', {'class':'td left'}, ['Device ID']), E('div', {'class':'td left'}, [esc(data.device_id)])]),
                E('div', { 'class': 'tr' }, [E('div', {'class':'td left'}, ['Server URL']), E('div', {'class':'td left'}, [esc(data.server_url)])]),
                E('div', { 'class': 'tr' }, [E('div', {'class':'td left'}, ['HMAC authentication']), E('div', {'class':'td left'}, [data.auth_ok ? 'OK' : 'Failed'])]),
                E('div', { 'class': 'tr' }, [E('div', {'class':'td left'}, ['Response signature']), E('div', {'class':'td left'}, [data.response_signature_ok ? 'OK' : 'Failed'])]),
                E('div', { 'class': 'tr' }, [E('div', {'class':'td left'}, ['Round-trip']), E('div', {'class':'td left'}, [Number(data.latency_ms || 0).toFixed(1) + ' ms'])]),
                E('div', { 'class': 'tr' }, [E('div', {'class':'td left'}, ['Server version']), E('div', {'class':'td left'}, [esc(srv.version || '-')])]),
                E('div', { 'class': 'tr' }, [E('div', {'class':'td left'}, ['Error']), E('div', {'class':'td left'}, [esc(data.error || '-')])])
            ])
        ]);
    },

    handleRetest: function(ev) {
        var btn = ev.currentTarget;
        btn.disabled = true;
        return fs.exec('/usr/bin/pw-agent', ['diagnose-json']).then(L.bind(function(res) {
            var data = this.parseResult(res);
            var old = document.getElementById('pw-agent-diagnostic');
            var fresh = E('div', {'id':'pw-agent-diagnostic'}, [this.renderDiag(data)]);
            if (old) old.parentNode.replaceChild(fresh, old);
            ui.addNotification(null, E('p', {}, [data.ok ? 'Connection test passed.' : 'Connection test failed.']));
        }, this)).finally(function(){ btn.disabled = false; });
    },

    render: function(res) {
        var data = this.parseResult(res);
        return E('div', {}, [
            E('h2', {}, ['PW Agent']),
            E('div', {'class':'cbi-map-descr'}, [
                'Telegram/OpenWrt agent health page. This test validates the configured server URL, Device ID, shared key (HMAC), TLS reachability, and signed server response without leasing a Telegram command.'
            ]),
            E('div', {'id':'pw-agent-diagnostic'}, [this.renderDiag(data)]),
            E('div', {'class':'cbi-page-actions'}, [
                E('button', {'class':'btn cbi-button cbi-button-action', 'click': ui.createHandlerFn(this, 'handleRetest')}, ['Run Connection Test'])
            ])
        ]);
    },

    handleSaveApply: null,
    handleSave: null,
    handleReset: null
});
