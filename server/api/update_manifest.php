<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
$raw=raw_body();$auth=authenticate_device($raw);$body=json_decode_assoc($raw);
$file=dirname(__DIR__).'/releases/pw-agent-openwrt-'.app_version().'.tar.gz';
$sigFile=$file.'.sig';
if(!is_file($file)||!is_file($sigFile))response_json(['ok'=>false,'error'=>'signed release package missing'],$auth['secret'],503);
$current=(string)($body['current_version']??'');
response_json([
    'ok'=>true,
    'version'=>app_version(),
    'current_version'=>$current,
    'update_available'=>version_compare(app_version(),$current,'>'),
    'sha256'=>hash_file('sha256',$file),
    'size'=>filesize($file),
    'signature_b64'=>base64_encode((string)file_get_contents($sigFile)),
],$auth['secret']);
