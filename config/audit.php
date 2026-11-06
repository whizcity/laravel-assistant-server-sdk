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
];
