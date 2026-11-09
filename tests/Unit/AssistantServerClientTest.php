<?php

use Orchestra\Testbench\TestCase;
use Psr\Http\Message\RequestInterface;
use Whizcity\AssistantServer\AssistantServerServiceProvider;
use Whizcity\AssistantServer\Client\AssistantServerClient;

/**
 * Captures the real outgoing PSR-7 request at the transport boundary via a
 * Guzzle handler. Expected signatures are derived independently from the
 * observed headers plus standard hash functions, without calling Signer —
 * built to catch "signed thing ≠ sent thing" class bugs.
 */
final class AssistantServerClientTest extends TestCase
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
            'assistant_server.address' => 'http://assistant-server.test',
            'assistant_server.app_id' => 'demo-app',
            'assistant_server.secret_key' => 'demo-secret',
        ]);
    }

    private function client(): AssistantServerClient
    {
        return new AssistantServerClient([
            'handler' => function (RequestInterface $request, array $options) {
                $this->requests[] = $request;
                return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'],
                    '{"text":"hello","items":[{"text":"hello"}],"words":[],"elapse":0.12}');
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

    public function test_request_multipart_signs_the_wire_body_and_carries_the_file(): void
    {
        $png = "\x89PNG\r\n\x1a\nfake-image-bytes";
        $result = $this->client()->requestMultipart('post', '/api/v1/ocr', 'file', 'receipt.png', $png, ['return_word_box' => 'true']);

        $request = $this->requests[0] ?? null;
        $this->assertNotNull($request, 'no HTTP request was sent');
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v1/ocr', $request->getUri()->getPath());
        $this->assertMatchesRegularExpression('#^multipart/form-data; boundary=.+#', $request->getHeaderLine('Content-Type'));

        $body = (string) $request->getBody();
        // The filename and raw multipart bytes go into the wire body; the
        // extra form fields are on board too.
        $this->assertStringContainsString('Content-Disposition: form-data; name="file"; filename="receipt.png"', $body);
        $this->assertStringContainsString('Content-Disposition: form-data; name="return_word_box"', $body);
        $this->assertStringContainsString('true', $body);
        $this->assertStringContainsString($png, $body);
        // The signature covers the exact raw multipart bytes sent on the wire,
        // matching the server-side verification exactly.
        $this->assertSame(hash('sha256', $body), $request->getHeaderLine('X-Content-SHA256'));
        $this->assertSame($this->expectedSignature($request), $request->getHeaderLine('X-Signature'));

        $this->assertTrue($result->isSuccess());
        $this->assertSame('hello', $result->getData()['text']);
    }

    public function test_request_multipart_works_without_extra_form_fields(): void
    {
        $this->client()->requestMultipart('post', '/api/v1/ocr', 'file', 'scan.jpg', 'jpeg-bytes');

        $request = $this->requests[0] ?? null;
        $this->assertNotNull($request, 'no HTTP request was sent');
        $body = (string) $request->getBody();
        $this->assertStringContainsString('Content-Disposition: form-data; name="file"; filename="scan.jpg"', $body);
        $this->assertStringNotContainsString('return_word_box', $body);
        $this->assertSame($this->expectedSignature($request), $request->getHeaderLine('X-Signature'));
    }
}
