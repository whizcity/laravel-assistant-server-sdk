<?php

use Orchestra\Testbench\TestCase;
use Psr\Http\Message\RequestInterface;
use Whizcity\AssistantServer\AssistantServerServiceProvider;
use Whizcity\AssistantServer\Client\AssistantServerClient;

/**
 * 通过 Guzzle handler 在 transport 边界抓取真实发出的 PSR-7 请求。
 * 签名期望值由观察到的头 + 标准 hash 函数独立推导，不调用 Signer，
 * 专门抓住「签的东西 ≠ 发出去的东西」这类 bug。
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
        // filename 与二进制字节进入 wire body，extra form 字段同样在场
        $this->assertStringContainsString('Content-Disposition: form-data; name="file"; filename="receipt.png"', $body);
        $this->assertStringContainsString('Content-Disposition: form-data; name="return_word_box"', $body);
        $this->assertStringContainsString('true', $body);
        $this->assertStringContainsString($png, $body);
        // 签名就是实际发送的 multipart 原始字节摘要，与服务端验签口径一致
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
