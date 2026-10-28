<?php
declare(strict_types=1);
require dirname(__DIR__).'/schema_migrations.php';
function assert_migration(bool $ok,string $msg): void {if(!$ok)throw new RuntimeException($msg);}
$dsn=(string)getenv('PW_TEST_MYSQL_DSN');
if(!$dsn){echo "Migration pure checks only: DB not connected\n";exit;}
$pdo=new PDO($dsn,(string)getenv('PW_TEST_MYSQL_USER'),(string)getenv('PW_TEST_MYSQL_PASSWORD'),[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$tables=['pw_schema_migrations','pw_web_accounts','pw_web_login_attempts','pw_fleet_rollouts','pw_fleet_rollout_targets'];
foreach($tables as $name)$pdo->exec('DROP TABLE IF EXISTS '.$name);
try {
    $pdo->beginTransaction();
    try{
        pw_migrations_apply($pdo);
        throw new RuntimeException('DDL migration was permitted inside transaction');
    }catch(RuntimeException $e){
        assert_migration(str_contains($e->getMessage(),'during a database transaction'),'Wrong transaction guard');
    }finally{$pdo->rollBack();}
    $first=pw_migrations_apply($pdo);
    assert_migration(count($first)===2,'Both migrations should run once');
    $again=pw_migrations_apply($pdo);
    assert_migration($again===[],'Repeated migration must be idempotent');
    $status=pw_migrations_status($pdo);
    assert_migration(count($status['installed'])===2&&$status['pending']===[],'Migration status incorrect');
    foreach(array_slice($tables,1) as $name){
        $q=$pdo->prepare('SHOW TABLES LIKE ?');$q->execute([$name]);
        assert_migration($q->fetchColumn()!==false,'Expected schema table absent: '.$name);
    }
}finally {
    foreach(array_reverse($tables) as $name)$pdo->exec('DROP TABLE IF EXISTS '.$name);
}
echo "Additive migrations: transaction guard, first run, idempotency and schema ledger: OK\n";
