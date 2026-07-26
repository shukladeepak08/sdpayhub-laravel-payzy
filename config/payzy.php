<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    */
    'default' => env('PAYZY_DEFAULT_GATEWAY', 'razorpay'),

    /*
    |--------------------------------------------------------------------------
    | Operating Mode
    |--------------------------------------------------------------------------
    |
    | Supported: sandbox, live
    |
    */
    'mode' => env('PAYZY_MODE', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    */
    'currency' => env('PAYZY_CURRENCY', 'INR'),

    /*
    |--------------------------------------------------------------------------
    | Gateway Credentials
    |--------------------------------------------------------------------------
    */
    'gateways' => [

        'razorpay' => [
            'key' => env('RAZORPAY_KEY'),
            'secret' => env('RAZORPAY_SECRET'),
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
            'base_url' => env('RAZORPAY_BASE_URL', 'https://api.razorpay.com/v1'),
        ],

        'stripe' => [
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'base_url' => env('STRIPE_BASE_URL', 'https://api.stripe.com/v1'),
            'api_version' => env('STRIPE_API_VERSION', '2024-06-20'),
        ],

        'paypal' => [
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            'base_url' => env(
                'PAYPAL_BASE_URL',
                env('PAYZY_MODE', 'sandbox') === 'live'
                    ? 'https://api-m.paypal.com'
                    : 'https://api-m.sandbox.paypal.com'
            ),
        ],

        'paytm' => [
            'merchant_id' => env('PAYTM_MERCHANT_ID'),
            'merchant_key' => env('PAYTM_MERCHANT_KEY'),
            'website' => env('PAYTM_WEBSITE', 'WEBSTAGING'),
            'industry_type' => env('PAYTM_INDUSTRY_TYPE', 'Retail'),
            'channel_id' => env('PAYTM_CHANNEL_ID', 'WEB'),
            'base_url' => env(
                'PAYTM_BASE_URL',
                env('PAYZY_MODE', 'sandbox') === 'live'
                    ? 'https://securegw.paytm.in'
                    : 'https://securegw-stage.paytm.in'
            ),
        ],

        'phonepe' => [
            'client_id' => env('PHONEPE_CLIENT_ID'),
            'client_secret' => env('PHONEPE_CLIENT_SECRET'),
            'client_version' => env('PHONEPE_CLIENT_VERSION', '1'),
            'merchant_id' => env('PHONEPE_MERCHANT_ID'),
            'salt_key' => env('PHONEPE_SALT_KEY'),
            'salt_index' => env('PHONEPE_SALT_INDEX', '1'),
            'base_url' => env(
                'PHONEPE_BASE_URL',
                env('PAYZY_MODE', 'sandbox') === 'live'
                    ? 'https://api.phonepe.com/apis/pg'
                    : 'https://api-preprod.phonepe.com/apis/pg-sandbox'
            ),
            'oauth_url' => env(
                'PHONEPE_OAUTH_URL',
                env('PAYZY_MODE', 'sandbox') === 'live'
                    ? 'https://api.phonepe.com/apis/identity-manager'
                    : 'https://api-preprod.phonepe.com/apis/pg-sandbox'
            ),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeouts (seconds)
    |--------------------------------------------------------------------------
    */
    'timeouts' => [
        'connect' => (int) env('PAYZY_CONNECT_TIMEOUT', 10),
        'request' => (int) env('PAYZY_REQUEST_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Retries
    |--------------------------------------------------------------------------
    */
    'retries' => [
        'times' => (int) env('PAYZY_RETRY_TIMES', 3),
        'sleep_milliseconds' => (int) env('PAYZY_RETRY_SLEEP_MS', 200),
        'multiplier' => (float) env('PAYZY_RETRY_MULTIPLIER', 2.0),
        'jitter' => (bool) env('PAYZY_RETRY_JITTER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => (bool) env('PAYZY_LOGGING', true),
        'channel' => env('PAYZY_LOG_CHANNEL', 'stack'),
        'mask_keys' => [
            'key',
            'secret',
            'password',
            'token',
            'authorization',
            'api_key',
            'client_secret',
            'merchant_key',
            'salt_key',
            'webhook_secret',
            'card',
            'cvv',
            'cvc',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        'enabled' => (bool) env('PAYZY_WEBHOOKS_ENABLED', true),
        'prefix' => env('PAYZY_WEBHOOK_PREFIX', 'payzy/webhooks'),
        'middleware' => ['api'],
        'timestamp_tolerance_seconds' => (int) env('PAYZY_WEBHOOK_TOLERANCE', 300),
        'nonce_ttl_seconds' => (int) env('PAYZY_WEBHOOK_NONCE_TTL', 86400),
        'queue' => env('PAYZY_WEBHOOK_QUEUE', 'default'),
        'queue_connection' => env('PAYZY_WEBHOOK_QUEUE_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */
    'idempotency' => [
        'enabled' => (bool) env('PAYZY_IDEMPOTENCY_ENABLED', true),
        'driver' => env('PAYZY_IDEMPOTENCY_DRIVER', 'cache'), // cache|database
        'ttl_seconds' => (int) env('PAYZY_IDEMPOTENCY_TTL', 86400),
        'cache_store' => env('PAYZY_IDEMPOTENCY_CACHE_STORE'),
        'header' => env('PAYZY_IDEMPOTENCY_HEADER', 'Idempotency-Key'),
        'auto_generate' => (bool) env('PAYZY_IDEMPOTENCY_AUTO', false),
    ],

];
