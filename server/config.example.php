<?php
return [
    // The public HTTPS URL where the contents of server/ are deployed.
    'base_url' => 'https://example.com/routerbot',

    'db' => [
        'dsn' => 'mysql:host=localhost;port=3306;dbname=PW_AGENT;charset=utf8mb4',
        'user' => 'PW_AGENT_USER',
        'pass' => 'CHANGE_ME',
    ],

    'telegram' => [
        'bot_token' => 'CHANGE_ME',
        'webhook_secret' => 'CHANGE_ME',
        'allowed_user_ids' => [123456789],
        'default_device_id' => 'router-01',
        'alert_chat_id' => 123456789,
        'terminal_enabled' => true,
    ],

    'monitoring' => [
        'offline_after_seconds' => 300,
    ],

    // Use server/bin/setup.php instead of editing these values manually.
    'devices' => [
        'router-01' => [
            'secret' => 'CHANGE_ME_64_RANDOM_HEX',
            'enabled' => true,
        ],
    ],
];
