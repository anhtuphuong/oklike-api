<?php

declare(strict_types=1);

return [
    'smm_tg' => [
        'base_url' => 'https://api.smm-tg.net',
        'api_key' => 'CHANGE_ME_SMM_TG_API_KEY',
        'timeout' => 20,
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'oklike_api',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'client_keys' => [
        'CHANGE_ME_VIESMM_KEY',
    ],
    'currency' => [
        'display_currency' => 'USD',
        'usdt_to_display_currency' => 1.0,
    ],
    'services' => [
        // service_id => [
        //     'time_leave' => 30, // allowed: 4, 30, 60, 90
        //     'markup_pct' => 10,
        //     // Optional fixed selling rate per 1000 in USDT. If set, /pricing is ignored for this service.
        //     // 'rate_per_1000' => 2.50,
        //     // Optional overrides
        //     // 'name' => 'Instagram Followers',
        //     // 'min' => 100,
        //     // 'max' => 100000,
        // ]
        101 => [
            'time_leave' => 30,
            'markup_pct' => 10,
        ],
    ],
];
