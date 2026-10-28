<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
require dirname(__DIR__).'/lib.php';
require dirname(__DIR__).'/schema_migrations.php';
try {
    ensure_v060_schema();
    $applied=pw_migrations_apply(db());
    $status=pw_migrations_status(db());
    echo json_encode(['ok'=>true,'applied'=>$applied,'status'=>$status],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){
    fwrite(STDERR,"Migration failed; database backup must be retained: ".$e->getMessage().PHP_EOL);
    exit(1);
}
