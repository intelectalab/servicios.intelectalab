<?php

namespace App\Modules\Subscription\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * En qué punto está la vigencia de la suscripción de la empresa.
 *
 * - active: vigente.
 * - grace: venció hace menos de GRACE_DAYS días; todo sigue funcionando, con aviso.
 * - read_only: pasó la gracia; se puede ver y exportar, pero no crear ni modificar hasta renovar
 *   (EnsureSubscriptionWritable). Los datos no se tocan.
 *
 * Sin suscripción (empresa armada a mano por la plataforma) o sin fecha de fin: active.
 * Al extender la vigencia desde la consola, vuelve sola a active.
 */
class SubscriptionState
{
    public const GRACE_DAYS = 7;

    /**
     * @return array{state: string, ends_at: ?string, grace_until: ?string, is_trial: bool}
     */
    public static function for(string $tenantId): array
    {
        return self::resolve($tenantId);
    }

    public static function isReadOnly(string $tenantId): bool
    {
        return self::for($tenantId)['state'] === 'read_only';
    }

    /** Mensaje para quien intenta guardar algo en solo lectura. */
    public static function readOnlyMessage(string $tenantId): string
    {
        $endsAt = self::for($tenantId)['ends_at'];
        $date = $endsAt ? Carbon::parse($endsAt)->timezone('America/Lima')->format('d/m/Y') : null;

        return 'La suscripción de la empresa venció'.($date ? " el {$date}" : '').' y el sistema está en solo lectura: '
            .'puedes ver y exportar, pero no crear ni modificar. Para renovarla, pídelo en Administración → Módulos y plan.';
    }

    private static function resolve(string $tenantId): array
    {
        $subscription = DB::table('tenant_subscriptions')
            ->where('tenant_id', $tenantId)->where('status', 'ACTIVE')
            ->orderByDesc('starts_at')->first(['ends_at', 'configuration']);

        if (! $subscription || ! $subscription->ends_at) {
            return ['state' => 'active', 'ends_at' => null, 'grace_until' => null, 'is_trial' => false];
        }

        $endsAt = Carbon::parse($subscription->ends_at);
        $graceUntil = $endsAt->copy()->addDays(self::GRACE_DAYS);
        $config = json_decode((string) $subscription->configuration, true) ?: [];

        return [
            'state' => now()->lt($endsAt) ? 'active' : (now()->lt($graceUntil) ? 'grace' : 'read_only'),
            'ends_at' => $endsAt->toIso8601String(),
            'grace_until' => $graceUntil->toIso8601String(),
            'is_trial' => (bool) ($config['is_trial'] ?? false),
        ];
    }
}
