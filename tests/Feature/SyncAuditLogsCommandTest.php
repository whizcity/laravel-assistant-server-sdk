<?php

use Orchestra\Testbench\TestCase;
use Psr\Http\Message\RequestInterface;
use Whizcity\AssistantServer\AssistantServerServiceProvider;
use Whizcity\AssistantServer\Client\AuditCollectorClient;
use Whizcity\AssistantServer\Audit\Models\AuditLog;

/**
 * 命令层面集成：audit:sync 应当通过 AuditCollectorClient（一次性 HMAC 签名）
 * 批量推送，成功标记 synced，失败自增 retry_count。
 */
final class SyncAuditLogsCommandTest extends TestCase
{
    /** @var RequestInterface[] */
    public static array $sent = [];

    protected function getPackageProviders($app): array
    {
        return [AssistantServerServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$sent = [];
        config([
            'assistant_server.address' => 'http://audit-collector.test',
            'assistant_server.app_id' => 'demo-app',
            'assistant_server.secret_key' => 'demo-secret',
        ]);
    }

    private function bindCollector(\Closure $respond): void
    {
        $this->app->bind(AuditCollectorClient::class, fn () => new AuditCollectorClient([
            'handler' => function (RequestInterface $request, array $options) use ($respond) {
                self::$sent[] = $request;
                return $respond($request);
            },
        ]));
    }

    private function makeLog(array $overrides = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'event_id' => '01HV4ZJ3KF7XY98ZXNQFS11JJ9',
            'service' => 'order-service',
            'action' => 'update',
            'resource' => 'orders',
            'resource_id' => '123',
            'operator_id' => '45',
            'operator_name' => '王五',
            'operator_ip' => '1.2.3.4',
            'before' => ['status' => 'pending'],
            'after' => ['status' => 'paid'],
            'trace_id' => 'abc-123',
            'user_agent' => 'UA/1.0',
            'synced' => false,
            'retry_count' => 0,
            'created_at' => '2024-05-06 10:00:00',
        ], $overrides));
    }

    public function test_sync_pushes_signed_batch_and_marks_rows_synced(): void
    {
        $this->bindCollector(fn () => new GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"accepted":2,"failed":0}',
        ));
        $a = $this->makeLog(['operator_name' => '王五']);
        $b = $this->makeLog(['resource_id' => '456', 'event_id' => '01HV4ZJ49YXVZFSBNBH3Y1JCZC']);

        $this->artisan('audit:sync')
            ->expectsOutputToContain('Synced 2 logs')
            ->assertExitCode(0);

        $this->assertCount(1, self::$sent);
        $request = self::$sent[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v1/audit/logs/batch', $request->getUri()->getPath());
        $this->assertSame('demo-app', $request->getHeaderLine('X-App-Id'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame([
            'event_id' => '01HV4ZJ3KF7XY98ZXNQFS11JJ9',
            'service' => 'order-service',
            'action' => 'update',
            'resource' => 'orders',
            'resource_id' => '123',
            'operator_id' => '45',
            'operator_name' => '王五',
            'operator_ip' => '1.2.3.4',
            'before' => ['status' => 'pending'],
            'after' => ['status' => 'paid'],
            'trace_id' => 'abc-123',
            'user_agent' => 'UA/1.0',
            'created_at' => '2024-05-06T10:00:00.000000Z',
        ], $body[0]);
        $this->assertSame('456', $body[1]['resource_id']);

        $this->assertTrue((bool) $a->refresh()->synced);
        $this->assertTrue((bool) $b->refresh()->synced);
        $this->assertNotNull($a->refresh()->synced_at);
    }

    public function test_failed_sync_increments_retry_and_keeps_rows_unsynced(): void
    {
        $this->bindCollector(fn () => new GuzzleHttp\Psr7\Response(
            500,
            ['Content-Type' => 'application/json'],
            '{"code":"HTTP_ERROR","message":"save failed"}',
        ));
        $log = $this->makeLog();

        $this->artisan('audit:sync')->assertExitCode(1);

        $this->assertFalse((bool) $log->refresh()->synced);
        $this->assertSame(1, $log->refresh()->retry_count);
    }

    public function test_missing_config_fails_without_touching_rows(): void
    {
        config(['assistant_server.address' => null]);
        $this->bindCollector(fn () => new GuzzleHttp\Psr7\Response(200, [], '{}'));
        $log = $this->makeLog();

        $this->artisan('audit:sync')->assertExitCode(1);

        $this->assertCount(0, self::$sent);
        $this->assertFalse((bool) $log->refresh()->synced);
        $this->assertSame(0, $log->refresh()->retry_count);
    }

    public function test_first_failure_waits_two_minutes_before_retry(): void
    {
        $this->bindCollector(fn () => new GuzzleHttp\Psr7\Response(
            500,
            ['Content-Type' => 'application/json'],
            '{"code":"HTTP_ERROR","message":"save failed"}',
        ));
        $before = now();
        $log = $this->makeLog();

        $this->artisan('audit:sync')->assertExitCode(1);

        $log->refresh();
        $this->assertSame(1, $log->retry_count);
        $minutes = $before->floatDiffInMinutes($log->next_retry_at);
        $this->assertGreaterThan(1.9, $minutes);
        $this->assertLessThan(2.1, $minutes);
    }

    public function test_backoff_doubles_with_each_failed_attempt(): void
    {
        $this->bindCollector(fn () => new GuzzleHttp\Psr7\Response(
            500,
            ['Content-Type' => 'application/json'],
            '{"code":"HTTP_ERROR","message":"save failed"}',
        ));
        $before = now();
        $log = $this->makeLog(['retry_count' => 1, 'next_retry_at' => '2023-01-01 00:00:00']);

        $this->artisan('audit:sync')->assertExitCode(1);

        $log->refresh();
        $this->assertSame(2, $log->retry_count);
        $minutes = $before->floatDiffInMinutes($log->next_retry_at);
        $this->assertGreaterThan(4.9, $minutes);
        $this->assertLessThan(5.1, $minutes);
    }

    public function test_backoff_escalates_beyond_double_on_later_attempts(): void
    {
        $this->bindCollector(fn () => new GuzzleHttp\Psr7\Response(
            500,
            ['Content-Type' => 'application/json'],
            '{"code":"HTTP_ERROR","message":"save failed"}',
        ));
        $before = now();
        $log = $this->makeLog(['retry_count' => 2, 'next_retry_at' => '2023-01-01 00:00:00']);

        $this->artisan('audit:sync')->assertExitCode(1);

        $log->refresh();
        $this->assertSame(3, $log->retry_count);
        $minutes = $before->floatDiffInMinutes($log->next_retry_at);
        $this->assertGreaterThan(14.9, $minutes);
        $this->assertLessThan(15.1, $minutes);
    }

    public function test_rows_waiting_out_the_backoff_are_not_picked_up(): void
    {
        $this->bindCollector(fn () => new GuzzleHttp\Psr7\Response(200, [], '{"accepted":1,"failed":0}'));
        $log = $this->makeLog(['retry_count' => 1, 'next_retry_at' => now()->addMinutes(10)]);

        $this->artisan('audit:sync')->assertSuccessful();

        $this->assertCount(0, self::$sent);
        $this->assertSame(1, $log->refresh()->retry_count);
    }

    public function test_cooled_rows_sync_while_hot_rows_stay_backing_off(): void
    {
        $this->bindCollector(fn () => new GuzzleHttp\Psr7\Response(
            500,
            ['Content-Type' => 'application/json'],
            '{"code":"HTTP_ERROR","message":"save failed"}',
        ));
        $cooled = $this->makeLog(['retry_count' => 1, 'next_retry_at' => '2023-01-01 00:00:00']);
        $hot = $this->makeLog([
            'event_id' => '01HV4ZJ49YXVZFSBNBH3Y1JCZD',
            'resource_id' => '456',
            'retry_count' => 1,
            'next_retry_at' => now()->addMinutes(10),
        ]);

        $this->artisan('audit:sync')->assertExitCode(1);

        $this->assertCount(1, self::$sent);
        $body = json_decode((string) self::$sent[0]->getBody(), true);
        $this->assertCount(1, $body);
        $this->assertSame($cooled->resource_id, $body[0]['resource_id']);

        $this->assertSame(2, $cooled->refresh()->retry_count);
        $hot->refresh();
        $this->assertSame(1, $hot->retry_count);
    }

    public function test_no_pending_logs_short_circuits_without_sending(): void
    {
        $this->bindCollector(fn () => new GuzzleHttp\Psr7\Response(200, [], '{}'));

        $this->artisan('audit:sync')->assertSuccessful();

        $this->assertCount(0, self::$sent);
    }
}
