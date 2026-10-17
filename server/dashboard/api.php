<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
header('Cache-Control: no-store');

$in=json_decode_assoc(raw_body());
$init=(string)($in['init_data']??'');
try{$tgUser=tg_validate_webapp_init_data($init);}catch(Throwable $e){response_json(['ok'=>false,'error'=>$e->getMessage()],null,403);}
$uid=(int)($tgUser['id']??0);
ensure_v060_schema();
if($uid<=0||!v060_user_allowed($uid))response_json(['ok'=>false,'error'=>'Unauthorized user'],null,403);

$action=(string)($in['action']??'summary');
$device=(string)($in['device_id']??v060_active_device($uid));

function dash_need(int $uid,string $perm): void {
    if(!v060_has_permission($uid,$perm))response_json(['ok'=>false,'error'=>'Permission denied: '.$perm],null,403);
}
function dash_device(int $uid,string $device): void {
    if($device===''||!v060_can_device($uid,$device)||!v060_device_enabled($device))response_json(['ok'=>false,'error'=>'Device access denied'],null,403);
}
function dash_json($v): array { return is_array($v)?$v:[]; }

try{
    if($action==='summary'){
        dash_need($uid,'device.read');
        $fleet=v060_fleet_summary($uid);
        $active=v060_active_device($uid);
        $user=v060_user($uid);
        response_json(['ok'=>true,'fleet'=>$fleet,'active_device_id'=>$active,'role'=>$user['role']??'viewer']);
    }

    if($action==='select_device'){
        dash_device($uid,$device);v060_set_active_device($uid,$device);
        response_json(['ok'=>true,'active_device_id'=>$device]);
    }

    if($action==='device_detail'){
        dash_device($uid,$device);
        $row=v060_device_row($device);
        $m=db()->prepare('SELECT * FROM pw_metrics WHERE device_id=? ORDER BY captured_at DESC LIMIT 60');$m->execute([$device]);
        $metrics=array_reverse($m->fetchAll());
        $maint=v060_maintenance_row($device);
        response_json(['ok'=>true,'device'=>$row,'metrics'=>$metrics,'maintenance'=>$maint,'overview'=>v060_monitoring_overview($uid,$device)]);
    }

    if($action==='device_create'){
        dash_need($uid,'device.manage');
        $name=trim((string)($in['name']??''));
        $created=v060_register_device($name,$uid);
        $cmd=v060_provision_command((string)$created['token']);
        unset($created['secret']);
        response_json(['ok'=>true,'device'=>$created,'install_command'=>$cmd,'expires_minutes'=>30]);
    }

    if($action==='device_update'){
        dash_need($uid,'device.manage');dash_device($uid,$device);
        $name=trim((string)($in['display_name']??''));if($name===''||strlen($name)>128)throw new RuntimeException('Invalid display name');
        $location=trim((string)($in['location']??''));if(strlen($location)>128)throw new RuntimeException('Location too long');
        $tags=dash_json($in['tags']??[]);$tags=array_values(array_unique(array_filter(array_map(fn($x)=>substr(trim((string)$x),0,48),$tags))));
        $groupId=isset($in['group_id'])&&$in['group_id']!==''?(int)$in['group_id']:null;
        if($groupId!==null){$g=db()->prepare('SELECT id FROM pw_device_groups WHERE id=?');$g->execute([$groupId]);if(!$g->fetchColumn())throw new RuntimeException('Group not found');}
        db()->prepare('UPDATE pw_device_registry SET display_name=?,location=?,tags_json=?,group_id=? WHERE device_id=?')->execute([$name,$location,json_encode($tags,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$groupId,$device]);
        v060_audit_event($device,$uid,'dashboard','device.update','done',['display_name'=>$name,'location'=>$location,'tags'=>$tags,'group_id'=>$groupId]);
        response_json(['ok'=>true,'device'=>v060_device_row($device)]);
    }

    if($action==='groups'){
        dash_need($uid,'device.read');
        $rows=db()->query('SELECT g.*,COUNT(r.device_id) device_count FROM pw_device_groups g LEFT JOIN pw_device_registry r ON r.group_id=g.id GROUP BY g.id ORDER BY g.name')->fetchAll();
        response_json(['ok'=>true,'groups'=>$rows]);
    }
    if($action==='group_create'){
        dash_need($uid,'group.manage');
        $name=trim((string)($in['name']??''));if($name===''||strlen($name)>96)throw new RuntimeException('Group name must be 1-96 bytes');
        $desc=substr(trim((string)($in['description']??'')),0,255);
        db()->prepare('INSERT INTO pw_device_groups(name,description) VALUES(?,?)')->execute([$name,$desc]);
        v060_audit_event($device?:'fleet',$uid,'dashboard','group.create','done',['name'=>$name]);
        response_json(['ok'=>true,'id'=>(int)db()->lastInsertId()]);
    }

    if($action==='users'){
        dash_need($uid,'rbac.read');
        $rows=db()->query('SELECT telegram_user_id,role,enabled,device_scope_json,created_at,updated_at FROM pw_users ORDER BY created_at')->fetchAll();
        response_json(['ok'=>true,'users'=>$rows]);
    }
    if($action==='user_upsert'){
        dash_need($uid,'rbac.manage');
        $caller=v060_user($uid);$callerRole=(string)($caller['role']??'viewer');
        $target=(int)($in['telegram_user_id']??0);if($target<=0)throw new RuntimeException('Invalid Telegram user ID');
        $role=(string)($in['role']??'viewer');if(!in_array($role,['owner','admin','operator','viewer'],true))throw new RuntimeException('Invalid role');
        $existing=v060_user($target);$existingRole=(string)($existing['role']??'');
        if(($role==='owner'||$existingRole==='owner')&&$callerRole!=='owner')throw new RuntimeException('Only an Owner may create or modify another Owner');
        $scope=dash_json($in['device_scope']??['*']);$scope=array_values(array_unique(array_filter(array_map('strval',$scope),fn($x)=>$x==='*'||preg_match('/^[A-Za-z0-9_-]{1,64}$/',$x))));if(!$scope)$scope=['*'];
        $enabled=!isset($in['enabled'])||!empty($in['enabled']);
        if($existingRole==='owner'&&(!$enabled||$role!=='owner')){
            $owners=(int)db()->query("SELECT COUNT(*) FROM pw_users WHERE enabled=1 AND role='owner'")->fetchColumn();
            if($owners<=1)throw new RuntimeException('The last enabled Owner cannot be demoted or disabled');
        }
        db()->prepare('INSERT INTO pw_users(telegram_user_id,role,enabled,device_scope_json) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role),enabled=VALUES(enabled),device_scope_json=VALUES(device_scope_json),updated_at=NOW()')->execute([$target,$role,$enabled?1:0,json_encode($scope,JSON_UNESCAPED_SLASHES)]);
        v060_audit_event($device?:'fleet',$uid,'dashboard','rbac.user_upsert','done',['target_user'=>$target,'role'=>$role,'scope'=>$scope,'enabled'=>$enabled]);
        response_json(['ok'=>true]);
    }

    if($action==='audit'){
        dash_device($uid,$device);dash_need($uid,'audit.read');
        response_json(['ok'=>true,'audit'=>v060_audit_recent($uid,$device,50),'changes'=>v060_config_changes($uid,$device,30)]);
    }
    if($action==='rollback'){
        dash_device($uid,$device);dash_need($uid,'rollback');
        $changeId=(int)($in['change_id']??0);if($changeId<=0)throw new RuntimeException('Invalid change ID');
        $id=v060_queue_rollback($uid,$changeId,$uid,null);
        response_json(['ok'=>true,'command_id'=>$id]);
    }

    if($action==='alerts'){
        dash_device($uid,$device);dash_need($uid,'monitor.read');
        $st=db()->prepare('SELECT * FROM pw_alert_rules WHERE device_id=? ORDER BY id DESC');$st->execute([$device]);
        response_json(['ok'=>true,'alerts'=>$st->fetchAll()]);
    }
    if($action==='alert_create'){
        dash_device($uid,$device);dash_need($uid,'alert.manage');
        $id=v060_add_alert($uid,$device,(string)($in['metric']??''),(string)($in['operator']??'>'),(float)($in['threshold']??0),(int)($in['duration_seconds']??0),(int)($in['cooldown_seconds']??900),$uid);
        response_json(['ok'=>true,'id'=>$id]);
    }
    if($action==='alert_toggle'){
        dash_device($uid,$device);dash_need($uid,'alert.manage');
        $id=(int)($in['id']??0);$enabled=!empty($in['enabled']);
        $st=db()->prepare('UPDATE pw_alert_rules SET enabled=? WHERE id=? AND device_id=?');$st->execute([$enabled?1:0,$id,$device]);
        response_json(['ok'=>true]);
    }
    if($action==='alert_delete'){
        dash_device($uid,$device);dash_need($uid,'alert.manage');
        db()->prepare('DELETE FROM pw_alert_rules WHERE id=? AND device_id=?')->execute([(int)($in['id']??0),$device]);
        response_json(['ok'=>true]);
    }

    if($action==='maintenance_set'){
        dash_device($uid,$device);dash_need($uid,'maintenance.manage');
        v060_set_maintenance($uid,$device,(int)($in['minutes']??0),substr(trim((string)($in['reason']??'')),0,255));
        response_json(['ok'=>true,'maintenance'=>v060_maintenance_row($device)]);
    }

    if($action==='schedules'){
        dash_device($uid,$device);dash_need($uid,'device.read');
        $st=db()->prepare('SELECT * FROM pw_schedules WHERE device_id=? ORDER BY id DESC');$st->execute([$device]);
        response_json(['ok'=>true,'schedules'=>$st->fetchAll()]);
    }
    if($action==='schedule_create'){
        dash_device($uid,$device);dash_need($uid,'schedule.manage');
        $id=v060_add_schedule($uid,$device,(string)($in['name']??'Scheduled action'),(string)($in['command_action']??''),dash_json($in['params']??[]),(string)($in['schedule_type']??'interval'),dash_json($in['schedule_spec']??[]));
        response_json(['ok'=>true,'id'=>$id]);
    }
    if($action==='schedule_toggle'){
        dash_device($uid,$device);dash_need($uid,'schedule.manage');
        $id=(int)($in['id']??0);$enabled=!empty($in['enabled']);
        db()->prepare('UPDATE pw_schedules SET enabled=? WHERE id=? AND device_id=?')->execute([$enabled?1:0,$id,$device]);
        response_json(['ok'=>true]);
    }
    if($action==='schedule_delete'){
        dash_device($uid,$device);dash_need($uid,'schedule.manage');
        db()->prepare('DELETE FROM pw_schedules WHERE id=? AND device_id=?')->execute([(int)($in['id']??0),$device]);
        response_json(['ok'=>true]);
    }

    response_json(['ok'=>false,'error'=>'Unknown dashboard action'],null,404);
}catch(Throwable $e){
    response_json(['ok'=>false,'error'=>$e->getMessage()],null,400);
}
