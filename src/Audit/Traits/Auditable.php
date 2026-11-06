<?php

namespace Whizcity\AssistantServer\Audit\Traits;

use Whizcity\AssistantServer\Audit\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

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
     * Extra per-model blacklist (merged with config('audit.except')).
     *
     * @var array<int, string>
     */
    protected array $auditExcept = [];

    /**
     * Column on the authenticated user taken as the operator display name
     * snapshot. Override in models whose actor table differs (e.g. "nickname").
     */
    public function auditOperatorNameColumn(): string
    {
        return 'name';
    }

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
        $before = $this->filterAuditFields($before);
        $after  = $this->filterAuditFields($after);

        if (empty($before) && empty($after)) {
            return;
        }

        AuditLog::create([
            'service'     => config('audit.service'),
            'action'      => $action,
            'resource'    => $this->getTable(),
            'resource_id' => (string) $this->getKey(),
            'operator_id' => Auth::id() ? (string) Auth::id() : null,
            'operator_name' => Auth::user()?->{$this->auditOperatorNameColumn()},
            'operator_ip' => request()?->ip(),
            'before'      => $before,
            'after'       => $after,
            'trace_id'    => request()?->header('X-Trace-Id')
                              ?? request()?->header('X-Request-Id'),
            'user_agent'  => request()?->userAgent(),
            'synced'      => false,
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
            config('audit.except', []),
            $this->auditExcept
        ));

        foreach ($except as $field) {
            unset($data[$field]);
        }

        return $data;
    }
}
