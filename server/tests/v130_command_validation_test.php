<?php
declare(strict_types=1);
require dirname(__DIR__).'/v130_command_validation.php';

function expectOkay(string $name, string $action,array $data,bool $confirmed,array $expected): void {
    $got=dash_v130_safe_params($action,$data,$confirmed);
    if($got!==$expected)throw new RuntimeException($name.': unexpected data');
    echo "PASS ".$name.PHP_EOL;
}
function expectFailure(string $name,string $action,array $data,bool $confirmed): void {
    try{dash_v130_safe_params($action,$data,$confirmed);}
    catch(RuntimeException $e){echo "PASS ".$name.PHP_EOL;return;}
    throw new RuntimeException($name.': expected validation error');
}

expectOkay('read-only untouched','passwall2.nodes.list',[],false,[]);
expectOkay('safe node id','passwall2.node.test',['id'=>'node-01'],false,['id'=>'node-01']);
expectOkay('safe select','passwall2.node.select',['id'=>'node_02'],true,['id'=>'node_02']);
expectFailure('select must confirm','passwall2.node.select',['id'=>'node-01'],false);
expectFailure('delete must confirm','passwall2.node.delete',['id'=>'node-01'],false);
expectFailure('no shell chars in ids','passwall2.node.test',['id'=>'x;cat /etc/shadow'],false);
expectFailure('no delete all','passwall2.subscription.delete',['id'=>'all'],true);
expectOkay('valid encrypted link','passwall2.node.add_link',['link'=>'vless://masked@example.org:443'],true,['link'=>'vless://masked@example.org:443']);
expectFailure('unsupported link','passwall2.node.add_link',['link'=>'file:///etc/shadow'],true);
expectFailure('link must confirm','passwall2.node.add_link',['link'=>'vless://x@y'],false);
expectOkay('https subscription','passwall2.subscription.add',['url'=>'https://example.com/sub','remark'=>'test'],true,['url'=>'https://example.com/sub','remark'=>'test']);
expectFailure('reject http','passwall2.subscription.add',['url'=>'http://example.com/sub','remark'=>'test'],true);
expectFailure('reject credentials in URL','passwall2.subscription.add',['url'=>'https://user:password@example.com/sub','remark'=>'test'],true);
expectFailure('missing remark','passwall2.subscription.add',['url'=>'https://example.com/sub','remark'=>''],true);
expectOkay('SSID read-only preview','wifi.plan_ssid',['section'=>'default_radio0','ssid'=>'شبکه'],false,['section'=>'default_radio0','ssid'=>'شبکه']);
expectFailure('SSID too long','wifi.plan_ssid',['section'=>'default_radio0','ssid'=>str_repeat('A',33)],false);
expectFailure('SSID newline','wifi.plan_ssid',['section'=>'default_radio0','ssid'=>"evil\nname"],false);
expectFailure('SSID injection section','wifi.plan_ssid',['section'=>'radio0;reboot','ssid'=>'Valid'],false);
expectFailure('SSID empty','wifi.plan_ssid',['section'=>'default_radio0','ssid'=>''],false);
echo "All v1.3 parameter validation checks passed.".PHP_EOL;


$validLed=dash_v130_safe_params('led.identify',['name'=>'blue:status'],true);
if($validLed!==['name'=>'blue:status'])throw new RuntimeException('Valid sysfs LED name rejected');
foreach(['../bad','bad/name','..','name;reboot',''] as $name){
    try{dash_v130_safe_params('led.identify',['name'=>$name],true);throw new RuntimeException('Unsafe LED name accepted');}
    catch(RuntimeException $e){if($e->getMessage()==='Unsafe LED name accepted')throw $e;}
}
try{dash_v130_safe_params('led.identify',['name'=>'blue:status'],false);throw new RuntimeException('LED identify without confirmation accepted');}
catch(RuntimeException $e){if($e->getMessage()==='LED identify without confirmation accepted')throw $e;}


