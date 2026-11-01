<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLogger
{
    public static function log(
        string $tenantId,
        ?string $userId,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $beforeData = null,
        ?array $afterData = null,
        array $metadata = []
    ): void {
        $id = (string) Str::uuid();
        DB::table('audit_logs')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'correlation_id' => (string) Str::uuid(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'before_data' => $beforeData ? json_encode($beforeData) : null,
            'after_data' => $afterData ? json_encode($afterData) : null,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
        ]);

        // Si la acción dispara reglas de la empresa (Control → Workflows), corren al guardar.
        Workflows::audited($tenantId, $userId, $action, $entityType, $entityId, $id);
    }
}