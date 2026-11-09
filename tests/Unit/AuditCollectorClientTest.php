<?php

use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Whizcity\AssistantServer\AssistantServerServiceProvider;
use Whizcity\AssistantServer\Client\AuditCollectorClient;

/**
 * Captures the real outgoing PSR-7 request at the transport boundary via a
 * Guzzle handler. Expected signatures are derived independently from the
 * observed headers plus standard hash functions, without calling Signer —
 * built to catch "signed thing ≠ sent thing" class bugs.
 */
final class AuditCollectorClientTest extends TestCase
{
    /** @var RequestInterface[] */
    private array $requests = [];

    protected function getPackageProviders($app): array
    {
        return [AssistantServerServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'assistant_server.address' => 'http://audit-collector.test',
            'assistant_server.app_id' => 'demo-app',
            'assistant_server.secret_key' => 'demo-secret',
        ]);
    }

    private function client(): AuditCollectorClient
    {
        return new AuditCollectorClient([
            'handler' => function (RequestInterface $request, array $options) {
                $this->requests[] = $request;
                return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"accepted":2,"failed":0}');
            },
        ]);
    }

    private function expectedSignature(RequestInterface $request): string
    {
        $canonical = implode("\n", [
            $request->getMethod(),
            $request->getUri()->getPath(),
            $request->getUri()->getQuery(),
            $request->getHeaderLine('X-Timestamp'),
            $request->getHeaderLine('X-Nonce'),
            hash('sha256', (string) $request->getBody()),
        ]);

        return hash_hmac('sha256', $canonical, 'demo-secret');
    }

    public function test_sync_batch_signs_and_posts_the_exact_wire_json(): void
    {
        $result = $this->client()->syncBatch([['service' => 'demo', 'note' => '中文']]);

        $request = $this->requests[0] ?? null;
        $this->assertNotNull($request, 'no HTTP request was sent');
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v1/audit/logs/batch', $request->getUri()->getPath());
        $this->assertSame('http://audit-collector.test/api/v1/audit/logs/batch', (string) $request->getUri());
        $this->assertSame('[{"service":"demo","note":"中文"}]', (string) $request->getBody());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('demo-app', $request->getHeaderLine('X-App-Id'));
        $this->assertSame(hash('sha256', '[{"service":"demo","note":"中文"}]'), $request->getHeaderLine('X-Content-SHA256'));
        $this->assertMatchesRegularExpression('/^\d{1,10}$/', $request->getHeaderLine('X-Timestamp'));
        $this->assertSame($this->expectedSignature($request), $request->getHeaderLine('X-Signature'));

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['accepted' => 2, 'failed' => 0], $result->getData());
    }

    public function test_query_sends_a_get_whose_query_string_appears_in_the_signature(): void
    {
        $result = $this->client()->query(['action' => 'update', 'resource' => 'orders', 'size' => 20]);

        $request = $this->requests[0] ?? null;
        $this->assertNotNull($request, 'no HTTP request was sent');
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/api/v1/audit/logs', $request->getUri()->getPath());
        $this->assertSame('action=update&resource=orders&size=20', $request->getUri()->getQuery());
        $this->assertSame('', (string) $request->getBody());
        $this->assertSame('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $request->getHeaderLine('X-Content-SHA256'));
        $this->assertSame($this->expectedSignature($request), $request->getHeaderLine('X-Signature'));

        $this->assertTrue($result->isSuccess());
    }

    public function test_get_substitutes_the_id_into_the_signed_path(): void
    {
        $this->client()->get('5');

        $request = $this->requests[0] ?? null;
        $this->assertNotNull($request, 'no HTTP request was sent');
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/api/v1/audit/logs/5', $request->getUri()->getPath());
        $this->assertSame($this->expectedSignature($request), $request->getHeaderLine('X-Signature'));
    }

    public function test_unknown_api_name_is_reported_without_sending(): void
    {
        $result = $this->client()->syncShout(['x' => 1]);

        $this->assertCount(0, $this->requests);
        $this->assertFalse($result->isSuccess());
        $this->assertSame('VALIDATION_ERROR', $result->getCode());
        $this->assertStringContainsString('syncShout', $result->getMessage());
    }

    public function test_missing_configuration_is_reported_without_sending(): void
    {
        config(['assistant_server.address' => null]);
        $result = $this->client()->syncBatch(['service' => 'demo']);
        $this->assertCount(0, $this->requests);
        $this->assertFalse($result->isSuccess());
        $this->assertSame('CONFIG_ERROR', $result->getCode());

        config(['assistant_server.address' => 'http://audit-collector.test', 'assistant_server.app_id' => null]);
        $result = $this->client()->syncBatch(['service' => 'demo']);
        $this->assertCount(0, $this->requests);
        $this->assertFalse($result->isSuccess());
        $this->assertSame('CONFIG_ERROR', $result->getCode());
    }

    public function test_http_error_status_falls_back_to_status_and_body_snippet(): void
    {
        $result = new AuditCollectorClient([
            'handler' => function (RequestInterface $request, array $options) {
                return new \GuzzleHttp\Psr7\Response(502, ['Content-Type' => 'text/html'], 'Bad Gateway');
            },
        ])->syncBatch(['service' => 'demo']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(502, $result->getCode());
        $this->assertSame('Bad Gateway', $result->getMessage());
    }

    public function test_transport_exception_is_reported_as_internal_error(): void
    {
        $result = new AuditCollectorClient([
            'handler' => function (RequestInterface $request, array $options) {
                throw new \RuntimeException('boom');
            },
        ])->syncBatch(['service' => 'demo']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('INTERNAL_ERROR', $result->getCode());
        $this->assertStringContainsString('boom', $result->getMessage());
    }

    public function test_sync_posts_a_single_log_as_json(): void
    {
        $captured = null;
        $result = new AuditCollectorClient([
            'handler' => function (RequestInterface $request, array $options) use (&$captured) {
                $captured = $request;
                return new \GuzzleHttp\Psr7\Response(200, [], '{"status":"ok"}');
            },
        ])->sync(['service' => 'demo']);

        $this->assertNotNull($captured, 'no HTTP request was sent');
        $this->assertSame('POST', $captured->getMethod());
        $this->assertSame('/api/v1/audit/logs', $captured->getUri()->getPath());
        $this->assertSame('{"service":"demo"}', (string) $captured->getBody());
        $this->assertTrue($result->isSuccess());
    }

    public function test_request_is_a_signed_post_with_a_json_body_for_any_path(): void
    {
        $result = $this->client()->request('post', '/api/v1/audit/logs/batch', [['service' => 'demo', 'note' => '中文']]);

        $request = $this->requests[0] ?? null;
        $this->assertNotNull($request, 'no HTTP request was sent');
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v1/audit/logs/batch', $request->getUri()->getPath());
        $this->assertSame('[{"service":"demo","note":"中文"}]', (string) $request->getBody());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame($this->expectedSignature($request), $request->getHeaderLine('X-Signature'));

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['accepted' => 2, 'failed' => 0], $result->getData());
    }

    public function test_request_is_a_signed_get_whose_query_participates_in_the_signature(): void
    {
        $result = $this->client()->request('get', '/api/v1/audit/logs', ['action' => 'update', 'resource' => 'orders', 'size' => 20]);

        $request = $this->requests[0] ?? null;
        $this->assertNotNull($request, 'no HTTP request was sent');
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/api/v1/audit/logs', $request->getUri()->getPath());
        $this->assertSame('action=update&resource=orders&size=20', $request->getUri()->getQuery());
        $this->assertSame('', (string) $request->getBody());
        $this->assertSame($this->expectedSignature($request), $request->getHeaderLine('X-Signature'));

        $this->assertTrue($result->isSuccess());
    }

    public function test_client_stays_completely_silent_when_audit_log_is_disabled(): void
    {
        config(['assistant_server.log' => false]);
        Log::spy();

        $result = $this->client()->syncBatch([['service' => 'demo']]);

        $this->assertTrue($result->isSuccess());
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('error');
    }

    public function test_transport_exception_is_reported_without_logging_when_disabled(): void
    {
        config(['assistant_server.log' => false]);
        Log::spy();

        $result = new AuditCollectorClient([
            'handler' => function (RequestInterface $request, array $options) {
                throw new \RuntimeException('boom');
            },
        ])->syncBatch(['service' => 'demo']);

        $this->assertSame('INTERNAL_ERROR', $result->getCode());
        Log::shouldNotHaveReceived('error');
    }

    public function test_client_logs_the_call_and_the_result_when_enabled(): void
    {
        config(['assistant_server.log' => true]);
        Log::spy();

        $this->client()->syncBatch([['service' => 'demo']]);

        Log::shouldHaveReceived('debug')->twice();
        Log::shouldNotHaveReceived('error');
    }

    public function test_transport_exception_is_logged_as_error_when_enabled(): void
    {
        config(['assistant_server.log' => true]);
        Log::spy();

        $result = new AuditCollectorClient([
            'handler' => function (RequestInterface $request, array $options) {
                throw new \RuntimeException('boom');
            },
        ])->syncBatch(['service' => 'demo']);

        $this->assertSame('INTERNAL_ERROR', $result->getCode());
        Log::shouldHaveReceived('error');
    }
}
