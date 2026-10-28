<?php
declare(strict_types=1);

/**
 * First-party cPanel-safe browser authentication. Telegram remains separate.
 * Do not load this module over HTTP and never put a web session ID in a URL.
 */
function pw_web_auth_validate_credentials(string $username,string $password): void {
    if(!preg_match('/^[a-z][a-z0-9._-]{2,31}$/D',$username)){
        throw new RuntimeException('Web username must be 3-32 lowercase ASCII characters, starting with a letter.');
    }
    if(strlen($password)<12||strlen($password)>256)throw new RuntimeException('Web password must be 12-256 bytes.');
}
function pw_web_auth_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_web_accounts(
       id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
       username VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
       password_hash VARCHAR(255) NOT NULL,
       telegram_user_id BIGINT NOT NULL,
       updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pw_web_login_attempts(
       identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
       failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
       locked_until DATETIME NULL,
       updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function pw_web_auth_install(PDO $pdo,string $username,string $password,int $owner): void {
    pw_web_auth_validate_credentials($username,$password);
    if($owner<=0)throw new RuntimeException('A Telegram Owner ID is required for browser authorization.');
    pw_web_auth_schema($pdo);
    // Only owner-authenticated provisioning or a fresh install may call this.
    $hash=password_hash($password,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_BCRYPT);
    $st=$pdo->prepare("INSERT INTO pw_web_accounts(id,username,password_hash,telegram_user_id)
                      VALUES(1,?,?,?) ON DUPLICATE KEY UPDATE
                      username=VALUES(username),password_hash=VALUES(password_hash),
                      telegram_user_id=VALUES(telegram_user_id)");
    $st->execute([$username,$hash,$owner]);
}
function pw_web_auth_cookie_path(): string {
    $parts=parse_url((string)(cfg()['base_url']??''));
    $path=(string)($parts['path']??'');
    return rtrim($path,'/').'/' ?: '/';
}
function pw_web_auth_begin(): void {
    if(session_status()===PHP_SESSION_ACTIVE)return;
    if(!str_starts_with((string)(cfg()['base_url']??''),'https://')){
        throw new RuntimeException('HTTPS is mandatory for browser sessions.');
    }
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.cookie_httponly','1');
    ini_set('session.cookie_secure','1');
    session_name('pw_web_control');
    session_set_cookie_params([
      'lifetime'=>0,'path'=>pw_web_auth_cookie_path(),'secure'=>true,'httponly'=>true,'samesite'=>'Strict',
    ]);
    session_start();
    if(empty($_SESSION['pw_csrf']))$_SESSION['pw_csrf']=bin2hex(random_bytes(32));
}
function pw_web_auth_csrf(): string {
    pw_web_auth_begin();
    return (string)$_SESSION['pw_csrf'];
}
function pw_web_auth_csrf_ok(string $value): bool {
    return $value!==''&&hash_equals(pw_web_auth_csrf(),$value);
}
function pw_web_auth_user(): ?int {
    pw_web_auth_begin();
    $uid=(int)($_SESSION['pw_uid']??0);
    $last=(int)($_SESSION['pw_touch']??0);
    $agent=(string)($_SESSION['pw_ua']??'');
    if($uid<=0||$last<=0||time()-$last>1800||$agent!==hash('sha256',(string)($_SERVER['HTTP_USER_AGENT']??''))){
        if($uid>0){unset($_SESSION['pw_uid'],$_SESSION['pw_touch'],$_SESSION['pw_ua']);}
        return null;
    }
    // All browser sessions must carry the hash fingerprint issued at login.
    // No legacy passwordless web sessions exist before v1.4. Fail closed on
    // missing fingerprints, missing accounts, DB errors and password rotation.
    $fingerprint=(string)($_SESSION['pw_auth_fingerprint']??'');
    if($fingerprint===''){
        unset($_SESSION['pw_uid'],$_SESSION['pw_touch'],$_SESSION['pw_ua'],$_SESSION['pw_auth_fingerprint']);
        return null;
    }
    try{
        $q=db()->query('SELECT password_hash,telegram_user_id FROM pw_web_accounts WHERE id=1');
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||(int)$row['telegram_user_id']!==$uid||!hash_equals($fingerprint,hash('sha256',(string)$row['password_hash']))){
            unset($_SESSION['pw_uid'],$_SESSION['pw_touch'],$_SESSION['pw_ua'],$_SESSION['pw_auth_fingerprint']);
            return null;
        }
    }catch(Throwable $e){
        unset($_SESSION['pw_uid'],$_SESSION['pw_touch'],$_SESSION['pw_ua'],$_SESSION['pw_auth_fingerprint']);
        return null;
    }
    $_SESSION['pw_touch']=time();
    return $uid;
}
function pw_web_auth_login(PDO $pdo,string $username,string $password,string $remote): bool {
    pw_web_auth_schema($pdo);
    $key=hash_hmac('sha256',$remote,(string)(cfg()['security']['instance_key']??''));
    $look=$pdo->prepare('SELECT failures,locked_until FROM pw_web_login_attempts WHERE identity_hash=?');
    $look->execute([$key]);
    $attempt=$look->fetch(PDO::FETCH_ASSOC);
    if($attempt&&!empty($attempt['locked_until'])&&strtotime((string)$attempt['locked_until'])>time()){
        return false;
    }
    $st=$pdo->prepare('SELECT username,password_hash,telegram_user_id FROM pw_web_accounts WHERE id=1');
    $st->execute();$account=$st->fetch(PDO::FETCH_ASSOC);
    $valid=$account&&hash_equals((string)$account['username'],$username)
      &&password_verify($password,(string)$account['password_hash']);
    if(!$valid){
        $count=min(15,((int)($attempt['failures']??0))+1);
        $lock=$count>=7?date('Y-m-d H:i:s',time()+900):null;
        $q=$pdo->prepare('INSERT INTO pw_web_login_attempts(identity_hash,failures,locked_until)
                          VALUES(?,?,?) ON DUPLICATE KEY UPDATE failures=VALUES(failures),locked_until=VALUES(locked_until)');
        $q->execute([$key,$count,$lock]);
        return false;
    }
    $pdo->prepare('DELETE FROM pw_web_login_attempts WHERE identity_hash=?')->execute([$key]);
    pw_web_auth_begin();
    session_regenerate_id(true);
    $_SESSION['pw_uid']=(int)$account['telegram_user_id'];
    $_SESSION['pw_auth_fingerprint']=hash('sha256',(string)$account['password_hash']);
    $_SESSION['pw_touch']=time();
    $_SESSION['pw_ua']=hash('sha256',(string)($_SERVER['HTTP_USER_AGENT']??''));
    $_SESSION['pw_csrf']=bin2hex(random_bytes(32));
    return true;
}
function pw_web_auth_logout(): void {
    pw_web_auth_begin();
    $_SESSION=[];
    session_regenerate_id(true);
    $_SESSION['pw_csrf']=bin2hex(random_bytes(32));
}
