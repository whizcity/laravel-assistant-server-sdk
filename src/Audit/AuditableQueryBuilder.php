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
 * (performUpdate -> setKeysForSaveQuery(...)->update($dirty)); the Auditable
 * trait flips a save-path flag around both internal calls, so they are left
 * to the model-event hooks and never recorded twice here.
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
                    continue; // payload emptied by sensitive-field filtering or no real change: skip
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
     * Auto-audit query()-level inserts (single row or list of rows).
     * Multi-row inserts record ONE audit row per statement with the filtered
     * rows in after = {"count": N, "rows": [...]}; keeps the audit table one
     * row per write statement. Oversized payloads (>16KB JSON, the collector
     * limit) collapse to {"count": N, "truncated": true}.
     * Single-row and insertGetId keep the existing per-row recording with
     * resource_id = the returned id when available.
     */
    public function insert(array $values): bool
    {
        $shouldAudit = ! $this->fromSavePath() && $this->model->hasActorSnapshot();
        $rows = [];
        if ($shouldAudit) {
            $rows = (isset($values[0]) && is_array($values[0])) ? $values : [$values];
        }

        $ok = parent::insert($values);

        if ($ok && $shouldAudit) {
            $rows = array_values(array_filter(array_map(
                fn ($row) => $this->model->auditFilterPayload($row) ?: null,
                $rows
            )));
            if ($rows === []) {
                return $ok; // payload emptied by sensitive-field filtering: skip
            }

            if (count($rows) === 1) {
                AuditLog::record(AuditAction::Create, $this->model->getTable(), '', null, $rows[0]);
                return $ok;
            }

            $after = ['count' => count($rows), 'rows' => $rows];
            if (strlen(json_encode($after, JSON_UNESCAPED_UNICODE)) > 16384) {
                $after = ['count' => count($rows), 'truncated' => true]; // collector validates before/after <= 16KB JSON
            }
            AuditLog::record(AuditAction::Create, $this->model->getTable(), '', null, $after);
        }

        return $ok;
    }

    public function insertGetId(array $values, $sequence = null): int
    {
        $shouldAudit = ! $this->fromSavePath() && $this->model->hasActorSnapshot();
        $row = $shouldAudit ? $values : [];

        $id = parent::insertGetId($values, $sequence);

        if ($id && $shouldAudit) {
            $after = $this->model->auditFilterPayload($row) ?: [];
            if ($after !== []) {
                AuditLog::record(AuditAction::Create, $this->model->getTable(), (string) $id, null, $after);
            }
        }

        return $id;
    }

    /**
     * True when the model save internals (performUpdate / performDeleteOnModel)
     * are the caller — the Auditable trait flips $savePathActive around both,
     * and those paths are audited by the model-event hooks already.
     */
    private function fromSavePath(): bool
    {
        return $this->model->savePathActive();
    }
}
