<?php

namespace Whizcity\AssistantServer\Audit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Whizcity\AssistantServer\Audit\AuditAction;

class AuditLog extends Model
{
    /**
     * Per-side byte budget for the JSON serialization of before/after; the
     * collector's AuditEvent schema validates each side <= 16KB, the headroom
     * absorbs serialization differences between PHP and the server.
     */
    public const PAYLOAD_LIMIT_BYTES = 16000;

    public $timestamps = false;

    protected $table = 'audit_logs';

    protected $fillable = [
        'event_id',
        'service',
        'action',
        'resource',
        'resource_id',
        'operator_id',
        'operator_name',
        'operator_ip',
        'before',
        'after',
        'trace_id',
        'user_agent',
        'synced',
        'synced_at',
        'retry_count',
        'next_retry_at',
        'created_at',
    ];

    protected $casts = [
        'before'        => 'array',
        'after'         => 'array',
        'synced'        => 'integer',
        'synced_at'     => 'datetime',
        'retry_count'   => 'integer',
        'next_retry_at' => 'datetime',
        'created_at'    => 'datetime',
    ];

    /**
     * Shared writer for model hooks AND manual call sites that bypass
     * Eloquent events (DB::table(...) / query builder) — Eloquent hooks
     * never fire for those, so nothing is captured automatically.
     *
     * Skips rows without an operator snapshot (non-admin context) and
     * rows with an empty before+after payload. $before/$after payloads
     * are taken as-is; the caller is responsible for sensitive fields.
     */
    public static function record(AuditAction $action, string $resource, $resourceId, ?array $before, ?array $after): void
    {
        // No actor snapshot = console/queue context, not an admin-panel operation: skip.
        $snapshot = (array) (request()?->get((string) config('assistant_server.audit.user_info_key', 'user')) ?: []);
        if (empty($snapshot)) {
            return;
        }

        if (empty($before) && empty($after)) {
            return;
        }

        // An event whose per-side payload exceeds the budget is split by
        // top-level keys into several events (see shards()).
        foreach (self::shards($before, $after) as [$eventBefore, $eventAfter]) {
            AuditLog::create([
                'event_id'    => (string) Str::ulid(),
                'service'     => config('assistant_server.audit.service'),
                'action'      => $action->value,
                'resource'    => $resource,
                'resource_id' => (string) $resourceId,
                'operator_id' => isset($snapshot['id']) ? (string) $snapshot['id'] : null,
                'operator_name' => $snapshot['nickname'] ?? null,
                'operator_ip' => request()?->ip(),
                'before'      => $eventBefore,
                'after'       => $eventAfter,
                'trace_id'    => request()?->header('X-Trace-Id')
                                  ?? request()?->header('X-Request-Id'),
                'user_agent'  => request()?->userAgent(),
                'synced'      => 0,
                'next_retry_at' => now(),
                'created_at'  => now(),
            ]);
        }
    }

    /**
     * Split an event whose before/after sides (JSON-serialized) exceed the
     * budget into several events by top-level keys. before/after pairs stay
     * aligned on the same key groups. A single atomic value bigger than the
     * budget cannot be split any further and stays whole in one record.
     *
     * @return list<array{0: ?array, 1: ?array}>
     */
    private static function shards(?array $before, ?array $after): array
    {
        $size = static fn ($payload): int => $payload === null
            ? 0
            : strlen(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '');

        if ($size($before) <= self::PAYLOAD_LIMIT_BYTES && $size($after) <= self::PAYLOAD_LIMIT_BYTES) {
            return [[$before, $after]];
        }

        $events = [];
        $bAcc = $aAcc = [];
        $bSize = $aSize = 0;
        $touched = false;

        foreach (array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? []))) as $key) {
            $bPair = array_key_exists($key, $before ?? []) ? [$key => $before[$key]] : [];
            $aPair = array_key_exists($key, $after ?? []) ? [$key => $after[$key]] : [];
            $bAdd = strlen(json_encode($bPair, JSON_UNESCAPED_UNICODE) ?: '');
            $aAdd = strlen(json_encode($aPair, JSON_UNESCAPED_UNICODE) ?: '');

            if ($touched && ($bSize + $bAdd > self::PAYLOAD_LIMIT_BYTES || $aSize + $aAdd > self::PAYLOAD_LIMIT_BYTES)) {
                $events[] = [$bAcc ?: null, $aAcc ?: null];
                $bAcc = $aAcc = [];
                $bSize = $aSize = 0;
            }

            $bAcc += $bPair;
            $aAcc += $aPair;
            $bSize += $bAdd;
            $aSize += $aAdd;
            $touched = true;
        }

        $events[] = [$bAcc ?: null, $aAcc ?: null];

        return $events;
    }
}
