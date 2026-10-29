<?php
declare(strict_types=1);

// Staged, Ed25519-signed backend upgrade for cPanel PHP CLI.
// Never execute an uploaded ZIP. Always keep existing config.php and runtime.
// Requires manual CLI authorization; browser-triggered deployment is deliberately
// not offered until owner-only upload auditing and real-host testing exist.
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require_once dirname(__DIR__).'/release_tools.php';

function upgrade_fail(string $message): never {throw new RuntimeException($message);}
function upgrade_path_is_safe(string $name): bool {
    if($name===''||strlen($name)>260||str_contains($name,"\0")||str_contains($name,'\\')||str_starts_with($name,'/')
       ||str_contains($name,'//'))return false;
    foreach(explode('/',$name) as $part){if($part===''||$part==='.'||$part==='..')return false;}
    $forbidden=['config.php','config.example.php','.env','.runtime','logs','backups','releases','cache','tests'];
    foreach(explode('/',$name) as $part){if(in_array($part,$forbidden,true))return false;}
    return true;
}
function upgrade_check_tree(string $root,string $name): void {
    $path=$root;
    foreach(explode('/',$name) as $chunk){
        $path.='/'.$chunk;
        if(is_link($path))upgrade_fail('Refusing symlink target for '.$name);
    }
}
function upgrade_write(string $path,string $bytes): void {
    $folder=dirname($path);
    if(!is_dir($folder)&&!mkdir($folder,0750,true)&&!is_dir($folder))upgrade_fail('Cannot create destination directory');
    $tmp=$path.'.pw-upgrade-'.bin2hex(random_bytes(5));
    if(file_put_contents($tmp,$bytes,LOCK_EX)===false)upgrade_fail('Write failed');
    chmod($tmp,0640);
    if(!rename($tmp,$path)){@unlink($tmp);upgrade_fail('Atomic file replacement failed');}
}
function upgrade_inventory(string $archive,string $signature,string $checksum): array {
    if(!extension_loaded('zip'))upgrade_fail('PHP ext-zip required');
    if(!extension_loaded('sodium'))upgrade_fail('PHP ext-sodium required');
    if(!is_file($archive)||!is_file($signature)||!is_file($checksum))upgrade_fail('Signed ZIP, signature and SHA256 files are required');
    if(filesize($archive)>35*1024*1024)upgrade_fail('Release ZIP too large');
    $bytes=file_get_contents($archive);
    $sig=file_get_contents($signature);
    if($bytes===false||$sig===false)upgrade_fail('Cannot read release files');
    $shaText=file_get_contents($checksum);
    if($shaText===false||!preg_match('/^([a-fA-F0-9]{64})\\b/',trim($shaText),$m)
       ||!hash_equals(strtolower($m[1]),hash('sha256',$bytes)))upgrade_fail('SHA256 mismatch');
    pw_release_verify_signature($bytes,$sig);
    $zip=new ZipArchive();
    if($zip->open($archive)!==true)upgrade_fail('Corrupt release ZIP');
    $files=[];$total=0;
    if($zip->numFiles<5||$zip->numFiles>1200)upgrade_fail('Unexpected release size');
    for($i=0;$i<$zip->numFiles;$i++){
        $info=$zip->statIndex($i);if(!is_array($info))upgrade_fail('Bad ZIP entry');
        $name=(string)$info['name'];
        if(str_ends_with($name,'/')){if(!upgrade_path_is_safe(rtrim($name,'/')))upgrade_fail('Unsafe directory in ZIP');continue;}
        if(!upgrade_path_is_safe($name)||isset($files[$name]))upgrade_fail('Unsafe/duplicate file in ZIP');
        if(($info['size']??0)>8*1024*1024)upgrade_fail('Oversized ZIP entry');
        $total+=(int)$info['size'];if($total>45*1024*1024)upgrade_fail('ZIP total size exceeded');
        if($zip->getExternalAttributesIndex($i,$os,$attrs)){
            if($os===ZipArchive::OPSYS_UNIX && (($attrs>>16)&0170000)===0120000)upgrade_fail('ZIP symlinks are not permitted');
        }
        $data=$zip->getFromIndex($i);
        if(!is_string($data)||strlen($data)!==(int)$info['size'])upgrade_fail('Invalid ZIP content');
        $files[$name]=$data;
    }
    $zip->close();
    foreach(['lib.php','setup_core.php','control/index.php','api/poll.php','.htaccess'] as $required){
        if(!isset($files[$required]))upgrade_fail('Release ZIP missing '.$required);
    }
    return $files;
}
function upgrade_rollback(string $root,string $private): void {
    $manifestPath=$private.'/manifest.json';
    if(!is_file($manifestPath))upgrade_fail('No upgrade rollback manifest exists');
    $manifest=json_decode((string)file_get_contents($manifestPath),true);
    if(!is_array($manifest)||!isset($manifest['files'])||!is_array($manifest['files']))upgrade_fail('Corrupt rollback manifest');
    foreach($manifest['files'] as $name=>$wasPresent){
        if(!is_string($name)||!upgrade_path_is_safe($name)||!is_bool($wasPresent))upgrade_fail('Invalid rollback entry');
        upgrade_check_tree($root,$name);
        $target=$root.'/'.$name;
        if($wasPresent){
            $copy=$private.'/old/'.$name;
            if(!is_file($copy))upgrade_fail('Original file missing: '.$name);
            upgrade_write($target,(string)file_get_contents($copy));
        }else{
            if(is_file($target)&&!unlink($target))upgrade_fail('Could not remove newly installed file: '.$name);
        }
    }
    @file_put_contents($private.'/rollback-completed',date(DATE_ATOM));
    echo "Prior PHP backend files restored. Review MySQL schema/migrations separately.\n";
}
function upgrade_main(array $argv): void {
    $root=dirname(__DIR__);
    if(!is_file($root.'/config.php'))upgrade_fail('Existing cPanel installation required; Setup is separate');
    $private=dirname($root).'/.pw-agent-backend-upgrade';
    if(!is_dir($private)&&!mkdir($private,0700,true)&&!is_dir($private))upgrade_fail('Cannot create private upgrade state');
    chmod($private,0700);
    file_put_contents($private.'/.htaccess',"Require all denied\nDeny from all\n");
    $lock=fopen($private.'/upgrade.lock','c+');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))upgrade_fail('Another backend upgrade is active');
    try{
      if(in_array('--rollback',$argv,true)){upgrade_rollback($root,$private);return;}
      if(in_array('--accept',$argv,true)){
        if(!is_file($private.'/manifest.json'))upgrade_fail('No pending backend upgrade to accept');
        $dest=$private.'/history-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(3));
        if(!mkdir($dest,0700))upgrade_fail('Cannot archive prior rollback data');
        if(is_dir($private.'/old')&&!rename($private.'/old',$dest.'/old'))upgrade_fail('Could not archive old backend files');
        if(!rename($private.'/manifest.json',$dest.'/manifest.json'))upgrade_fail('Could not archive old manifest');
        echo "Upgrade accepted. Archived files remain private under ".$dest."\n";
        return;
      }
      $args=[];
      for($i=1;$i<count($argv);$i++)if(str_starts_with($argv[$i],'--')&&isset($argv[$i+1])&&!str_starts_with($argv[$i+1],'--'))$args[$argv[$i]]=$argv[++$i];
      $apply=in_array('--apply',$argv,true);
      foreach(['--archive','--sig','--sha256'] as $flag)if(!isset($args[$flag]))upgrade_fail('Missing '.$flag);
      $files=upgrade_inventory($args['--archive'],$args['--sig'],$args['--sha256']);
      if(!$apply){echo "Signature verified. ".count($files)." files staged for review. Pass --apply to install.\n";return;}
      if(file_exists($private.'/manifest.json'))upgrade_fail('A prior upgrade manifest exists; archive/rollback it before applying another upgrade');
      $present=[];
      foreach($files as $name=>$data){
        upgrade_check_tree($root,$name);
        $target=$root.'/'.$name;$was=is_file($target);
        $present[$name]=$was;
        if($was){
          $backup=$private.'/old/'.$name;
          $dir=dirname($backup);
          if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))upgrade_fail('Cannot prepare backup');
          if(!copy($target,$backup))upgrade_fail('Cannot back up original: '.$name);
          chmod($backup,0600);
        }
      }
      if(file_put_contents($private.'/manifest.json',json_encode(['files'=>$present,'at'=>date(DATE_ATOM)],JSON_THROW_ON_ERROR),LOCK_EX)===false)
          upgrade_fail('Cannot persist rollback manifest');
      try{
        foreach($files as $name=>$data)upgrade_write($root.'/'.$name,$data);
        echo "Signed PHP backend installed, ".count($files)." files. Original config.php preserved.\n";
        echo "Rollback: php ".escapeshellarg(__FILE__)." --rollback\n";
        echo "Check health, API, MySQL migrations and Agent compatibility before using live routers.\n";
      }catch(Throwable $e){
        upgrade_rollback($root,$private);
        throw $e;
      }
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
// Pure guardrail regression test; safe for CI without installed config.php.
if(in_array('--self-test',$argv,true)){
    foreach(['../config.php','/etc/passwd','config.php','releases/old','sub/../danger','sub\\evil','.runtime/secrets','cli/../../bad'] as $bad)
        if(upgrade_path_is_safe($bad))upgrade_fail('Unsafe upgrade path allowed: '.$bad);
    foreach(['lib.php','control/index.php','cli/backend-upgrade.php','assets/control-v14.css','.htaccess'] as $good)
        if(!upgrade_path_is_safe($good))upgrade_fail('Safe upgrade path denied: '.$good);
    echo "Signed backend updater path checks OK\n";
    exit(0);
}
try{upgrade_main($argv);}catch(Throwable $e){fwrite(STDERR,"UPGRADE ERROR: ".$e->getMessage()."\n");exit(1);}
