<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Whizcity\AssistantServer\Audit\AuditAction;
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

    public function test_mass_query_builder_writes_are_audited_per_row(): void
    {
        // query()->where(...)->update()/delete() 不依赖模型事件拿变化——
        // 自定义 Builder 先抓 before 原像再执行原语句，随后逐行落审计；
        // 实例 save 同走 Builder::update()，靠调用栈区分避免重复记账。
        $this->actor(['id' => '9', 'nickname' => '张三']);
        TestAuditSubject::create(['title' => 'a', 'status' => 'draft']);
        TestAuditSubject::create(['title' => 'b', 'status' => 'draft']);
        $this->assertCount(2, AuditLog::all());

        TestAuditSubject::query()->where('id', 1)->update(['status' => 'paid']);

        $updates = AuditLog::query()->where('action', AuditAction::Update->value)->get();
        $this->assertCount(1, $updates);
        $this->assertSame('1', $updates[0]->resource_id);
        $this->assertSame(['status' => 'draft'], $updates[0]->before);
        $this->assertSame(['status' => 'paid'], $updates[0]->after);
        $this->assertSame(3, AuditLog::count());

        TestAuditSubject::query()->where('id', 2)->delete();

        $deletes = AuditLog::query()->where('action', AuditAction::Delete->value)->get();
        $this->assertCount(1, $deletes);
        $this->assertSame('2', $deletes[0]->resource_id);
        $this->assertSame(['title' => 'b', 'status' => 'draft'], $deletes[0]->before);
        $this->assertNull($deletes[0]->after);
        $this->assertSame(4, AuditLog::count());
    }

    public function test_mass_query_builder_writes_stay_silent_without_snapshot(): void
    {
        // 无快照：语句照常执行（行真的被改/删），但不产生任何审计行，
        // 也不在批量路径上崩溃（WeakMap 回归守护）。
        TestAuditSubject::create(['title' => 'a', 'status' => 'draft']);
        TestAuditSubject::create(['title' => 'b', 'status' => 'draft']);

        TestAuditSubject::query()->where('id', 1)->update(['status' => 'paid']);
        $this->assertSame(['status' => 'paid'], TestAuditSubject::find(1)?->only('status'));

        TestAuditSubject::query()->where('id', 2)->delete();
        $this->assertNull(TestAuditSubject::find(2));

        $this->assertCount(0, AuditLog::all());
    }

    public function test_mass_insert_via_query_is_audited_unless_save_path(): void
    {
        // 实例 create() 内部同样走 Builder::insert：save-path 旗挡住钩子路径，
        // 只有显式的 query()->insertGetId() 才额外落一行。
        $this->actor(['id' => '9', 'nickname' => '张三']);
        TestAuditSubject::create(['title' => '青山', 'status' => 'draft']);

        $newId = TestAuditSubject::query()->insertGetId(['title' => '外围', 'status' => 'paid']);

        $row = AuditLog::query()->latest('id')->first();
        $this->assertSame('create', $row->action);
        $this->assertSame('audit_subjects', $row->resource);
        $this->assertSame((string) $newId, $row->resource_id);
        $this->assertNull($row->before);
        $this->assertSame(['title' => '外围', 'status' => 'paid'], $row->after);

        $this->assertCount(2, AuditLog::all()); // 1 钩子(create()) + 1 批量 insertGetId
    }

    public function test_mass_insert_records_and_stays_silent_without_snapshot(): void
    {
        TestAuditSubject::query()->insert(['title' => '旁路', 'status' => 'paid']);
        $this->assertTrue(TestAuditSubject::query()->where('title', '旁路')->exists());
        $this->assertCount(0, AuditLog::all());
    }

    public function test_instance_save_records_exactly_once(): void
    {
        // 实例 save 同样穿过自定义 Builder：调用栈守卫必须挡住双记账。
        $this->actor(['id' => '9', 'nickname' => '张三']);
        TestAuditSubject::create(['title' => 'a', 'status' => 'draft']);

        TestAuditSubject::find(1)?->update(['status' => 'refunded']);

        $updates = AuditLog::query()->where('action', AuditAction::Update->value)->get();
        $this->assertCount(1, $updates);
        $this->assertSame(['status' => 'draft'], $updates[0]->before);
        $this->assertSame(['status' => 'refunded'], $updates[0]->after);
    }

    public function test_new_rows_are_immediately_eligible_for_sync(): void
    {
        // next_retry_at 写入即填 now（不再用 NULL 表示从未失败），同步查询无需 whereNull。
        $this->actor(['id' => '9', 'nickname' => '张三']);
        TestAuditSubject::create(['title' => '订单一', 'status' => 'paid']);

        $row = AuditLog::query()->latest('id')->first();
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $row->next_retry_at);
        $this->assertTrue($row->next_retry_at->isPast() || $row->next_retry_at->eq(now()));
    }

    public function test_query_builder_writes_can_be_recorded_manually(): void
    {
        // DB::table(...) 不触发 Eloquent 事件，钩子拿不到变化；旁路写入点手动补记。
        $this->actor(['id' => '9', 'nickname' => '张三']);
        DB::table('audit_subjects')->insert(['id' => 3, 'title' => '旁路写入', 'status' => 'draft']);
        DB::table('audit_subjects')->where('id', 3)->update(['status' => 'paid']);

        AuditLog::record(AuditAction::Update, 'audit_subjects', '3', ['status' => 'draft'], ['status' => 'paid']);

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
        AuditLog::record(AuditAction::Update, 'audit_subjects', '3', ['status' => 'draft'], ['status' => 'paid']); // 无快照
        $this->actor(['id' => '9', 'nickname' => '张三']);
        AuditLog::record(AuditAction::Update, 'audit_subjects', '3', null, null); // 无变化数据

        $this->assertCount(0, AuditLog::all());
    }

    public function test_action_is_a_backed_enum_written_as_the_case_value(): void
    {
        $this->actor(['id' => '9', 'nickname' => '张三']);
        DB::table('audit_subjects')->insert(['id' => 4, 'title' => '旁路写入', 'status' => 'draft']);

        AuditLog::record(AuditAction::Update, 'audit_subjects', '4', ['status' => 'draft'], ['status' => 'paid']);

        $row = AuditLog::query()->latest('id')->first();
        $this->assertSame('update', $row->action);
    }

    public function test_record_rejects_raw_strings_for_action(): void
    {
        $this->expectException(\TypeError::class);

        AuditLog::record('update', 'audit_subjects', '4', null, null);
    }
}

final class TestAuditSubject extends Model
{
    use Auditable;

    protected $table = 'audit_subjects';
    protected $guarded = [];
    public $timestamps = false;
}
