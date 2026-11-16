<?php

/*
|--------------------------------------------------------------------------
| DutCh’M — configuration métier
|--------------------------------------------------------------------------
| Toutes les valeurs proviennent du fichier .env (voir .env.example).
*/

return [
    'web_public_url' => rtrim(env('WEB_PUBLIC_URL', env('APP_URL', 'http://localhost:8000')), '/'),
    'admin_public_url' => rtrim(env('ADMIN_PUBLIC_URL', env('APP_URL', 'http://localhost:8000').'/admin'), '/'),
    'cors_origins' => array_values(array_filter(array_map('trim', explode(',', env('CORS_ORIGINS', 'http://localhost:5173'))))),

    'jwt' => [
        'admin_secret' => env('JWT_ADMIN_ACCESS_SECRET'),
        'customer_secret' => env('JWT_CUSTOMER_ACCESS_SECRET'),
        'temp_secret' => env('JWT_TEMP_SECRET'),
        'access_ttl' => (int) env('JWT_ACCESS_TTL', 900),
    ],
    'refresh_ttl_days' => (int) env('REFRESH_TTL_DAYS', 30),
    'cookie_secure' => filter_var(env('COOKIE_SECURE', false), FILTER_VALIDATE_BOOL),
    'cookie_domain' => env('COOKIE_DOMAIN') ?: null,
    'totp_issuer' => env('TOTP_ISSUER', 'DutCh’M Admin'),

    'upload_dir' => env('UPLOAD_DIR', 'storage/app/uploads'),
    'upload_max_mb' => (int) env('UPLOAD_MAX_MB', 8),

    'sms' => [
        'provider' => env('SMS_PROVIDER', 'log'),
        'api_url' => env('SMS_API_URL', ''),
        'api_key' => env('SMS_API_KEY', ''),
        'sender' => env('SMS_SENDER', 'DUTCHM'),
    ],
    'whatsapp' => [
        'provider' => env('WHATSAPP_PROVIDER', 'log'),
        'api_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v20.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID', ''),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN', ''),
    ],

    'payments' => [
        'mode' => env('PAYMENT_MODE', 'mock'),
        'mock_webhook_secret' => env('MOCK_WEBHOOK_SECRET', 'mock-webhook-secret'),
        'airtel' => [
            'base_url' => env('AIRTEL_BASE_URL', 'https://openapiuat.airtel.africa'),
            'client_id' => env('AIRTEL_CLIENT_ID', ''),
            'client_secret' => env('AIRTEL_CLIENT_SECRET', ''),
            'country' => env('AIRTEL_COUNTRY', 'GA'),
            'currency' => env('AIRTEL_CURRENCY', 'XAF'),
            'webhook_secret' => env('AIRTEL_WEBHOOK_SECRET', ''),
        ],
        'moov' => [
            'base_url' => env('MOOV_BASE_URL', ''),
            'merchant_id' => env('MOOV_MERCHANT_ID', ''),
            'api_key' => env('MOOV_API_KEY', ''),
            'api_secret' => env('MOOV_API_SECRET', ''),
            'webhook_secret' => env('MOOV_WEBHOOK_SECRET', ''),
        ],
    ],

    'throttle_limit' => (int) env('THROTTLE_LIMIT', 120),

    'seed_admin' => [
        'full_name' => env('SEED_ADMIN_FULL_NAME', 'Super admin DutCh’M'),
        'email' => env('SEED_ADMIN_EMAIL', 'superadmin@amoria.ga'),
        // « ?: » : une variable présente mais vide dans le .env ne doit pas créer un mot de passe vide.
        'password' => env('SEED_ADMIN_PASSWORD') ?: '12345678',
    ],
];
