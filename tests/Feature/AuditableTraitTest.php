<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Whizcity\AssistantServer\Audit\AuditAction;
use Whizcity\AssistantServer\AssistantServerServiceProvider;
use Whizcity\AssistantServer\Audit\Models\AuditLog;
use Whizcity\AssistantServer\Audit\Traits\Auditable;

/**
 * Trait behavior: each event lands as one audit row. operator_id /
 * operator_name come from the actor snapshot injected by the middleware
 * (array with id / nickname), keyed by assistant_server.audit.user_info_key
 * (default user). Without a snapshot the event is treated as a non-admin
 * action (CLI/queue context) and nothing is stored.
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
        // How consuming middleware injects: $request->attributes->add([<configured key> => $user])
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
        // No snapshot = not an admin-panel operation (CLI/queue/no request): skip.
        TestAuditSubject::create(['title' => '订单一', 'status' => 'paid']);

        $this->assertCount(0, AuditLog::all());
    }

    public function test_mass_query_builder_writes_are_audited_per_row(): void
    {
        // query()->where(...)->update()/delete() cannot rely on model events:
        // the custom Builder snapshots the before image first, runs the original
        // statement, then records row by row. Instance saves go through the same
        // Builder::update(); an explicit save-path flag prevents double recording.
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
        // No snapshot: the statements still execute (rows really change/vanish),
        // but no audit row is produced and the batch path must not crash
        // (WeakMap regression guard).
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
        // Instance create() routes through Builder::insert too: the save-path
        // flag blocks the hook path, and only an explicit query()->insertGetId()
        // records an extra row.
        $this->actor(['id' => '9', 'nickname' => '张三']);
        TestAuditSubject::create(['title' => '青山', 'status' => 'draft']);

        $newId = TestAuditSubject::query()->insertGetId(['title' => '外围', 'status' => 'paid']);

        $row = AuditLog::query()->latest('id')->first();
        $this->assertSame('create', $row->action);
        $this->assertSame('audit_subjects', $row->resource);
        $this->assertSame((string) $newId, $row->resource_id);
        $this->assertNull($row->before);
        $this->assertSame(['title' => '外围', 'status' => 'paid'], $row->after);

        $this->assertCount(2, AuditLog::all()); // 1 hook (create()) + 1 batch insertGetId
    }

    public function test_mass_insert_records_and_stays_silent_without_snapshot(): void
    {
        TestAuditSubject::query()->insert(['title' => '旁路', 'status' => 'paid']);
        $this->assertTrue(TestAuditSubject::query()->where('title', '旁路')->exists());
        $this->assertCount(0, AuditLog::all());
    }

    public function test_multi_row_insert_is_recorded_as_one_statement_row(): void
    {
        // N-row batch insert = ONE audit row ({"count": N, "rows": [...]})。
        $this->actor(['id' => '9', 'nickname' => '张三']);

        TestAuditSubject::query()->insert([
            ['title' => '批量一', 'status' => 'paid'],
            ['title' => '批量二', 'status' => 'draft'],
        ]);

        $row = AuditLog::query()->latest('id')->first();
        $this->assertSame('create', $row->action);
        $this->assertSame('audit_subjects', $row->resource);
        $this->assertSame('', $row->resource_id);
        $this->assertNull($row->before);
        $this->assertSame(['count' => 2, 'rows' => [
            ['title' => '批量一', 'status' => 'paid'],
            ['title' => '批量二', 'status' => 'draft'],
        ]], $row->after);
        $this->assertCount(1, AuditLog::all()); // one statement, one audit row
    }

    public function test_oversized_multi_row_insert_is_chunked_into_multiple_events(): void
    {
        // After must stay within the collector's 16KB JSON limit per event:
        // a batch too big for one event is split into several chunk events,
        // every key of the payloads preserved (no "truncated" lossy flag).
        $this->actor(['id' => '9', 'nickname' => '张三']);

        $rows = [];
        for ($i = 0; $i < 400; $i++) {
            $rows[] = ['title' => str_repeat('x', 50) . $i, 'status' => 'paid'];
        }
        TestAuditSubject::query()->insert($rows);

        $records = AuditLog::all();
        $this->assertGreaterThan(1, $records->count());

        $countSum = 0;
        $seenRows = [];
        foreach ($records as $record) {
            $this->assertLessThanOrEqual(16384, strlen(json_encode($record->after, JSON_UNESCAPED_UNICODE)));
            $this->assertSame('create', $record->action);
            $this->assertArrayHasKey('count', $record->after);
            $this->assertArrayHasKey('rows', $record->after);
            $countSum += $record->after['count'];
            $seenRows = array_merge($seenRows, $record->after['rows']);
        }
        $this->assertSame(400, $countSum);
        $this->assertSame($rows, $seenRows); // every original row surfaces in some chunk
    }

    public function test_manual_record_with_oversized_payload_is_split_by_keys(): void
    {
        // A single event whose after side exceeds 16KB is split into several
        // events by top-level keys; per-event payloads each fit the budget.
        $this->actor(['id' => '9', 'nickname' => '张三']);

        $payload = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'] as $key) {
            $payload[$key] = str_repeat('好', 900); // ~2700B value each -> ~27KB total
        }
        AuditLog::record(AuditAction::Update, 'manual', '1', null, $payload);

        $records = AuditLog::query()->orderBy('id')->get();
        $this->assertGreaterThan(1, $records->count());

        $seenKeys = [];
        foreach ($records as $record) {
            $this->assertSame('update', $record->action);
            $this->assertLessThanOrEqual(16384, strlen(json_encode($record->after, JSON_UNESCAPED_UNICODE)));
            $seenKeys = array_merge($seenKeys, array_keys($record->after));
        }
        $this->assertSame(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'], $seenKeys);
    }

    public function test_single_atomic_value_over_the_budget_stays_in_one_record(): void
    {
        // One key whose value alone exceeds the budget cannot be split any
        // further: it stays whole in one record (server-side may reject it
        // at sync time — that is the pre-existing behavior, kept visible).
        $this->actor(['id' => '9', 'nickname' => '张三']);

        AuditLog::record(AuditAction::Update, 'manual', '1', null, ['blob' => str_repeat('长', 10000)]);

        $this->assertCount(1, AuditLog::all());
        $this->assertSame(['blob' => str_repeat('长', 10000)], AuditLog::query()->latest('id')->first()->after);
    }

    public function test_single_row_insert_keeps_per_row_recording(): void
    {
        $this->actor(['id' => '9', 'nickname' => '张三']);

        TestAuditSubject::query()->insert(['title' => '单独', 'status' => 'paid']);

        $this->assertCount(1, AuditLog::all());
        $this->assertSame(['title' => '单独', 'status' => 'paid'], AuditLog::query()->latest('id')->first()->after);
    }

    public function test_instance_save_records_exactly_once(): void
    {
        // Instance saves pass through the custom Builder just the same: the
        // save-path guard must block double recording.
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
        // next_retry_at is filled with now() at write time (no more NULL =
        // never failed), so the sync query needs no whereNull.
        $this->actor(['id' => '9', 'nickname' => '张三']);
        TestAuditSubject::create(['title' => '订单一', 'status' => 'paid']);

        $row = AuditLog::query()->latest('id')->first();
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $row->next_retry_at);
        $this->assertTrue($row->next_retry_at->isPast() || $row->next_retry_at->eq(now()));
    }

    public function test_query_builder_writes_can_be_recorded_manually(): void
    {
        // DB::table(...) fires no Eloquent events, hooks see nothing: record
        // manually at these bypass call sites.
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
        AuditLog::record(AuditAction::Update, 'audit_subjects', '3', ['status' => 'draft'], ['status' => 'paid']); // no snapshot
        $this->actor(['id' => '9', 'nickname' => '张三']);
        AuditLog::record(AuditAction::Update, 'audit_subjects', '3', null, null); // nothing to record

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

    public function test_mass_delete_of_multiple_rows_is_recorded_as_one_statement_row(): void
    {
        $this->actor(['id' => '9', 'nickname' => '张三']);
        TestAuditSubject::create(['title' => '订单一', 'status' => 'draft']);
        TestAuditSubject::create(['title' => '订单二', 'status' => 'draft']);

        TestAuditSubject::query()->where('id', '>', 0)->delete();

        $this->assertCount(3, AuditLog::all()); // 2 hook creates + 1 merged delete

        $row = AuditLog::query()->latest('id')->first();
        $this->assertSame('delete', $row->action);
        $this->assertSame('audit_subjects', $row->resource);
        $this->assertSame('', $row->resource_id);
        $this->assertNull($row->before);
        $this->assertSame([
            'count' => 2,
            'rows'  => [
                '1' => ['title' => '订单一', 'status' => 'draft'],
                '2' => ['title' => '订单二', 'status' => 'draft'],
            ],
        ], $row->after);
    }

    public function test_oversized_mass_delete_is_chunked(): void
    {
        $this->actor(['id' => '9', 'nickname' => '张三']);
        DB::table('audit_subjects')->insert(collect(range(1, 100))->map(
            fn ($i) => ['id' => $i, 'title' => str_repeat('好', 60), 'status' => 'paid']
        )->all());

        TestAuditSubject::query()->where('id', '>', 0)->delete();

        $logs = AuditLog::query()->where('action', 'delete')->get();
        $this->assertGreaterThan(1, $logs->count());

        $seenIds = [];
        $total = 0;
        foreach ($logs as $log) {
            // Server-side budget check: json.dumps(ensure_ascii=False) UTF-8 bytes.
            $this->assertLessThanOrEqual(16384, strlen(json_encode($log->after, JSON_UNESCAPED_UNICODE)));
            foreach ($log->after['rows'] as $id => $image) {
                $seenIds[] = (int) $id;
                $total++;
                $this->assertSame(['title' => str_repeat('好', 60), 'status' => 'paid'], $image);
            }
        }

        $this->assertSame(100, $total);
        $this->assertSame(range(1, 100), $seenIds);
    }
}

final class TestAuditSubject extends Model
{
    use Auditable;

    protected $table = 'audit_subjects';
    protected $guarded = [];
    public $timestamps = false;
}
