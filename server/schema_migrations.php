<?php
declare(strict_types=1);
require_once __DIR__.'/web_auth.php';
require_once __DIR__.'/fleet_rollout.php';

/**
 * Additive migration registry for cPanel/MySQL.
 * Never use MySQL DDL within a transaction: it triggers implicit commits.
 * Never rotate existing instance keys, OAuth tokens, or device secrets.
 */
function pw_migrations_manifest(): array {
    return [
      '20261028_01_web_auth' => 'browser username/password account and lockout tables',
      '20261028_02_fleet_rollout' => 'staged fleet canary rollout tables',
    ];
}
function pw_migrations_apply(PDO $pdo): array {
    // MySQL DDL must be outside transactions or it can commit other request work.
    if($pdo->inTransaction())throw new RuntimeException('Migrations cannot run during a database transaction');
    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_schema_migrations(
        migration_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        description VARCHAR(255) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $seen=[];
    foreach($pdo->query('SELECT migration_id FROM pw_schema_migrations')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $seen[(string)$id]=true;
    }
    $applied=[];
    foreach(pw_migrations_manifest() as $id=>$description){
        if(!empty($seen[$id]))continue;
        if($id==='20261028_01_web_auth')pw_web_auth_schema($pdo);
        elseif($id==='20261028_02_fleet_rollout')pw_rollout_schema($pdo);
        else throw new RuntimeException('Unknown migration');
        $st=$pdo->prepare('INSERT IGNORE INTO pw_schema_migrations(migration_id,description) VALUES(?,?)');
        $st->execute([$id,$description]);
        $applied[]=$id;
    }
    return $applied;
}
function pw_migrations_status(PDO $pdo): array {
    if($pdo->inTransaction())throw new RuntimeException('Migration status must not create schema inside a transaction');
    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_schema_migrations(
        migration_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        description VARCHAR(255) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $installed=array_map('strval',$pdo->query('SELECT migration_id FROM pw_schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
    return [
      'installed'=>$installed,
      'pending'=>array_values(array_diff(array_keys(pw_migrations_manifest()),$installed)),
      'reversible_without_data_loss'=>false,
      'note'=>'Additive migrations only; restore database backup for full rollback.',
    ];
}
