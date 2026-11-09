<?php

namespace Whizcity\AssistantServer\Audit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Whizcity\AssistantServer\Audit\AuditAction;

class AuditLog extends Model
{
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

        AuditLog::create([
            'event_id'    => (string) Str::ulid(),
            'service'     => config('assistant_server.audit.service'),
            'action'      => $action->value,
            'resource'    => $resource,
            'resource_id' => (string) $resourceId,
            'operator_id' => isset($snapshot['id']) ? (string) $snapshot['id'] : null,
            'operator_name' => $snapshot['nickname'] ?? null,
            'operator_ip' => request()?->ip(),
            'before'      => $before,
            'after'       => $after,
            'trace_id'    => request()?->header('X-Trace-Id')
                              ?? request()?->header('X-Request-Id'),
            'user_agent'  => request()?->userAgent(),
            'synced'      => 0,
            'created_at'  => now(),
        ]);
    }
}