expectOkay('Manual LED brightness','led.brightness.set',['name'=>'blue:status','brightness'=>20],true,['name'=>'blue:status','brightness'=>20]);
expectFailure('LED brightness requires confirmation','led.brightness.set',['name'=>'blue:status','brightness'=>20],false);
expectFailure('LED brightness value too large','led.brightness.set',['name'=>'blue:status','brightness'=>99999],true);
expectFailure('LED brightness shell injection','led.brightness.set',['name'=>'blue;rm','brightness'=>1],true);
expectOkay('OpenVPN service start','openvpn.service.control',['mode'=>'start'],true,['mode'=>'start']);
expectFailure('OpenVPN service needs confirmation','openvpn.service.control',['mode'=>'stop'],false);
expectFailure('OpenVPN service shell injection','openvpn.service.control',['mode'=>'restart;reboot'],true);
expectOkay('OpenVPN safe route dry-run','openvpn.route.plan',['profile'=>'office','mode'=>'through_vpn','cidr'=>'192.0.2.0/24'],false,['profile'=>'office','mode'=>'through_vpn','cidr'=>'192.0.2.0/24']);
expectFailure('OpenVPN invalid CIDR','openvpn.route.plan',['profile'=>'office','mode'=>'through_vpn','cidr'=>'invalid'],false);
expectFailure('OpenVPN injection profile','openvpn.route.plan',['profile'=>'office;touch','mode'=>'bypass_vpn','cidr'=>'192.0.2.0/24'],false);


expectOkay('Wi-Fi safe apply confirmed','wifi.ssid.safe_apply',['section'=>'default_radio0','ssid'=>'PW Secure'],true,['section'=>'default_radio0','ssid'=>'PW Secure']);
expectFailure('Wi-Fi safe apply requires confirmation','wifi.ssid.safe_apply',['section'=>'default_radio0','ssid'=>'PW Secure'],false);
expectFailure('Wi-Fi safe apply refuses injection','wifi.ssid.safe_apply',['section'=>'radio0;reboot','ssid'=>'PW Secure'],true);
expectFailure('Wi-Fi safe apply rejects long SSID','wifi.ssid.safe_apply',['section'=>'default_radio0','ssid'=>str_repeat('Z',33)],true);
expectOkay('Wi-Fi rollback confirmation','wifi.ssid.safe_confirm',['transaction_id'=>str_repeat('a',32)],true,['transaction_id'=>str_repeat('a',32)]);
expectFailure('Wi-Fi rollback ID validation','wifi.ssid.safe_confirm',['transaction_id'=>'../file'],true);
expectFailure('Wi-Fi rollback must confirm','wifi.ssid.safe_confirm',['transaction_id'=>str_repeat('a',32)],false);


expectOkay('Allowlisted DNS restart','system.service.restart',['name'=>'dnsmasq'],true,['name'=>'dnsmasq']);
expectFailure('DNS restart without confirmation','system.service.restart',['name'=>'dnsmasq'],false);
expectFailure('Dangerous network restart denied','system.service.restart',['name'=>'network'],true);
expectFailure('Dangerous firewall restart denied','system.service.restart',['name'=>'firewall'],true);
expectFailure('Command injection service name denied','system.service.restart',['name'=>'dnsmasq;reboot'],true);


expectOkay('PBR add validated','pbr.policy.add',['name'=>'pw_office','cidr'=>'192.0.2.0/24','interface'=>'wan'],true,['name'=>'pw_office','cidr'=>'192.0.2.0/24','interface'=>'wan']);
expectFailure('PBR add requires confirm','pbr.policy.add',['name'=>'pw_office','cidr'=>'192.0.2.0/24','interface'=>'wan'],false);
expectFailure('PBR default route refused','pbr.policy.add',['name'=>'pw_office','cidr'=>'0.0.0.0/0','interface'=>'wan'],true);
expectFailure('PBR shell injection','pbr.policy.add',['name'=>'pw_x;reboot','cidr'=>'192.0.2.0/24','interface'=>'wan'],true);
expectOkay('PBR delete validated','pbr.policy.delete',['name'=>'pw_office'],true,['name'=>'pw_office']);
expectFailure('PBR arbitrary delete refused','pbr.policy.delete',['name'=>'default'],true);
expectOkay('OpenVPN profile enabled','openvpn.profile.toggle',['profile'=>'office','enabled'=>true],true,['profile'=>'office','enabled'=>true]);
expectFailure('OpenVPN profile injection','openvpn.profile.toggle',['profile'=>'office;reboot','enabled'=>true],true);
expectOkay('Client block validated','clients.internet.block',['mac'=>'02:11:22:33:44:55','blocked'=>true],true,['mac'=>'02:11:22:33:44:55','blocked'=>true]);
expectFailure('Client block requires confirmation','clients.internet.block',['mac'=>'02:11:22:33:44:55','blocked'=>true],false);
expectFailure('Client MAC invalid','clients.internet.block',['mac'=>'02:11:22:33:44:gg','blocked'=>true],true);
expectOkay('SQM rates validated','sqm.queue.set',['section'=>'wan','download_kbit'=>'20000','upload_kbit'=>'5000'],true,['section'=>'wan','download_kbit'=>'20000','upload_kbit'=>'5000']);
expectFailure('SQM injection denied','sqm.queue.set',['section'=>'wan;reboot','download_kbit'=>'20000','upload_kbit'=>'5000'],true);
expectOkay('Wi-Fi channel safe apply','wifi.channel.safe_apply',['radio'=>'radio0','channel'=>'auto'],true,['radio'=>'radio0','channel'=>'auto']);
expectFailure('Wi-Fi channel injection','wifi.channel.safe_apply',['radio'=>'radio0','channel'=>'1;reboot'],true);
expectOkay('WAN guard enabled','firewall.wan_guard.set',['enabled'=>true],true,['enabled'=>true]);
expectFailure('WAN guard must confirm','firewall.wan_guard.set',['enabled'=>true],false);
expectOkay('UCI safe confirm','config.safe.confirm',['transaction_id'=>str_repeat('a',32)],true,['transaction_id'=>str_repeat('a',32)]);
expectFailure('UCI safe confirm invalid ID','config.safe.confirm',['transaction_id'=>'../../bad'],true);


