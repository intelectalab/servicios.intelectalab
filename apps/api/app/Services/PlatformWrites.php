<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Cambios en las tablas de plataforma (saas_plans, tenant_subscriptions). La base solo los
 * deja pasar dentro de una sesión de superadmin vigente y verificada en dos pasos
 * (trigger guard_platform_tables): aquí se le indica cuál es, solo para la transacción en curso.
 */
class PlatformWrites
{
    /** Ejecuta $callback en una transacción con la sesión del superadmin de esta petición. */
    public static function run(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            self::enter();

            return $callback();
        });
    }

    /** Dentro de una transacción ya abierta. */
    public static function enter(): void
    {
        $token = request()->user()?->currentAccessToken();
        $id = $token instanceof PersonalAccessToken ? (string) $token->getKey() : '';
        DB::statement("SELECT set_config('app.platform_token', ?, true)", [$id]);
    }
}
