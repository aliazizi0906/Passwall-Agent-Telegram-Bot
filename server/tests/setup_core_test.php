<?php
declare(strict_types=1);
require dirname(__DIR__).'/setup_core.php';

function expect_true(bool $ok,string $message): void {
    if(!$ok){fwrite(STDERR,"FAIL: ".$message."\n");exit(1);}
}

$cases=[
    [
        ['HTTPS'=>'on','HTTP_HOST'=>'example.com','SCRIPT_NAME'=>'/setup.php'],
        'https://example.com',
    ],
    [
        ['HTTPS'=>'on','HTTP_HOST'=>'example.com','SCRIPT_NAME'=>'/pw/setup.php'],
        'https://example.com/pw',
    ],
    [
        ['HTTPS'=>'on','HTTP_HOST'=>'example.com','SCRIPT_NAME'=>'/tools/openwrt/setup.php'],
        'https://example.com/tools/openwrt',
    ],
    [
        ['HTTP_X_FORWARDED_PROTO'=>'https','HTTP_HOST'=>'router.example.com','SCRIPT_NAME'=>'/nested/a/setup.php'],
        'https://router.example.com/nested/a',
    ],
];
foreach($cases as [$server,$expected]){
    expect_true(pw_setup_detect_base_url($server)===$expected,'base URL mismatch for '.$expected);
}

expect_true(pw_setup_validate_base_url('https://example.com/a/b')==='https://example.com/a/b','valid HTTPS subpath rejected');
try{pw_setup_validate_base_url('http://example.com/a');expect_true(false,'HTTP base URL was accepted');}catch(RuntimeException $e){}

foreach(['armv5','armv5tel','armv6','armv7','armv7l','armv8l','aarch64','x86_64','i686','mips','mipsel','mips64','mips64le','riscv64','loong64','ppc64le'] as $a){
    expect_true(pw_setup_normalize_arch($a)!=='','architecture normalization failed for '.$a);
}

$cmd=pw_setup_router_command('https://example.com/network/control','abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-');
expect_true(str_contains($cmd,"PW_SERVER_BASE='https://example.com/network/control'"),'router command lost subdirectory base URL');
expect_true(str_contains($cmd,"PW_AGENT_VERSION='v".pw_setup_version()."'"),'router command is not pinned to the backend Agent version');
expect_true(!str_contains($cmd,'/home/'),'router command contains a hardcoded home path');
expect_true(str_contains($cmd,'$PW_SERVER_BASE/install/remote.sh'),'bootstrap must download from the exported configured cPanel domain');
$steps=pw_setup_router_steps('https://example.com/network/control','abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-');
expect_true(count($steps)===6,'expected six individually executable commands');
foreach($steps as $i=>$step){
    expect_true(strlen($step)<120,'OpenWrt paste line too long: '.($i+1));
    expect_true(!str_contains($step,"\\n")&&!str_contains($step,'sh -c'),'nested bootstrap unexpectedly reintroduced');
}
expect_true(str_starts_with($steps[0],'export PW_SERVER_BASE='),'base not exported');
expect_true(str_starts_with($steps[1],'export PW_PROVISION_TOKEN='),'token not exported');
expect_true(str_contains($steps[3],'mktemp -d')&&str_contains($steps[3],'wget -O "$tmp/pw.sh"'),'wget bootstrap missing');
expect_true(str_contains($steps[4],'sh -n "$tmp/pw.sh"'),'shell syntax validation missing');
expect_true($steps[5]==='unset PW_PROVISION_TOKEN','token cleanup missing');
expect_true(str_contains($cmd,'test -s'), 'bootstrap can execute empty downloads');

$cron=pw_setup_cron_command('/home/demo/public_html/arbitrary/nested/path');
expect_true(str_contains($cron,"/home/demo/public_html/arbitrary/nested/path/cron/monitor.php"),'cron command did not preserve actual installation path');
expect_true(!str_contains($cron,'public_html/pwagent'),'cron command contains the old fixed deployment directory');

echo "setup_core_test: OK\n";
