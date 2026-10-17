<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(1);}
require dirname(__DIR__) . '/lib.php';

function arg_value(string $name): ?string {
    global $argv;
    $prefix='--'.$name.'=';
    foreach($argv as $a) if(str_starts_with($a,$prefix)) return substr($a,strlen($prefix));
    return null;
}
function download_bytes(string $url): string {
    if(!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required');
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>180,
        CURLOPT_USERAGENT=>'pw-agent-installer/'.app_version(),
    ]);
    $raw=curl_exec($ch);
    if($raw===false){$e=curl_error($ch);curl_close($ch);throw new RuntimeException($e);}
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($code<200||$code>=300) throw new RuntimeException('HTTP '.$code.' for '.$url);
    return (string)$raw;
}
function sync_arch(string $arch): void {
    $arch=v050_normalize_arch($arch);$version=app_version();
    $base='https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/download/v'.$version;
    $asset='pw-agent-openwrt-'.$arch.'.tar.gz';
    echo "Downloading {$asset}...\n";
    $bundle=download_bytes($base.'/'.$asset);
    $shaText=trim(download_bytes($base.'/'.$asset.'.sha256'));
    $expected=strtolower((string)preg_split('/\s+/', $shaText)[0]);
    $actual=hash('sha256',$bundle);
    if(!preg_match('/^[a-f0-9]{64}$/',$expected)||!hash_equals($expected,$actual)) throw new RuntimeException('SHA256 verification failed for '.$asset);
    $sig=download_bytes($base.'/'.$asset.'.sig');
    if(strlen($sig)!==64) throw new RuntimeException('Invalid Ed25519 signature length for '.$asset);
    $dir=dirname(__DIR__).'/releases';
    if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir)) throw new RuntimeException('Could not create releases directory');
    $target=$dir.'/pw-agent-openwrt-'.$version.'-'.$arch.'.tar.gz';
    $tmp=$target.'.tmp';
    if(file_put_contents($tmp,$bundle,LOCK_EX)===false) throw new RuntimeException('Could not write '.$tmp);
    rename($tmp,$target);
    if(file_put_contents($target.'.sig',$sig,LOCK_EX)===false) throw new RuntimeException('Could not write signature');
    echo "Installed update bundle: {$target}\nSHA256: {$actual}\n";
}

try{
    $arg=arg_value('arch')??'';
    if(in_array('--all',$argv,true)){
        foreach(['armv7','arm64','amd64','386','mips','mipsle'] as $arch){
            try{sync_arch($arch);}catch(Throwable $e){fwrite(STDERR,"{$arch}: {$e->getMessage()}\n");}
        }
    }else{
        if($arg==='') throw new RuntimeException('Usage: php sync-release.php --arch=armv7  (or --all)');
        sync_arch($arg);
    }
}catch(Throwable $e){fwrite(STDERR,"ERROR: ".$e->getMessage()."\n");exit(1);}
