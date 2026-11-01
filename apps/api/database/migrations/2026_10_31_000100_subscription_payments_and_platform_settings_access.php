<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pagos de suscripción en su propia tabla y configuración de plataforma solo para la consola.
 *
 * 1. `tenant_subscription_payments`: cada pago registrado al extender la vigencia (antes iban
 *    dentro de `tenant_subscriptions.configuration`, donde se podían sobrescribir). Aislada por
 *    empresa como la suscripción; la app inserta y lee, pero no edita ni borra. Los pagos que ya
 *    estaban en la configuración se pasan aquí y se quitan de ella.
 * 2. `platform_settings`: la app de las empresas (erp_app) ya no puede leerla ni cambiarla; la
 *    lee y la cambia el usuario de la consola (rol erp_platform).
 *
 * Se ejecuta con el usuario dueño de las tablas (erp_user).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tenant_subscription_payments (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                subscription_id uuid NOT NULL REFERENCES tenant_subscriptions(id),
                amount numeric(12, 2) NOT NULL CHECK (amount >= 0),
                currency char(3) NOT NULL DEFAULT 'PEN',
                payment_method varchar(100) NOT NULL CHECK (btrim(payment_method) <> ''),
                reference varchar(100),
                notes text,
                months integer CHECK (months IS NULL OR months BETWEEN 1 AND 120),
                previous_ends_at timestamptz,
                new_ends_at timestamptz NOT NULL,
                created_by uuid REFERENCES users(id) ON DELETE SET NULL,
                created_by_name varchar(255),
                created_at timestamptz NOT NULL DEFAULT now(),
                -- Extender: la nueva vigencia va después de la anterior.
                CONSTRAINT tenant_subscription_payments_extends CHECK (previous_ends_at IS NULL OR new_ends_at > previous_ends_at)
            );
            CREATE INDEX IF NOT EXISTS tenant_subscription_payments_tenant ON tenant_subscription_payments (tenant_id, created_at DESC);
            ALTER TABLE tenant_subscription_payments ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON tenant_subscription_payments;
            CREATE POLICY tenant_isolation ON tenant_subscription_payments
                USING (tenant_id = app_tenant_id())
                WITH CHECK (tenant_id = app_tenant_id());
            GRANT SELECT, INSERT ON tenant_subscription_payments TO erp_app;
            REVOKE UPDATE, DELETE, TRUNCATE ON tenant_subscription_payments FROM erp_app;

            -- Los pagos que ya estaban en la configuración de la suscripción (el dueño no pasa por la RLS).
            INSERT INTO tenant_subscription_payments (id, tenant_id, subscription_id, amount, currency, payment_method, reference, notes,
                                                      months, previous_ends_at, new_ends_at, created_by, created_by_name, created_at)
            SELECT COALESCE(NULLIF(p->>'id', '')::uuid, gen_random_uuid()), s.tenant_id, s.id,
                   COALESCE((p->>'amount')::numeric, 0), COALESCE(NULLIF(p->>'currency', ''), 'PEN'),
                   COALESCE(NULLIF(p->>'payment_method', ''), 'Sin indicar'), NULLIF(p->>'reference', ''), NULLIF(p->>'notes', ''),
                   NULLIF(p->>'months', '')::integer, NULLIF(p->>'previous_ends_at', '')::timestamptz, (p->>'new_ends_at')::timestamptz,
                   (SELECT u.id FROM users u WHERE u.id::text = p->>'created_by'), NULLIF(p->>'created_by_name', ''),
                   COALESCE(NULLIF(p->>'created_at', '')::timestamptz, now())
            FROM tenant_subscriptions s
            CROSS JOIN LATERAL jsonb_array_elements(s.configuration->'payments') p
            WHERE jsonb_typeof(s.configuration->'payments') = 'array'
            ON CONFLICT (id) DO NOTHING;
            UPDATE tenant_subscriptions SET configuration = configuration - 'payments' WHERE configuration ? 'payments';

            -- Configuración de plataforma: fuera del alcance de la app de las empresas.
            REVOKE ALL ON platform_settings FROM erp_app;
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_platform') THEN
                    GRANT SELECT, INSERT, UPDATE ON platform_settings TO erp_platform;
                END IF;
            END $$;
            -- Los datos de ejemplo de la primera versión no son canales reales: sin fila, la plataforma los muestra "sin configurar".
            DELETE FROM platform_settings
            WHERE key = 'support' AND value->>'whatsapp' = '+51999999999' AND value->>'email' = 'soporte@erp-saas.com';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            GRANT SELECT, INSERT, UPDATE, DELETE ON platform_settings TO erp_app;
            UPDATE tenant_subscriptions s SET configuration = s.configuration || jsonb_build_object('payments', (
                SELECT jsonb_agg(jsonb_build_object(
                    'id', p.id, 'amount', p.amount, 'currency', p.currency, 'payment_method', p.payment_method, 'reference', p.reference,
                    'notes', p.notes, 'months', p.months, 'previous_ends_at', p.previous_ends_at, 'new_ends_at', p.new_ends_at,
                    'created_at', p.created_at, 'created_by', p.created_by, 'created_by_name', p.created_by_name
                ) ORDER BY p.created_at DESC) FROM tenant_subscription_payments p WHERE p.subscription_id = s.id))
            WHERE EXISTS (SELECT 1 FROM tenant_subscription_payments p WHERE p.subscription_id = s.id);
            DROP TABLE IF EXISTS tenant_subscription_payments;
        SQL);
    }
};
