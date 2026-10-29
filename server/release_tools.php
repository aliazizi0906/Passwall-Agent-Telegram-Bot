<?php
declare(strict_types=1);

require_once __DIR__.'/setup_core.php'; // Shared versioned CPU-family resolver for Setup and Dashboard.

// Pinned to UPDATE_PUBLIC_KEY.b64 in the source repository and to OpenWrt's bootstrap.
// A SHA256 checksum from the same release is not an authenticity proof.
const PW_RELEASE_ED25519_PUBLIC_KEY_B64 = 'fDiTaZG26qn3BCI3c8cFiO0KdqMW561w6JSaMwXCecw=';

function pw_release_verify_signature_with_key(string $bundle,string $signature,string $key): bool {
    if(!function_exists('sodium_crypto_sign_verify_detached'))return false;
    if(strlen($signature)!==64||strlen($key)!==32)return false;
    return sodium_crypto_sign_verify_detached($signature,$bundle,$key);
}

function pw_release_verify_signature(string $bundle,string $signature): void {
    if(!function_exists('sodium_crypto_sign_verify_detached')){
        throw new RuntimeException('sodium PHP extension is needed for signed release caching (enable ext-sodium in cPanel).');
    }
    $key=base64_decode(PW_RELEASE_ED25519_PUBLIC_KEY_B64,true);
    if(!is_string($key)||!pw_release_verify_signature_with_key($bundle,$signature,$key)){
        throw new RuntimeException('Ed25519 signature verification failed for downloaded Agent release');
    }
}

function pw_release_download_bytes(string $url): string {
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension is required');
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_CONNECTTIMEOUT=>15,
        CURLOPT_TIMEOUT=>180,
        CURLOPT_USERAGENT=>'pw-agent-release-sync/signed',
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
    pw_release_verify_signature($bundle,$sig);

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
