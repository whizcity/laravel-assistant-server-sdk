<?php

use PHPUnit\Framework\TestCase;
use Whizcity\AssistantServer\Client\ResultCase;

/**
 * 服务端契约（assistant-server /app/errors.py）：错误响应是
 * {"code","message"(,"details")}，正确的响应 body 本身无 code 字段。
 * 这些测试抓住：code 判定被改成按 HTTP 状态、getData 丢字段、message 类型漂移。
 */
final class ResultCaseTest extends TestCase
{
    public function test_response_without_code_is_success_and_returns_itself_as_data(): void
    {
        $result = new ResultCase();
        $result->setResponseResult(['accepted' => 2, 'failed' => 0]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->getCode());
        $this->assertSame(['accepted' => 2, 'failed' => 0], $result->getData());
    }

    public function test_explicit_data_key_is_returned_by_get_data(): void
    {
        $result = new ResultCase();
        $result->setResponseResult(['data' => ['id' => 5], 'page' => 1]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['id' => 5], $result->getData());
    }

    public function test_response_with_code_is_a_failure(): void
    {
        $result = new ResultCase();
        $result->setResponseResult(['code' => 'SIGNATURE_INVALID', 'message' => 'Signature verification failed']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('SIGNATURE_INVALID', $result->getCode());
        $this->assertSame('Signature verification failed', $result->getMessage());
        $this->assertSame([], $result->getData());
    }

    public function test_empty_result_is_a_failure(): void
    {
        $result = new ResultCase();

        $this->assertFalse($result->isSuccess());
    }

    public function test_message_is_coerced_to_string(): void
    {
        $result = new ResultCase();
        $result->setResponseResult(['code' => 'C', 'message' => 500]);

        $this->assertSame('500', $result->getMessage());
    }
}
