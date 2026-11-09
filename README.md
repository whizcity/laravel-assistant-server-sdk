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
AUDIT_USER_INFO_KEY=
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

## Trait 审计

在模型上引入 trait 即自动审计 create / update / delete：

```php
use Whizcity\AssistantServer\Audit\Traits\Auditable;

class Order extends Model
{
    use Auditable;

    // 可选白名单：只记这些字段（空数组 = 全字段，但永远叠加 except 黑名单）
    protected array $auditOnly = [];

    // 可选：模型级额外黑名单，与 config 的 assistant_server.audit.except 合并
    protected array $auditExcept = ['remember_token'];
}
```

落库规则：

- **create**：记全字段快照（after）；**update**：只记 dirty 字段的 before→after；**delete**：记原值（before）。敏感字段由 `audit.except` + `$auditExcept` 双层过滤。
- **operator_id / operator_name**：取请求上认证中间件注入的 actor 快照（数组，含 `id` / `nickname`），键名就是上面 env 里的 `AUDIT_USER_INFO_KEY`。
- **无快照不记录**：命令行 / 定时任务 / 队列等非管理员上下文不产生审计行。
- 落库即结束，同步到中心由 `audit:sync` 完成；`DB::table(...)` 直写的的场景见下节「手动补记」。

## 手动补记

模型 trait（`Auditable`）只覆盖 Eloquent 事件写入。两类写入点不触发有效审计，需要手动补一行：

- `DB::table(...)` / 查询构造器 —— 模型事件根本不触发；
- `Model::query()->where(...)->update([...])` / `->delete()`（批量 SQL）——Laravel 会触发 `updating` 事件但拿不到 dirty，trait 的 updated 钩子跳过，不落审计行。

```php
use Whizcity\AssistantServer\Audit\AuditAction;
use Whizcity\AssistantServer\Audit\Models\AuditLog;

$before = DB::table('orders')->where('id', 5)->first(['status']); // update 前先取一份
DB::table('orders')->where('id', 5)->update(['status' => 'paid']);

AuditLog::record(
    AuditAction::Update,          // 枚举：Create / Update / Delete，传字符串会 TypeError
    'orders',
    '5',
    ['status' => 'pending'],      // before
    ['status' => 'paid'],         // after
);
```

规则与 trait 一致：请求上没有认证中间件注入的 actor 快照（`assistant_server.audit.user_info_key` 所指键）时不落库；`before`/`after` 原样入库，敏感字段由调用方自行剔除。

## 命名空间

`Whizcity\AssistantServer`

测试：`composer test`（需要 dev 依赖 `orchestra/testbench` / `phpunit/phpunit`，随包 lock）。
