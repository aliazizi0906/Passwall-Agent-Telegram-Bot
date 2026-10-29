<?php
declare(strict_types=1);
require dirname(__DIR__).'/install/bundle_core.php';
function expect_asset(bool $ok,string $reason): void {if(!$ok)throw new RuntimeException($reason);}
$version=pw_setup_version();
$root='/private/pw-agent';
$path=pw_install_cached_asset_path($root,$version,'armv7l','bundle');
expect_asset($path===$root.'/releases/pw-agent-openwrt-'.$version.'-armv7.tar.gz','armv7 bundle path');
expect_asset(pw_install_cached_asset_path($root,$version,'mipsel','signature')===$root.'/releases/pw-agent-openwrt-'.$version.'-mipsle.tar.gz.sig','MIPSLE signature path');
expect_asset(str_ends_with(pw_install_cached_asset_path($root,$version,'riscv64','sha256'),'.tar.gz.sha256'),'RISC-V hash path');
foreach([
 [$version,'../../etc','bundle'],
 [$version,'armv7','../../config'],
 ['1.3.0-rc.2','armv7','bundle'],
 ['../evil','armv7','bundle'],
] as [$ver,$arch,$part]){
 try{
   pw_install_cached_asset_path($root,$ver,$arch,$part);
   throw new RuntimeException('Unsafe asset accepted: '.$arch.'/'.$part);
 }catch(RuntimeException $e){
   if(str_starts_with($e->getMessage(),'Unsafe asset accepted'))throw $e;
 }
}
echo "cPanel asset resolver: version, architecture, format and path whitelist OK\n";
