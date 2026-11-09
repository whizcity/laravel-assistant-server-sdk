<?php

namespace Whizcity\AssistantServer\Client;

/**
 * Sugar for the audit domain of assistant-server, riding on
 * AssistantServerClient. New collectors endpoints never require an SDK
 * change — call request() directly and add a named method here only when a
 * call site proves frequent.
 *
 * @method ResultCase sync(array $log)                POST a single audit log (single-item sync).
 * @method ResultCase syncBatch(array $logs)          POST a JSON array of audit logs (batch sync).
 * @method ResultCase get(string $id, array $filters = []) GET one audit log by id (single item).
 * @method ResultCase query(array $filters)           GET logs filtered by query string (query/pull back).
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
