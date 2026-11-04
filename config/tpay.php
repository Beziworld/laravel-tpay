<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAPI credentials
    |--------------------------------------------------------------------------
    |
    | Client id and secret from the merchant panel (Integration > API > Open
    | API Keys). "security_code" is the notification security code from
    | Settings > Notifications; it is used to check the md5sum of every
    | notification. "merchant_id" (optional) restricts notifications to this
    | merchant account.
    |
    */

    'client_id' => env('TPAY_CLIENT_ID'),

    'client_secret' => env('TPAY_CLIENT_SECRET'),

    'security_code' => env('TPAY_SECURITY_CODE'),

    'merchant_id' => env('TPAY_MERCHANT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Sandbox uses https://openapi.sandbox.tpay.com, production uses
    | https://api.tpay.com. Sandbox credentials are created at
    | https://register.sandbox.tpay.com.
    |
    */

    'sandbox' => (bool) env('TPAY_SANDBOX', true),

    /*
    |--------------------------------------------------------------------------
    | Transaction defaults
    |--------------------------------------------------------------------------
    |
    | Default payer redirect URLs and notification target. When
    | "notification_url" is empty and notifications are enabled, the URL of
    | the package route is used. Tpay honours a per-transaction notification
    | URL only when "Allow override" is enabled in the merchant panel.
    |
    */

    'lang' => env('TPAY_LANG', 'pl'),

    'success_url' => env('TPAY_SUCCESS_URL'),

    'error_url' => env('TPAY_ERROR_URL'),

    'notification_url' => env('TPAY_NOTIFICATION_URL'),

    'notification_email' => env('TPAY_NOTIFICATION_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | HTTP client and token cache
    |--------------------------------------------------------------------------
    |
    | The OAuth access token is cached in the given store (null = default
    | store) until shortly before it expires.
    |
    */

    'http' => [
        'timeout' => (int) env('TPAY_TIMEOUT', 20),
        'connect_timeout' => (int) env('TPAY_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('TPAY_RETRIES', 1),
        'retry_delay' => (int) env('TPAY_RETRY_DELAY', 300),
    ],

    'cache' => [
        'store' => env('TPAY_CACHE_STORE'),
        'prefix' => 'tpay',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | The notification route checks the md5sum and the JWS signature
    | (X-JWS-Signature header, RS256, certificate chained to the Tpay root
    | CA) before dispatching any event. It answers "TRUE", which stops
    | further deliveries. Disabling either check is not recommended.
    |
    */

    'notifications' => [
        'enabled' => (bool) env('TPAY_NOTIFICATIONS_ENABLED', true),
        'path' => env('TPAY_NOTIFICATION_PATH', 'tpay/notification'),
        'name' => 'tpay.notification',
        'middleware' => ['api'],
        'verify_md5' => (bool) env('TPAY_VERIFY_MD5', true),
        'verify_jws' => (bool) env('TPAY_VERIFY_JWS', true),
        'certificate_ttl' => (int) env('TPAY_CERTIFICATE_TTL', 7200),
    ],

];
