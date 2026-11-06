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
    'address' => env('ASSISTANT_SERVER_ADDRESS', (function () {
        // 旧变量 AUDIT_ENDPOINT 语义是"完整路径前缀"（如 http://host:8080/api/v1/logs），
        // 兼容期从中剥出服务地址。
        $endpoint = env('AUDIT_ENDPOINT');
        if (empty($endpoint) || ($parts = parse_url($endpoint)) === false || empty($parts['host'])) {
            return null;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . $port;
    })()),

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
];
