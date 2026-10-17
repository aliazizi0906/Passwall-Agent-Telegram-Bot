<?php
declare(strict_types=1);

function pw_release_download_bytes(string $url): string {
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension is required');
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_CONNECTTIMEOUT=>15,
        CURLOPT_TIMEOUT=>180,
        CURLOPT_USERAGENT=>'pw-agent-release-sync/0.6',
        CURLOPT_MAXREDIRS=>5,
    ]);
    $raw=curl_exec($ch);
    if($raw===false){$e=curl_error($ch);curl_close($ch);throw new RuntimeException($e);}
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($code<200||$code>=300)throw new RuntimeException('HTTP '.$code.' for '.$url);
    return (string)$raw;
}

function pw_release_sync_arch(string $root,string $version,string $arch): array {
    if(function_exists('pw_setup_normalize_arch'))$arch=pw_setup_normalize_arch($arch);
    elseif(function_exists('v050_normalize_arch'))$arch=v050_normalize_arch($arch);
    else{
        $arch=strtolower(trim($arch));
        if(!in_array($arch,['armv7','arm64','amd64','386','mips','mipsle'],true))throw new RuntimeException('Unsupported architecture');
    }

    if(!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9._-]+)?$/',$version))throw new RuntimeException('Invalid release version');
    $base='https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/download/v'.$version;
    $asset='pw-agent-openwrt-'.$arch.'.tar.gz';

    $bundle=pw_release_download_bytes($base.'/'.$asset);
    $shaText=trim(pw_release_download_bytes($base.'/'.$asset.'.sha256'));
    $expected=strtolower((string)preg_split('/\s+/', $shaText)[0]);
    $actual=hash('sha256',$bundle);
    if(!preg_match('/^[a-f0-9]{64}$/',$expected)||!hash_equals($expected,$actual)){
        throw new RuntimeException('SHA256 verification failed for '.$asset);
    }

    $sig=pw_release_download_bytes($base.'/'.$asset.'.sig');
    if(strlen($sig)!==64)throw new RuntimeException('Invalid Ed25519 signature length for '.$asset);

    $dir=rtrim($root,'/').'/releases';
    if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Could not create releases directory');
    $target=$dir.'/pw-agent-openwrt-'.$version.'-'.$arch.'.tar.gz';
    $tmp=$target.'.tmp.'.bin2hex(random_bytes(3));
    if(file_put_contents($tmp,$bundle,LOCK_EX)===false)throw new RuntimeException('Could not write temporary release bundle');
    if(!@rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('Could not install release bundle');}
    if(file_put_contents($target.'.sig',$sig,LOCK_EX)===false)throw new RuntimeException('Could not write release signature');
    if(file_put_contents($target.'.sha256',$actual."  ".basename($target)."\n",LOCK_EX)===false)throw new RuntimeException('Could not write release checksum');

    @chmod($target,0640);@chmod($target.'.sig',0640);@chmod($target.'.sha256',0640);
    return ['arch'=>$arch,'asset'=>$asset,'path'=>$target,'sha256'=>$actual,'size'=>strlen($bundle)];
}
