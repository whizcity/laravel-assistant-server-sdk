<?php

namespace Whizcity\AssistantServer\Client;

/**
 * Server contract (FastAPI collector): an error response body is
 * {"code": <str>, "message": <str>}; a correct response body is the result
 * itself and carries no "code" key.
 */
class ResultCase
{
    public const SUCCESS_CODE = 0;

    private array $responseResult = [];

    public function setResponseResult($responseResult): void
    {
        $this->responseResult = $responseResult;
    }

    public function getResponseResult(): array
    {
        return $this->responseResult;
    }

    /**
     * @return string|int Server error codes are strings (e.g. "SIGNATURE_INVALID");
     *                    internal fallbacks may use integers (e.g. HTTP status).
     */
    public function getCode(): int|string
    {
        return array_key_exists('code', $this->responseResult)
            ? $this->responseResult['code']
            : self::SUCCESS_CODE;
    }

    public function getMessage(): string
    {
        return strval($this->responseResult['message'] ?? '');
    }

    /**
     * @return array The "data" key on success when present, otherwise the whole
     *               response body; [] on failure.
     */
    public function getData(): array
    {
        return $this->responseResult['data'] ?? ($this->isSuccess() ? $this->responseResult : []);
    }

    public function isSuccess(): bool
    {
        return count($this->responseResult) > 0 && !array_key_exists('code', $this->responseResult);
    }
}
