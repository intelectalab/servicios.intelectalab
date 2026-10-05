<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AuditHistory
{
    /**
     * Movimientos registrados en auditoría para un registro, del más reciente
     * al más antiguo, con el nombre de quien los hizo. Requiere app.tenant_id.
     *
     * @return array<int, array{action: string, user: ?string, at: string, metadata: array}>
     */
    public static function for(string $entityType, string $entityId): array
    {
        return DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.entity_type', $entityType)
            ->where('audit_logs.entity_id', $entityId)
            ->orderByDesc('audit_logs.created_at')
            // created_at solo tiene precisión de segundos: si guardar y enviar caen en
            // el mismo segundo, el alta o la edición van antes que el cambio de estado.
            ->orderByRaw("CASE WHEN audit_logs.action LIKE '%.create' OR audit_logs.action LIKE '%.version' THEN 2 WHEN audit_logs.action LIKE '%.update' THEN 1 ELSE 0 END")
            ->limit(100)
            ->get(['audit_logs.action', 'users.name as user', 'audit_logs.created_at as at', 'audit_logs.metadata'])
            ->map(fn ($row) => [
                'action' => $row->action,
                'user' => $row->user,
                'at' => \Illuminate\Support\Carbon::parse($row->at)->toIso8601String(),
                'metadata' => json_decode($row->metadata ?? '{}', true) ?: [],
            ])
            ->all();
    }
}
