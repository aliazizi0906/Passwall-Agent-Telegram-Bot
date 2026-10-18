<?php
declare(strict_types=1);

require dirname(__DIR__).'/lib.php';

function v070_test_assert(bool $ok,string $message): void {
    if(!$ok){fwrite(STDERR,"FAIL: ".$message."\n");exit(1);}
}
function v070_test_throws(callable $fn,string $message): void {
    try{$fn();}catch(Throwable $e){return;}
    v070_test_assert(false,$message);
}

v070_test_assert(app_version()==='0.7.0','backend version should be 0.7.0');
$valid=v070_validate_profile_rules_php([
    ['package'=>'wireless','key'=>'wireless.radio0.channel','value'=>'36'],
    ['package'=>'firewall','key'=>'firewall.@zone[0].masq','value'=>'1'],
]);
v070_test_assert(count($valid)===2,'safe profile rules should be accepted');
v070_test_throws(static fn()=>v070_validate_profile_rules_php([
    ['package'=>'wireless','key'=>'wireless.default_radio0.key','value'=>'not-a-real-password'],
]),'Wi-Fi secret keys must be rejected from profiles');
v070_test_throws(static fn()=>v070_validate_profile_rules_php([
    ['package'=>'network','key'=>'network.lan.ipaddr','value'=>'192.0.2.1'],
]),'potentially disruptive IP configuration must be rejected from profiles');
v070_test_throws(static fn()=>v070_validate_profile_rules_php([
    ['package'=>'wireless','key'=>'wireless.radio0.channel','value'=>'vless://example.invalid'],
]),'proxy subscription-like profile values must be rejected');
v070_test_throws(static fn()=>v070_validate_profile_rules_php([
    ['package'=>'wireless','key'=>'wireless.radio0.channel','value'=>'36'],
    ['package'=>'wireless','key'=>'wireless.radio0.channel','value'=>'40'],
]),'duplicate profile keys must be rejected');

$key=random_bytes(32);$secret=bin2hex(random_bytes(32));
$sealed=v070_seal_secret_with_key($secret,$key);
v070_test_assert(v070_open_secret_with_key($sealed,$key)===$secret,'instance-key envelope should round-trip');
v070_test_throws(static fn()=>v070_open_secret_with_key($sealed,random_bytes(32)),'wrong instance key must not decrypt a secret');
$backup=random_bytes(4096);$sealedBackup=v070_seal_backup_payload($backup,$key);
v070_test_assert(v070_open_backup_payload($sealedBackup,$key)===$backup,'encrypted backup payload should round-trip');
v070_test_throws(static fn()=>v070_open_backup_payload($sealedBackup,random_bytes(32)),'wrong instance key must not decrypt a backup');
$tampered=$sealedBackup;$tampered[strlen($tampered)-1]=chr(ord($tampered[strlen($tampered)-1])^1);
v070_test_throws(static fn()=>v070_open_backup_payload($tampered,$key),'tampered backup ciphertext must be rejected');

echo "platform_v070_test: OK\n";
