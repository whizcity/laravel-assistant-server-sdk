<?php

namespace Whizcity\AssistantServer\Audit\Traits;

use Whizcity\AssistantServer\Audit\Models\AuditLog;
use Illuminate\Support\Str;

trait Auditable
{
    /**
     * Whitelist of fields to log.
     * Empty array means "all fields except those in $auditExcept / config".
     *
     * @var array<int, string>
     */
    protected array $auditOnly = [];

    /**
     * Extra per-model blacklist (merged with config('assistant_server.audit.except')).
     *
     * @var array<int, string>
     */
    protected array $auditExcept = [];

    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->writeAuditLog('create', null, $model->getAttributes());
        });

        static::updating(function ($model) {
            // Stash original attributes so we can compute a proper before image.
            $model->audit_before = $model->getOriginal();
        });

        static::updated(function ($model) {
            $before = $model->audit_before ?? [];
            $after  = $model->getAttributes();
            $dirty  = $model->getChanges();

            if (empty($dirty)) {
                return;
            }

            $model->writeAuditLog(
                'update',
                array_intersect_key($before, $dirty),
                array_intersect_key($after, $dirty)
            );
        });

        static::deleted(function ($model) {
            $model->writeAuditLog('delete', $model->getOriginal(), null);
        });
    }

    /**
     * Persist one audit record to the local table. Never performs HTTP.
     */
    protected function writeAuditLog(string $action, ?array $before, ?array $after): void
    {
        // No actor snapshot = console/queue context, not an admin-panel operation: skip.
        $snapshot = (array) (request()?->get((string) config('assistant_server.audit.user_info_key', 'user')) ?: []);
        if (empty($snapshot)) {
            return;
        }

        $before = $this->filterAuditFields($before);
        $after  = $this->filterAuditFields($after);

        if (empty($before) && empty($after)) {
            return;
        }

        AuditLog::create([
            'event_id'    => (string) Str::ulid(),
            'service'     => config('assistant_server.audit.service'),
            'action'      => $action,
            'resource'    => $this->getTable(),
            'resource_id' => (string) $this->getKey(),
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

    protected function filterAuditFields(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        if (! empty($this->auditOnly)) {
            $data = array_intersect_key($data, array_flip($this->auditOnly));
        }

        $except = array_unique(array_merge(
            config('assistant_server.audit.except', []),
            $this->auditExcept
        ));

        foreach ($except as $field) {
            unset($data[$field]);
        }

        return $data;
    }
}
