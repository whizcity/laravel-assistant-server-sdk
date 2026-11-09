<?php

namespace Whizcity\AssistantServer\Audit;

/**
 * Canonical audit actions. Backed by the exact DB values (string(16) column),
 * so the spelling can never drift between call sites.
 */
enum AuditAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
