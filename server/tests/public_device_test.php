<?php
declare(strict_types=1);
require dirname(__DIR__).'/platform_v060.php';
$raw=[
 'device_id'=>'router-1',
 'display_name'=>'Office',
 'enabled'=>1,
 'last_seen'=>'2026-10-28 11:00:00',
 'agent_version'=>'1.4.0-alpha.1',
 'hostname'=>'test-router',
 'secret_sealed'=>'ENCRYPTED_SECRET_MUST_NOT_LEAK',
 'device_secret'=>'PLAIN_SECRET_MUST_NOT_LEAK',
 'meta_json'=>'{"device_secret":"NESTED_SECRET_MUST_NOT_LEAK"}',
 'last_ip'=>'192.0.2.1',
 'password'=>'PASS_MUST_NOT_LEAK',
 'token'=>'TOKEN_MUST_NOT_LEAK'
];
$public=v060_public_device_fields($raw);
foreach(['secret_sealed','device_secret','meta_json','last_ip','password','token'] as $field){
 if(array_key_exists($field,$public))throw new RuntimeException('Sensitive device key escaped: '.$field);
}
foreach(['device_id','display_name','enabled','last_seen','agent_version','hostname'] as $field){
 if(!array_key_exists($field,$public))throw new RuntimeException('Required public field missing: '.$field);
}
if(str_contains(json_encode($public),'SECRET_MUST_NOT_LEAK'))throw new RuntimeException('Device secrets appeared in serialized response');
echo "Public router metadata allowlist: OK\n";
