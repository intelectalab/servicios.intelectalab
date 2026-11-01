<?php

namespace App\Modules\Subscription\Support;

use App\Modules\Subscription\Http\Controllers\SuperAdmin\PlatformDashboardController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Configuración de la plataforma (canales de soporte que ven todas las empresas). La lee y la
 * cambia el usuario de la consola: la app de las empresas no tiene acceso a la tabla. Lo que
 * leen las empresas se guarda en caché y se renueva al guardar.
 */
class PlatformSettings
{
    public const SUPPORT_KEY = 'support';

    private const CONNECTION = PlatformDashboardController::CONNECTION;

    private const CACHE_PREFIX = 'platform-settings:';

    public static function get(string $key): ?array
    {
        return Cache::rememberForever(self::CACHE_PREFIX.$key, function () use ($key) {
            $value = DB::connection(self::CONNECTION)->table('platform_settings')->where('key', $key)->value('value');
            $decoded = is_array($value) ? $value : json_decode((string) $value, true);

            return is_array($decoded) ? $decoded : null;
        });
    }

    public static function set(string $key, array $value): void
    {
        DB::connection(self::CONNECTION)->table('platform_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now()]
        );
        Cache::forget(self::CACHE_PREFIX.$key);
    }

    /** Canales de soporte; null mientras la plataforma no los configure (no se muestran datos de ejemplo). */
    public static function support(): ?array
    {
        $support = self::get(self::SUPPORT_KEY);

        return $support && (($support['whatsapp'] ?? '') !== '' || ($support['email'] ?? '') !== '') ? $support : null;
    }
}
