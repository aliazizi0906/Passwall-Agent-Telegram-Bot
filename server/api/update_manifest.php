<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

$raw=raw_body();$auth=authenticate_device($raw);$body=json_decode_assoc($raw);
try{$arch=v050_normalize_arch((string)($body['arch']??''));}
catch(Throwable $e){response_json(['ok'=>false,'error'=>$e->getMessage()],$auth['secret'],400);}
$file=v050_release_file($arch);$sigFile=$file.'.sig';
if(!is_file($file)||!is_file($sigFile))response_json(['ok'=>false,'error'=>'signed release package missing for '.$arch],$auth['secret'],503);
$current=(string)($body['current_version']??'');
$sig=file_get_contents($sigFile);if($sig===false)response_json(['ok'=>false,'error'=>'signature read failed'],$auth['secret'],500);
response_json([
    'ok'=>true,
    'version'=>app_version(),
    'current_version'=>$current,
    'arch'=>$arch,
    'update_available'=>version_compare(app_version(),$current,'>'),
    'sha256'=>hash_file('sha256',$file),
    'size'=>filesize($file),
    'signature_b64'=>base64_encode($sig),
],$auth['secret']);
