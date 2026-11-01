<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Enlace de ingreso propio de cada empresa: /e/{nombre-corto}. El nombre corto se genera al crear
 * la empresa y lo puede cambiar su Administrador (Marca) o la plataforma. La pantalla de ingreso lee
 * por él solo lo público de la marca: nombre, logo, color y bienvenida.
 */
class LoginSlug
{
    /** Palabras que no pueden ser el enlace de una empresa (rutas y nombres de la plataforma). */
    public const RESERVED = [
        'admin', 'api', 'app', 'e', 'login', 'logout', 'portal', 'superadmin', 'plataforma', 'consola', 'www', 'soporte',
        'support', 'ayuda', 'help', 'gscreativas', 'erp', 'erp-saas', 'sistema', 'demo', 'test', 'prueba', 'static', 'assets',
    ];

    public const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/';

    private const CACHE_PREFIX = 'login-brand:';

    /** Nombre corto libre a partir del nombre de la empresa: "Minera Los Andes S.A.C." → "minera-los-andes". */
    public static function forName(string $name, ?string $exceptTenantId = null): string
    {
        $base = Str::slug(preg_replace('/\b(s\.?\s?a\.?\s?c\.?|s\.?\s?a\.?|e\.?\s?i\.?\s?r\.?\s?l\.?|s\.?\s?r\.?\s?l\.?)\s*$/i', '', $name) ?: $name);
        $base = trim(substr($base, 0, 36), '-');
        if (strlen($base) < 3 || in_array($base, self::RESERVED, true)) {
            $base = trim('empresa-'.$base, '-');
        }

        $slug = $base;
        for ($i = 2; self::taken($slug, $exceptTenantId); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /** Valida un nombre corto elegido a mano; devuelve el normalizado. */
    public static function validate(string $slug, string $tenantId): string
    {
        $slug = strtolower(trim($slug));
        if (! preg_match(self::PATTERN, $slug) || str_contains($slug, '--')) {
            throw ValidationException::withMessages(['login_slug' => ['Usa de 3 a 40 letras minúsculas, números o guiones (sin guion al inicio, al final ni dos seguidos).']]);
        }
        if (in_array($slug, self::RESERVED, true)) {
            throw ValidationException::withMessages(['login_slug' => ['Ese nombre está reservado por la plataforma. Elige otro.']]);
        }
        if (self::taken($slug, $tenantId)) {
            throw ValidationException::withMessages(['login_slug' => ['Otra empresa ya usa ese enlace. Elige otro.']]);
        }

        return $slug;
    }

    /**
     * Lo público de la marca para la pantalla de ingreso, o null si el enlace no existe o la empresa
     * no está activa (la pantalla muestra entonces el ingreso general, sin decir que no existe).
     */
    public static function brand(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if (! preg_match(self::PATTERN, $slug)) {
            return null;
        }

        $brand = Cache::remember(self::CACHE_PREFIX.$slug, 600, function () use ($slug) {
            $tenant = DB::table('tenants')->where('login_slug', $slug)->where('status', 'ACTIVE')->first(['id']);
            if (! $tenant) {
                return false;
            }

            // La marca está aislada por empresa: se lee dentro de su contexto, y solo lo público.
            return DB::transaction(function () use ($tenant, $slug) {
                DB::statement("SELECT set_config('app.tenant_id', ?, true)", [(string) $tenant->id]);
                $brand = Branding::of((string) $tenant->id);
                $welcome = DB::table('company_branding')->where('tenant_id', $tenant->id)->value('login_welcome');

                return [
                    'slug' => $slug,
                    'name' => $brand['trade_name'] ?: $brand['name'],
                    'logo' => $brand['logo'],
                    'logo_width' => $brand['logo_width'],
                    'logo_height' => $brand['logo_height'],
                    'primary_color' => $brand['primary_color'],
                    'welcome' => $welcome,
                ];
            });
        });

        return $brand ?: null;
    }

    /** Al cambiar la marca o el enlace de una empresa: la pantalla de ingreso lo toma enseguida. */
    public static function forget(?string ...$slugs): void
    {
        foreach (array_filter($slugs) as $slug) {
            Cache::forget(self::CACHE_PREFIX.strtolower($slug));
        }
    }

    public static function of(string $tenantId): ?string
    {
        return DB::table('tenants')->where('id', $tenantId)->value('login_slug');
    }

    private static function taken(string $slug, ?string $exceptTenantId): bool
    {
        return DB::table('tenants')->where('login_slug', $slug)
            ->when($exceptTenantId, fn ($query) => $query->where('id', '<>', $exceptTenantId))->exists();
    }
}
