<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TenantAccess
{
    /** Código de error que el frontend usa para llevar a la pantalla de bloqueo. */
    public const BLOCKED_CODE = 'TENANT_BLOCKED';

    /**
     * Única fuente de los mensajes por estado: los usan el login y la pantalla
     * de bloqueo (que muestra el mensaje que le devuelve la API). ":empresa" se
     * reemplaza por el nombre del tenant.
     */
    private const MESSAGES = [
        'SUSPENDED' => 'La cuenta de :empresa está suspendida. Tus datos se conservan; contacta a soporte de la plataforma.',
        'PROVISIONING' => 'La cuenta de :empresa todavía se está configurando. Podrás ingresar cuando termine la activación.',
        'MAINTENANCE' => 'La cuenta de :empresa está en mantenimiento. Tus datos se conservan; intenta de nuevo más tarde.',
        'CLOSED' => 'La cuenta de :empresa está cerrada. Contacta a soporte de la plataforma.',
    ];

    private const FALLBACK = 'La cuenta de :empresa está inactiva. Tus datos se conservan; contacta a soporte de la plataforma.';

    /**
     * Si el tenant NO puede operar devuelve su estado y el mensaje para el usuario; null si está activo.
     *
     * @return array{status: string, message: string}|null
     */
    public static function blocked(string $tenantId): ?array
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->first(['name', 'status']);

        if (! $tenant || $tenant->status === 'ACTIVE') {
            return null;
        }

        return [
            'status' => (string) $tenant->status,
            'message' => str_replace(':empresa', $tenant->name, self::MESSAGES[$tenant->status] ?? self::FALLBACK),
        ];
    }
}
