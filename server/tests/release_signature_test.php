<?php
declare(strict_types=1);
require dirname(__DIR__).'/release_tools.php';

function verify_expect(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException('Release verification test failed: '.$label);
}
$root=dirname(__DIR__,2);
$expected=trim((string)file_get_contents($root.'/UPDATE_PUBLIC_KEY.b64'));
verify_expect(hash_equals($expected,PW_RELEASE_ED25519_PUBLIC_KEY_B64),'Pinned public key diverged from source');
$pub=base64_decode(PW_RELEASE_ED25519_PUBLIC_KEY_B64,true);
verify_expect(is_string($pub)&&strlen($pub)===32,'Pinned public key has wrong length');
$bootstrap=(string)file_get_contents($root.'/openwrt/install-remote.sh');
verify_expect(str_contains($bootstrap,"PUBLIC_KEY_B64='".PW_RELEASE_ED25519_PUBLIC_KEY_B64."'"),'Router and backend signing keys differ');

if(!function_exists('sodium_crypto_sign_keypair')){
    echo "SKIP synthetic signature tests: sodium is unavailable; release caching fails closed\n";
    exit(0);
}
$keyPair=sodium_crypto_sign_keypair();
$private=sodium_crypto_sign_secretkey($keyPair);
$public=sodium_crypto_sign_publickey($keyPair);
$bundle="test payload\n".random_bytes(24);
$sig=sodium_crypto_sign_detached($bundle,$private);
verify_expect(pw_release_verify_signature_with_key($bundle,$sig,$public),'valid Ed25519 signature rejected');
verify_expect(!pw_release_verify_signature_with_key($bundle.'changed',$sig,$public),'tampered archive accepted');
verify_expect(!pw_release_verify_signature_with_key($bundle,$sig,random_bytes(32)),'wrong publisher key accepted');
verify_expect(!pw_release_verify_signature_with_key($bundle,substr($sig,0,63),$public),'truncated signature accepted');
try{pw_release_verify_signature($bundle,$sig);throw new RuntimeException('Test-generated key should not match published key');}
catch(RuntimeException $e){
    verify_expect(str_contains($e->getMessage(),'Ed25519 signature verification failed'),'published-key failure wrong');
}
echo "Release signing public-key pin, Ed25519 verification, tamper and key replacement: OK\n";
