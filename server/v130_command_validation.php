<?php
declare(strict_types=1);

// Pure input validation: safe to use in PHPUnit/CLI without loading runtime secrets.
function dash_v130_safe_params(string $action,array $params,bool $confirmed): array {
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
