<?php

namespace Whizcity\AssistantServer;

use Illuminate\Support\ServiceProvider;
use Whizcity\AssistantServer\Audit\Commands\CleanSyncedAuditLogs;
use Whizcity\AssistantServer\Audit\Commands\SyncAuditLogs;

class AssistantServerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/assistant_server.php' => config_path('assistant_server.php'),
        ], 'assistant-server-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'audit-migrations');

        // Allow consumers to skip publishing migrations if they prefer
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncAuditLogs::class,
                CleanSyncedAuditLogs::class,
            ]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/assistant_server.php', 'assistant_server');
    }
}
