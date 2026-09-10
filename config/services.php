<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'biteship' => [
        'api_key' => env('BITESHIP_API_KEY'),
        'base_url' => rtrim(preg_replace('#/v1(/orders)?/?$#', '', (string) env('BITESHIP_BASE_URL', 'https://api.biteship.com')), '/'),
        'origin_postal_code' => (int) env('BITESHIP_ORIGIN_POSTAL_CODE', 12220),
        'origin_area_id' => env('BITESHIP_ORIGIN_AREA_ID', 'IDNP6IDNC417IDND2093IDNZ12220'),
        'origin_address' => env('BITESHIP_ORIGIN_ADDRESS', 'Jl. Kebayoran Lama No. 12, Jakarta Selatan'),
        'origin_contact_name' => env('BITESHIP_ORIGIN_CONTACT_NAME', 'NOBYDERM Store'),
        'origin_contact_phone' => env('BITESHIP_ORIGIN_CONTACT_PHONE', '081234567890'),
        'timeout' => (int) env('BITESHIP_TIMEOUT', 10),
        'free_shipping_min_spend' => (float) env('SHIPPING_FREE_MIN_SPEND', 500000),
        'webhook_signature_key' => env('BITESHIP_WEBHOOK_SIGNATURE_KEY', 'X-Biteship-Signature'),
        'webhook_secret' => env('BITESHIP_WEBHOOK_SECRET', ''),
    ],

    'midtrans' => [
        'enabled' => (bool) env('MIDTRANS_ENABLED', false),
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
        'snap_base_url' => env('MIDTRANS_IS_PRODUCTION', false)
            ? 'https://app.midtrans.com'
            : 'https://app.sandbox.midtrans.com',
        'api_base_url' => env('MIDTRANS_IS_PRODUCTION', false)
            ? 'https://api.midtrans.com/v2/'
            : 'https://api.sandbox.midtrans.com/v2/',
    ],

];
