<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Whizcity\AssistantServer\AssistantServerServiceProvider;
use Whizcity\AssistantServer\Audit\Models\AuditLog;
use Whizcity\AssistantServer\Audit\Traits\Auditable;

/**
 * Trait 行为：事件落库即快照。operator_id / operator_name 取自请求上
 * 中间件注入的 actor 快照（数组，含 id / nickname），键名由
 * assistant_server.audit.user_info_key 配置（默认 user）；无快照时
 * 视作非后台管理员操作（CLI/队列上下文），事件不落库。
 */
final class AuditableTraitTest extends TestCase
{
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
        \Illuminate\Support\Facades\Schema::create('audit_subjects', function ($table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
        });
    }

    private function actor(array $snapshot): void
    {
        // 消费方中间件的注入方式：$request->attributes->add([<配置的键名> => $user])
        request()->attributes->add([config('assistant_server.audit.user_info_key', 'user') => $snapshot]);
    }

    public function test_created_rows_snapshot_the_operator_from_request_userinfo(): void
    {
        $this->actor(['id' => 9, 'nickname' => '张三']);

        TestAuditSubject::create(['title' => '订单一', 'status' => 'paid']);

        $row = AuditLog::query()->latest('id')->first();
        $this->assertSame('9', $row->operator_id);
        $this->assertSame('张三', $row->operator_name);
    }

    public function test_operator_snapshot_request_key_is_configurable(): void
    {
        config(['assistant_server.audit.user_info_key' => 'session_actor']);
        request()->attributes->add(['session_actor' => ['id' => '7', 'nickname' => '李四']]);

        TestAuditSubject::create(['title' => '订单二', 'status' => 'paid']);

        $row = AuditLog::query()->latest('id')->first();
        $this->assertSame('7', $row->operator_id);
        $this->assertSame('李四', $row->operator_name);
    }

    public function test_created_rows_carry_a_unique_ulid_event_id(): void
    {
        $this->actor(['id' => 9, 'nickname' => '张三']);
        TestAuditSubject::create(['title' => '订单一', 'status' => 'paid']);
        TestAuditSubject::create(['title' => '订单二', 'status' => 'paid']);

        $rows = AuditLog::query()->latest('id')->limit(2)->get();
        $this->assertCount(2, $rows);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $rows[0]->event_id);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $rows[1]->event_id);
        $this->assertNotSame($rows[0]->event_id, $rows[1]->event_id);
    }

    public function test_operations_without_actor_snapshot_are_not_audited(): void
    {
        // 无快照 = 非后台管理员操作（CLI/队列/无请求上下文），事件不落库。
        TestAuditSubject::create(['title' => '订单一', 'status' => 'paid']);

        $this->assertCount(0, AuditLog::all());
    }

    public function test_query_builder_writes_can_be_recorded_manually(): void
    {
        // DB::table(...) 不触发 Eloquent 事件，钩子拿不到变化；旁路写入点手动补记。
        $this->actor(['id' => '9', 'nickname' => '张三']);
        DB::table('audit_subjects')->insert(['id' => 3, 'title' => '旁路写入', 'status' => 'draft']);
        DB::table('audit_subjects')->where('id', 3)->update(['status' => 'paid']);

        AuditLog::record('update', 'audit_subjects', '3', ['status' => 'draft'], ['status' => 'paid']);

        $row = AuditLog::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('update', $row->action);
        $this->assertSame('audit_subjects', $row->resource);
        $this->assertSame('3', $row->resource_id);
        $this->assertSame('9', $row->operator_id);
        $this->assertSame(['status' => 'draft'], $row->before);
        $this->assertSame(['status' => 'paid'], $row->after);
    }

    public function test_manual_recorders_skip_rows_without_snapshot_and_empty_payloads(): void
    {
        AuditLog::record('update', 'audit_subjects', '3', ['status' => 'draft'], ['status' => 'paid']); // 无快照
        $this->actor(['id' => '9', 'nickname' => '张三']);
        AuditLog::record('update', 'audit_subjects', '3', null, null); // 无变化数据

        $this->assertCount(0, AuditLog::all());
    }
}

final class TestAuditSubject extends Model
{
    use Auditable;

    protected $table = 'audit_subjects';
    protected $guarded = [];
    public $timestamps = false;
}
