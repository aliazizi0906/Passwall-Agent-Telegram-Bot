<?php
declare(strict_types=1);

// Pure input validation: safe to use in PHPUnit/CLI without loading runtime secrets.
function dash_v130_safe_params(string $action,array $params,bool $confirmed): array {
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
    $mutations=['passwall2.node.select','passwall2.node.delete','passwall2.node.add_link',
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
