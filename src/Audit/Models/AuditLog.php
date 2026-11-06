<?php

namespace Whizcity\AssistantServer\Audit\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_logs';

    protected $fillable = [
        'event_id',
        'service',
        'action',
        'resource',
        'resource_id',
        'operator_id',
        'operator_name',
        'operator_ip',
        'before',
        'after',
        'trace_id',
        'user_agent',
        'synced',
        'synced_at',
        'retry_count',
        'next_retry_at',
        'created_at',
    ];

    protected $casts = [
        'before'        => 'array',
        'after'         => 'array',
        'synced'        => 'integer',
        'synced_at'     => 'datetime',
        'retry_count'   => 'integer',
        'next_retry_at' => 'datetime',
        'created_at'    => 'datetime',
    ];
}
