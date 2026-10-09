<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las sesiones abiertas antes de que se guardara la marca de verificación en dos pasos
 * quedaron sin ella. En las cuentas donde el código es obligatorio (plataforma, quien lo
 * tiene activado, administradores de empresa y usuarios del portal) toda sesión pasó por
 * el código, así que se marcan.
 *
 * Solo corrige esa marca. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE personal_access_tokens t
            SET mfa = true
            FROM users u
            WHERE u.id = t.tokenable_id
              AND t.mfa = false
              AND t.user_agent IS NULL
              AND (
                  u.is_super_admin
                  OR u.mfa_enabled
                  OR EXISTS (
                      SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                      WHERE ur.user_id = u.id AND upper(r.code) = 'ADMIN'
                  )
                  OR EXISTS (SELECT 1 FROM portal_users p WHERE p.user_id = u.id AND p.status = 'ACTIVE')
              )
        ");
    }

    public function down(): void
    {
        // La marca corregida es cierta: no se deshace.
    }
};
