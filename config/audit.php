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
    | Exponential backoff after a failed batch sync: attempt N is followed by
    | retry_wait_minutes * 2^(N-1) minutes (2 / 4 / 8 / ... minutes).
    |
    */
    'retry_wait_minutes' => 2,
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
