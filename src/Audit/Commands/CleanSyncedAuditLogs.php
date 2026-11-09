<?php

namespace Whizcity\AssistantServer\Audit\Commands;

use Whizcity\AssistantServer\Audit\Models\AuditLog;
use Illuminate\Console\Command;

class CleanSyncedAuditLogs extends Command
{
    protected $signature = 'audit:clean
                            {--days= : Retention days (defaults to config audit.retention_days)}
                            {--limit=5000 : Max rows to delete per batch}';

    protected $description = 'Delete old already-synced local audit logs';

    public function handle(): int
    {
        $days  = (int) ($this->option('days') ?: config('assistant_server.audit.retention_days', 30));
        $limit = (int) $this->option('limit');
        $threshold = now()->subDays($days);

        $this->info("Cleaning synced logs older than {$threshold}...");

        $total = 0;

        do {
            $ids = AuditLog::query()
                ->where('synced', 1)
                ->where('synced_at', '<', $threshold)
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted = AuditLog::whereIn('id', $ids)->delete();
            $total  += $deleted;

            $this->info("Deleted {$deleted} (total {$total})");

            // Brief pause to reduce pressure on the database
            usleep(100000);
        } while ($ids->count() === $limit);

        $this->info("Done. Removed {$total} rows");

        return self::SUCCESS;
    }
}
