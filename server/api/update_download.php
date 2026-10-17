<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
$raw=raw_body();$auth=authenticate_device($raw);json_decode_assoc($raw);
$file=dirname(__DIR__).'/releases/pw-agent-openwrt-'.app_version().'.tar.gz';
if(!is_file($file))response_json(['ok'=>false,'error'=>'release package missing'],$auth['secret'],503);
$bytes=file_get_contents($file);if($bytes===false)response_json(['ok'=>false,'error'=>'release read failed'],$auth['secret'],500);
response_json(['ok'=>true,'version'=>app_version(),'sha256'=>hash('sha256',$bytes),'size'=>strlen($bytes),'data_b64'=>base64_encode($bytes)],$auth['secret']);
