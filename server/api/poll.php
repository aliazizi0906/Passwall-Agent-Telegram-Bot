<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

$raw = raw_body();
$auth = authenticate_device($raw);
$device = $auth['device_id'];
$secret = $auth['secret'];
$body = json_decode_assoc($raw);
ensure_v060_schema();

$pdo = db();
$st = $pdo->prepare(
    'INSERT INTO pw_devices (device_id,last_seen,last_ip,agent_version,architecture,hostname,meta_json) VALUES (?,NOW(),?,?,?,?,?) '
    . 'ON DUPLICATE KEY UPDATE last_seen=NOW(),last_ip=VALUES(last_ip),agent_version=VALUES(agent_version),architecture=VALUES(architecture),hostname=VALUES(hostname),meta_json=VALUES(meta_json)'
);
$st->execute([
    $device,
    $_SERVER['REMOTE_ADDR'] ?? '',
    (string)($body['agent_version'] ?? ''),
    substr((string)($body['architecture'] ?? ''),0,32),
    (string)($body['hostname'] ?? ''),
    json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
]);

$cmd = null;
// Recover expired leases while respecting the retry budget.
$pdo->prepare("UPDATE pw_commands SET status='failed', finished_at=NOW(), error_text='retry budget exhausted' WHERE device_id=? AND status='leased' AND expires_at IS NOT NULL AND expires_at < NOW() AND attempt_count >= max_attempts")->execute([$device]);
$pdo->prepare("UPDATE pw_commands SET status='queued', leased_at=NULL, lease_token=NULL, expires_at=NULL WHERE device_id=? AND status='leased' AND expires_at IS NOT NULL AND expires_at < NOW() AND attempt_count < max_attempts")->execute([$device]);
$pdo->beginTransaction();
try {
    $q = $pdo->prepare("SELECT id,action,params_json,idempotency_key,attempt_count,max_attempts FROM pw_commands WHERE device_id=? AND status='queued' ORDER BY id ASC LIMIT 1 FOR UPDATE");
    $q->execute([$device]);
    $row = $q->fetch();
    if ($row) {
        $leaseToken=bin2hex(random_bytes(16));
        $u = $pdo->prepare("UPDATE pw_commands SET status='leased', leased_at=NOW(), lease_token=?, expires_at=(NOW()+INTERVAL 90 SECOND), attempt_count=attempt_count+1 WHERE id=? AND status='queued' AND attempt_count < max_attempts");
        $u->execute([$leaseToken,(int)$row['id']]);
        if ($u->rowCount() === 1) {
            $plainParams=v050_open_params($device,(string)$row['action'],(string)$row['params_json']);
            $params = json_decode($plainParams, true);
            if (!is_array($params)) $params = [];
            // Go agent expects command.params to be a JSON object. PHP encodes an
            // empty array as [] rather than {}, so explicitly emit an empty object.
            $paramsOut = ($params === []) ? new stdClass() : $params;
            $cmd = [
                'id' => (int)$row['id'],
                'action' => (string)$row['action'],
                'params' => $paramsOut,
                'lease_token' => $leaseToken,
                'idempotency_key' => (string)($row['idempotency_key']??''),
            ];
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    response_json(['ok'=>false,'error'=>'queue failure'], $secret, 500);
}

v060_record_metric($device,$body);
response_json(['ok'=>true,'command'=>$cmd,'rotate_secret'=>!empty($auth['rotation_pending'])], $secret);
