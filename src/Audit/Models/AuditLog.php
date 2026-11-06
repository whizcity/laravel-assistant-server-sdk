<?php

namespace Whizcity\AssistantServer\Audit\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_logs';

    protected $fillable = [
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
        'created_at',
    ];

    protected $casts = [
        'before'     => 'array',
        'after'      => 'array',
        'synced'     => 'boolean',
        'synced_at'  => 'datetime',
        'created_at' => 'datetime',
    ];
}
