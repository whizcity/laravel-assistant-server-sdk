<?php

namespace Whizcity\AssistantServer\Audit\Traits;

use Whizcity\AssistantServer\Audit\Models\AuditLog;
use Whizcity\AssistantServer\Audit\AuditAction;

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
            $model->writeAuditLog(AuditAction::Create, null, $model->getAttributes());
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
                AuditAction::Update,
                array_intersect_key($before, $dirty),
                array_intersect_key($after, $dirty)
            );
        });

        static::deleted(function ($model) {
            $model->writeAuditLog(AuditAction::Delete, $model->getOriginal(), null);
        });
    }

    /**
     * Persist one audit record to the local table. Never performs HTTP.
     */
    protected function writeAuditLog(AuditAction $action, ?array $before, ?array $after): void
    {
        $before = $this->filterAuditFields($before);
        $after  = $this->filterAuditFields($after);

        AuditLog::record($action, $this->getTable(), $this->getKey(), $before, $after);
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
