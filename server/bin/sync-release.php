<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only. Use the Fleet Dashboard Release Cache on cPanel without Terminal.\n");exit(1);}
require dirname(__DIR__).'/lib.php';
require dirname(__DIR__).'/release_tools.php';

function arg_value(string $name): ?string {
    global $argv;
    $prefix='--'.$name.'=';
    foreach($argv as $a)if(str_starts_with($a,$prefix))return substr($a,strlen($prefix));
    return null;
}

try{
    $root=dirname(__DIR__);
    $arg=arg_value('arch')??'';
    $arches=in_array('--all',$argv,true)?['armv7','arm64','amd64','386','mips','mipsle']:[$arg];
    if($arches===[''])throw new RuntimeException('Usage: php sync-release.php --arch=armv7  (or --all)');
    $failed=0;
    foreach($arches as $arch){
        try{
            $r=pw_release_sync_arch($root,app_version(),$arch);
            echo "Installed {$r['asset']}\nSHA256: {$r['sha256']}\n";
        }catch(Throwable $e){
            $failed++;
            fwrite(STDERR,$arch.': '.$e->getMessage()."\n");
        }
    }
    if($failed>0)exit(1);
}catch(Throwable $e){
    fwrite(STDERR,"ERROR: ".$e->getMessage()."\n");
    exit(1);
}
