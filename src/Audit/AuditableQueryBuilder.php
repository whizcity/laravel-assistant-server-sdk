<?php

namespace Whizcity\AssistantServer\Audit;

use Illuminate\Database\Eloquent\Builder;
use Whizcity\AssistantServer\Audit\Models\AuditLog;

/**
 * Query builder that keeps query()-level mass update/delete audited.
 *
 * Plain Eloquent events cannot capture these writes (updated hook gets no
 * dirty state / delete never fires), so this builder snapshots before-images
 * first, runs the original statement, then records one audit row per row via
 * AuditLog::record(). Instance saves pass through the SAME Builder::update()
 * (performUpdate -> setKeysForSaveQuery(...)->update($dirty)), so the save
 * path is detected via the call stack and left to the model-event hooks to
 * avoid double recording.
 */
class AuditableQueryBuilder extends Builder
{
    public function update(array $values): int
    {
        $shouldAudit = ! $this->fromSavePath() && $this->model->hasActorSnapshot();

        $beforeRows = [];
        if ($shouldAudit) {
            $columnList = array_values(array_unique(array_merge([$this->model->getKeyName()], array_keys($values))));
            $beforeRows = (clone $this)->get($columnList)->mapWithKeys(function ($row) use ($columnList) {
                $image = $row->auditFilterPayload(array_intersect_key($row->getOriginal(), array_flip($columnList)));
                unset($image[$this->model->getKeyName()]);

                return [$row->getKey() => $image];
            })->all();
        }

        $affected = parent::update($values);

        if ($shouldAudit) {
            $after = $this->model->auditFilterPayload($values) ?: [];
            foreach ($beforeRows as $key => $image) {
                if ($image === null || $image === $after) {
                    continue; // 敏感字段被清空 / 实际无变化，跳过
                }
                AuditLog::record(AuditAction::Update, $this->model->getTable(), $key, $image, $after);
            }
        }

        return $affected;
    }

    public function delete(): int
    {
        $shouldAudit = ! $this->fromSavePath() && $this->model->hasActorSnapshot();

        $beforeRows = [];
        if ($shouldAudit) {
            $beforeRows = (clone $this)->get()->mapWithKeys(function ($row) {
                $image = $row->auditFilterPayload($row->getOriginal());
                unset($image[$this->model->getKeyName()]);

                return [$row->getKey() => $image];
            })->all();
        }

        $affected = parent::delete();

        if ($shouldAudit) {
            foreach ($beforeRows as $key => $image) {
                if ($image === null) {
                    continue;
                }
                AuditLog::record(AuditAction::Delete, $this->model->getTable(), $key, $image, null);
            }
        }

        return $affected;
    }

    /**
     * True when the model save internals (performUpdate / performDeleteOnModel)
     * are the caller — those paths are audited by the model-event hooks already.
     */
    private function fromSavePath(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $frame) {
            if (in_array($frame['function'] ?? '', ['performUpdate', 'performDeleteOnModel'], true)) {
                return true;
            }
        }

        return false;
    }
}
