<?php

namespace Tests\Feature\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Superadmin de prueba con una sesión real (token de Sanctum), con o sin verificación en dos pasos. */
trait PlatformSession
{
    private function superAdmin(string $status = 'ACTIVE'): User
    {
        $user = new User();
        $user->forceFill([
            'email' => 'sa-'.Str::random(8).'@erp-saas.test', 'password_hash' => bcrypt('password123'),
            'name' => 'Plataforma Prueba', 'status' => $status, 'is_super_admin' => true,
        ])->save();

        return $user;
    }

    /** Planes o suscripciones de prueba: la base solo los deja escribir con una sesión de superadmin verificada. */
    private function asPlatform(callable $callback): mixed
    {
        $token = $this->superAdmin()->createToken('fixture', ['*'], now()->addHour())->accessToken;
        $token->forceFill(['mfa' => true])->save();
        DB::statement("SELECT set_config('app.platform_token', ?, true)", [(string) $token->getKey()]);
        try {
            return $callback();
        } finally {
            DB::statement("SELECT set_config('app.platform_token', '', true)");
        }
    }

    private function signIn(User $user, bool $mfa = true): void
    {
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('api-token', ['*'], now()->addHour());
        $token->accessToken->forceFill(['mfa' => $mfa])->save();
        $this->withToken($token->plainTextToken);
    }
}
