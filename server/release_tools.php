<?php
declare(strict_types=1);

const PW_RELEASE_PUBLIC_KEY_B64 = 'fDiTaZG26qn3BCI3c8cFiO0KdqMW561w6JSaMwXCecw=';

function pw_release_download_bytes(string $url,int $maxBytes=20971520): string {
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension is required');
    if($maxBytes<1||$maxBytes>26214400)throw new RuntimeException('Invalid release download size limit');
    $parts=parse_url($url);$host=strtolower((string)($parts['host']??''));
    if(($parts['scheme']??'')!=='https'||$host!=='github.com'||isset($parts['user'])||isset($parts['pass']))throw new RuntimeException('Refusing non-official release URL');
    $body='';$tooLarge=false;
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_CONNECTTIMEOUT=>15,
        CURLOPT_TIMEOUT=>180,
        CURLOPT_USERAGENT=>'pw-agent-release-sync/0.7',
        CURLOPT_MAXREDIRS=>5,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,&$tooLarge,$maxBytes): int {
            $length=strlen($chunk);
            if(strlen($body)+$length>$maxBytes){$tooLarge=true;return 0;}
            $body.=$chunk;return $length;
        },
    ]);
    $ok=curl_exec($ch);
    if($ok===false){$e=curl_error($ch);curl_close($ch);if($tooLarge)throw new RuntimeException('Release asset exceeds size limit');throw new RuntimeException($e);}
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    if($code<200||$code>=300)throw new RuntimeException('HTTP '.$code.' for '.$url);
    $ep=parse_url($effective);$eh=strtolower((string)($ep['host']??''));
    if(($ep['scheme']??'')!=='https'||!($eh==='github.com'||str_ends_with($eh,'.githubusercontent.com')))throw new RuntimeException('Release download redirected outside trusted GitHub hosts');
    return $body;
}

function pw_release_verify_signature(string $payload,string $signature): void {
    if(!function_exists('sodium_crypto_sign_verify_detached'))throw new RuntimeException('PHP sodium extension is required for release verification');
    $public=base64_decode(PW_RELEASE_PUBLIC_KEY_B64,true);
    if($public===false||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)throw new RuntimeException('Invalid embedded release public key');
    if(strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES||!sodium_crypto_sign_verify_detached($signature,$payload,$public))throw new RuntimeException('Ed25519 verification failed for release asset');
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

    $bundle=pw_release_download_bytes($base.'/'.$asset,20971520);
    $shaText=trim(pw_release_download_bytes($base.'/'.$asset.'.sha256',4096));
    $expected=strtolower((string)preg_split('/\s+/', $shaText)[0]);
    $actual=hash('sha256',$bundle);
    if(!preg_match('/^[a-f0-9]{64}$/',$expected)||!hash_equals($expected,$actual)){
        throw new RuntimeException('SHA256 verification failed for '.$asset);
    }

    $sig=pw_release_download_bytes($base.'/'.$asset.'.sig',1024);
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
