<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pagar a un proveedor es un permiso aparte de aprobar su comprobante.
 *
 * En el prototipo Gerencia aprueba y paga con el mismo permiso. Aquí se separa (`payables.pay`):
 * quien aprueba el gasto no es quien saca el dinero. Conservan el pago los roles que ya lo tenían
 * con `payables.approve`, salvo Gerencia, que se queda con aprobar.
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO permissions (code, name, description) VALUES
                ('payables.pay', 'Pagar a proveedores', 'Registrar y revertir pagos a proveedores desde una cuenta de banco o caja.')
            ON CONFLICT (code) DO NOTHING;

            UPDATE permissions SET name = 'Aprobar comprobantes de proveedor',
                description = 'Aprobar o rechazar un comprobante de proveedor para pago.'
            WHERE code = 'payables.approve';

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT rp.role_id, (SELECT id FROM permissions WHERE code = 'payables.pay')
            FROM role_permissions rp
            JOIN permissions p ON p.id = rp.permission_id
            JOIN roles r ON r.id = rp.role_id
            WHERE p.code = 'payables.approve' AND upper(r.code) <> 'GERENCIA'
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_permissions WHERE permission_id = (SELECT id FROM permissions WHERE code = 'payables.pay');
            DELETE FROM permissions WHERE code = 'payables.pay';
            UPDATE permissions SET name = 'Aprobar comprobantes y pagar a proveedores',
                description = 'Aprobar o rechazar un comprobante de proveedor y registrar pagos a proveedores.'
            WHERE code = 'payables.approve';
        SQL);
    }
};
