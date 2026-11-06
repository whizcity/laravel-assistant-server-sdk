<?php

namespace Whizcity\AssistantServer\Audit\Commands;

use Whizcity\AssistantServer\Client\AuditCollectorClient;
use Whizcity\AssistantServer\Audit\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncAuditLogs extends Command
{
    protected $signature = 'audit:sync {--limit=300 : Number of rows to sync per run}';

    protected $description = 'Batch push unsynced local audit logs to the central collector';

    public function handle(AuditCollectorClient $client): int
    {
        $limit    = (int) $this->option('limit');
        $maxRetry = (int) config('audit.max_retry', 5);

        $logs = AuditLog::query()
            ->where('synced', false)
            ->where('retry_count', '<', $maxRetry)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($logs->isEmpty()) {
            $this->info('No pending audit logs');
            return self::SUCCESS;
        }

        $payload = $logs->map(function (AuditLog $log) {
            return [
                'service'     => $log->service,
                'action'      => $log->action,
                'resource'    => $log->resource,
                'resource_id' => $log->resource_id,
                'operator_id' => $log->operator_id,
                'operator_name' => $log->operator_name,
                'operator_ip' => $log->operator_ip,
                'before'      => $log->before,
                'after'       => $log->after,
                'trace_id'    => $log->trace_id,
                'user_agent'  => $log->user_agent,
                'created_at'  => $log->created_at?->toISOString(),
            ];
        })->values()->all();

        $result = $client->syncBatch($payload);

        if ($result->isSuccess()) {
            AuditLog::whereIn('id', $logs->pluck('id'))->update([
                'synced'    => true,
                'synced_at' => now(),
            ]);

            $this->info("Synced {$logs->count()} logs");
            return self::SUCCESS;
        }

        // Configuration gaps are persistent and unaffected by retries: report
        // directly, without burning retry_count on the rows.
        if ($result->getCode() === 'CONFIG_ERROR') {
            $this->error($result->getMessage());
            return self::FAILURE;
        }

        AuditLog::whereIn('id', $logs->pluck('id'))->increment('retry_count');

        Log::warning('Audit batch sync failed', [
            'code'    => $result->getCode(),
            'message' => $result->getMessage(),
        ]);

        $this->error("Sync failed: {$result->getCode()} {$result->getMessage()}");
        return self::FAILURE;
    }
}
