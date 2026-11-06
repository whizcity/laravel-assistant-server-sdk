<?php

use PHPUnit\Framework\TestCase;
use Whizcity\AssistantServer\Client\Signer;

/**
 * 期望值直接取自服务端实现（assistant-server /app/auth/signature.py），
 * 固定输入、固定输出：任何一端改动导致 six-field canonical 或 HMAC 计算漂移，
 * 这里的字面量断言就会抓住它。期望值的推导不经过被测代码。
 */
final class SignerTest extends TestCase
{
    public function test_post_json_batch_matches_the_server_fixture(): void
    {
        $headers = Signer::headers(
            appId: 'demo-app',
            secret: 'demo-secret',
            method: 'POST',
            path: '/api/v1/audit/logs/batch',
            body: '{"service":"demo","note":"中文"}',
            query: '',
            timestamp: '1700000000',
            nonce: 'n0nce1234567890abcdefghij',
        );

        $this->assertSame([
            'X-App-Id' => 'demo-app',
            'X-Timestamp' => '1700000000',
            'X-Nonce' => 'n0nce1234567890abcdefghij',
            'X-Content-SHA256' => 'f87fc63e6f6225e1e825d948cf1c30f27096510fac3c505828e787eff3c5aa47',
            'X-Signature' => '1d1ed0f780f961b70593c1507b68d32e8b4ecf0455e73025265f8d809e641e95',
        ], $headers);
    }

    public function test_get_with_query_string_signs_the_raw_query_and_the_empty_body(): void
    {
        $headers = Signer::headers(
            appId: 'tester',
            secret: 's3cret-key-x',
            method: 'GET',
            path: '/api/v1/audit/logs',
            body: '',
            query: 'action=update&resource=orders&size=20',
            timestamp: '1893456000',
            nonce: 'getnonce-1234567890abcdef',
        );

        $this->assertSame('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $headers[Signer::HEADER_CONTENT_SHA256]);
        $this->assertSame('bddc28599b00333520d4c5312c3aa8ebdd6b2b028932d629c530a9a8d1ba1503', $headers[Signer::HEADER_SIGNATURE]);
    }

    public function test_defaults_use_unix_seconds_and_a_server_acceptable_nonce(): void
    {
        // 服务端把 >= 10^10 的值按毫秒拒绝（TIMESTAMP_INVALID），把
        // 不满足 [A-Za-z0-9_-]{8,64} 的随机数拒绝（SIGNATURE_INVALID）。
        $headers = Signer::headers('app', 'secret', 'POST', '/api/v1/audit/logs', '{}');

        $this->assertMatchesRegularExpression('/^\d{1,10}$/', $headers[Signer::HEADER_TIMESTAMP]);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{8,64}$/', $headers[Signer::HEADER_NONCE]);
        $this->assertSame('app', $headers[Signer::HEADER_APP_ID]);
        $this->assertSame(64, strlen($headers[Signer::HEADER_SIGNATURE]));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $headers[Signer::HEADER_CONTENT_SHA256]);
    }
}
