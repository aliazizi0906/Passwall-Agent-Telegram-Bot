<?php
return [
    'base_url' => 'https://YOUR-DOMAIN.example/routerbot',

    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=CPANEL_DB;charset=utf8mb4',
        'user' => 'CPANEL_DB_USER',
        'pass' => 'CHANGE_ME',
    ],

    'telegram' => [
        'bot_token' => '123456789:AA_CHANGE_ME',
        'webhook_secret' => 'CHANGE_ME_LONG_RANDOM',
        'allowed_user_ids' => [123456789],
        'default_device_id' => 'gale-home',
        'alert_chat_id' => 123456789,
        'terminal_enabled' => true,
    ],

    'monitoring' => [
        // cPanel cron should execute server/cron/monitor.php once per minute.
        'offline_after_seconds' => 300,
    ],

    'devices' => [
        'gale-home' => [
            'secret' => 'CHANGE_ME_64_RANDOM_CHARS',
            'enabled' => true,
        ],
    ],
];
