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
 * trait flips a save-path flag around the three internal calls (performUpdate
 * / performDeleteOnModel / performInsert), so those paths are left to the
 * model-event hooks and never recorded twice here.
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
            if (count($beforeRows) === 1) {
                // A one-row statement keeps the per-row shape with resource_id = key.
                foreach ($beforeRows as $key => $image) {
                    if ($image === null) {
                        continue;
                    }
                    AuditLog::record(AuditAction::Delete, $this->model->getTable(), $key, $image, null);
                }
            } else {
                // Multi-row delete records ONE statement event (keys as the rows map),
                // chunked when the combined payload would exceed the 16KB collector budget.
                foreach ($this->chunkRows($beforeRows) as $chunk) {
                    AuditLog::record(
                        AuditAction::Delete,
                        $this->model->getTable(),
                        '',
                        null,
                        ['count' => count($chunk), 'rows' => $chunk],
                    );
                }
            }
        }

        return $affected;
    }

    /**
     * Auto-audit query()-level inserts (single row or list of rows).
     * Multi-row inserts record ONE audit row per statement with the filtered
     * rows in after = {"count": N, "rows": [...]}. A batch whose payload
     * would exceed the 16KB collector budget is split into several chunk
     * events, each within budget — no lossy truncation. Single-row and
     * insertGetId keep the existing per-row recording with resource_id =
     * the returned id when available.
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

            $chunks = $this->chunkRows($rows);

            foreach ($chunks as $chunk) {
                AuditLog::record(
                    AuditAction::Create,
                    $this->model->getTable(),
                    '',
                    null,
                    ['count' => count($chunk), 'rows' => array_values($chunk)],
                );
            }
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
     * Group rows so one audit event's after payload stays inside the 16KB
     * collector budget (AuditLog::PAYLOAD_LIMIT_BYTES). Keys are preserved:
     * inserts re-index (array_values) into a row list, deletes keep the
     * id-keyed map. Used by both insert() chunking and delete() merging.
     *
     * @return array<int, array<string, mixed>>
     */
    private function chunkRows(array $rows): array
    {
        $chunks = [];
        $current = [];
        $currentSize = 0;
        foreach ($rows as $key => $row) {
            $rowSize = strlen(json_encode($row, JSON_UNESCAPED_UNICODE)) + 16;
            if ($current !== [] && $currentSize + $rowSize > AuditLog::PAYLOAD_LIMIT_BYTES) {
                $chunks[] = $current;
                $current = [];
                $currentSize = 0;
            }
            $current[$key] = $row;
            $currentSize += $rowSize;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * True when the model save internals (performUpdate / performDeleteOnModel
     * / performInsert) are the caller — the Auditable trait flips
     * $savePathActive around all three, and those paths are audited by the
     * model-event hooks already.
     */
    private function fromSavePath(): bool
    {
        return $this->model->savePathActive();
    }
}
