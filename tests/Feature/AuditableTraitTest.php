<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Orchestra\Testbench\TestCase;
use Whizcity\AssistantServer\AssistantServerServiceProvider;
use Whizcity\AssistantServer\Audit\Models\AuditLog;
use Whizcity\AssistantServer\Audit\Traits\Auditable;

/**
 * Trait 行为：事件落库即快照。operator_name 取自认证用户的显示名列，
 * 模型可用 $auditOperatorNameColumn 改列；无登录用户两侧字段均为 null。
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

    private function actor(array $attributes): User
    {
        $actor = new TestActor($attributes);
        $this->actingAs($actor);

        return $actor;
    }

    public function test_created_rows_snapshot_the_operator_display_name(): void
    {
        $this->actor(['id' => 9, 'name' => '张三', 'nickname' => '昵称张']);

        TestAuditSubject::create(['title' => '订单一', 'status' => 'paid']);

        $row = AuditLog::query()->latest('id')->first();
        $this->assertSame('9', $row->operator_id);
        $this->assertSame('张三', $row->operator_name);
    }

    public function test_models_can_pick_a_different_display_name_column(): void
    {
        $this->actor(['id' => 9, 'name' => '张三', 'nickname' => '昵称张']);

        TestAuditSubjectNickname::create(['title' => '订单一', 'status' => 'paid']);

        $row = AuditLog::query()->latest('id')->first();
        $this->assertSame('昵称张', $row->operator_name);
    }

    public function test_created_rows_carry_a_unique_ulid_event_id(): void
    {
        TestAuditSubject::create(['title' => '订单一', 'status' => 'paid']);
        TestAuditSubject::create(['title' => '订单二', 'status' => 'paid']);

        $rows = AuditLog::query()->latest('id')->limit(2)->get();
        $this->assertCount(2, $rows);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $rows[0]->event_id);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $rows[1]->event_id);
        $this->assertNotSame($rows[0]->event_id, $rows[1]->event_id);
    }

    public function test_guest_actions_leave_operator_fields_null(): void
    {
        TestAuditSubject::create(['title' => '订单一', 'status' => 'paid']);

        $row = AuditLog::query()->latest('id')->first();
        $this->assertNull($row->operator_id);
        $this->assertNull($row->operator_name);
    }
}

final class TestActor extends User
{
    protected $guarded = [];
    public $timestamps = false;
}

final class TestAuditSubject extends Model
{
    use Auditable;

    protected $table = 'audit_subjects';
    protected $guarded = [];
    public $timestamps = false;
}

final class TestAuditSubjectNickname extends Model
{
    use Auditable;
    public function auditOperatorNameColumn(): string
    {
        return 'nickname';
    }

    protected $table = 'audit_subjects';
    protected $guarded = [];
    public $timestamps = false;
}