expectOkay('Native RGB control','led.rgb.set',['name'=>'rgb:status','red'=>255,'green'=>40,'blue'=>0],true,['name'=>'rgb:status','red'=>255,'green'=>40,'blue'=>0]);
expectFailure('Native RGB requires confirmation','led.rgb.set',['name'=>'rgb:status','red'=>255,'green'=>40,'blue'=>0],false);
expectFailure('Native RGB out of range','led.rgb.set',['name'=>'rgb:status','red'=>256,'green'=>40,'blue'=>0],true);
expectFailure('Native RGB rejects path traversal','led.rgb.set',['name'=>'../led','red'=>255,'green'=>40,'blue'=>0],true);


expectOkay('DNS guard action','firewall.dns53_guard.set',['enabled'=>true],true,['enabled'=>true]);
expectFailure('DNS guard requires confirmation','firewall.dns53_guard.set',['enabled'=>true],false);
expectFailure('DNS guard state must be boolean','firewall.dns53_guard.set',['enabled'=>'yes'],true);


expectOkay('LuCI hostname','luci.system.hostname.set',['hostname'=>'Gale-Router'],true,['hostname'=>'Gale-Router']);
expectFailure('LuCI hostname must confirm','luci.system.hostname.set',['hostname'=>'Gale-Router'],false);
expectFailure('LuCI hostname injection','luci.system.hostname.set',['hostname'=>'bad;rm'],true);
expectOkay('LuCI static lease','luci.dhcp.static.add',['mac'=>'02:11:22:33:44:55','ip'=>'192.168.1.50','name'=>'printer'],true,['mac'=>'02:11:22:33:44:55','ip'=>'192.168.1.50','name'=>'printer']);
expectFailure('LuCI invalid lease IP','luci.dhcp.static.add',['mac'=>'02:11:22:33:44:55','ip'=>'abc','name'=>'printer'],true);
expectFailure('LuCI DHCP lease needs confirmation','luci.dhcp.static.add',['mac'=>'02:11:22:33:44:55','ip'=>'192.168.1.50','name'=>'printer'],false);
expectOkay('LuCI lease deletion','luci.dhcp.static.delete',['mac'=>'02:11:22:33:44:55'],true,['mac'=>'02:11:22:33:44:55']);
expectFailure('LuCI multicast delete rejected','luci.dhcp.static.delete',['mac'=>'01:00:5e:00:00:fb'],true);
expectOkay('LuCI Wi-Fi interface toggle','luci.wifi.iface.toggle',['section'=>'default_radio0','enabled'=>false],true,['section'=>'default_radio0','enabled'=>false]);
expectFailure('LuCI Wi-Fi toggle requires confirmation','luci.wifi.iface.toggle',['section'=>'default_radio0','enabled'=>true],false);
expectFailure('LuCI Wi-Fi section injection','luci.wifi.iface.toggle',['section'=>'radio0;reboot','enabled'=>false],true);
