<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Assistant-Server Address
    |--------------------------------------------------------------------------
    |
    | Base address of the Python (FastAPI) assistant-server, without path or
    | trailing slash. API paths live in the client's request() call sites and
    | in each domain client's APIMap.
    | Example: http://assistant-server:8080
    |
    */
    'address' => env('ASSISTANT_SERVER_ADDRESS'),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | App credentials used to sign every request with one-time HMAC
    | signature authentication (X-App-Id / X-Timestamp / X-Nonce /
    | X-Content-SHA256 / X-Signature). Wholesale plain-secret headers are not
    | sent over the wire.
    |
    */
    'app_id'     => env('ASSISTANT_SERVER_APP_ID'),
    'secret_key' => env('ASSISTANT_SERVER_SECRET_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Client Logging (troubleshooting)
    |--------------------------------------------------------------------------
    |
    | Set ASSISTANT_SERVER_LOG=true to trace client activity: every call (API
    | name and arguments) and every wire result (method / path / response
    | code) at debug level, plus transport exceptions at error level. While
    | disabled the client is completely silent.
    |
    */
    'log' => env('ASSISTANT_SERVER_LOG', false),

    /*
    |--------------------------------------------------------------------------
    | Audit Submodule (log module)
    |--------------------------------------------------------------------------
    |
    | The audit pipeline keeps its own namespace under assistant_server.audit.
    |
    */
    'audit' => [
        // Identifier of the current service. Appears in every audit log record.
        'service' => env('AUDIT_SERVICE_NAME', env('APP_NAME', 'unknown-service')),

        // Max sync attempts kept for failed rows; ladder spacing is fixed inside SyncAuditLogs.
        'max_retry' => 5,

        // Fields that are never written into before/after payloads.
        'except' => [
            'password',
            'password_confirmation',
            'remember_token',
            'api_token',
            'secret',
            'id_card',
        ],

        // Local retention (days) for already-synced rows.
        'retention_days' => env('AUDIT_RETENTION_DAYS', 30),

        /*
        | Name of the request attribute that your auth middleware fills with
        | the actor snapshot ("id", "nickname"). Ships with a neutral default
        | so the SDK never hardcodes an internal key; consuming apps point
        | AUDIT_USER_INFO_KEY at the real attribute name set by their middleware.
        */
        'user_info_key' => env('AUDIT_USER_INFO_KEY', 'user'),
    ],
];
