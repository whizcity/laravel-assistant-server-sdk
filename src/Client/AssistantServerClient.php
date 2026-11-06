<?php

namespace Whizcity\AssistantServer\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\MultipartStream;
use Illuminate\Support\Facades\Log;

/**
 * Client for assistant-server. Calls go through one-time HMAC signature
 * authentication, see Signer for the canonical string to sign.
 *
 * Call any endpoint with the generic entry point, so that new endpoints on
 * the server do not require an SDK release:
 *
 *     $result = app(AssistantServerClient::class)
 *         ->request('post', '/api/v1/audit/logs/batch', $logs);
 *     if ($result->isSuccess()) {
 *         $data = $result->getData();
 *     }
 *
 * Domain subclasses ride on request() through the __call APIMap mapping and
 * are optional sugar: e.g. AuditCollectorClient for the audit domain.
 */
class AssistantServerClient
{
    private string $baseUri;
    private string $appId;
    private string $secretKey;
    /** @var array<string, array{0: string, 1: string, 2: string}> */
    protected array $APIMap = [];
    private ResultCase $responseResult;
    private array $guzzleOptions;
    private bool $logEnabled;

    public function __construct(array $guzzleOptions = [])
    {
        $this->responseResult = new ResultCase();
        $this->baseUri = (string) config('assistant_server.address');
        $this->appId = (string) config('assistant_server.app_id');
        $this->secretKey = (string) config('assistant_server.secret_key');
        $this->logEnabled = (bool) config('assistant_server.log');
        $this->guzzleOptions = $guzzleOptions;
    }

    public function getResult(): ResultCase
    {
        return $this->responseResult;
    }

    /**
     * Generic signed call to any endpoint:
     *
     *     $client->request('post', '/api/v1/audit/logs/batch', $logs);
     *     $client->request('get', '/api/v1/audit/logs', ['service' => 'x']);
     *
     * POST / PUT / PATCH send $params as the JSON body; other methods (GET,
     * DELETE...) send $params as the query string, which then participates in
     * the signature. The path is used as given. Returns a ResultCase; HTTP
     * 4xx/5xx and transport errors never throw — read isSuccess()/getCode().
     */
    public function request(string $method, string $path, array $params = []): ResultCase
    {
        $method = strtolower($method);
        if (in_array($method, ['post', 'put', 'patch'], true)) {
            $body = json_encode($params, JSON_UNESCAPED_UNICODE) ?: '{}';
            $this->apply($body, 'application/json', $method, $path, '');
            return $this->responseResult;
        }
        $this->apply('', null, $method, $path, http_build_query($params));
        return $this->responseResult;
    }

    /**
     * Generic signed call carrying one file plus optional extra form fields
     * as multipart/form-data (e.g. the OCR upload below):
     *
     *     $client->requestMultipart('post', '/api/v1/ocr',
     *         'file', 'receipt.png', $imageBytes, ['return_word_box' => 'true']);
     *
     * The raw multipart wire body is generated up front with MultipartStream,
     * so the signature digest matches the exact bytes sent. Returns a
     * ResultCase; read isSuccess()/getCode()/getMessage() as usual.
     */
    public function requestMultipart(string $method, string $path, string $fileField, string $filename, string $fileContent, array $extraForm = []): ResultCase
    {
        $method = strtolower($method);
        $boundary = bin2hex(random_bytes(16));
        $elements = [['name' => $fileField, 'filename' => $filename, 'contents' => $fileContent]];
        foreach ($extraForm as $name => $value) {
            $elements[] = ['name' => (string) $name, 'contents' => (string) $value];
        }
        $body = (string) new MultipartStream($elements, $boundary);
        $this->apply($body, 'multipart/form-data; boundary=' . $boundary, $method, $path, '');
        return $this->responseResult;
    }

    /**
     * Sends an already-shaped request. Builds the wire request, signs it with
     * the one-time HMAC headers, sends it, and stores the decoded response in
     * ResultCase. HTTP 4xx/5xx must not throw (http_errors=false): callers
     * read isSuccess()/getCode()/getMessage().
     */
    private function apply(string $body, ?string $contentType, string $method, string $path, string $query): void
    {
        try {
            if ($this->baseUri === '' || $this->appId === '' || $this->secretKey === '') {
                $this->responseResult->setResponseResult([
                    'code'    => 'CONFIG_ERROR',
                    'message' => 'assistant_server.address / assistant_server.app_id / assistant_server.secret_key is not configured',
                ]);
                return;
            }

            $headers = Signer::headers(
                appId: $this->appId,
                secret: $this->secretKey,
                method: $method,
                path: $path,
                query: $query,
                body: $body,
            );
            if ($contentType !== null) {
                $headers['Content-Type'] = $contentType;
            }

            $client = new Client(array_merge([
                'base_uri'        => $this->baseUri,
                'timeout'         => 20,
                'connect_timeout' => 10,
                'verify'          => false,
            ], $this->guzzleOptions));

            $response = $client->request($method, $query !== '' ? $path.'?'.$query : $path, array_filter([
                'headers'     => $headers,
                'body'        => $body,
                'http_errors' => false,
            ], fn ($value) => $value !== ''));
            $responseBody = trim((string) $response->getBody());
            $responseArr = json_decode($responseBody, true);
            if (!is_array($responseArr)) {
                $responseArr = [
                    'code'    => $response->getStatusCode(),
                    'message' => mb_substr($responseBody, 0, 500),
                ];
            }
            $this->responseResult->setResponseResult($responseArr);
            if ($this->logEnabled) {
                Log::debug('AssistantServerClient result', [
                    'method' => strtoupper($method),
                    'path'   => $path,
                    'query'  => $query,
                    'code'   => $this->responseResult->getCode(),
                ]);
            }
        } catch (\Throwable $e) {
            if ($this->logEnabled) {
                Log::error("AssistantServerClient->{$path} request failed:" . $e->getMessage());
            }
            $this->responseResult->setResponseResult([
                'code'    => 'INTERNAL_ERROR',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Named sugar defined by domain subclasses through their $APIMap.
     *
     * @param string|array $arg Parameters by request type:
     *   - "json": associative body (or single log).
     *   - "query": filter array turned into the raw query string (it participates in the signature).
     *   - "id": id string substituted into the path, optional second arg = filter array.
     */
    public function __call($name, $arg): ResultCase
    {
        if ($this->logEnabled) {
            Log::debug('AssistantServerClient call', ['api' => $name, 'args' => $arg]);
        }
        if (empty($this->APIMap[$name])) {
            $this->responseResult->setResponseResult([
                'code'    => 'VALIDATION_ERROR',
                'message' => "the api call name is wrong the api does not exist:{$name}",
            ]);
            return $this->responseResult;
        }
        [$path, $method, $type] = $this->APIMap[$name];

        if ('json' === $type) {
            return $this->request($method, $path, $arg[0] ?? []);
        }
        if ('id' === $type) {
            return $this->request($method, str_replace('{id}', rawurlencode(strval($arg[0] ?? '')), $path), $arg[1] ?? []);
        }
        return $this->request($method, $path, $arg[0] ?? []);
    }
}
