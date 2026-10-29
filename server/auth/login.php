<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib.php';
require dirname(__DIR__).'/web_auth.php';
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net; font-src \'self\' https://cdn.jsdelivr.net; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
$secure=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')
    ||strtolower((string)($_SERVER['REQUEST_SCHEME']??''))==='https'
    ||strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]))==='https';
if(!$secure){
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){
        http_response_code(403);exit('HTTPS is required for password submission.');
    }
    header('Location: '.rtrim((string)(cfg()['base_url']??''),'/').'/auth/login.php',true,302);
    exit;
}
header('Strict-Transport-Security: max-age=31536000');
pw_web_auth_begin();
$error='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if(!pw_web_auth_csrf_ok((string)($_POST['csrf']??'')))$error='Session expired; reload and try again.';
    elseif(isset($_POST['logout'])){
        pw_web_auth_logout();header('Location: login.php',true,303);exit;
    }else{
        $username=trim((string)($_POST['username']??''));
        $password=(string)($_POST['password']??'');
        try{
            if(strlen($username)>32||strlen($password)>256)throw new RuntimeException('Invalid credentials');
            if(!pw_web_auth_login(db(),$username,$password,(string)($_SERVER['REMOTE_ADDR']??'unknown'))){
                $error='نام کاربری یا رمز عبور نادرست است، یا ورود موقتاً محدود شده است.';
            }else{
                header('Location: ../control/',true,303);exit;
            }
        }catch(Throwable $e){$error='ورود انجام نشد. لطفاً اتصال دیتابیس را بررسی کنید.';}
    }
}
$authed=pw_web_auth_user()!==null;
if($authed&&($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Location: ../control/',true,302);exit;}
function auth_h(string $s): string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html>
<html lang="fa" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ورود به PW Control Center</title>
<link rel="stylesheet" href="../assets/vazirmatn.css">
<style>
:root{color-scheme:dark;--bg:#071223;--panel:#11243b;--line:#30455e;--text:#eaf3ff;--muted:#a3b7cf;--accent:#4f8eff}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:radial-gradient(circle at 25% 0%,#213f68 0%,transparent 40%),var(--bg);color:var(--text);font:15px/1.9 'Vazirmatn',Tahoma,system-ui,sans-serif}
main{width:min(460px,calc(100% - 32px));border:1px solid var(--line);padding:32px;border-radius:23px;background:rgba(17,36,59,.94);box-shadow:0 24px 65px #0005}
.brand{display:flex;align-items:center;gap:13px;margin-bottom:25px}.mark{background:linear-gradient(135deg,#397cff,#2dbdb5);color:#fff;padding:7px 14px;border-radius:14px;font-size:22px;font-weight:800}
h1{font-size:24px;margin:0}.muted{color:var(--muted);font-size:13px;margin-top:4px}
label{display:block;font-weight:700;margin-top:19px;margin-bottom:6px}input{width:100%;border:1px solid var(--line);color:var(--text);background:#0b1a2e;border-radius:12px;padding:13px 15px;font:inherit;direction:ltr;text-align:left}input:focus{outline:2px solid var(--accent);outline-offset:1px}
button{width:100%;font:inherit;font-weight:700;padding:12px;margin-top:25px;border-radius:13px;background:var(--accent);color:#fff;border:0;cursor:pointer}
.error{border:1px solid #bc5868;background:#69202d3d;color:#ffc4cc;padding:12px;border-radius:10px}
small{color:var(--muted)}a{color:#95c2ff}footer{margin-top:25px;font-size:12px;color:var(--muted);border-top:1px solid var(--line);padding-top:15px}
</style><link rel="stylesheet" href="../assets/login-v14.css"><script src="../assets/login-v14.js" defer></script></head><body><main class="auth-shell">
<aside class="auth-story" aria-label="معرفی مرکز کنترل"><div class="auth-kicker"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 10h2M7 14h2M16 10h2M16 14h2" stroke-linecap="round"/></svg> PW CONTROL / OPENWRT</div><div><h2>یک مرکز کنترل.<br>تمام روترهای شما.</h2><p>پایش شبکه، مدیریت روترها و عملیات مجاز از طریق مرورگر؛ بدون وابستگی به باز بودن تلگرام و بدون نیاز به پورت مدیریتی WAN.</p></div><div class="auth-benefits"><div><span>✓</span> نشست HTTPS و محدودیت تلاش‌های ورود</div><div><span>✓</span> مدیریت چند روتر با کنترل سطح دسترسی</div><div><span>✓</span> نمایش قابلیت‌های واقعی هر سخت‌افزار OpenWrt</div></div></aside>
<section class="auth-form">
<div class="brand"><div class="mark">PW</div><div><h1>مرکز کنترل روتر</h1><p class="muted">ورود مستقل به پنل مدیریت OpenWrt</p></div></div>
<?php if($error!==''):?><p class="error" role="alert"><?=auth_h($error)?></p><?php endif;?>
<?php if($authed):?>
<p>شما وارد پنل شده‌اید.</p><a href="../control/">رفتن به داشبورد</a>
<form method="post"><input type="hidden" name="csrf" value="<?=auth_h(pw_web_auth_csrf())?>"><button name="logout" value="1">خروج ایمن</button></form>
<?php else:?>
<form method="post" autocomplete="on">
<input type="hidden" name="csrf" value="<?=auth_h(pw_web_auth_csrf())?>">
<label for="username">نام کاربری</label><input id="username" name="username" autocomplete="username" maxlength="32" required autofocus>
<label for="password">رمز عبور</label><div class="password-field"><input id="password" name="password" type="password" autocomplete="current-password" maxlength="256" required><button type="button" class="password-toggle" id="revealPassword" aria-controls="password" aria-pressed="false">نمایش</button></div>
<button type="submit">ورود به پنل</button>
</form>
<footer>ورود فقط از طریق HTTPS و نشست امن انجام می‌شود. رمز اولیه در نصب تازه تنظیم می‌شود؛ برای فعال‌سازی روی نصب قبلی، از حساب Owner داخل Telegram Mini App استفاده کنید.</footer>
<?php endif;?>
</section></main>
</body></html>
