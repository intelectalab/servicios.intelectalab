<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trabajar las tareas de un proyecto es un permiso aparte de gestionar el proyecto.
 *
 * En la matriz del prototipo, quien ejecuta (el técnico o consultor) tiene "editar proyectos",
 * que también deja pausar, cancelar y cambiar el presupuesto. Aquí se separa: `tasks.manage`
 * permite crear, editar y mover tareas y marcar su control; `projects.manage` queda para el
 * proyecto en sí. Quien ya gestionaba proyectos conserva las tareas.
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO permissions (code, name, description) VALUES
                ('tasks.manage', 'Gestionar tareas de proyectos', 'Crear, editar y mover las tareas de un proyecto y marcar su control obligatorio.')
            ON CONFLICT (code) DO NOTHING;

            UPDATE module_catalog
            SET permission_prefixes = '["projects", "tasks", "deliverables", "contracted_services"]'::jsonb
            WHERE upper(code) = 'PROYECTOS';

            INSERT INTO role_permissions (role_id, permission_id)
            SELECT rp.role_id, (SELECT id FROM permissions WHERE code = 'tasks.manage')
            FROM role_permissions rp
            JOIN permissions p ON p.id = rp.permission_id
            WHERE p.code = 'projects.manage'
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM permissions WHERE code = 'tasks.manage';
            UPDATE module_catalog
            SET permission_prefixes = '["projects", "deliverables", "contracted_services"]'::jsonb
            WHERE upper(code) = 'PROYECTOS';
        SQL);
    }
};
