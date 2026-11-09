**要求：** PHP ^8.4 · Laravel ^12


**. 安装**：

```bash
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/whizcity/laravel-assistant-server-sdk.git"
  }
]
```

发布配置（可选）：

```bash
php artisan vendor:publish --tag=assistant-server-config
php artisan vendor:publish --tag=audit-migrations
php artisan migrate
```

版本约定：本仓库打 git tag（如 `v0.1.0`）即为可锁定版本;

## 环境变量

```env
ASSISTANT_SERVER_ADDRESS=http://assistant-server:8080
ASSISTANT_SERVER_APP_ID=your-app-id
ASSISTANT_SERVER_SECRET_KEY=your-secret-key

# 排查问题用：开启后客户端记录每次调用的请求与响应（debug 级）、传输异常（error 级）
ASSISTANT_SERVER_LOG=false

# audit 域专属
AUDIT_SERVICE_NAME=order-service
AUDIT_RETENTION_DAYS=30
```

定时任务（Laravel 12，`routes/console.php`）：

```php
Schedule::command('audit:sync --limit=300')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('audit:clean --days=30')
    ->dailyAt('03:00')
    ->withoutOverlapping();
```

| 命令 | 说明 |
|------|------|
| `php artisan audit:sync --limit=300` | 批量推送未同步日志 |
| `php artisan audit:clean --days=30` | 删除已同步且超过保留期的本地日志 |

```

## 命名空间

`Whizcity\AssistantServer`

测试：`composer test`（需要 dev 依赖 `orchestra/testbench` / `phpunit/phpunit`，随包 lock）。
