<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    private ?string $platformFixtureToken = null;

    /**
     * Preparativos que en la vida real hace la consola (encender módulos fuera de un plan,
     * cambiar el estado de una empresa, quitar un módulo): la base solo los deja pasar con una
     * sesión de superadmin verificada (guard_tenant_tables, guard_platform_tables).
     */
    protected function asPlatformFixture(callable $callback): mixed
    {
        if ($this->platformFixtureToken === null) {
            $user = new User();
            $user->forceFill([
                'email' => 'sa-fixture-'.Str::random(8).'@erp-saas.test', 'password_hash' => bcrypt(Str::random(20)),
                'name' => 'Plataforma Prueba', 'status' => 'ACTIVE', 'is_super_admin' => true,
            ])->save();
            // Sin vencimiento: algunas pruebas viajan en el tiempo y la base compara con la hora real.
            $token = $user->createToken('fixture', ['*'])->accessToken;
            $token->forceFill(['mfa' => true])->save();
            $this->platformFixtureToken = (string) $token->getKey();
        }

        DB::statement("SELECT set_config('app.platform_token', ?, true)", [$this->platformFixtureToken]);
        try {
            return $callback();
        } finally {
            // Si el preparativo falló, la transacción quedó abortada: no se tapa su error.
            try {
                DB::statement("SELECT set_config('app.platform_token', '', true)");
            } catch (\Illuminate\Database\QueryException) {
            }
        }
    }
}
