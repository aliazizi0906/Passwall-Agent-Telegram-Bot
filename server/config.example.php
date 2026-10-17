<?php
return [
    // Public base URL, without trailing slash.
    'base_url' => 'https://YOUR-DOMAIN.example/routerbot',

    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=CPANEL_DB;charset=utf8mb4',
        'user' => 'CPANEL_DB_USER',
        'pass' => 'CHANGE_ME',
    ],

    'telegram' => [
        'bot_token' => '123456789:AA_CHANGE_ME',
        // Generate a long random value and set the same value when setting the Telegram webhook.
        'webhook_secret' => 'CHANGE_ME_LONG_RANDOM',
        // Numeric Telegram user IDs only.
        'allowed_user_ids' => [123456789],
        'default_device_id' => 'gale-home',
        // Optional: where autonomous failover alerts are sent. Defaults to the first allowed user.
        'alert_chat_id' => 123456789,
        // Enables the Telegram Mini App SSH console. HTTPS base_url is required.
        'terminal_enabled' => true,
    ],

    // Device IDs and secrets must match /etc/pw-agent/config.json on OpenWrt.
    // For v1 the secret is kept here instead of in the database, reducing accidental DB exposure.
    'devices' => [
        'gale-home' => [
            'secret' => 'CHANGE_ME_64_RANDOM_CHARS',
            'enabled' => true,
        ],
    ],
];
