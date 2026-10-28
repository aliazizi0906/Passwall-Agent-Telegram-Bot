<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib.php';
require __DIR__.'/bundle_core.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
function pw_install_fail(int $code,string $message): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')pw_install_fail(405,'POST is required.');
$requestHttps=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')
    ||strtolower((string)($_SERVER['REQUEST_SCHEME']??''))==='https'
    ||(string)($_SERVER['SERVER_PORT']??'')==='443';
if(!str_starts_with((string)(cfg()['base_url']??''),'https://')||!$requestHttps)
    pw_install_fail(403,'HTTPS required for provisioning asset transfer.');
header('Strict-Transport-Security: max-age=31536000');

$token=(string)($_POST['token']??'');
$arch=(string)($_POST['arch']??'');
$part=(string)($_POST['part']??'');
$requestedVersion=(string)($_POST['version']??'');
if($requestedVersion!=='v'.app_version())pw_install_fail(400,'Requested release does not match the backend.');
if(!preg_match('/^[A-Za-z0-9_-]{32,128}$/D',$token))pw_install_fail(403,'Invalid token.');
if(strlen($arch)>32||strlen($part)>16)pw_install_fail(400,'Invalid asset request.');

try{
    $path=pw_install_cached_asset_path(dirname(__DIR__),app_version(),$arch,$part);
}catch(Throwable $e){pw_install_fail(400,'Unsupported architecture or release asset.');}

// Avoid an unauthorized file download; validation never consumes the token.
// Provisioning and the device secret are handled separately after signature verification.
ensure_v060_schema();
$st=db()->prepare('SELECT 1 FROM pw_provision_tokens
     WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1');
$st->execute([hash('sha256',$token)]);
if(!$st->fetchColumn())pw_install_fail(403,'Provision token expired or unavailable.');
if(!is_file($path)||!is_readable($path))pw_install_fail(503,'Signed release cache missing; sync the matching CPU architecture on cPanel.');
$length=filesize($path);
if($length===false||$length<=0||$length>100*1024*1024)pw_install_fail(503,'Invalid release cache file size.');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.basename($path).'"');
header('Content-Length: '.$length);
$fh=fopen($path,'rb');
if($fh===false)pw_install_fail(500,'Release read failed.');
while(!feof($fh)){
    $chunk=fread($fh,65536);
    if($chunk===false)break;
    echo $chunk;
}
fclose($fh);
