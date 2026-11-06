<?php

namespace Whizcity\AssistantServer\Audit\Commands;

use Whizcity\AssistantServer\Client\AuditCollectorClient;
use Whizcity\AssistantServer\Audit\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncAuditLogs extends Command
{
    protected $signature = "audit:sync {--limit=300 : Number of rows to sync per run}";

    protected $description = "Batch push unsynced local audit logs to the central collector";

    public function handle(AuditCollectorClient $client): int
    {
        $limit = (int) $this->option("limit");
        $maxRetry = (int) config("audit.max_retry", 5);

        $logs = AuditLog::query()
            ->where("synced", 0)
            ->where("retry_count", "<", $maxRetry)
            ->where(
                fn($q) => $q
                    ->whereNull("next_retry_at")
                    ->orWhere("next_retry_at", "<=", now()),
            )
            ->orderBy("id")
            ->limit($limit)
            ->get();

        if ($logs->isEmpty()) {
            $this->info("No pending audit logs");
            return self::SUCCESS;
        }

        $payload = $logs
            ->map(function (AuditLog $log) {
                return [
                    "event_id" => $log->event_id,
                    "service" => $log->service,
                    "action" => $log->action,
                    "resource" => $log->resource,
                    "resource_id" => $log->resource_id,
                    "operator_id" => $log->operator_id,
                    "operator_name" => $log->operator_name,
                    "operator_ip" => $log->operator_ip,
                    "before" => $log->before,
                    "after" => $log->after,
                    "trace_id" => $log->trace_id,
                    "user_agent" => $log->user_agent,
                    "created_at" => $log->created_at?->toISOString(),
                ];
            })
            ->values()
            ->all();

        $result = $client->syncBatch($payload);

        if ($result->isSuccess()) {
            AuditLog::whereIn("id", $logs->pluck("id"))->update([
                "synced" => 1,
                "synced_at" => now(),
            ]);

            $this->info("Synced {$logs->count()} logs");
            return self::SUCCESS;
        }

        // Configuration gaps are persistent and unaffected by retries: report
        // directly, without burning retry_count on the rows.
        if ($result->getCode() === "CONFIG_ERROR") {
            $this->error($result->getMessage());
            return self::FAILURE;
        }

        // Exponential-free backoff ladder (industry style, e.g. webhook and
        // Sidekiq-style explicit schedules): after attempt N the next attempt
        // waits RETRY_LADDER_MINUTES[N-1] minutes. Rows cooling down are
        // skipped by the gate above and retried when their time is up.
        foreach ($logs as $log) {
            $attempts = $log->retry_count + 1;
            $log->update([
                "retry_count" => $attempts,
                "next_retry_at" => now()->addMinutes(
                    $this->retryWaitMinutes($attempts),
                ),
            ]);
        }

        Log::warning("Audit batch sync failed", [
            "code" => $result->getCode(),
            "message" => $result->getMessage(),
        ]);

        $this->error(
            "Sync failed: {$result->getCode()} {$result->getMessage()}",
        );
        return self::FAILURE;
    }

    /**
     * Escalating retry schedule in minutes — an industry-style explicit
     * ladder (as used by webhook retry systems and Sidekiq's default
     * backoff table) rather than strict doubling: 2, 5, 15, 30, 60.
     * Attempts past the table clamp to the last entry.
     */
    private function retryWaitMinutes(int $attempts): int
    {
        $ladderMinutes = [2, 5, 15, 30, 60];

        return $ladderMinutes[
            min(max($attempts, 1), count($ladderMinutes)) - 1
        ];
    }
}
