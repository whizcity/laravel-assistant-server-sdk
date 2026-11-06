<?php

namespace Whizcity\AssistantServer\Client;

/**
 * Sugar for the audit domain of assistant-server, riding on
 * AssistantServerClient. New collectors endpoints never require an SDK
 * change — call request() directly and add a named method here only when a
 * call site proves frequent.
 *
 * @method ResultCase sync(array $log)                POST a single audit log ("单条同步").
 * @method ResultCase syncBatch(array $logs)          POST a JSON array of audit logs ("批量同步").
 * @method ResultCase get(string $id, array $filters = []) GET one audit log by id ("单条").
 * @method ResultCase query(array $filters)           GET logs filtered by query string ("查询/回拉").
 */
class AuditCollectorClient extends AssistantServerClient
{
    protected array $APIMap = [
        // api name => [path, method, request type]
        // request type: "json" (POST, body) | "query" (GET, filters) | "id" (GET, {id} substitution)
        'sync'      => ['/api/v1/audit/logs', 'post', 'json'],
        'syncBatch' => ['/api/v1/audit/logs/batch', 'post', 'json'],
        'get'       => ['/api/v1/audit/logs/{id}', 'get', 'id'],
        'query'     => ['/api/v1/audit/logs', 'get', 'query'],
    ];
}
