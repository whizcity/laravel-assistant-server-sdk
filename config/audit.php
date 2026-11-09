<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Service Name
    |--------------------------------------------------------------------------
    |
    | Identifier of the current service. Appears in every audit log record.
    |
    */
    'service' => env('AUDIT_SERVICE_NAME', env('APP_NAME', 'unknown-service')),

    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    |
    | Backoff waits follow the fixed ladder baked into SyncAuditLogs
    | (2 / 5 / 15 / 30 / 60 minutes per failed attempt), not a config knob.
    |
    */
    'max_retry' => 5,

    /*
    |--------------------------------------------------------------------------
    | Fields that are never logged
    |--------------------------------------------------------------------------
    */
    'except' => [
        'password',
        'password_confirmation',
        'remember_token',
        'api_token',
        'secret',
        'id_card',
    ],

    /*
    |--------------------------------------------------------------------------
    | Local retention (days) for already-synced rows
    |--------------------------------------------------------------------------
    */
    'retention_days' => env('AUDIT_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Operator Snapshot Request Key
    |--------------------------------------------------------------------------
    |
    | Name of the request attribute that your auth middleware fills with the
    | actor snapshot ("id", "nickname"). Ships with a neutral default so the
    | SDK never hardcodes an internal key; consuming apps point
    | AUDIT_USER_INFO_KEY at the real attribute name set by their middleware.
    |
    */
    'user_info_key' => env('AUDIT_USER_INFO_KEY', 'user'),
];
