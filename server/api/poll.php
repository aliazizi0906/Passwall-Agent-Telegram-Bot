<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

$raw = raw_body();
$auth = authenticate_device($raw);
$device = $auth['device_id'];
$secret = $auth['secret'];
$body = json_decode_assoc($raw);

$pdo = db();
$st = $pdo->prepare(
    'INSERT INTO pw_devices (device_id,last_seen,last_ip,agent_version,hostname,meta_json) VALUES (?,NOW(),?,?,?,?) '
    . 'ON DUPLICATE KEY UPDATE last_seen=NOW(),last_ip=VALUES(last_ip),agent_version=VALUES(agent_version),hostname=VALUES(hostname),meta_json=VALUES(meta_json)'
);
$st->execute([
    $device,
    $_SERVER['REMOTE_ADDR'] ?? '',
    (string)($body['agent_version'] ?? ''),
    (string)($body['hostname'] ?? ''),
    json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
]);

$cmd = null;
// Recover commands leased by an agent that crashed or rebooted before returning a result.
$pdo->prepare("UPDATE pw_commands SET status='queued', leased_at=NULL WHERE device_id=? AND status='leased' AND leased_at < (NOW() - INTERVAL 5 MINUTE)")->execute([$device]);
$pdo->beginTransaction();
try {
    $q = $pdo->prepare("SELECT id,action,params_json FROM pw_commands WHERE device_id=? AND status='queued' ORDER BY id ASC LIMIT 1 FOR UPDATE");
    $q->execute([$device]);
    $row = $q->fetch();
    if ($row) {
        $u = $pdo->prepare("UPDATE pw_commands SET status='leased', leased_at=NOW() WHERE id=? AND status='queued'");
        $u->execute([(int)$row['id']]);
        if ($u->rowCount() === 1) {
            $params = json_decode((string)$row['params_json'], true);
            if (!is_array($params)) $params = [];
            // Go agent expects command.params to be a JSON object. PHP encodes an
            // empty array as [] rather than {}, so explicitly emit an empty object.
            $paramsOut = ($params === []) ? new stdClass() : $params;
            $cmd = [
                'id' => (int)$row['id'],
                'action' => (string)$row['action'],
                'params' => $paramsOut,
            ];
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    response_json(['ok'=>false,'error'=>'queue failure'], $secret, 500);
}

response_json(['ok'=>true,'command'=>$cmd], $secret);
