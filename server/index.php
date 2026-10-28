<?php
declare(strict_types=1);

if(!is_file(__DIR__.'/config.php')){
    header('Location: setup.php',true,302);
    exit;
}
require __DIR__.'/lib.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$version=htmlspecialchars(app_version(),ENT_QUOTES,'UTF-8');
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>PW Control Center · <?= $version ?></title>
<link rel="stylesheet" href="assets/vazirmatn.css">
<style>
:root{color-scheme:light;--bg:#f6f8fc;--card:#fff;--text:#172941;--muted:#65758d;--line:#dce5ef;--accent:#2263ca}
@media(prefers-color-scheme:dark){:root{color-scheme:dark;--bg:#0c1729;--card:#16243a;--text:#e5eeff;--muted:#93a8c3;--line:#2b3c55;--accent:#82b1ff}}
*{box-sizing:border-box}body{font:14px/1.8 'Vazirmatn',Tahoma,system-ui,sans-serif;margin:0;background:var(--bg);color:var(--text)}
main{max-width:900px;margin:auto;padding:50px 18px}.brand{display:flex;align-items:center;gap:14px;margin-bottom:20px}
.logo{background:linear-gradient(120deg,#2563eb,#0ca6a6);border-radius:14px;width:56px;height:56px;display:grid;place-items:center;font-size:18px;color:#fff;font-weight:900}
h1{font-size:27px;margin:0}.sub{color:var(--muted)}.layout{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.card{padding:23px;border:1px solid var(--line);border-radius:18px;background:var(--card)}.card h2{margin:0 0 8px;font-size:18px}.card p{color:var(--muted)}
a{color:var(--accent);font-weight:700;text-decoration:none;word-wrap:break-word}a:hover{text-decoration:underline}.note{margin-top:16px;background:var(--card);border:1px solid var(--line);border-right:4px solid var(--accent);border-radius:11px;padding:14px}
small{color:var(--muted)}@media(max-width:620px){.layout{grid-template-columns:1fr}main{padding:26px 15px}}
</style>
</head>
<body><main>
<div class="brand"><div class="logo">PW</div><div><h1>مرکز کنترل PW Agent</h1><div class="sub">Self-hosted OpenWrt Control Center · v<?= $version ?></div></div></div>
<div class="layout">
<section class="card"><h2>پنل مدیریتی جدید · PW Control</h2><p>پنل فارسی/انگلیسی، نظارت بر شبکه و روتر، Passwall2، دستگاه‌های LAN، Wi-Fi و عیب‌یابی شبکه.</p>
<a href="auth/login.php">ورود با نام کاربری و رمز →</a> · <a href="control/">Open Control Center →</a></section>
<section class="card"><h2>داشبورد تلگرام · Fleet</h2><p>مدیریت روترهای ثبت‌شده، مجوز کاربران، Monitoring، Alerts و Schedule.</p>
<a href="dashboard/">Telegram Dashboard →</a></section>
<section class="card"><h2>تست سلامت Backend</h2><p>بررسی اتصال PHP به MySQL و وضعیت Schema و نسخه نصب‌شده.</p>
<a href="health.php">Backend Health Check →</a></section>
<section class="card"><h2>وضعیت نصب و امنیت</h2><p>فایل `config.php` موجود است و Web Setup قفل شده. هیچ Token یا Secret در این صفحه نمایش داده نمی‌شود.</p>
<a href="setup.php">Setup (locked) →</a></section>
</div>
<div class="note"><strong>نحوه ورود به پنل / Authentication:</strong> پنل وب با نام کاربری و رمز تعیین‌شده در Setup یا از طریق <strong>Telegram Mini App</strong> با احراز هویت معتبر در دسترس است. اطلاعات ورود وب در دیتابیس به‌صورت Hash ذخیره می‌شود. برای فعال‌کردن ورود روی نصب قدیمی، از حساب Owner داخل Mini App قسمت System استفاده کن.</div>
<p><small>PW Agent v<?= $version ?> · Hosted cPanel-compatible PHP/MySQL backend · Router connects using outbound HTTPS/HMAC</small></p>
</main></body></html>
