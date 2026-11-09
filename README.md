# Whizcity Assistant-Server（Laravel SDK）

私有 Composer 包：`assistant-server`（Python / FastAPI）的 Laravel 客户端 SDK。统一封装一次性 HMAC 签名鉴权与请求响应协议；audit 日志域（本地落盘 + 定时批量同步）与 OCR 域是当前已内置能力的示例，服务端新加接口不需要改 SDK。

**要求：** PHP ^8.4 · Laravel ^12

## 架构

```
Whizcity\AssistantServer\
├─ Client\
│  ├─ AssistantServerClient     通用客户端：一次性 HMAC 签名、request()/requestMultipart()、进度日志开关
│  ├─ AuditCollectorClient      audit 域命名糖（extends AssistantServerClient）
│  ├─ Signer                    与 assistant-server app.auth.signature 同一算法
│  └─ ResultCase                统一响应语义（body 无 code 字段即成功）
└─ Audit\
   ├─ Traits\Auditable          Model 引入后本地落盘操作日志
   ├─ Models\AuditLog           本地 audit_logs 表
   └─ Commands\{SyncAuditLogs, CleanSyncedAuditLogs}
```

audit 域数据流：

```
Model (use Auditable)
  └─ 写入本地 audit_logs 表（同步、低延迟）
       └─ php artisan audit:sync   →  POST /api/v1/audit/logs/batch
       └─ php artisan audit:clean  →  清理已同步的旧数据
```

- 业务请求路径**只写本地库**，不发起 HTTP
- 失败自动重试：内置递进间隔 **2 / 5 / 15 / 30 / 60 分钟**（行业常用显式阶梯，不作配置项），`retry_count` 超上限后保留待人工处理
- 冷却中的行不参与下一次 sync，新写入的行不受影响
- 默认过滤密码等敏感字段

## 安装

私有库（GitHub 仓库 `whizcity/laravel-assistant-server-sdk`），未发布 Packagist，消费方需先登记 VCS 仓库再安装。公司环境的机器普遍预置了 GitHub SSH 凭证，**SSH URL 直接可拉，免 token**。

**1. 消费方项目登记仓库**（composer.json 的 `repositories` 加一条）：

```json
"repositories": [
  {
    "type": "vcs",
    "url": "git@github.com:whizcity/laravel-assistant-server-sdk.git"
  }
]
```

**2. 安装**：

```bash
composer require whizcity/assistant-server
```

极少数用不了 SSH 的环境（如临时 CI 容器没有 deploy key），改用 HTTPS URL + token 兜底：`repositories` 的 url 换成 `https://github.com/whizcity/laravel-assistant-server-sdk.git`，机器上一次性 `composer config -g github-oauth.github.com ghp_your_token`；CI 里用 `COMPOSER_AUTH={"github-oauth":{"github.com":"ghp_your_token"}}` 环境变量。

发布配置（可选）：

```bash
php artisan vendor:publish --tag=assistant-server-config
php artisan vendor:publish --tag=audit-migrations
php artisan migrate
```

版本约定：本仓库打 git tag（如 `v0.1.0`）即为可锁定版本；开发版用 `dev-main` 引用。

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

## audit 域使用

在需要审计的模型上引入 Trait：

```php
use Whizcity\AssistantServer\Audit\Traits\Auditable;

class Order extends Model
{
    use Auditable;

    // 可选：只记录这些字段
    protected array $auditOnly = ['status', 'amount', 'customer_id'];

    // 可选：额外排除字段
    // protected array $auditExcept = ['internal_note'];
}
```

`operator_id` / `operator_name` 取自请求上认证中间件注入的 actor 快照（数组，含 `id`、`nickname`）。快照键名可配置，SDK 默认用中性键 `user`，不硬编码任何内部实现——部署方在 env 里把 `AUDIT_USER_INFO_KEY` 指到自己中间件真实注册的键名即可；guest 请求两侧为 `null`。

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

## 客户端调用

