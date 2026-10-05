<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class LoginThrottle
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;

    /**
     * El bloqueo va por correo, exista o no la cuenta, para que la respuesta
     * no revele qué correos están registrados.
     */
    public static function isLocked(string $email): bool
    {
        return Cache::has(self::lockKey($email));
    }

    /**
     * Registra un intento fallido. Devuelve true si con este intento la cuenta queda bloqueada.
     */
    public static function fail(string $email): bool
    {
        $key = self::attemptsKey($email);
        $attempts = (int) Cache::get($key, 0) + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            Cache::forget($key);
            Cache::put(self::lockKey($email), true, now()->addMinutes(self::LOCK_MINUTES));

            return true;
        }

        Cache::put($key, $attempts, now()->addMinutes(self::LOCK_MINUTES));

        return false;
    }

    /**
     * Limpia intentos y bloqueo: al iniciar sesión bien o al restablecer la contraseña.
     */
    public static function clear(string $email): void
    {
        Cache::forget(self::attemptsKey($email));
        Cache::forget(self::lockKey($email));
    }

    private static function attemptsKey(string $email): string
    {
        return 'login-attempts:'.sha1(mb_strtolower(trim($email)));
    }

    private static function lockKey(string $email): string
    {
        return 'login-lock:'.sha1(mb_strtolower(trim($email)));
    }
}
