<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Gateway Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the API gateway service
    |
    */

    // Gateway name for identification
    'name' => env('GATEWAY_NAME', 'MLGateway'),

    // Origin API URL to proxy requests to
    'origin_url' => env('ORIGIN_API_URL', 'https://dream-api.sendto.you/api'),

    // Request timeout in seconds
    'timeout' => (int) env('ORIGIN_API_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    */

    'rate_limit' => [
        // General API rate limit (requests per minute)
        'per_minute' => (int) env('RATE_LIMIT_PER_MINUTE', 60),

        // Upload endpoint rate limit (requests per minute)
        'upload_per_minute' => (int) env('RATE_LIMIT_UPLOAD_PER_MINUTE', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes Configuration
    |--------------------------------------------------------------------------
    |
    | Define which routes should be proxied and which should be handled locally
    |
    */

    'routes' => [
        // Routes to handle locally (not proxied)
        'local' => [
            'auth/send-sms-code',
        ],

        // Routes to return 404 (not available through gateway)
        'blocked' => [
            'admin',
            'admin/*',
        ],

        // Routes that require special handling (file uploads)
        'uploads' => [
            'upload/image',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Configuration
    |--------------------------------------------------------------------------
    |
    | API Key and IP whitelist for protected endpoints
    |
    */

    'auth' => [
        // API Keys for authentication (comma-separated or array)
        // Leave empty to disable API key check
        'api_keys' => env('GATEWAY_API_KEYS', ''),

        // IP whitelist for protected endpoints (comma-separated or array)
        // Supports: single IP (192.168.1.1), CIDR (192.168.1.0/24), wildcard (192.168.1.*)
        // Leave empty to allow all IPs (when API key is valid)
        'ip_whitelist' => env('GATEWAY_IP_WHITELIST', ''),
    ],
];
