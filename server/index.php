<?php
declare(strict_types=1);

if(!is_file(__DIR__.'/config.php')){
    header('Location: setup.php',true,302);
    exit;
}

require __DIR__.'/lib.php';
header('Cache-Control: no-store');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PW Agent</title>
<style>
:root{color-scheme:dark;--bg:#0b0f14;--card:#131a22;--line:#283747;--text:#eef4fb;--muted:#8fa3b8;--accent:#58a6ff}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.55 system-ui,-apple-system,Segoe UI,sans-serif}main{max-width:760px;margin:auto;padding:28px}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;margin:14px 0}.muted{color:var(--muted)}a{color:var(--accent)}code{background:#080b0f;border:1px solid var(--line);border-radius:7px;padding:2px 6px}
</style>
</head>
<body><main>
<h1>PW Agent <?=htmlspecialchars(app_version(),ENT_QUOTES,'UTF-8')?></h1>
<div class="card">
<b>Backend installed</b>
<p class="muted">This directory is a PW Agent backend installation. Management is performed from Telegram and the Telegram Mini App Dashboard.</p>
<p><a href="health.php">Health check</a></p>
</div>
<div class="card">
<b>Security</b>
<p class="muted">Browser Setup is automatically locked while <code>config.php</code> exists. Do not publish or copy runtime secrets into public files.</p>
</div>
</main></body></html>
