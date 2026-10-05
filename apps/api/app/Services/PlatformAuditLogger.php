<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlatformAuditLogger
{
    /**
     * Registra una acción de la consola de plataforma (super admin).
     * Para acciones dentro de un tenant se usa AuditLogger.
     */
    public static function log(
        ?string $userId,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $beforeData = null,
        ?array $afterData = null,
        array $metadata = []
    ): void {
        DB::table('platform_audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'before_data' => $beforeData ? json_encode($beforeData) : null,
            'after_data' => $afterData ? json_encode($afterData) : null,
            'metadata' => json_encode((object) $metadata),
            'created_at' => now(),
        ]);
    }
}
