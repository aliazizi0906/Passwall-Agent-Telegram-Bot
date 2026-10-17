<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
$raw=raw_body();$auth=authenticate_device($raw);$body=json_decode_assoc($raw);
response_json([
    'ok'=>true,
    'version'=>app_version(),
    'server_time'=>time(),
    'device_id'=>$auth['device_id'],
    'agent_version'=>(string)($body['agent_version']??''),
    'hostname'=>(string)($body['hostname']??''),
],$auth['secret']);
