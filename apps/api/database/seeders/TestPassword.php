<?php

namespace Database\Seeders;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Contraseña de las cuentas de prueba que crean los seeders locales. Ninguna va escrita en el
 * código (la repo es pública): se toma de SEED_TEST_PASSWORD en el .env local o, si no está, se
 * genera una al azar y se muestra una sola vez al correr el seeder.
 */
final class TestPassword
{
    public static function for(string $email, ?Command $command = null): string
    {
        $fromEnv = (string) env('SEED_TEST_PASSWORD', '');
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $password = Str::password(16, symbols: false);
        $command?->warn("Contraseña de {$email}: {$password} (guárdala: no se vuelve a mostrar).");

        return $password;
    }

    /** true si la contraseña viene del .env: solo entonces se puede reescribir la de una cuenta que ya existe. */
    public static function configured(): bool
    {
        return (string) env('SEED_TEST_PASSWORD', '') !== '';
    }
}
