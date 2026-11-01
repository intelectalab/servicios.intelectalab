<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VaultCrypto
{
    /**
     * Cifra el secreto (usando la APP_KEY de Laravel) y crea una nueva
     * versión, guardando el resultado como hexadecimal para evitar
     * problemas de codificación con el tipo bytea de PostgreSQL.
     */
    public static function storeVersion(string $tenantId, string $credentialId, string $plainSecret, ?string $userId, int $version, ?string $reason = null): void
    {
        $encrypted = Crypt::encryptString($plainSecret);
        $hex = bin2hex($encrypted);

        DB::insert(
            'INSERT INTO credential_versions (id, tenant_id, credential_id, version, secret_ciphertext, created_by, reason, created_at)
             VALUES (?, ?, ?, ?, decode(?, \'hex\'), ?, ?, now())',
            [(string) Str::uuid(), $tenantId, $credentialId, $version, $hex, $userId, $reason]
        );
    }

    /**
     * Descifra la última versión del secreto. Nunca debe registrarse
     * el valor devuelto en logs ni en auditoría.
     */
    public static function revealLatest(string $credentialId): ?string
    {
        $row = DB::selectOne(
            "SELECT encode(secret_ciphertext, 'hex') as hex
             FROM credential_versions
             WHERE credential_id = ?
             ORDER BY version DESC
             LIMIT 1",
            [$credentialId]
        );

        if (! $row) {
            return null;
        }

        $encrypted = hex2bin($row->hex);

        return Crypt::decryptString($encrypted);
    }
}