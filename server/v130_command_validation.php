<?php
declare(strict_types=1);

// Pure input validation: safe to use in PHPUnit/CLI without loading runtime secrets.
function dash_v130_safe_params(string $action,array $params,bool $confirmed): array {
    if($action==='wifi.ssid.safe_apply'){
        if(!$confirmed)throw new RuntimeException('Wi-Fi safe apply requires explicit confirmation');
        $section=(string)($params['section']??'');
        $ssid=(string)($params['ssid']??'');
        if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$section))throw new RuntimeException('Invalid Wi-Fi section');
        if($ssid===''||strlen($ssid)>32||trim($ssid)===''||!preg_match('//u',$ssid)||preg_match('/[\\x00-\\x1F\\x7F]/',$ssid))
            throw new RuntimeException('SSID must be valid UTF-8 and 1-32 bytes');
        return ['section'=>$section,'ssid'=>$ssid];
    }
    if($action==='wifi.ssid.safe_confirm'){
        if(!$confirmed)throw new RuntimeException('Wi-Fi confirmation requires explicit consent');
        $id=(string)($params['transaction_id']??'');
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Invalid Wi-Fi transaction ID');
        return ['transaction_id'=>$id];
    }
    if($action==='system.service.restart'){
        if(!$confirmed)throw new RuntimeException('Service restart requires confirmation');
        $service=(string)($params['name']??'');
        if(!in_array($service,['dnsmasq','odhcpd','sysntpd'],true))
            throw new RuntimeException('Service is not approved for remote restart');
        return ['name'=>$service];
    }
    if(in_array($action,['pbr.policy.add','pbr.policy.delete','openvpn.profile.toggle',
                          'clients.internet.block','sqm.queue.set','wifi.channel.safe_apply',
                          'firewall.wan_guard.set','config.safe.confirm'],true)){
        if(!$confirmed)throw new RuntimeException('Configuration mutation requires explicit confirmation');
        $idPattern='/^[A-Za-z][A-Za-z0-9_]{0,47}$/D';
        if($action==='config.safe.confirm'){
            $id=(string)($params['transaction_id']??'');
            if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Invalid transaction');
            return ['transaction_id'=>$id];
        }
        if($action==='pbr.policy.delete'){
            $name=(string)($params['name']??'');
            if(!preg_match($idPattern,$name)||!str_starts_with($name,'pw_'))
                throw new RuntimeException('Only PW-managed PBR policy names may be deleted');
            return ['name'=>$name];
        }
        if($action==='pbr.policy.add'){
            $name=(string)($params['name']??'');
            $interface=(string)($params['interface']??'');
            $cidr=(string)($params['cidr']??'');
            $parts=explode('/',$cidr);
            if(!preg_match($idPattern,$name)||!str_starts_with($name,'pw_')
              ||!preg_match($idPattern,$interface)||strlen($cidr)>64||count($parts)!==2
              ||filter_var($parts[0],FILTER_VALIDATE_IP)===false
              ||!ctype_digit($parts[1])||(int)$parts[1]<1||(int)$parts[1]>(filter_var($parts[0],FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)?128:32))
                throw new RuntimeException('Invalid PBR policy fields');
            return ['name'=>$name,'cidr'=>$cidr,'interface'=>$interface];
        }
        if($action==='openvpn.profile.toggle'){
            $profile=(string)($params['profile']??'');
            if(!preg_match($idPattern,$profile)||!is_bool($params['enabled']??null))
                throw new RuntimeException('Invalid OpenVPN profile toggle');
            return ['profile'=>$profile,'enabled'=>$params['enabled']];
        }
        if($action==='clients.internet.block'){
            $mac=(string)($params['mac']??'');
            if(!preg_match('/^(?:[a-fA-F0-9]{2}:){5}[a-fA-F0-9]{2}$/D',$mac)||!is_bool($params['blocked']??null))
                throw new RuntimeException('Invalid MAC or blocked flag');
            return ['mac'=>strtolower($mac),'blocked'=>$params['blocked']];
        }
        if($action==='sqm.queue.set'){
            $section=(string)($params['section']??'');
            $d=(string)($params['download_kbit']??'');
            $u=(string)($params['upload_kbit']??'');
            if(!preg_match($idPattern,$section)||!ctype_digit($d)||!ctype_digit($u)
              ||(int)$d<64||(int)$d>10000000||(int)$u<64||(int)$u>10000000)
                throw new RuntimeException('Invalid SQM queue/rates');
            return ['section'=>$section,'download_kbit'=>$d,'upload_kbit'=>$u];
        }
        if($action==='wifi.channel.safe_apply'){
            $radio=(string)($params['radio']??'');
            $channel=(string)($params['channel']??'');
            if(!preg_match($idPattern,$radio)||!($channel==='auto'||(ctype_digit($channel)&&(int)$channel>=1&&(int)$channel<=233)))
                throw new RuntimeException('Invalid radio/channel');
            return ['radio'=>$radio,'channel'=>$channel];
        }
        if($action==='firewall.wan_guard.set'){
            if(!is_bool($params['enabled']??null))throw new RuntimeException('Invalid WAN guard state');
            return ['enabled'=>$params['enabled']];
        }
    }
    if($action==='led.rgb.set'){
        if(!$confirmed)throw new RuntimeException('RGB change requires explicit confirmation');
        $name=(string)($params['name']??'');
        if(!preg_match('/^[A-Za-z0-9:_+.-]{1,96}$/D',$name)||$name==='.'||$name==='..')
            throw new RuntimeException('Invalid native RGB LED name');
        $result=['name'=>$name];
        foreach(['red','green','blue'] as $color){
            $value=$params[$color]??null;
            if(!is_int($value)||$value<0||$value>255)throw new RuntimeException('Invalid RGB intensity: '.$color);
            $result[$color]=$value;
        }
        return $result;
    }
    if($action==='led.brightness.set'){
        if(!$confirmed)throw new RuntimeException('LED brightness change requires confirmation');
        $name=(string)($params['name']??'');
        $level=$params['brightness']??null;
        if(!preg_match('/^[A-Za-z0-9:_+.-]{1,96}$/D',$name)||$name==='.'||$name==='..')
            throw new RuntimeException('Invalid LED name');
        if(!is_int($level)||$level<0||$level>65535)throw new RuntimeException('Invalid LED brightness');
        return ['name'=>$name,'brightness'=>$level];
    }
    if($action==='openvpn.service.control'){
        if(!$confirmed)throw new RuntimeException('OpenVPN service mutation requires confirmation');
        $mode=(string)($params['mode']??'');
        if(!in_array($mode,['start','stop','restart'],true))throw new RuntimeException('Unsupported OpenVPN service operation');
        return ['mode'=>$mode];
    }
    if($action==='openvpn.route.plan'){
        $profile=(string)($params['profile']??'');
        $mode=(string)($params['mode']??'');
        $cidr=(string)($params['cidr']??'');
        $cidrParts=explode('/',$cidr);
        if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$profile)||!in_array($mode,['through_vpn','bypass_vpn'],true)
           ||strlen($cidr)>64||count($cidrParts)!==2||filter_var($cidrParts[0],FILTER_VALIDATE_IP)===false
           ||filter_var($cidrParts[1],FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>128]])===false)
            throw new RuntimeException('Invalid OpenVPN route preview');
        return ['profile'=>$profile,'mode'=>$mode,'cidr'=>$cidr];
    }
    if($action==='led.identify'){
        if(!$confirmed)throw new RuntimeException('LED identification requires explicit confirmation');
        $name=(string)($params['name']??'');
        if(!preg_match('/^[A-Za-z0-9:_+.-]{1,96}$/D',$name)||$name==='.'||$name==='..')
            throw new RuntimeException('Invalid sysfs LED name');
        return ['name'=>$name];
    }
    if($action==='wifi.plan_ssid'){
        $section=(string)($params['section']??'');
        $ssid=(string)($params['ssid']??'');
        if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$section))throw new RuntimeException('Invalid named Wi-Fi UCI section');
        if($ssid===''||strlen($ssid)>32||trim($ssid)===''||!preg_match('//u',$ssid)||preg_match('/[\\x00-\\x1F\\x7F]/',$ssid))
            throw new RuntimeException('SSID must be valid UTF-8 and 1-32 bytes');
        return ['section'=>$section,'ssid'=>$ssid];
    }
    $nodeIdActions=['passwall2.node.select','passwall2.node.test','passwall2.node.delete'];
    $subscriptionIdActions=['passwall2.subscription.update','passwall2.subscription.delete'];
    $mutations=['passwall2.node.select','passwall2.node.delete','passwall2.node.add_link','passwall2.enable','passwall2.disable',
                'passwall2.subscription.add','passwall2.subscription.update','passwall2.subscription.delete'];
    if(in_array($action,$mutations,true)&&!$confirmed)throw new RuntimeException('Explicit confirmation is required');
    if(in_array($action,$nodeIdActions,true)||in_array($action,$subscriptionIdActions,true)){
        $id=(string)($params['id']??'');
        if(!preg_match('/^[A-Za-z0-9_-]{1,96}$/',$id))throw new RuntimeException('Invalid identifier');
        if($action==='passwall2.subscription.delete'&&$id==='all')throw new RuntimeException('Cannot delete all subscriptions');
        return ['id'=>$id];
    }
    if($action==='passwall2.node.add_link'){
        $link=trim((string)($params['link']??''));
        if($link===''||strlen($link)>12000)throw new RuntimeException('Invalid node links');
        if(!preg_match('~^(?:vless|vmess|trojan|ss|ssr|hysteria2|hy2|tuic|socks5|socks)://~i',$link))
            throw new RuntimeException('Unsupported node link scheme');
        return ['link'=>$link];
    }
    if($action==='passwall2.subscription.add'){
        $url=trim((string)($params['url']??''));
        $remark=trim((string)($params['remark']??''));
        $parsed=parse_url($url);
        if(!is_array($parsed)||!in_array(strtolower((string)($parsed['scheme']??'')),['https'],true)
           ||empty($parsed['host'])||isset($parsed['user'])||isset($parsed['pass'])||strlen($url)>4096)
            throw new RuntimeException('Subscription must be a valid HTTPS URL without embedded credentials');
        if($remark===''||strlen($remark)>80)throw new RuntimeException('Subscription name must be 1-80 bytes');
        return ['url'=>$url,'remark'=>$remark];
    }
    return $params;
}
