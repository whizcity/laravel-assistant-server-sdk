<?php

namespace Whizcity\AssistantServer\Client;

/**
 * One-time HMAC signature for the central audit collector (FastAPI,
 * app.auth.signature). Both sides share a single algorithm; do not invent
 * a second one.
 *
 * Canonical string to sign (UTF-8, fields joined with "\n", fixed order):
 *
 *     HTTP_METHOD
 *     PATH
 *     QUERY
 *     TIMESTAMP
 *     NONCE
 *     CONTENT_SHA256
 *
 * - HTTP_METHOD is uppercase.
 * - PATH is the path without host or query.
 * - QUERY is the raw query string without "?"; empty string when absent.
 * - TIMESTAMP is Unix seconds (not milliseconds; the server rejects 10+ digits).
 * - NONCE is 8 to 64 characters of [A-Za-z0-9_-].
 * - CONTENT_SHA256 is the lowercase hex SHA-256 of the raw request body,
 *   computed for an empty body as well.
 * - The signature is the lowercase hex of
 *   HMAC-SHA256(appSecret as UTF-8 bytes, canonical string).
 */
class Signer
{
    public const HEADER_APP_ID = 'X-App-Id';
    public const HEADER_TIMESTAMP = 'X-Timestamp';
    public const HEADER_NONCE = 'X-Nonce';
    public const HEADER_CONTENT_SHA256 = 'X-Content-SHA256';
    public const HEADER_SIGNATURE = 'X-Signature';

    /**
     * Build the five signed request headers for a request.
     *
     * @param string $appId
     * @param string $secret
     * @param string $method
     * @param string $path Path without host or query.
     * @param string $body Raw request body; its SHA-256 goes into the signature.
     * @param string $query Raw query string without "?"; empty string when absent.
     * @param string|null $timestamp
     * @param string|null $nonce
     * @return array<string, string>
     */
    public static function headers(
        string $appId,
        string $secret,
        string $method,
        string $path,
        string $body,
        string $query = '',
        ?string $timestamp = null,
        ?string $nonce = null,
    ): array {
        $timestamp = $timestamp ?? (string) time();
        $nonce = $nonce ?? self::defaultNonce();

        $digest = hash('sha256', $body);
        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            $query,
            $timestamp,
            $nonce,
            $digest,
        ]);
        $signature = hash_hmac('sha256', $canonical, $secret);

        return [
            self::HEADER_APP_ID => $appId,
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_CONTENT_SHA256 => $digest,
            self::HEADER_SIGNATURE => $signature,
        ];
    }

    /**
     * 32 [0-9a-f] hex chars: within the server-side [A-Za-z0-9_-]{8,64} pattern.
     */
    private static function defaultNonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
