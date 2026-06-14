<?php
return [

    // ===== SMM-TG (backend thực tế) =====
    'smm_tg' => [
        'base_url' => 'https://api.smm-tg.net',
        'api_key'  => 'sub_xxxxxxxxxxxxxxxxxxxxxxxx', // API key do SMM-TG cấp
    ],

    // ===== MySQL =====
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'oklike',
        'user' => 'oklike_user',
        'pass' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // ===== Tỉ giá quy đổi USDT (SMM-TG) -> USD (hiển thị cho VieSMM) =====
    // Nếu coi USDT ~ USD thì để 1.0
    'usdt_to_display_currency' => 1.0,
    'display_currency' => 'USD',

    // ===== Danh sách API key hợp lệ mà VieSMM dùng để gọi oklike.shop =====
    // key => tên client (chỉ để log)
    'client_keys' => [
        'viesmm_xxxxxxxxxxxxxxxxxxxxxxxx' => 'viesmm',
    ],

    // ===== Mapping service (id v2 của oklike.shop) -> SMM-TG time_leave =====
    // mỗi service tương ứng 1 time_leave cố định bên SMM-TG
    'services' => [
        1 => [
            'name'       => 'Telegram Members [Retention 4 days]',
            'category'   => 'Telegram Members',
            'time_leave' => 4,
            'min'        => 500,
            'max'        => 100000,
            // markup % cộng thêm trên giá đã quy đổi từ SMM-TG (vd 20 = +20%)
            'markup_pct' => 20,
            'rate_per_1000' => null, // null = tự tính theo SMM-TG /pricing; hoặc set cố định để khỏi gọi /pricing
        ],
        2 => [
            'name'       => 'Telegram Members [Retention 30 days]',
            'category'   => 'Telegram Members',
            'time_leave' => 30,
            'min'        => 500,
            'max'        => 100000,
            'markup_pct' => 20,
            'rate_per_1000' => null,
        ],
        3 => [
            'name'       => 'Telegram Members [Retention 60 days]',
            'category'   => 'Telegram Members',
            'time_leave' => 60,
            'min'        => 500,
            'max'        => 100000,
            'markup_pct' => 20,
            'rate_per_1000' => null,
        ],
        4 => [
            'name'       => 'Telegram Members [Retention 90 days]',
            'category'   => 'Telegram Members',
            'time_leave' => 90,
            'min'        => 500,
            'max'        => 100000,
            'markup_pct' => 20,
            'rate_per_1000' => null,
        ],
    ],
];
