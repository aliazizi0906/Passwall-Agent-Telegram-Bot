<?php
declare(strict_types=1);
require dirname(__DIR__).'/web_auth.php';
function cfg(): array {
    return [
      'base_url'=>'https://example.test/pw-agent',
      'security'=>['instance_key'=>base64_encode(str_repeat('A',32))],
    ];
}
$GLOBALS['pw_test_database']=null;
function db(): PDO {
    if(!($GLOBALS['pw_test_database'] instanceof PDO))throw new RuntimeException('Test database is not connected');
    return $GLOBALS['pw_test_database'];
}
$_SERVER['HTTP_USER_AGENT']='PW-Agent-CI';
function expect_web(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);}
foreach([
 ['owner1','A-long-secure-password',true],
 ['a.b-c','0123456789ABCDE',true],
 ['Admin','longlonglonglong',false],
 ['a','longlonglonglong',false],
 ['u','short',false],
 ['bad/username','longlonglonglong',false],
] as [$name,$pass,$valid]){
    try{pw_web_auth_validate_credentials($name,$pass);$result=true;}
    catch(RuntimeException $e){$result=false;}
    expect_web($valid===$result,"Invalid validation result for $name");
}
expect_web(pw_web_auth_cookie_path()==='/pw-agent/','Session cookie path');
pw_web_auth_begin();
$first=pw_web_auth_csrf();
expect_web(strlen($first)===64,'CSRF entropy');
expect_web(pw_web_auth_csrf_ok($first),'CSRF valid');
expect_web(!pw_web_auth_csrf_ok('bad'),'CSRF invalid');
$_SESSION['pw_uid']=123456;
$_SESSION['pw_touch']=time();
$_SESSION['pw_ua']=hash('sha256','PW-Agent-CI');
expect_web(pw_web_auth_user()===123456,'Valid browser session');
$_SESSION['pw_touch']=time()-1900;
expect_web(pw_web_auth_user()===null,'Expired browser session');
pw_web_auth_logout();
expect_web(pw_web_auth_user()===null,'Logout invalidates session');
expect_web(!pw_web_auth_csrf_ok($first),'Logout rotates CSRF');

$dsn=(string)(getenv('PW_TEST_MYSQL_DSN')?:'');
if($dsn!==''){
    $pdo=new PDO($dsn,(string)getenv('PW_TEST_MYSQL_USER'),(string)getenv('PW_TEST_MYSQL_PASSWORD'),[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
    $GLOBALS['pw_test_database']=$pdo;
    $pdo->exec('DROP TABLE IF EXISTS pw_web_login_attempts');
    $pdo->exec('DROP TABLE IF EXISTS pw_web_accounts');
    pw_web_auth_install($pdo,'admin','Testing-Unique-Password_123',123456);
    $account=$pdo->query('SELECT username,password_hash,telegram_user_id FROM pw_web_accounts')->fetch();
    expect_web($account['username']==='admin'&&(int)$account['telegram_user_id']===123456,'Owner mapping');
    expect_web($account['password_hash']!=='Testing-Unique-Password_123','Password must not be plaintext');
    expect_web(password_verify('Testing-Unique-Password_123',$account['password_hash']),'Password hash correctness');
    expect_web(!pw_web_auth_login($pdo,'admin','bad','127.0.0.1'),'Wrong password refused');
    expect_web(pw_web_auth_login($pdo,'admin','Testing-Unique-Password_123','127.0.0.1'),'Right password accepted');
    expect_web(pw_web_auth_user()===123456,'Authenticated browser session persisted');
    pw_web_auth_logout();
    for($i=0;$i<7;$i++){expect_web(!pw_web_auth_login($pdo,'admin','bad','127.0.0.2'),'Brute-force rejection');}
    expect_web(!pw_web_auth_login($pdo,'admin','Testing-Unique-Password_123','127.0.0.2'),'Lockout enforced');
    expect_web(pw_web_auth_login($pdo,'admin','Testing-Unique-Password_123','127.0.0.3'),'Second session before rotation');
    pw_web_auth_install($pdo,'admin','New-Secure-Password_789',123456);
    expect_web(pw_web_auth_user()===null,'Password reset must revoke existing web session');
    expect_web(pw_web_auth_login($pdo,'admin','New-Secure-Password_789','127.0.0.3'),'Credential rotation');
    $pdo->exec('DROP TABLE pw_web_login_attempts');
    $pdo->exec('DROP TABLE pw_web_accounts');
}
echo "Web auth validation, cookie scope, session, CSRF, logout".($dsn?' and MySQL credential/lockout':"").": OK\n";