通用客户端 `AssistantServerClient`，任意路径直接调（服务端加接口不用等 SDK）：

```php
use Whizcity\AssistantServer\Client\AssistantServerClient;

$client = app(AssistantServerClient::class);

// POST/PUT/PATCH：参数作 JSON body；GET 等：参数作 query string（query 参与签名）
$result = $client->request('post', '/api/v1/audit/logs/batch', $logs);
$result = $client->request('get', '/api/v1/audit/logs', ['service' => 'order-service']);

// 文件上传走 multipart（例：OCR），签名覆盖 multipart 原始字节
$result = $client->requestMultipart('post', '/api/v1/ocr',
    'file', 'receipt.png', $imageBytes, ['return_word_box' => 'true']);

if ($result->isSuccess()) {
    $data = $result->getData();   // 例如 OCR: ['text' => ..., 'items' => ..., 'words' => ..., 'elapse' => ...]
} else {
    $result->getCode();    // 例如 "VALIDATION_ERROR"、"INTERNAL_ERROR"、"CONFIG_ERROR"、HTTP 状态码
    $result->getMessage();
}
```

audit 域高频接口可走命名糖 `AuditCollectorClient`（`__call` + `APIMap`）：

```php
use Whizcity\AssistantServer\Client\AuditCollectorClient;

```php
$collector = app(AuditCollectorClient::class);
$collector->syncBatch($logs);
$collector->query(['action' => 'update', 'resource' => 'orders']);
$collector->get('123');
```

命名方法只是糖——任意路径都能 `request()`，新接口想糖了在 APIMap 加一行即可。

排查问题时把 `ASSISTANT_SERVER_LOG=true` 打开：客户端以 debug 级别记录每次调用（API 名与参数）与每次线上结果（method / path / 返回 code），传输异常以 error 级别记录；默认完全静默。

## 中心服务约定（audit 域）

- `POST /api/v1/audit/logs` — 单条同步，Body 为 JSON 对象
- `POST /api/v1/audit/logs/batch` — 批量同步（推荐），Body 为 JSON 数组
- 每条记录带客户端生成的 `event_id`（ULID），collector 以其为幂等键（重试重推同批不产生重复入库），并作为中心侧唯一锚点
- `GET /api/v1/audit/logs/{id}` — 单条查询
- `GET /api/v1/audit/logs` — 查询/回拉，过滤参数放在 query string
- OCR：`POST /api/v1/ocr` — multipart/form-data，字段 `file`（jpg/png/bmp/webp/tiff），可选 `return_word_box`

  签名串 = `HTTP大写分\nPATH\n原始QUERY\nTIMESTAMP\nNONCE\nSHA256(BODY)`，
  `X-Signature` = HMAC-SHA256(appSecret, 签名串) 小写 hex；五个头一并发送：
  `X-App-Id` / `X-Timestamp`（Unix 秒）/ `X-Nonce`（8–64 位 [A-Za-z0-9_-]）/
  `X-Content-SHA256`（空 body 也要算）/ `X-Signature`。
- 错误响应为 `{"code": "<str>", "message": "<str>"}`；正确响应 body 无 `code` 字段。
- 单次批量建议 ≤ 500 条

四个 audit 接口的路径是占位，等 Python 端定稿后在 `AuditCollectorClient` 的 `APIMap` 一处调整。audit 字段示例：

```json
{
  "event_id": "01HV4ZJ3KF7XY98ZXNQFS11JJ9",
  "service": "order-service",
  "action": "update",
  "resource": "orders",
  "resource_id": "123",
  "operator_id": "45",
  "operator_name": "张三",
  "operator_ip": "1.2.3.4",
  "before": {"status": "pending"},
  "after": {"status": "paid"},
  "trace_id": "abc-123",
  "user_agent": "...",
  "created_at": "2026-11-06T10:00:00+08:00"
}
```

## 命名空间

`Whizcity\AssistantServer`

测试：`composer test`（需要 dev 依赖 `orchestra/testbench` / `phpunit/phpunit`，随包 lock）。
