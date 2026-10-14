<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finanzas: servicios recurrentes y sus ciclos.
 *
 * Las tablas `recurring_service_instances`, `recurring_service_cycles` y `recurring_service_prices`
 * ya existían; se conservan y solo se les añade lo que faltaba. La instancia guarda el precio
 * pactado (no el del catálogo), su vigencia, su cliente, su responsable y su sede. Cada ciclo
 * guarda su periodo, su importe, sus intentos y su clave: un periodo se procesa una sola vez.
 * `recurring_service_prices` pasa a ser el historial de vigencias anteriores de una instancia.
 *
 * Lo que la base garantiza por sí sola:
 *   - un ciclo por instancia y periodo (clave única), y un evento facturable por ciclo;
 *   - instancias, ciclos y vigencias anteriores no se borran; una vigencia cerrada no se edita.
 *
 * Solo añade: no borra datos. Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- 1. Instancias: precio pactado, vigencia, cliente, responsable, sede y versión.
            ALTER TABLE recurring_service_instances
                ADD COLUMN IF NOT EXISTS customer_id uuid REFERENCES customers(id) ON DELETE RESTRICT,
                ADD COLUMN IF NOT EXISTS unit_price numeric(18,4) NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS quantity numeric(18,4) NOT NULL DEFAULT 1,
                ADD COLUMN IF NOT EXISTS currency varchar(10) NOT NULL DEFAULT 'PEN',
                ADD COLUMN IF NOT EXISTS start_date date,
                ADD COLUMN IF NOT EXISTS end_date date,
                ADD COLUMN IF NOT EXISTS status_reason text,
                ADD COLUMN IF NOT EXISTS owner_user_id uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS branch_id uuid REFERENCES branches(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 0;

            -- Las que ya existían toman sus datos del servicio contratado y de su contrato.
            UPDATE recurring_service_instances ri SET
                customer_id = cs.customer_id,
                unit_price = cs.price,
                quantity = cs.quantity,
                currency = CASE WHEN c.currency IN ('PEN', 'USD') THEN c.currency ELSE 'PEN' END,
                start_date = coalesce(cs.start_date, c.start_date, ri.created_at::date),
                end_date = coalesce(cs.end_date, c.end_date),
                owner_user_id = coalesce(cs.owner_user_id, c.created_by),
                branch_id = cs.branch_id
            FROM contracted_services cs JOIN contracts c ON c.id = cs.contract_id
            WHERE cs.id = ri.contracted_service_id AND ri.customer_id IS NULL;

            ALTER TABLE recurring_service_instances DROP CONSTRAINT IF EXISTS recurring_service_instances_status_check;
            ALTER TABLE recurring_service_instances ADD CONSTRAINT recurring_service_instances_status_check
                CHECK (status IN ('ACTIVE', 'SUSPENDED', 'EXPIRED', 'CANCELLED'));
            ALTER TABLE recurring_service_instances DROP CONSTRAINT IF EXISTS recurring_service_instances_currency_check;
            ALTER TABLE recurring_service_instances ADD CONSTRAINT recurring_service_instances_currency_check
                CHECK (currency IN ('PEN', 'USD'));
            ALTER TABLE recurring_service_instances DROP CONSTRAINT IF EXISTS recurring_service_instances_amounts_check;
            ALTER TABLE recurring_service_instances ADD CONSTRAINT recurring_service_instances_amounts_check
                CHECK (unit_price >= 0 AND quantity > 0);
            ALTER TABLE recurring_service_instances DROP CONSTRAINT IF EXISTS recurring_service_instances_term_check;
            ALTER TABLE recurring_service_instances ADD CONSTRAINT recurring_service_instances_term_check
                CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date);
            CREATE INDEX IF NOT EXISTS idx_recurring_instances_tenant_status ON recurring_service_instances (tenant_id, status);
            CREATE INDEX IF NOT EXISTS idx_recurring_instances_next_cycle ON recurring_service_instances (tenant_id, next_cycle_date);
            CREATE INDEX IF NOT EXISTS idx_recurring_instances_owner ON recurring_service_instances (tenant_id, owner_user_id);
            CREATE INDEX IF NOT EXISTS idx_recurring_instances_branch ON recurring_service_instances (tenant_id, branch_id);
            -- Una instancia no se borra: se cancela.
            REVOKE DELETE ON recurring_service_instances FROM erp_app;

            -- 2. Ciclos: periodo, importe, intentos, error y clave.
            ALTER TABLE recurring_service_cycles
                ADD COLUMN IF NOT EXISTS period varchar(20),
                ADD COLUMN IF NOT EXISTS amount numeric(18,4) NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS attempts integer NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS error text,
                ADD COLUMN IF NOT EXISTS idempotency_key varchar(150);
            UPDATE recurring_service_cycles SET period = to_char(cycle_start, 'YYYY-MM-DD') WHERE period IS NULL;
            UPDATE recurring_service_cycles SET idempotency_key = tenant_id || ':' || recurring_instance_id || ':' || period WHERE idempotency_key IS NULL;
            ALTER TABLE recurring_service_cycles ALTER COLUMN period SET NOT NULL;
            ALTER TABLE recurring_service_cycles ALTER COLUMN idempotency_key SET NOT NULL;
            -- Un periodo de una instancia se procesa una sola vez: el reintento reutiliza la misma fila.
            CREATE UNIQUE INDEX IF NOT EXISTS ux_recurring_cycles_key ON recurring_service_cycles (tenant_id, idempotency_key);
            ALTER TABLE recurring_service_cycles DROP CONSTRAINT IF EXISTS recurring_service_cycles_billable_event_fkey;
            ALTER TABLE recurring_service_cycles ADD CONSTRAINT recurring_service_cycles_billable_event_fkey
                FOREIGN KEY (billable_event_id) REFERENCES billable_events(id) ON DELETE SET NULL;
            ALTER TABLE recurring_service_cycles DROP CONSTRAINT IF EXISTS recurring_service_cycles_completed_check;
            ALTER TABLE recurring_service_cycles ADD CONSTRAINT recurring_service_cycles_completed_check
                CHECK (status <> 'COMPLETED' OR billable_event_id IS NOT NULL);
            -- Los ciclos no se borran.
            REVOKE DELETE ON recurring_service_cycles FROM erp_app;

            -- 3. Vigencias anteriores (historial de renovaciones): se anotan y ya no cambian.
            REVOKE UPDATE, DELETE ON recurring_service_prices FROM erp_app;

            -- 4. Permisos. `recurring.view` y `recurring.manage` ya existían sin pantalla.
            INSERT INTO permissions (code, name, description) VALUES
                ('recurring.create', 'Procesar ciclos recurrentes', 'Procesar los ciclos vencidos: cada uno deja su evento facturable.'),
                ('recurring.export', 'Exportar servicios recurrentes', 'Descargar el listado en Excel o CSV.')
            ON CONFLICT (code) DO NOTHING;
            UPDATE permissions SET name = 'Ver servicios recurrentes', description = 'Instancias recurrentes, su vigencia y sus ciclos.' WHERE code = 'recurring.view';
            UPDATE permissions SET name = 'Gestionar servicios recurrentes', description = 'Renovar, suspender, reactivar y cancelar una instancia recurrente.' WHERE code = 'recurring.manage';

            UPDATE module_catalog
            SET permission_prefixes = permission_prefixes || '["recurring"]'::jsonb
            WHERE upper(code) = 'FINANZAS' AND NOT permission_prefixes @> '["recurring"]'::jsonb;

            -- 5. Alcance por rol: también lo llevan los servicios recurrentes.
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments', 'recurring'));

            -- 6. Reparto (matriz del prototipo). El Administrador de cada empresa: todo.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r JOIN permissions p ON p.code LIKE 'recurring.%'
            WHERE r.tenant_id IS NOT NULL AND (r.is_system OR upper(r.code) = 'ADMIN')
            ON CONFLICT DO NOTHING;

            -- Gerencia ve y exporta toda la empresa.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('recurring.view', 'recurring.export')
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, 'recurring', 'TENANT' FROM roles r
            WHERE upper(r.code) = 'GERENCIA' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;

            -- Ventas ve los de lo que vendió (sin fila de alcance: lo propio).
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'recurring.view'
            WHERE upper(r.code) = 'VENTAS' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;

            -- Operaciones ve los de su sede.
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'recurring.view'
            WHERE upper(r.code) = 'OPERACIONES' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
            INSERT INTO role_scopes (role_id, tenant_id, resource, scope)
            SELECT r.id, r.tenant_id, 'recurring', 'BRANCH' FROM roles r
            WHERE upper(r.code) = 'OPERACIONES' AND r.tenant_id IS NOT NULL
            ON CONFLICT DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM role_scopes WHERE resource = 'recurring';
            ALTER TABLE role_scopes DROP CONSTRAINT IF EXISTS role_scopes_resource_check;
            ALTER TABLE role_scopes ADD CONSTRAINT role_scopes_resource_check
                CHECK (resource IN ('quotations', 'contracts', 'orders', 'portal', 'leads', 'opportunities', 'activities',
                                    'contracted_services', 'work_orders', 'projects', 'time_entries', 'cost_entries', 'agenda',
                                    'billing', 'receivables', 'payments'));
            UPDATE module_catalog SET permission_prefixes = permission_prefixes - 'recurring' WHERE upper(code) = 'FINANZAS';
            DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code IN ('recurring.create', 'recurring.export'));
            DELETE FROM permissions WHERE code IN ('recurring.create', 'recurring.export');

            GRANT UPDATE, DELETE ON recurring_service_prices TO erp_app;
            GRANT DELETE ON recurring_service_cycles, recurring_service_instances TO erp_app;

            DROP INDEX IF EXISTS ux_recurring_cycles_key;
            ALTER TABLE recurring_service_cycles DROP CONSTRAINT IF EXISTS recurring_service_cycles_billable_event_fkey;
            ALTER TABLE recurring_service_cycles DROP CONSTRAINT IF EXISTS recurring_service_cycles_completed_check;
            ALTER TABLE recurring_service_cycles
                DROP COLUMN IF EXISTS period, DROP COLUMN IF EXISTS amount, DROP COLUMN IF EXISTS attempts,
                DROP COLUMN IF EXISTS error, DROP COLUMN IF EXISTS idempotency_key;

            ALTER TABLE recurring_service_instances DROP CONSTRAINT IF EXISTS recurring_service_instances_status_check;
            ALTER TABLE recurring_service_instances DROP CONSTRAINT IF EXISTS recurring_service_instances_currency_check;
            ALTER TABLE recurring_service_instances DROP CONSTRAINT IF EXISTS recurring_service_instances_amounts_check;
            ALTER TABLE recurring_service_instances DROP CONSTRAINT IF EXISTS recurring_service_instances_term_check;
            ALTER TABLE recurring_service_instances
                DROP COLUMN IF EXISTS customer_id, DROP COLUMN IF EXISTS unit_price, DROP COLUMN IF EXISTS quantity, DROP COLUMN IF EXISTS currency,
                DROP COLUMN IF EXISTS start_date, DROP COLUMN IF EXISTS end_date, DROP COLUMN IF EXISTS status_reason,
                DROP COLUMN IF EXISTS owner_user_id, DROP COLUMN IF EXISTS branch_id, DROP COLUMN IF EXISTS lock_version;
        SQL);
    }
};
