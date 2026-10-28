<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Límite de crédito del cliente: permiso para aprobar una cotización o activar un contrato
 * aunque el cliente supere su límite, cuando la empresa eligió "Bloquear" en sus reglas.
 *
 * No depende de un módulo del plan (el límite vive en la ficha del cliente). Lo reciben el
 * Administrador de cada empresa y Gerencia, que es quien aprueba.
 *
 * Solo añade. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO permissions (code, name, description) VALUES
                ('credit.approve', 'Aprobar sobre el límite de crédito',
                 'Aprobar una cotización o activar un contrato aunque el cliente supere su límite de crédito, cuando la empresa lo bloquea. Siempre con motivo.')
            ON CONFLICT (code) DO NOTHING;

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'credit.approve'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) IN ('ADMIN', 'GERENCIA'))
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_permissions WHERE permission_id = (SELECT id FROM permissions WHERE code = 'credit.approve');
            DELETE FROM permissions WHERE code = 'credit.approve';
        SQL);
    }
};
