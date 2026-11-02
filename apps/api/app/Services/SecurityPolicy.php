<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Política de seguridad de la empresa (Administración → Seguridad y aislamiento):
 * - require_mfa_all: código al correo para todos sus usuarios, no solo el administrador;
 * - session_hours: cuánto dura una sesión sin "Recordarme" (1 a 12 h; 8 si no se cambió);
 * - allow_remember: si se permite "Recordarme" (30 días);
 * - idle_minutes: cerrar la sesión tras ese tiempo sin uso (0 = no).
 * Se aplica al iniciar sesión (verificación y duración) y en cada petición (inactividad).
 */
class SecurityPolicy
{
    public const IDLE_OPTIONS = [0, 15, 30, 60, 120, 240];

    public static function for(?string $tenantId): array
    {
        $defaults = [
            'require_mfa_all' => false,
            'session_hours' => max(1, min(12, intdiv((int) config('sanctum.session_minutes', 480), 60))),
            'allow_remember' => true,
            'idle_minutes' => 0,
        ];
        if (! $tenantId) {
            return $defaults;
        }

        $configuration = json_decode(DB::table('tenants')->where('id', $tenantId)->value('configuration') ?: '{}', true) ?: [];
        $security = $configuration['security'] ?? [];

        return [
            'require_mfa_all' => (bool) ($security['require_mfa_all'] ?? $defaults['require_mfa_all']),
            'session_hours' => max(1, min(12, (int) ($security['session_hours'] ?? $defaults['session_hours']))),
            'allow_remember' => (bool) ($security['allow_remember'] ?? $defaults['allow_remember']),
            'idle_minutes' => in_array((int) ($security['idle_minutes'] ?? 0), self::IDLE_OPTIONS, true) ? (int) ($security['idle_minutes'] ?? 0) : 0,
        ];
    }

    /** Minutos de la sesión nueva según la política. */
    public static function sessionMinutes(?string $tenantId, bool $remember): int
    {
        $policy = self::for($tenantId);
        if ($remember && $policy['allow_remember']) {
            return (int) config('sanctum.remember_minutes');
        }

        return $policy['session_hours'] * 60;
    }
}
