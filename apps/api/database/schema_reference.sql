-- Esquema completo de la base del ERP (estructura, RLS, funciones y permisos) más los catálogos que
-- llenan las migraciones (migrations, permissions, module_catalog, saas_plans). Lo usa la prueba
-- automática de GitHub (.github/workflows/ci.yml). Sin datos de empresas ni contraseñas.
-- Regenerar tras cada migración nueva: ver docs/cambios/2026-11-01_prueba-automatica-de-github.md
--
-- PostgreSQL database dump
--


-- Dumped from database version 17.11 (Debian 17.11-1.pgdg13+2)
-- Dumped by pg_dump version 17.11 (Debian 17.11-1.pgdg13+2)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: btree_gist; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA public;


--
-- Name: citext; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS citext WITH SCHEMA public;


--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: app_tenant_id(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.app_tenant_id() RETURNS uuid
    LANGUAGE sql STABLE
    AS $$
    SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid;
$$;


ALTER FUNCTION public.app_tenant_id() OWNER TO erp_user;

--
-- Name: app_user_id(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.app_user_id() RETURNS uuid
    LANGUAGE sql STABLE
    AS $$
    SELECT NULLIF(current_setting('app.user_id', true), '')::uuid;
$$;


ALTER FUNCTION public.app_user_id() OWNER TO erp_user;

--
-- Name: apply_payable_movement(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.apply_payable_movement() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    DECLARE
        target payables%ROWTYPE;
        new_balance numeric(18,4);
    BEGIN
        SELECT * INTO target FROM payables WHERE id = NEW.payable_id FOR UPDATE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'La cuenta por pagar del movimiento no existe' USING ERRCODE = 'foreign_key_violation';
        END IF;
        new_balance := target.balance_amount + NEW.amount;
        IF new_balance < 0 OR new_balance > target.original_amount THEN
            RAISE EXCEPTION 'El movimiento deja el saldo de la cuenta por pagar fuera de su importe' USING ERRCODE = 'check_violation';
        END IF;
        UPDATE payables SET balance_amount = new_balance,
            status = CASE WHEN new_balance = 0 THEN 'PAID' WHEN new_balance < target.original_amount THEN 'PARTIAL' ELSE 'OPEN' END
        WHERE id = target.id;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.apply_payable_movement() OWNER TO erp_user;

--
-- Name: apply_receivable_movement(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.apply_receivable_movement() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    DECLARE
        target receivables%ROWTYPE;
        new_balance numeric(18,4);
    BEGIN
        SELECT * INTO target FROM receivables WHERE id = NEW.receivable_id FOR UPDATE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'La cuenta por cobrar del movimiento no existe' USING ERRCODE = 'foreign_key_violation';
        END IF;
        new_balance := target.balance_amount + NEW.amount;
        IF new_balance < 0 OR new_balance > target.original_amount THEN
            RAISE EXCEPTION 'El movimiento deja el saldo de la cuenta por cobrar fuera de su importe' USING ERRCODE = 'check_violation';
        END IF;
        UPDATE receivables SET balance_amount = new_balance,
            status = CASE
                WHEN new_balance = 0 AND NEW.movement_type IN ('CREDIT_NOTE', 'VOID') THEN 'CANCELLED'
                WHEN new_balance = 0 THEN 'PAID'
                WHEN new_balance < target.original_amount THEN 'PARTIAL'
                ELSE 'OPEN' END
        WHERE id = target.id;
        UPDATE invoices SET balance_due = new_balance WHERE id = target.invoice_id;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.apply_receivable_movement() OWNER TO erp_user;

--
-- Name: check_acc_entry_balanced(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.check_acc_entry_balanced() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            DECLARE
                target uuid;
                lines integer;
                difference numeric;
            BEGIN
                -- Cada rama lee solo el campo de su tabla.
                IF TG_TABLE_NAME = 'acc_journal_entries' THEN
                    target := NEW.id;
                ELSE
                    target := NEW.entry_id;
                END IF;
                SELECT count(*), coalesce(sum(debit), 0) - coalesce(sum(credit), 0) INTO lines, difference
                FROM acc_journal_lines WHERE entry_id = target;
                IF lines < 2 THEN
                    RAISE EXCEPTION 'Un asiento necesita al menos dos líneas' USING ERRCODE = 'check_violation';
                END IF;
                IF difference <> 0 THEN
                    RAISE EXCEPTION 'El asiento no cuadra: el debe debe ser igual al haber' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END;
            $$;


ALTER FUNCTION public.check_acc_entry_balanced() OWNER TO erp_user;

--
-- Name: check_acc_entry_period(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.check_acc_entry_period() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF EXISTS (SELECT 1 FROM acc_periods p WHERE p.tenant_id = NEW.tenant_id AND p.period = NEW.period AND p.status = 'CLOSED') THEN
            RAISE EXCEPTION 'El periodo % está cerrado: no admite asientos', NEW.period USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.check_acc_entry_period() OWNER TO erp_user;

--
-- Name: check_acc_journal_line(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.check_acc_journal_line() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    DECLARE
        entry_created timestamptz;
        account_ok boolean;
    BEGIN
        SELECT created_at INTO entry_created FROM acc_journal_entries WHERE id = NEW.entry_id;
        IF NOT FOUND OR entry_created <> transaction_timestamp() THEN
            RAISE EXCEPTION 'Las líneas de un asiento se registran junto con el asiento' USING ERRCODE = 'check_violation';
        END IF;
        SELECT postable AND active INTO account_ok FROM acc_accounts WHERE tenant_id = NEW.tenant_id AND code = NEW.account_code;
        IF NOT coalesce(account_ok, false) THEN
            RAISE EXCEPTION 'La cuenta % no es imputable o está inactiva', NEW.account_code USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.check_acc_journal_line() OWNER TO erp_user;

--
-- Name: check_bank_transfer(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.check_bank_transfer() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    DECLARE
        source financial_accounts%ROWTYPE;
        target financial_accounts%ROWTYPE;
        available numeric;
    BEGIN
        -- Las dos cuentas se bloquean siempre en el mismo orden.
        PERFORM 1 FROM financial_accounts WHERE id IN (NEW.from_account_id, NEW.to_account_id) ORDER BY id FOR UPDATE;
        SELECT * INTO source FROM financial_accounts WHERE id = NEW.from_account_id;
        SELECT * INTO target FROM financial_accounts WHERE id = NEW.to_account_id;
        IF source.tenant_id IS DISTINCT FROM NEW.tenant_id OR target.tenant_id IS DISTINCT FROM NEW.tenant_id THEN
            RAISE EXCEPTION 'Las cuentas de la transferencia no son de esta empresa' USING ERRCODE = 'check_violation';
        END IF;
        IF source.currency <> target.currency OR source.currency <> NEW.currency THEN
            RAISE EXCEPTION 'Una transferencia es entre cuentas de la misma moneda' USING ERRCODE = 'check_violation';
        END IF;
        IF source.status <> 'ACTIVE' OR target.status <> 'ACTIVE' OR source.account_type = 'DETRACTION' OR target.account_type = 'DETRACTION' THEN
            RAISE EXCEPTION 'Una transferencia es entre cuentas activas que no son de detracciones' USING ERRCODE = 'check_violation';
        END IF;
        SELECT coalesce(sum(amount), 0) INTO available FROM bank_movements WHERE account_id = NEW.from_account_id;
        IF available < NEW.amount THEN
            RAISE EXCEPTION 'Saldo insuficiente en la cuenta de origen' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.check_bank_transfer() OWNER TO erp_user;

--
-- Name: check_checklist_item_update(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.check_checklist_item_update() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF OLD.removed_at IS NOT NULL THEN
            RAISE EXCEPTION 'Un ítem retirado de la plantilla no se modifica.' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.label IS DISTINCT FROM OLD.label OR NEW.required IS DISTINCT FROM OLD.required
           OR NEW.evidence_required IS DISTINCT FROM OLD.evidence_required OR NEW.checklist_id IS DISTINCT FROM OLD.checklist_id THEN
            RAISE EXCEPTION 'Un ítem de plantilla no se edita: se retira y se agrega otro.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END $$;


ALTER FUNCTION public.check_checklist_item_update() OWNER TO erp_user;

--
-- Name: check_document_update(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.check_document_update() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.created_by IS DISTINCT FROM OLD.created_by THEN
            RAISE EXCEPTION 'La empresa y el autor de un documento no cambian.' USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.status = 'ARCHIVED' AND (NEW.status IS DISTINCT FROM OLD.status OR NEW.title IS DISTINCT FROM OLD.title
            OR NEW.document_type IS DISTINCT FROM OLD.document_type OR NEW.visibility IS DISTINCT FROM OLD.visibility) THEN
            RAISE EXCEPTION 'Un documento archivado no se modifica.' USING ERRCODE = 'check_violation';
        END IF;
        -- Uno en cuarentena no se publica en el portal.
        IF NEW.status = 'QUARANTINED' AND NEW.visibility = 'PORTAL' AND OLD.visibility = 'INTERNAL' THEN
            RAISE EXCEPTION 'Un documento en cuarentena no se publica en el portal.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END $$;


ALTER FUNCTION public.check_document_update() OWNER TO erp_user;

--
-- Name: check_portal_user(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.check_portal_user() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
    BEGIN
        IF TG_OP = 'UPDATE' AND (NEW.customer_id <> OLD.customer_id OR NEW.user_id <> OLD.user_id OR NEW.tenant_id <> OLD.tenant_id) THEN
            RAISE EXCEPTION 'Un usuario del portal no cambia de cliente ni de empresa.';
        END IF;
        IF TG_OP = 'INSERT' THEN
            IF EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND is_super_admin) THEN
                RAISE EXCEPTION 'La cuenta de plataforma no puede ser usuario del portal.';
            END IF;
            IF EXISTS (SELECT 1 FROM tenant_users WHERE user_id = NEW.user_id) THEN
                RAISE EXCEPTION 'Una cuenta del sistema no puede ser usuario del portal.';
            END IF;
            IF NOT EXISTS (SELECT 1 FROM customers WHERE id = NEW.customer_id AND tenant_id = NEW.tenant_id) THEN
                RAISE EXCEPTION 'El cliente no es de esta empresa.';
            END IF;
        END IF;
        IF NEW.status <> 'INACTIVE' AND (TG_OP = 'INSERT' OR OLD.status = 'INACTIVE') AND (
            SELECT count(*) FROM portal_users
            WHERE customer_id = NEW.customer_id AND status <> 'INACTIVE' AND id <> NEW.id
        ) >= 10 THEN
            RAISE EXCEPTION 'El cliente ya tiene 10 usuarios del portal.';
        END IF;
        RETURN NEW;
    END $$;


ALTER FUNCTION public.check_portal_user() OWNER TO erp_user;

--
-- Name: check_purchase_receipt(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.check_purchase_receipt() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    DECLARE
        parent_status text;
        received numeric(8,2);
    BEGIN
        SELECT status INTO parent_status FROM purchase_orders WHERE id = NEW.purchase_order_id FOR UPDATE;
        IF NOT FOUND OR parent_status NOT IN ('APPROVED', 'PARTIALLY_RECEIVED') THEN
            RAISE EXCEPTION 'Solo se recibe una orden de compra aprobada' USING ERRCODE = 'check_violation';
        END IF;
        SELECT coalesce(sum(received_pct), 0) INTO received FROM purchase_receipts WHERE purchase_order_id = NEW.purchase_order_id;
        IF received + NEW.received_pct > 100 THEN
            RAISE EXCEPTION 'Lo recibido de una orden de compra no puede pasar del 100 %%' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.check_purchase_receipt() OWNER TO erp_user;

--
-- Name: check_support_holiday_update(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.check_support_holiday_update() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF OLD.removed_at IS NOT NULL THEN
            RAISE EXCEPTION 'Un feriado retirado no se modifica.' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.holiday_date IS DISTINCT FROM OLD.holiday_date OR NEW.name IS DISTINCT FROM OLD.name OR NEW.calendar_id IS DISTINCT FROM OLD.calendar_id THEN
            RAISE EXCEPTION 'Un feriado no se edita: se retira y se agrega otro.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END $$;


ALTER FUNCTION public.check_support_holiday_update() OWNER TO erp_user;

--
-- Name: check_tenant_user_not_portal(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.check_tenant_user_not_portal() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
    BEGIN
        IF EXISTS (SELECT 1 FROM portal_users WHERE user_id = NEW.user_id) THEN
            RAISE EXCEPTION 'Una cuenta del portal de clientes no puede ser usuario del sistema.';
        END IF;
        RETURN NEW;
    END $$;


ALTER FUNCTION public.check_tenant_user_not_portal() OWNER TO erp_user;

--
-- Name: check_transfer_movement(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.check_transfer_movement() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    DECLARE
        transfer bank_transfers%ROWTYPE;
    BEGIN
        IF NEW.ref_type <> 'TRANSFER' THEN
            RETURN NEW;
        END IF;
        SELECT * INTO transfer FROM bank_transfers WHERE id = NEW.ref_id;
        IF NOT FOUND
           OR NOT ((NEW.account_id = transfer.from_account_id AND NEW.amount = -transfer.amount)
                OR (NEW.account_id = transfer.to_account_id AND NEW.amount = transfer.amount)) THEN
            RAISE EXCEPTION 'El movimiento no corresponde a una transferencia registrada' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.check_transfer_movement() OWNER TO erp_user;

--
-- Name: notifications_read_once(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.notifications_read_once() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF OLD.read_at IS NOT NULL AND NEW.read_at IS DISTINCT FROM OLD.read_at THEN
            RAISE EXCEPTION 'Una notificación leída no cambia.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END $$;


ALTER FUNCTION public.notifications_read_once() OWNER TO erp_user;

--
-- Name: platform_dashboard(integer); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.platform_dashboard(p_soon_days integer DEFAULT 7) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
    WITH subs AS (
        SELECT ts.tenant_id, ts.plan_id, ts.status, ts.ends_at,
               COALESCE((ts.configuration->>'is_trial')::boolean, false) AS is_trial,
               COALESCE(ts.configuration->>'billing_period', p.default_billing_period, 'MONTHLY') AS period,
               p.monthly_price, p.annual_price, p.currency
        FROM tenant_subscriptions ts
        JOIN saas_plans p ON p.id = ts.plan_id
        WHERE ts.status = 'ACTIVE'
    ),
    paid AS (
        SELECT s.*, CASE WHEN s.period = 'ANNUAL' THEN COALESCE(s.annual_price, 0) / 12
                         ELSE COALESCE(s.monthly_price, 0) END AS mrr
        FROM subs s
        WHERE NOT s.is_trial AND (s.ends_at IS NULL OR s.ends_at >= now())
    ),
    current_sub AS (
        SELECT DISTINCT ON (s.tenant_id) s.tenant_id, s.plan_id, s.is_trial, s.ends_at
        FROM subs s
        ORDER BY s.tenant_id, s.ends_at DESC NULLS FIRST
    ),
    members AS (
        SELECT tu.tenant_id, count(*) AS users
        FROM tenant_users tu JOIN users u ON u.id = tu.user_id
        WHERE tu.status = 'ACTIVE' AND u.status = 'ACTIVE'
        GROUP BY tu.tenant_id
    ),
    portal AS (
        SELECT tenant_id, count(*) AS users FROM portal_users WHERE status = 'ACTIVE' GROUP BY tenant_id
    ),
    clients AS (
        SELECT tenant_id, count(*) AS total FROM customers GROUP BY tenant_id
    ),
    storage AS (
        SELECT tenant_id, sum(size_bytes) AS bytes FROM file_objects GROUP BY tenant_id
    ),
    months AS (
        SELECT generate_series(date_trunc('month', now()) - interval '11 months', date_trunc('month', now()), interval '1 month') AS m
    )
    SELECT jsonb_build_object(
        'generated_at', now(),
        'tenants', jsonb_build_object(
            'total', (SELECT count(*) FROM tenants),
            'by_status', COALESCE((SELECT jsonb_object_agg(status, n) FROM (SELECT status, count(*) AS n FROM tenants GROUP BY status) x), '{}'::jsonb),
            'new_this_month', (SELECT count(*) FROM tenants WHERE created_at >= date_trunc('month', now())),
            'new_last_month', (SELECT count(*) FROM tenants WHERE created_at >= date_trunc('month', now()) - interval '1 month' AND created_at < date_trunc('month', now())),
            'without_subscription', (SELECT count(*) FROM tenants t WHERE NOT EXISTS (SELECT 1 FROM subs s WHERE s.tenant_id = t.id))
        ),
        'subscriptions', jsonb_build_object(
            'current', (SELECT count(*) FROM subs WHERE ends_at IS NULL OR ends_at >= now()),
            'paid', (SELECT count(*) FROM paid),
            'trial', (SELECT count(*) FROM subs WHERE is_trial AND (ends_at IS NULL OR ends_at >= now())),
            'trial_expiring', (SELECT count(*) FROM subs WHERE is_trial AND ends_at >= now() AND ends_at < now() + make_interval(days => p_soon_days)),
            'trial_expired', (SELECT count(*) FROM subs WHERE is_trial AND ends_at < now()),
            'paid_expired', (SELECT count(*) FROM subs WHERE NOT is_trial AND ends_at < now())
        ),
        'revenue', COALESCE((SELECT jsonb_agg(jsonb_build_object('currency', currency, 'mrr', round(total, 2)) ORDER BY currency)
                             FROM (SELECT currency, sum(mrr) AS total FROM paid GROUP BY currency) r), '[]'::jsonb),
        'plans', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                                'id', p.id, 'code', p.code, 'name', p.name, 'active', p.active, 'currency', p.currency,
                                'tenants', (SELECT count(*) FROM subs s WHERE s.plan_id = p.id AND (s.ends_at IS NULL OR s.ends_at >= now())),
                                'paid', (SELECT count(*) FROM paid x WHERE x.plan_id = p.id),
                                'trial', (SELECT count(*) FROM subs s WHERE s.plan_id = p.id AND s.is_trial AND (s.ends_at IS NULL OR s.ends_at >= now())),
                                'mrr', round(COALESCE((SELECT sum(mrr) FROM paid x WHERE x.plan_id = p.id), 0), 2)
                            ) ORDER BY p.display_order NULLS LAST, p.monthly_price NULLS LAST, p.name)
                           FROM saas_plans p), '[]'::jsonb),
        'signups', (SELECT jsonb_agg(jsonb_build_object('month', to_char(m, 'YYYY-MM'),
                        'tenants', (SELECT count(*) FROM tenants t WHERE t.created_at >= m AND t.created_at < m + interval '1 month')) ORDER BY m)
                    FROM months),
        'users', jsonb_build_object(
            'tenant_users', (SELECT COALESCE(sum(users), 0) FROM members),
            'portal_users', (SELECT COALESCE(sum(users), 0) FROM portal),
            'super_admins', (SELECT count(*) FROM users WHERE is_super_admin AND status = 'ACTIVE')
        ),
        'trials', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                                'tenant_id', t.id, 'code', t.code, 'name', t.name, 'plan', p.name, 'ends_at', s.ends_at,
                                'days_left', floor(extract(epoch FROM (s.ends_at - now())) / 86400)::int
                            ) ORDER BY s.ends_at)
                            FROM subs s JOIN tenants t ON t.id = s.tenant_id JOIN saas_plans p ON p.id = s.plan_id
                            WHERE s.is_trial AND s.ends_at < now() + make_interval(days => p_soon_days)), '[]'::jsonb),
        'usage', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                                'tenant_id', t.id, 'code', t.code, 'name', t.name, 'status', t.status,
                                'plan', p.name, 'trial', cs.is_trial, 'created_at', t.created_at,
                                'users', COALESCE(m.users, 0),
                                'users_limit', NULLIF(COALESCE(p.limits->>'users', p.limits->>'max_users'), '')::int,
                                'portal_users', COALESCE(pu.users, 0),
                                'portal_users_limit', NULLIF(p.limits->>'portal_users', '')::int,
                                'customers', COALESCE(c.total, 0),
                                'customers_limit', NULLIF(p.limits->>'clients', '')::int,
                                'storage_mb', round(COALESCE(st.bytes, 0) / 1048576.0, 2)
                            ) ORDER BY t.created_at DESC)
                           FROM tenants t
                           LEFT JOIN current_sub cs ON cs.tenant_id = t.id
                           LEFT JOIN saas_plans p ON p.id = cs.plan_id
                           LEFT JOIN members m ON m.tenant_id = t.id
                           LEFT JOIN portal pu ON pu.tenant_id = t.id
                           LEFT JOIN clients c ON c.tenant_id = t.id
                           LEFT JOIN storage st ON st.tenant_id = t.id), '[]'::jsonb),
        'health', jsonb_build_object(
            'jobs_pending', (SELECT count(*) FROM jobs),
            'jobs_failed', (SELECT count(*) FROM failed_jobs),
            'failed_last_24h', (SELECT count(*) FROM failed_jobs WHERE failed_at >= now() - interval '24 hours'),
            'outbox_pending', (SELECT count(*) FROM outbox_events WHERE status = 'PENDING'),
            'outbox_failed', (SELECT count(*) FROM outbox_events WHERE status = 'FAILED'),
            'dead_letters', (SELECT count(*) FROM integration_dead_letters WHERE resolved_at IS NULL),
            'files_quarantined', (SELECT count(*) FROM file_objects WHERE antivirus_status IN ('INFECTED', 'QUARANTINED'))
        ),
        'audit', COALESCE((SELECT jsonb_agg(a ORDER BY a.created_at DESC) FROM (
                                SELECT l.created_at, l.action, l.entity_type, u.name AS user_name
                                FROM platform_audit_logs l LEFT JOIN users u ON u.id = l.user_id
                                ORDER BY l.created_at DESC LIMIT 8) a), '[]'::jsonb)
    )
    $$;


ALTER FUNCTION public.platform_dashboard(p_soon_days integer) OWNER TO erp_user;

--
-- Name: platform_subscriptions(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.platform_subscriptions() RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
    SELECT COALESCE(jsonb_agg(jsonb_build_object(
            'id', s.id, 'tenant_id', t.id, 'tenant_code', t.code, 'tenant_name', t.name, 'tenant_status', t.status,
            'plan', p.name, 'currency', p.currency, 'status', s.status, 'starts_at', s.starts_at, 'ends_at', s.ends_at,
            'trial', COALESCE((s.configuration->>'is_trial')::boolean, false),
            'billing_period', COALESCE(s.configuration->>'billing_period', p.default_billing_period, 'MONTHLY'),
            'price', CASE WHEN COALESCE(s.configuration->>'billing_period', p.default_billing_period) = 'ANNUAL'
                          THEN p.annual_price ELSE p.monthly_price END
        ) ORDER BY s.starts_at DESC), '[]'::jsonb)
    FROM tenant_subscriptions s
    JOIN tenants t ON t.id = s.tenant_id
    JOIN saas_plans p ON p.id = s.plan_id
    $$;


ALTER FUNCTION public.platform_subscriptions() OWNER TO erp_user;

--
-- Name: platform_tenant(uuid); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.platform_tenant(p_tenant uuid) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
    SELECT r || jsonb_build_object(
        'subscriptions', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                'id', s.id, 'plan', p.name, 'status', s.status, 'starts_at', s.starts_at, 'ends_at', s.ends_at,
                'trial', COALESCE((s.configuration->>'is_trial')::boolean, false),
                'billing_period', COALESCE(s.configuration->>'billing_period', p.default_billing_period, 'MONTHLY')
            ) ORDER BY s.starts_at DESC)
            FROM tenant_subscriptions s JOIN saas_plans p ON p.id = s.plan_id WHERE s.tenant_id = p_tenant), '[]'::jsonb),
        'status_log', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                'from', l.from_status, 'to', l.to_status, 'reason', l.reason, 'at', l.created_at, 'user_name', u.name
            ) ORDER BY l.created_at DESC)
            FROM tenant_status_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.tenant_id = p_tenant), '[]'::jsonb),
        'admins', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                'id', u.id, 'name', u.name, 'email', u.email::text, 'status', tu.status, 'last_login_at', u.last_login_at
            ) ORDER BY u.name)
            FROM user_roles ur JOIN roles ro ON ro.id = ur.role_id AND upper(ro.code) = 'ADMIN'
            JOIN users u ON u.id = ur.user_id
            JOIN tenant_users tu ON tu.tenant_id = ur.tenant_id AND tu.user_id = u.id
            WHERE ur.tenant_id = p_tenant), '[]'::jsonb)
    )
    FROM platform_tenant_rows(p_tenant) r
    $$;


ALTER FUNCTION public.platform_tenant(p_tenant uuid) OWNER TO erp_user;

--
-- Name: platform_tenant_rows(uuid); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.platform_tenant_rows(p_tenant uuid DEFAULT NULL::uuid) RETURNS SETOF jsonb
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
    SELECT jsonb_build_object(
        'id', t.id, 'code', t.code, 'name', t.name, 'legal_name', COALESCE(c.legal_name, t.legal_name),
        'trade_name', c.trade_name, 'ruc', c.tax_identifier, 'status', t.status, 'created_at', t.created_at,
        'subscription', CASE WHEN s.id IS NULL THEN NULL ELSE jsonb_build_object(
            'id', s.id, 'plan_id', s.plan_id, 'plan', p.name, 'plan_code', p.code, 'currency', p.currency,
            'monthly_price', p.monthly_price, 'annual_price', p.annual_price,
            'starts_at', s.starts_at, 'ends_at', s.ends_at,
            'trial', COALESCE((s.configuration->>'is_trial')::boolean, false),
            'billing_period', COALESCE(s.configuration->>'billing_period', p.default_billing_period, 'MONTHLY')
        ) END,
        -- Encendidos más los obligatorios del catálogo, que toda empresa tiene.
        'modules', COALESCE((SELECT jsonb_agg(code ORDER BY code) FROM (
                                SELECT upper(m.module_code) AS code FROM tenant_modules m WHERE m.tenant_id = t.id AND m.enabled
                                UNION SELECT upper(mc.code) FROM module_catalog mc WHERE mc.is_core AND mc.active) x), '[]'::jsonb),
        'users', (SELECT count(*) FROM tenant_users tu JOIN users u ON u.id = tu.user_id
                   WHERE tu.tenant_id = t.id AND tu.status = 'ACTIVE' AND u.status = 'ACTIVE'),
        'users_limit', NULLIF(COALESCE(p.limits->>'users', p.limits->>'max_users'), '')::int,
        'portal_users', (SELECT count(*) FROM portal_users pu WHERE pu.tenant_id = t.id AND pu.status = 'ACTIVE'),
        'portal_users_limit', NULLIF(p.limits->>'portal_users', '')::int,
        'customers', (SELECT count(*) FROM customers cu WHERE cu.tenant_id = t.id),
        'customers_limit', NULLIF(p.limits->>'clients', '')::int,
        'storage_mb', round(COALESCE((SELECT sum(f.size_bytes) FROM file_objects f WHERE f.tenant_id = t.id), 0) / 1048576.0, 2),
        'storage_limit_gb', NULLIF(p.consumption->>'storage_gb', '')::numeric
    )
    FROM tenants t
    LEFT JOIN LATERAL (
        SELECT * FROM companies c0 WHERE c0.tenant_id = t.id ORDER BY c0.created_at LIMIT 1
    ) c ON true
    LEFT JOIN LATERAL (
        SELECT * FROM tenant_subscriptions s0 WHERE s0.tenant_id = t.id AND s0.status = 'ACTIVE'
        ORDER BY s0.starts_at DESC LIMIT 1
    ) s ON true
    LEFT JOIN saas_plans p ON p.id = s.plan_id
    WHERE p_tenant IS NULL OR t.id = p_tenant
    ORDER BY t.created_at DESC
    $$;


ALTER FUNCTION public.platform_tenant_rows(p_tenant uuid) OWNER TO erp_user;

--
-- Name: platform_tenants(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.platform_tenants() RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
    SELECT COALESCE(jsonb_agg(r), '[]'::jsonb) FROM platform_tenant_rows(NULL) r
    $$;


ALTER FUNCTION public.platform_tenants() OWNER TO erp_user;

--
-- Name: portal_membership_for_user(uuid); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.portal_membership_for_user(p_user_id uuid) RETURNS TABLE(tenant_id uuid, status character varying)
    LANGUAGE sql SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
        SELECT tenant_id, status FROM portal_users WHERE user_id = p_user_id LIMIT 1;
    $$;


ALTER FUNCTION public.portal_membership_for_user(p_user_id uuid) OWNER TO erp_user;

--
-- Name: prevent_member_becoming_super_admin(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.prevent_member_becoming_super_admin() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
            BEGIN
                IF NEW.is_super_admin AND EXISTS (SELECT 1 FROM tenant_users WHERE user_id = NEW.id) THEN
                    RAISE EXCEPTION 'Un usuario que pertenece a un tenant no puede ser super admin.'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;


ALTER FUNCTION public.prevent_member_becoming_super_admin() OWNER TO erp_user;

--
-- Name: prevent_super_admin_membership(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.prevent_super_admin_membership() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND is_super_admin) THEN
                    RAISE EXCEPTION 'Una cuenta de plataforma (super admin) no puede pertenecer a un tenant.'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;


ALTER FUNCTION public.prevent_super_admin_membership() OWNER TO erp_user;

--
-- Name: protect_acc_account(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_acc_account() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF NEW.code IS DISTINCT FROM OLD.code OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
           OR NEW.account_type IS DISTINCT FROM OLD.account_type OR NEW.nature IS DISTINCT FROM OLD.nature THEN
            RAISE EXCEPTION 'El código, el tipo y la naturaleza de una cuenta contable no se modifican' USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.postable AND NOT NEW.postable
           AND EXISTS (SELECT 1 FROM acc_journal_lines l WHERE l.tenant_id = OLD.tenant_id AND l.account_code = OLD.code) THEN
            RAISE EXCEPTION 'Una cuenta con movimientos sigue siendo imputable' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.protect_acc_account() OWNER TO erp_user;

--
-- Name: protect_acc_journal_entry(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_acc_journal_entry() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF NEW.entry_number IS DISTINCT FROM OLD.entry_number OR NEW.entry_date IS DISTINCT FROM OLD.entry_date
           OR NEW.period IS DISTINCT FROM OLD.period OR NEW.description IS DISTINCT FROM OLD.description
           OR NEW.source_type IS DISTINCT FROM OLD.source_type OR NEW.source_id IS DISTINCT FROM OLD.source_id
           OR NEW.source_number IS DISTINCT FROM OLD.source_number OR NEW.reversal_of IS DISTINCT FROM OLD.reversal_of
           OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at
           OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id THEN
            RAISE EXCEPTION 'Un asiento contabilizado no se edita: se extorna' USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.status = 'REVERSED' OR (NEW.status = 'POSTED' AND (NEW.reversed_by IS DISTINCT FROM OLD.reversed_by OR NEW.reason IS DISTINCT FROM OLD.reason)) THEN
            RAISE EXCEPTION 'Un asiento se extorna una sola vez' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.protect_acc_journal_entry() OWNER TO erp_user;

--
-- Name: protect_bank_movement(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_bank_movement() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF NEW.account_id IS DISTINCT FROM OLD.account_id OR NEW.amount IS DISTINCT FROM OLD.amount
           OR NEW.movement_date IS DISTINCT FROM OLD.movement_date OR NEW.ref_type IS DISTINCT FROM OLD.ref_type
           OR NEW.ref_id IS DISTINCT FROM OLD.ref_id OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
           OR NEW.description IS DISTINCT FROM OLD.description OR NEW.op_number IS DISTINCT FROM OLD.op_number THEN
            RAISE EXCEPTION 'Un movimiento de banco o caja no se modifica' USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.reconciled AND (NOT NEW.reconciled OR NEW.statement_line_id IS DISTINCT FROM OLD.statement_line_id) THEN
            RAISE EXCEPTION 'Un movimiento conciliado no se vuelve a conciliar ni se desconcilia' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.protect_bank_movement() OWNER TO erp_user;

--
-- Name: protect_bank_statement_line(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_bank_statement_line() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF NEW.amount IS DISTINCT FROM OLD.amount OR NEW.line_date IS DISTINCT FROM OLD.line_date
           OR NEW.description IS DISTINCT FROM OLD.description OR NEW.op_number IS DISTINCT FROM OLD.op_number
           OR NEW.statement_id IS DISTINCT FROM OLD.statement_id OR NEW.account_id IS DISTINCT FROM OLD.account_id
           OR NEW.fingerprint IS DISTINCT FROM OLD.fingerprint THEN
            RAISE EXCEPTION 'Una línea de extracto no se modifica' USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.status <> 'UNMATCHED' AND (NEW.status IS DISTINCT FROM OLD.status OR NEW.movement_id IS DISTINCT FROM OLD.movement_id) THEN
            RAISE EXCEPTION 'Una línea de extracto ya resuelta no se vuelve a resolver' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.protect_bank_statement_line() OWNER TO erp_user;

--
-- Name: protect_budget_lines(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_budget_lines() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    DECLARE
        parent_status text;
    BEGIN
        SELECT status INTO parent_status FROM budgets WHERE id = NEW.budget_id;
        IF FOUND AND parent_status <> 'DRAFT' THEN
            RAISE EXCEPTION 'Las líneas de un presupuesto solo se modifican en borrador' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.protect_budget_lines() OWNER TO erp_user;

--
-- Name: protect_cash_expense_classification(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_cash_expense_classification() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF NEW.cost_center_id IS DISTINCT FROM OLD.cost_center_id OR NEW.expense_account IS DISTINCT FROM OLD.expense_account THEN
            IF NOT EXISTS (
                SELECT 1 FROM cash_expense_reclassifications r
                WHERE r.cash_expense_id = NEW.id AND r.created_at = now()
                  AND r.new_cost_center_id = NEW.cost_center_id AND r.new_account = NEW.expense_account
            ) THEN
                RAISE EXCEPTION 'La clasificación de un gasto de caja se corrige registrando la corrección' USING ERRCODE = 'check_violation';
            END IF;
        END IF;
        IF NEW.amount IS DISTINCT FROM OLD.amount OR NEW.expense_date IS DISTINCT FROM OLD.expense_date
           OR NEW.account_id IS DISTINCT FROM OLD.account_id OR NEW.expense_number IS DISTINCT FROM OLD.expense_number THEN
            RAISE EXCEPTION 'El importe, la fecha y la caja de un gasto no se modifican' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END $$;


ALTER FUNCTION public.protect_cash_expense_classification() OWNER TO erp_user;

--
-- Name: protect_cash_expense_reclassification(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_cash_expense_reclassification() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF OLD.new_entry_id IS NOT NULL THEN
        RAISE EXCEPTION 'Una corrección registrada no cambia' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;


ALTER FUNCTION public.protect_cash_expense_reclassification() OWNER TO erp_user;

--
-- Name: protect_contract_signature(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_contract_signature() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF TG_TABLE_NAME = 'contract_signature_signers' AND OLD.status <> 'PENDING' THEN
            RAISE EXCEPTION 'Una firma registrada no se modifica.';
        END IF;
        IF TG_TABLE_NAME = 'contract_signature_requests' THEN
            IF OLD.status <> 'PENDING' AND NOT (OLD.status = 'DECLINED' AND NEW.status = 'CANCELLED') AND NEW.status IS DISTINCT FROM OLD.status THEN
                RAISE EXCEPTION 'La solicitud de firma ya está cerrada.';
            END IF;
            IF NEW.document_hash <> OLD.document_hash OR NEW.contract_id <> OLD.contract_id THEN
                RAISE EXCEPTION 'La huella y el contrato de una solicitud de firma no cambian.';
            END IF;
        END IF;
        RETURN NEW;
    END $$;


ALTER FUNCTION public.protect_contract_signature() OWNER TO erp_user;

--
-- Name: protect_cost_center(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_cost_center() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF NEW.code IS DISTINCT FROM OLD.code OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id THEN
            RAISE EXCEPTION 'El código de un centro de costo no se modifica' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.protect_cost_center() OWNER TO erp_user;

--
-- Name: protect_financial_account(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_financial_account() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF NEW.currency IS DISTINCT FROM OLD.currency OR NEW.account_type IS DISTINCT FROM OLD.account_type
           OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id THEN
            RAISE EXCEPTION 'La moneda y el tipo de una cuenta no se modifican' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.protect_financial_account() OWNER TO erp_user;

--
-- Name: protect_issued_invoice(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_issued_invoice() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF OLD.status <> 'DRAFT' AND (
               NEW.status = 'DRAFT'
            OR NEW.customer_id IS DISTINCT FROM OLD.customer_id OR NEW.contract_id IS DISTINCT FROM OLD.contract_id
            OR NEW.invoice_number IS DISTINCT FROM OLD.invoice_number OR NEW.invoice_type IS DISTINCT FROM OLD.invoice_type
            OR NEW.series IS DISTINCT FROM OLD.series OR NEW.number IS DISTINCT FROM OLD.number
            OR NEW.currency IS DISTINCT FROM OLD.currency OR NEW.exchange_rate IS DISTINCT FROM OLD.exchange_rate
            OR NEW.issue_date IS DISTINCT FROM OLD.issue_date OR NEW.due_date IS DISTINCT FROM OLD.due_date
            OR NEW.subtotal IS DISTINCT FROM OLD.subtotal OR NEW.tax_total IS DISTINCT FROM OLD.tax_total OR NEW.total IS DISTINCT FROM OLD.total
            OR NEW.customer_tax_id IS DISTINCT FROM OLD.customer_tax_id OR NEW.customer_name IS DISTINCT FROM OLD.customer_name
            OR NEW.related_invoice_id IS DISTINCT FROM OLD.related_invoice_id OR NEW.reason IS DISTINCT FROM OLD.reason
            OR NEW.withholding_mode IS DISTINCT FROM OLD.withholding_mode OR NEW.withholding_type IS DISTINCT FROM OLD.withholding_type
            OR NEW.withholding_code IS DISTINCT FROM OLD.withholding_code OR NEW.withholding_rate IS DISTINCT FROM OLD.withholding_rate
            OR NEW.withholding_amount IS DISTINCT FROM OLD.withholding_amount
        ) THEN
            RAISE EXCEPTION 'Un comprobante emitido no se modifica: se corrige con una nota de crédito' USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.status <> 'DRAFT' AND OLD.withholding_status = 'RECEIVED' AND (
               NEW.withholding_status IS DISTINCT FROM OLD.withholding_status OR NEW.withholding_number IS DISTINCT FROM OLD.withholding_number
            OR NEW.withholding_date IS DISTINCT FROM OLD.withholding_date
        ) THEN
            RAISE EXCEPTION 'La constancia de una detracción o retención ya registrada no se modifica' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.protect_issued_invoice() OWNER TO erp_user;

--
-- Name: protect_issued_invoice_items(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_issued_invoice_items() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    DECLARE
        parent_id uuid;
        parent_status text;
    BEGIN
        parent_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.invoice_id ELSE NEW.invoice_id END;
        SELECT status INTO parent_status FROM invoices WHERE id = parent_id;
        -- Sin comprobante (se está borrando la empresa entera) no hay nada que proteger.
        IF FOUND AND parent_status <> 'DRAFT' THEN
            RAISE EXCEPTION 'Las líneas de un comprobante emitido no se modifican' USING ERRCODE = 'check_violation';
        END IF;
        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    END;
    $$;


ALTER FUNCTION public.protect_issued_invoice_items() OWNER TO erp_user;

--
-- Name: protect_payable_balance(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_payable_balance() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF NEW.balance_amount IS DISTINCT FROM OLD.balance_amount AND pg_trigger_depth() < 2 THEN
            RAISE EXCEPTION 'El saldo de una cuenta por pagar solo cambia con un movimiento' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.original_amount IS DISTINCT FROM OLD.original_amount OR NEW.supplier_invoice_id IS DISTINCT FROM OLD.supplier_invoice_id
           OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id OR NEW.currency IS DISTINCT FROM OLD.currency
           OR NEW.detraction_amount IS DISTINCT FROM OLD.detraction_amount THEN
            RAISE EXCEPTION 'El importe, el proveedor y el comprobante de una cuenta por pagar no se modifican' USING ERRCODE = 'check_violation';
        END IF;
        -- Una detracción depositada no vuelve a quedar pendiente.
        IF OLD.detraction_deposited AND NOT NEW.detraction_deposited THEN
            RAISE EXCEPTION 'La detracción de esta cuenta por pagar ya fue depositada' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.protect_payable_balance() OWNER TO erp_user;

--
-- Name: protect_purchase_order(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_purchase_order() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF OLD.status <> 'DRAFT' AND (
               NEW.supplier_id IS DISTINCT FROM OLD.supplier_id OR NEW.cost_center_id IS DISTINCT FROM OLD.cost_center_id
            OR NEW.currency IS DISTINCT FROM OLD.currency OR NEW.subtotal IS DISTINCT FROM OLD.subtotal
            OR NEW.tax IS DISTINCT FROM OLD.tax OR NEW.total IS DISTINCT FROM OLD.total
            OR NEW.order_number IS DISTINCT FROM OLD.order_number
        ) THEN
            RAISE EXCEPTION 'Una orden de compra solo se edita en borrador' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.protect_purchase_order() OWNER TO erp_user;

--
-- Name: protect_purchase_order_items(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_purchase_order_items() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    DECLARE
        parent_id uuid;
        parent_status text;
    BEGIN
        parent_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.purchase_order_id ELSE NEW.purchase_order_id END;
        SELECT status INTO parent_status FROM purchase_orders WHERE id = parent_id;
        -- Sin orden (se está borrando la empresa entera) no hay nada que proteger.
        IF FOUND AND parent_status <> 'DRAFT' THEN
            RAISE EXCEPTION 'Las líneas de una orden de compra solo se modifican en borrador' USING ERRCODE = 'check_violation';
        END IF;
        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    END;
    $$;


ALTER FUNCTION public.protect_purchase_order_items() OWNER TO erp_user;

--
-- Name: protect_receivable_balance(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_receivable_balance() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF NEW.balance_amount IS DISTINCT FROM OLD.balance_amount AND pg_trigger_depth() < 2 THEN
            RAISE EXCEPTION 'El saldo de una cuenta por cobrar solo cambia con un movimiento' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.original_amount IS DISTINCT FROM OLD.original_amount OR NEW.invoice_id IS DISTINCT FROM OLD.invoice_id
           OR NEW.customer_id IS DISTINCT FROM OLD.customer_id OR NEW.currency IS DISTINCT FROM OLD.currency THEN
            RAISE EXCEPTION 'El importe, el cliente y el comprobante de una cuenta por cobrar no se modifican' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.protect_receivable_balance() OWNER TO erp_user;

--
-- Name: protect_supplier_invoice(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_supplier_invoice() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF NEW.supplier_id IS DISTINCT FROM OLD.supplier_id OR NEW.doc_type IS DISTINCT FROM OLD.doc_type OR NEW.doc_number IS DISTINCT FROM OLD.doc_number
           OR NEW.issue_date IS DISTINCT FROM OLD.issue_date OR NEW.due_date IS DISTINCT FROM OLD.due_date
           OR NEW.purchase_order_id IS DISTINCT FROM OLD.purchase_order_id OR NEW.currency IS DISTINCT FROM OLD.currency
           OR NEW.exchange_rate IS DISTINCT FROM OLD.exchange_rate OR NEW.subtotal IS DISTINCT FROM OLD.subtotal
           OR NEW.tax IS DISTINCT FROM OLD.tax OR NEW.total IS DISTINCT FROM OLD.total
           OR NEW.detraction_amount IS DISTINCT FROM OLD.detraction_amount OR NEW.retention_4ta IS DISTINCT FROM OLD.retention_4ta THEN
            RAISE EXCEPTION 'Un comprobante de proveedor registrado no se modifica: se anula y se registra de nuevo' USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.status IN ('APPROVED', 'VOIDED') AND NEW.status IS DISTINCT FROM OLD.status THEN
            RAISE EXCEPTION 'Un comprobante de proveedor aprobado o anulado no cambia de estado' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END;
    $$;


ALTER FUNCTION public.protect_supplier_invoice() OWNER TO erp_user;

--
-- Name: protect_supplier_payment(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.protect_supplier_payment() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
        IF OLD.status = 'REVERSED' THEN
            RAISE EXCEPTION 'Un pago revertido no cambia' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END $$;


ALTER FUNCTION public.protect_supplier_payment() OWNER TO erp_user;

--
-- Name: resolve_active_tenant_for_user(uuid); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.resolve_active_tenant_for_user(p_user_id uuid) RETURNS uuid
    LANGUAGE sql SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
    SELECT tenant_id
    FROM tenant_users
    WHERE user_id = p_user_id
      AND status = 'ACTIVE'
    LIMIT 1;
$$;


ALTER FUNCTION public.resolve_active_tenant_for_user(p_user_id uuid) OWNER TO erp_user;

--
-- Name: resolve_portal_tenant_for_user(uuid); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.resolve_portal_tenant_for_user(p_user_id uuid) RETURNS uuid
    LANGUAGE sql SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
        SELECT tenant_id FROM portal_users WHERE user_id = p_user_id AND status = 'ACTIVE' LIMIT 1;
    $$;


ALTER FUNCTION public.resolve_portal_tenant_for_user(p_user_id uuid) OWNER TO erp_user;

--
-- Name: set_updated_at(); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;


ALTER FUNCTION public.set_updated_at() OWNER TO erp_user;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: companies; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.companies (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(50) NOT NULL,
    legal_name character varying(255) NOT NULL,
    trade_name character varying(255),
    tax_identifier character varying(100),
    tax_country character varying(10) DEFAULT 'PE'::character varying,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    address jsonb,
    configuration jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.companies OWNER TO erp_user;

--
-- Name: superadmin_companies(uuid); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.superadmin_companies(p_user_id uuid) RETURNS SETOF public.companies
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
                SELECT c.*
                FROM companies c
                WHERE EXISTS (
                    SELECT 1 FROM users u
                    WHERE u.id = p_user_id AND u.is_super_admin AND u.status = 'ACTIVE'
                );
            $$;


ALTER FUNCTION public.superadmin_companies(p_user_id uuid) OWNER TO erp_user;

--
-- Name: tenant_subscriptions; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.tenant_subscriptions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    plan_id uuid NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    starts_at timestamp with time zone NOT NULL,
    ends_at timestamp with time zone,
    configuration jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.tenant_subscriptions OWNER TO erp_user;

--
-- Name: superadmin_tenant_subscriptions(uuid); Type: FUNCTION; Schema: public; Owner: erp_user
--

CREATE FUNCTION public.superadmin_tenant_subscriptions(p_user_id uuid) RETURNS SETOF public.tenant_subscriptions
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
                SELECT ts.*
                FROM tenant_subscriptions ts
                WHERE EXISTS (
                    SELECT 1 FROM users u
                    WHERE u.id = p_user_id AND u.is_super_admin AND u.status = 'ACTIVE'
                );
            $$;


ALTER FUNCTION public.superadmin_tenant_subscriptions(p_user_id uuid) OWNER TO erp_user;

--
-- Name: acc_accounts; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.acc_accounts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(20) NOT NULL,
    name character varying(255) NOT NULL,
    account_type character varying(20) NOT NULL,
    nature character(1) NOT NULL,
    postable boolean DEFAULT true NOT NULL,
    parent_code character varying(20),
    active boolean DEFAULT true NOT NULL,
    is_system boolean DEFAULT false NOT NULL,
    created_by uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT acc_accounts_code_check CHECK (((code)::text ~ '^[0-9]{2,10}$'::text)),
    CONSTRAINT acc_accounts_name_check CHECK ((btrim((name)::text) <> ''::text)),
    CONSTRAINT acc_accounts_nature_check CHECK ((nature = ANY (ARRAY['D'::bpchar, 'C'::bpchar]))),
    CONSTRAINT acc_accounts_type_check CHECK (((account_type)::text = ANY ((ARRAY['ASSET'::character varying, 'LIABILITY'::character varying, 'EQUITY'::character varying, 'INCOME'::character varying, 'EXPENSE'::character varying])::text[])))
);


ALTER TABLE public.acc_accounts OWNER TO erp_user;

--
-- Name: acc_fiscal_years; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.acc_fiscal_years (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    fiscal_year integer NOT NULL,
    status character varying(10) DEFAULT 'OPEN'::character varying NOT NULL,
    closing_entry_id uuid,
    result numeric(16,2),
    closed_at timestamp with time zone,
    closed_by uuid,
    reason text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT acc_fiscal_years_closed_check CHECK ((((status)::text = 'OPEN'::text) OR (closed_at IS NOT NULL))),
    CONSTRAINT acc_fiscal_years_status_check CHECK (((status)::text = ANY ((ARRAY['OPEN'::character varying, 'CLOSED'::character varying])::text[]))),
    CONSTRAINT acc_fiscal_years_year_check CHECK (((fiscal_year >= 2000) AND (fiscal_year <= 2999)))
);


ALTER TABLE public.acc_fiscal_years OWNER TO erp_user;

--
-- Name: acc_journal_entries; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.acc_journal_entries (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    entry_number character varying(30) NOT NULL,
    entry_date date NOT NULL,
    period character(7) NOT NULL,
    description text NOT NULL,
    source_type character varying(30) NOT NULL,
    source_id uuid,
    source_number character varying(100),
    status character varying(10) DEFAULT 'POSTED'::character varying NOT NULL,
    reversal_of uuid,
    reversed_by uuid,
    reason text,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT acc_journal_entries_description_check CHECK ((btrim(description) <> ''::text)),
    CONSTRAINT acc_journal_entries_period_check CHECK (((period)::text = to_char((entry_date)::timestamp with time zone, 'YYYY-MM'::text))),
    CONSTRAINT acc_journal_entries_reversal_check CHECK ((((source_type)::text <> 'REVERSAL'::text) OR (reversal_of IS NOT NULL))),
    CONSTRAINT acc_journal_entries_reversed_check CHECK ((((status)::text = 'POSTED'::text) OR ((reversed_by IS NOT NULL) AND (btrim(COALESCE(reason, ''::text)) <> ''::text)))),
    CONSTRAINT acc_journal_entries_source_check CHECK (((source_type)::text = ANY ((ARRAY['MANUAL'::character varying, 'REVERSAL'::character varying, 'OPENING'::character varying, 'INVOICE'::character varying, 'PAYMENT'::character varying, 'REFUND'::character varying, 'WITHHOLDING'::character varying, 'FX'::character varying, 'SUPPLIER_INVOICE'::character varying, 'SUPPLIER_PAYMENT'::character varying, 'CASH_EXPENSE'::character varying, 'BANK_FEE'::character varying, 'UNIDENTIFIED'::character varying, 'TRANSFER'::character varying, 'YEAR_CLOSE'::character varying, 'IMPORT'::character varying])::text[]))),
    CONSTRAINT acc_journal_entries_status_check CHECK (((status)::text = ANY ((ARRAY['POSTED'::character varying, 'REVERSED'::character varying])::text[]))),
    CONSTRAINT acc_journal_entries_year_close_check CHECK ((((source_type)::text <> 'YEAR_CLOSE'::text) OR (to_char((entry_date)::timestamp with time zone, 'MM-DD'::text) = '12-31'::text)))
);


ALTER TABLE public.acc_journal_entries OWNER TO erp_user;

--
-- Name: acc_journal_lines; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.acc_journal_lines (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    entry_id uuid NOT NULL,
    "position" integer DEFAULT 1 NOT NULL,
    account_code character varying(20) NOT NULL,
    debit numeric(18,2) DEFAULT 0 NOT NULL,
    credit numeric(18,2) DEFAULT 0 NOT NULL,
    cost_center_id uuid,
    party_id uuid,
    note text,
    CONSTRAINT acc_journal_lines_amount_check CHECK (((debit >= (0)::numeric) AND (credit >= (0)::numeric) AND ((debit > (0)::numeric) <> (credit > (0)::numeric))))
);


ALTER TABLE public.acc_journal_lines OWNER TO erp_user;

--
-- Name: acc_periods; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.acc_periods (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    period character(7) NOT NULL,
    status character varying(10) DEFAULT 'OPEN'::character varying NOT NULL,
    closed_at timestamp with time zone,
    closed_by uuid,
    reason text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT acc_periods_closed_check CHECK ((((status)::text = 'OPEN'::text) OR (closed_at IS NOT NULL))),
    CONSTRAINT acc_periods_period_check CHECK ((period ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'::text)),
    CONSTRAINT acc_periods_status_check CHECK (((status)::text = ANY ((ARRAY['OPEN'::character varying, 'CLOSED'::character varying])::text[])))
);


ALTER TABLE public.acc_periods OWNER TO erp_user;

--
-- Name: advances; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.advances (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid,
    amount numeric(18,4) NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    balance numeric(18,4) NOT NULL,
    status character varying(30) DEFAULT 'OPEN'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT advances_amount_check CHECK ((amount > (0)::numeric)),
    CONSTRAINT advances_balance_check CHECK ((balance >= (0)::numeric))
);


ALTER TABLE public.advances OWNER TO erp_user;

--
-- Name: agenda_events; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.agenda_events (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    title character varying(255) NOT NULL,
    event_type character varying(30) DEFAULT 'MEETING'::character varying NOT NULL,
    status character varying(30) DEFAULT 'SCHEDULED'::character varying NOT NULL,
    starts_at timestamp with time zone NOT NULL,
    ends_at timestamp with time zone NOT NULL,
    assigned_user_id uuid NOT NULL,
    customer_id uuid,
    location character varying(255),
    notes text,
    cancel_reason text,
    branch_id uuid,
    created_by uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT agenda_events_cancel_reason_check CHECK ((((status)::text <> 'CANCELLED'::text) OR (btrim(COALESCE(cancel_reason, ''::text)) <> ''::text))),
    CONSTRAINT agenda_events_period_check CHECK (((ends_at > starts_at) AND (ends_at <= (starts_at + '24:00:00'::interval)))),
    CONSTRAINT agenda_events_status_check CHECK (((status)::text = ANY ((ARRAY['SCHEDULED'::character varying, 'DONE'::character varying, 'CANCELLED'::character varying])::text[]))),
    CONSTRAINT agenda_events_type_check CHECK (((event_type)::text = ANY ((ARRAY['MEETING'::character varying, 'VISIT'::character varying, 'MAINTENANCE'::character varying, 'SESSION'::character varying])::text[])))
);


ALTER TABLE public.agenda_events OWNER TO erp_user;

--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.audit_logs (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    user_id uuid,
    action character varying(100) NOT NULL,
    entity_type character varying(150),
    entity_id uuid,
    correlation_id uuid,
    ip_address inet,
    user_agent text,
    before_data jsonb,
    after_data jsonb,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.audit_logs OWNER TO erp_user;

--
-- Name: bank_movements; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.bank_movements (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    account_id uuid NOT NULL,
    movement_date date NOT NULL,
    description text NOT NULL,
    amount numeric(18,4) NOT NULL,
    ref_type character varying(30) NOT NULL,
    ref_id uuid,
    op_number character varying(255),
    reconciled boolean DEFAULT false NOT NULL,
    reconciled_at timestamp with time zone,
    statement_line_id uuid,
    created_by uuid,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT bank_movements_amount_check CHECK ((amount <> (0)::numeric)),
    CONSTRAINT bank_movements_ref_type_check CHECK (((ref_type)::text = ANY ((ARRAY['OPENING'::character varying, 'PAYMENT'::character varying, 'REFUND'::character varying, 'TRANSFER'::character varying, 'CASH_EXPENSE'::character varying, 'BANK_FEE'::character varying, 'DETRACTION'::character varying, 'UNIDENTIFIED'::character varying, 'SUPPLIER_PAYMENT'::character varying, 'MANUAL'::character varying, 'SUPPLIER_PAYMENT_REVERSAL'::character varying])::text[])))
);


ALTER TABLE public.bank_movements OWNER TO erp_user;

--
-- Name: bank_statement_lines; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.bank_statement_lines (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    statement_id uuid NOT NULL,
    account_id uuid NOT NULL,
    "position" integer NOT NULL,
    line_date date NOT NULL,
    description text DEFAULT ''::text NOT NULL,
    amount numeric(18,4) NOT NULL,
    op_number character varying(255),
    status character varying(30) DEFAULT 'UNMATCHED'::character varying NOT NULL,
    movement_id uuid,
    rule character varying(100),
    fingerprint character varying(255) NOT NULL,
    resolved_by uuid,
    resolved_at timestamp with time zone,
    CONSTRAINT bank_statement_lines_amount_check CHECK ((amount <> (0)::numeric)),
    CONSTRAINT bank_statement_lines_resolved_check CHECK ((((status)::text = ANY ((ARRAY['MATCHED'::character varying, 'CREATED'::character varying])::text[])) = (movement_id IS NOT NULL))),
    CONSTRAINT bank_statement_lines_status_check CHECK (((status)::text = ANY ((ARRAY['UNMATCHED'::character varying, 'MATCHED'::character varying, 'CREATED'::character varying, 'IGNORED'::character varying])::text[])))
);


ALTER TABLE public.bank_statement_lines OWNER TO erp_user;

--
-- Name: bank_statements; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.bank_statements (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    account_id uuid NOT NULL,
    file_name character varying(255) NOT NULL,
    file_sha256 character(64) NOT NULL,
    lines_count integer DEFAULT 0 NOT NULL,
    duplicates_count integer DEFAULT 0 NOT NULL,
    imported_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    document_id uuid
);


ALTER TABLE public.bank_statements OWNER TO erp_user;

--
-- Name: bank_transfers; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.bank_transfers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    from_account_id uuid NOT NULL,
    to_account_id uuid NOT NULL,
    amount numeric(18,2) NOT NULL,
    currency character varying(10) NOT NULL,
    reference character varying(255),
    transfer_date date NOT NULL,
    kind character varying(20) DEFAULT 'TRANSFER'::character varying NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT bank_transfers_accounts_check CHECK ((from_account_id <> to_account_id)),
    CONSTRAINT bank_transfers_amount_check CHECK ((amount > (0)::numeric)),
    CONSTRAINT bank_transfers_currency_check CHECK (((currency)::text = ANY ((ARRAY['PEN'::character varying, 'USD'::character varying])::text[]))),
    CONSTRAINT bank_transfers_kind_check CHECK (((kind)::text = ANY ((ARRAY['TRANSFER'::character varying, 'REFILL'::character varying])::text[])))
);


ALTER TABLE public.bank_transfers OWNER TO erp_user;

--
-- Name: billable_events; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.billable_events (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    source_type character varying(30) DEFAULT 'MANUAL'::character varying NOT NULL,
    source_id uuid,
    customer_id uuid NOT NULL,
    contract_id uuid,
    description text NOT NULL,
    base numeric(18,4) NOT NULL,
    amount numeric(18,4) NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    status character varying(30) DEFAULT 'PENDING'::character varying NOT NULL,
    invoice_id uuid,
    idempotency_key character varying(150) NOT NULL,
    cancel_reason text,
    owner_user_id uuid,
    created_by uuid,
    branch_id uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT billable_events_amount_check CHECK (((base > (0)::numeric) AND (amount >= base))),
    CONSTRAINT billable_events_cancel_reason_check CHECK ((((status)::text <> 'CANCELLED'::text) OR (btrim(COALESCE(cancel_reason, ''::text)) <> ''::text))),
    CONSTRAINT billable_events_currency_check CHECK (((currency)::text = ANY ((ARRAY['PEN'::character varying, 'USD'::character varying])::text[]))),
    CONSTRAINT billable_events_invoiced_check CHECK ((((status)::text <> 'INVOICED'::text) OR (invoice_id IS NOT NULL))),
    CONSTRAINT billable_events_source_check CHECK (((source_type)::text = ANY ((ARRAY['WORK_ORDER'::character varying, 'RECURRING_CYCLE'::character varying, 'TIME_ENTRY'::character varying, 'CONTRACT_MILESTONE'::character varying, 'MANUAL'::character varying])::text[]))),
    CONSTRAINT billable_events_status_check CHECK (((status)::text = ANY ((ARRAY['PENDING'::character varying, 'INVOICED'::character varying, 'CANCELLED'::character varying])::text[])))
);


ALTER TABLE public.billable_events OWNER TO erp_user;

--
-- Name: branches; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.branches (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    company_id uuid NOT NULL,
    code character varying(50) NOT NULL,
    name character varying(255) NOT NULL,
    address jsonb,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.branches OWNER TO erp_user;

--
-- Name: budget_lines; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.budget_lines (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    budget_id uuid NOT NULL,
    period_month integer NOT NULL,
    category character varying(100),
    amount numeric(18,4) DEFAULT 0 NOT NULL,
    cost_center_id uuid,
    account_code character varying(20),
    CONSTRAINT budget_lines_amount_check CHECK ((amount >= (0)::numeric)),
    CONSTRAINT budget_lines_period_month_check CHECK (((period_month >= 1) AND (period_month <= 12)))
);


ALTER TABLE public.budget_lines OWNER TO erp_user;

--
-- Name: budgets; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.budgets (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    fiscal_year integer NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    status character varying(30) DEFAULT 'DRAFT'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    approved_by uuid,
    approved_at timestamp with time zone,
    reason text,
    created_by uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    CONSTRAINT budgets_approved_check CHECK ((((status)::text = 'DRAFT'::text) OR (approved_at IS NOT NULL))),
    CONSTRAINT budgets_status_check CHECK (((status)::text = ANY ((ARRAY['DRAFT'::character varying, 'APPROVED'::character varying, 'CLOSED'::character varying])::text[])))
);


ALTER TABLE public.budgets OWNER TO erp_user;

--
-- Name: cache; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE public.cache OWNER TO erp_user;

--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE public.cache_locks OWNER TO erp_user;

--
-- Name: cash_counts; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.cash_counts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    account_id uuid NOT NULL,
    book_balance numeric(18,4) NOT NULL,
    counted numeric(18,4) NOT NULL,
    difference numeric(18,4) NOT NULL,
    note text,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT cash_counts_counted_check CHECK ((counted >= (0)::numeric)),
    CONSTRAINT cash_counts_difference_check CHECK ((difference = (counted - book_balance)))
);


ALTER TABLE public.cash_counts OWNER TO erp_user;

--
-- Name: cash_expense_reclassifications; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.cash_expense_reclassifications (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    cash_expense_id uuid NOT NULL,
    old_cost_center_id uuid,
    new_cost_center_id uuid NOT NULL,
    old_account character varying(100),
    new_account character varying(100) NOT NULL,
    reason text NOT NULL,
    reversed_entry_id uuid,
    new_entry_id uuid,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT cash_expense_reclassifications_change_check CHECK (((old_cost_center_id IS DISTINCT FROM new_cost_center_id) OR ((old_account)::text IS DISTINCT FROM (new_account)::text))),
    CONSTRAINT cash_expense_reclassifications_reason_check CHECK ((btrim(reason) <> ''::text))
);


ALTER TABLE public.cash_expense_reclassifications OWNER TO erp_user;

--
-- Name: cash_expenses; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.cash_expenses (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    account_id uuid NOT NULL,
    expense_number character varying(50) NOT NULL,
    expense_date date NOT NULL,
    description text NOT NULL,
    amount numeric(18,4) NOT NULL,
    receipt character varying(150),
    cost_center character varying(100),
    expense_account character varying(100),
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    cost_center_id uuid,
    CONSTRAINT cash_expenses_amount_check CHECK ((amount > (0)::numeric))
);


ALTER TABLE public.cash_expenses OWNER TO erp_user;

--
-- Name: checklist_items; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.checklist_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    checklist_id uuid NOT NULL,
    "position" integer NOT NULL,
    label character varying(500) NOT NULL,
    required boolean DEFAULT false NOT NULL,
    evidence_required boolean DEFAULT false NOT NULL,
    removed_at timestamp with time zone,
    CONSTRAINT checklist_items_label_check CHECK ((btrim((label)::text) <> ''::text))
);


ALTER TABLE public.checklist_items OWNER TO erp_user;

--
-- Name: checklists; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.checklists (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    service_id uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    created_by uuid,
    CONSTRAINT checklists_name_check CHECK ((btrim((name)::text) <> ''::text))
);


ALTER TABLE public.checklists OWNER TO erp_user;

--
-- Name: company_branding; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.company_branding (
    tenant_id uuid NOT NULL,
    logo bytea,
    logo_width integer,
    logo_height integer,
    logo_sha256 character(64),
    primary_color character(7) DEFAULT '#1B8FC9'::bpchar NOT NULL,
    secondary_color character(7),
    palette jsonb DEFAULT '[]'::jsonb NOT NULL,
    address character varying(300),
    phone character varying(40),
    email character varying(160),
    website character varying(160),
    updated_by uuid,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    login_welcome character varying(240),
    CONSTRAINT company_branding_logo_data CHECK (((logo IS NULL) = (logo_sha256 IS NULL))),
    CONSTRAINT company_branding_logo_size CHECK (((logo IS NULL) OR (octet_length(logo) <= 524288))),
    CONSTRAINT company_branding_palette CHECK (((jsonb_typeof(palette) = 'array'::text) AND (jsonb_array_length(palette) <= 8))),
    CONSTRAINT company_branding_primary CHECK ((primary_color ~ '^#[0-9A-F]{6}$'::text)),
    CONSTRAINT company_branding_secondary CHECK (((secondary_color IS NULL) OR (secondary_color ~ '^#[0-9A-F]{6}$'::text)))
);


ALTER TABLE public.company_branding OWNER TO erp_user;

--
-- Name: contacts; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.contacts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    party_id uuid NOT NULL,
    contact_type character varying(50) NOT NULL,
    value character varying(255) NOT NULL,
    is_primary boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    contact_name character varying(150),
    country_code character varying(5)
);


ALTER TABLE public.contacts OWNER TO erp_user;

--
-- Name: contract_items; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.contract_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    contract_id uuid NOT NULL,
    service_id uuid,
    description text NOT NULL,
    quantity numeric(18,4) NOT NULL,
    unit_price numeric(18,4) NOT NULL,
    line_total numeric(18,4) DEFAULT 0 NOT NULL,
    service_snapshot jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT contract_items_quantity_check CHECK ((quantity > (0)::numeric)),
    CONSTRAINT contract_items_unit_price_check CHECK ((unit_price >= (0)::numeric))
);


ALTER TABLE public.contract_items OWNER TO erp_user;

--
-- Name: contract_signature_requests; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.contract_signature_requests (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    contract_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    status character varying(15) DEFAULT 'PENDING'::character varying NOT NULL,
    document_hash character(64) NOT NULL,
    provider character varying(30) DEFAULT 'SIMPLE'::character varying NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    completed_at timestamp with time zone,
    declined_reason text,
    declined_at timestamp with time zone,
    cancelled_at timestamp with time zone,
    cancelled_by uuid,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    lock_version integer DEFAULT 0 NOT NULL,
    CONSTRAINT contract_signature_requests_declined_check CHECK ((((status)::text <> 'DECLINED'::text) OR (btrim(COALESCE(declined_reason, ''::text)) <> ''::text))),
    CONSTRAINT contract_signature_requests_hash_check CHECK ((document_hash ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT contract_signature_requests_signed_check CHECK ((((status)::text <> 'SIGNED'::text) OR (completed_at IS NOT NULL))),
    CONSTRAINT contract_signature_requests_status_check CHECK (((status)::text = ANY ((ARRAY['PENDING'::character varying, 'SIGNED'::character varying, 'DECLINED'::character varying, 'EXPIRED'::character varying, 'CANCELLED'::character varying])::text[])))
);


ALTER TABLE public.contract_signature_requests OWNER TO erp_user;

--
-- Name: contract_signature_signers; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.contract_signature_signers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    request_id uuid NOT NULL,
    side character varying(10) NOT NULL,
    portal_user_id uuid,
    user_id uuid,
    name character varying(255),
    email character varying(255),
    status character varying(10) DEFAULT 'PENDING'::character varying NOT NULL,
    typed_name character varying(255),
    otp_verified boolean DEFAULT false NOT NULL,
    signed_at timestamp with time zone,
    ip_address character varying(64),
    user_agent character varying(500),
    evidence_hash character(64),
    CONSTRAINT contract_signature_signers_customer_check CHECK ((((side)::text <> 'CLIENTE'::text) OR (portal_user_id IS NOT NULL))),
    CONSTRAINT contract_signature_signers_side_check CHECK (((side)::text = ANY ((ARRAY['CLIENTE'::character varying, 'EMPRESA'::character varying])::text[]))),
    CONSTRAINT contract_signature_signers_signed_check CHECK ((((status)::text <> 'SIGNED'::text) OR ((signed_at IS NOT NULL) AND otp_verified AND (evidence_hash ~ '^[0-9a-f]{64}$'::text) AND (user_id IS NOT NULL) AND (btrim((COALESCE(typed_name, ''::character varying))::text) <> ''::text)))),
    CONSTRAINT contract_signature_signers_status_check CHECK (((status)::text = ANY ((ARRAY['PENDING'::character varying, 'SIGNED'::character varying, 'DECLINED'::character varying])::text[])))
);


ALTER TABLE public.contract_signature_signers OWNER TO erp_user;

--
-- Name: contracted_services; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.contracted_services (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    contract_id uuid NOT NULL,
    service_id uuid NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    start_date date,
    end_date date,
    quantity numeric(18,4) DEFAULT 1 NOT NULL,
    price numeric(18,4) DEFAULT 0 NOT NULL,
    recurring boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    contract_item_id uuid,
    owner_user_id uuid,
    branch_id uuid,
    status_reason text,
    lock_version integer DEFAULT 0 NOT NULL,
    suspended_by_contract boolean DEFAULT false NOT NULL,
    CONSTRAINT contracted_services_reason_check CHECK ((((status)::text <> ALL ((ARRAY['SUSPENDED'::character varying, 'CANCELLED'::character varying])::text[])) OR (btrim(COALESCE(status_reason, ''::text)) <> ''::text))),
    CONSTRAINT contracted_services_status_check CHECK (((status)::text = ANY ((ARRAY['PENDING'::character varying, 'ACTIVE'::character varying, 'SUSPENDED'::character varying, 'COMPLETED'::character varying, 'CANCELLED'::character varying])::text[]))),
    CONSTRAINT contracted_services_suspended_by_contract_check CHECK (((NOT suspended_by_contract) OR ((status)::text = 'SUSPENDED'::text)))
);


ALTER TABLE public.contracted_services OWNER TO erp_user;

--
-- Name: contracts; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.contracts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    quotation_id uuid,
    contract_number character varying(100) NOT NULL,
    status character varying(30) DEFAULT 'DRAFT'::character varying NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    start_date date,
    end_date date,
    total_value numeric(18,4) DEFAULT 0 NOT NULL,
    terms text,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    sla_level character varying(30) DEFAULT 'ESTANDAR'::character varying NOT NULL,
    lock_version integer DEFAULT 0 NOT NULL,
    branch_id uuid,
    signature_status character varying(10) DEFAULT 'NONE'::character varying NOT NULL,
    CONSTRAINT contracts_signature_status_check CHECK (((signature_status)::text = ANY ((ARRAY['NONE'::character varying, 'PENDING'::character varying, 'SIGNED'::character varying, 'DECLINED'::character varying])::text[]))),
    CONSTRAINT contracts_sla_level_check CHECK (((sla_level)::text = ANY ((ARRAY['ESTANDAR'::character varying, 'PREMIUM'::character varying])::text[]))),
    CONSTRAINT contracts_status_check CHECK (((status)::text = ANY (ARRAY[('DRAFT'::character varying)::text, ('ACTIVE'::character varying)::text, ('SUSPENDED'::character varying)::text, ('TERMINATED'::character varying)::text, ('EXPIRED'::character varying)::text])))
);


ALTER TABLE public.contracts OWNER TO erp_user;

--
-- Name: cost_centers; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.cost_centers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    active boolean DEFAULT true NOT NULL,
    kind character varying(10) DEFAULT 'COST'::character varying NOT NULL,
    branch_id uuid,
    manager_user_id uuid,
    created_by uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT cost_centers_kind_check CHECK (((kind)::text = ANY ((ARRAY['COST'::character varying, 'REVENUE'::character varying])::text[])))
);


ALTER TABLE public.cost_centers OWNER TO erp_user;

--
-- Name: cost_entries; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.cost_entries (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    project_id uuid,
    work_order_id uuid,
    task_id uuid,
    supplier_id uuid,
    category character varying(100) NOT NULL,
    description text,
    amount numeric(18,4) NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    incurred_at date DEFAULT CURRENT_DATE NOT NULL,
    approved boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    quantity numeric(18,4) DEFAULT 1 NOT NULL,
    unit_cost numeric(18,4) DEFAULT 0 NOT NULL,
    status character varying(30) DEFAULT 'SUBMITTED'::character varying NOT NULL,
    created_by uuid,
    approved_by uuid,
    approved_at timestamp with time zone,
    reject_reason text,
    branch_id uuid,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    lock_version integer DEFAULT 0 NOT NULL,
    CONSTRAINT cost_entries_amount_check CHECK ((amount >= (0)::numeric)),
    CONSTRAINT cost_entries_quantity_check CHECK (((quantity > (0)::numeric) AND (unit_cost >= (0)::numeric))),
    CONSTRAINT cost_entries_reject_reason_check CHECK ((((status)::text <> 'REJECTED'::text) OR (btrim(COALESCE(reject_reason, ''::text)) <> ''::text))),
    CONSTRAINT cost_entries_status_check CHECK (((status)::text = ANY ((ARRAY['SUBMITTED'::character varying, 'APPROVED'::character varying, 'REJECTED'::character varying])::text[])))
);


ALTER TABLE public.cost_entries OWNER TO erp_user;

--
-- Name: credential_access_logs; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.credential_access_logs (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    credential_id uuid NOT NULL,
    user_id uuid,
    action character varying(50) NOT NULL,
    success boolean DEFAULT true NOT NULL,
    correlation_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    reason character varying(500)
);


ALTER TABLE public.credential_access_logs OWNER TO erp_user;

--
-- Name: credential_records; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.credential_records (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    credential_type character varying(100) NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    owner_entity_type character varying(150),
    owner_entity_id uuid,
    expires_at timestamp with time zone,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    owner_label character varying(150),
    CONSTRAINT chk_credential_expiry CHECK (((expires_at IS NULL) OR (expires_at > created_at))),
    CONSTRAINT credential_records_status_check CHECK (((status)::text = ANY (ARRAY[('ACTIVE'::character varying)::text, ('EXPIRED'::character varying)::text, ('REVOKED'::character varying)::text, ('ROTATION_REQUIRED'::character varying)::text]))),
    CONSTRAINT credential_records_type_check CHECK (((credential_type)::text = ANY ((ARRAY['SERVIDOR'::character varying, 'DNS'::character varying, 'HOSTING'::character varying, 'CLOUD'::character varying, 'API_KEY'::character varying, 'SMTP'::character varying, 'CERTIFICADO'::character varying, 'BASE_DATOS'::character varying])::text[])))
);


ALTER TABLE public.credential_records OWNER TO erp_user;

--
-- Name: credential_versions; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.credential_versions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    credential_id uuid NOT NULL,
    version integer NOT NULL,
    secret_ciphertext bytea NOT NULL,
    secret_metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    reason character varying(500)
);


ALTER TABLE public.credential_versions OWNER TO erp_user;

--
-- Name: crm_activities; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.crm_activities (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    lead_id uuid,
    opportunity_id uuid,
    activity_type character varying(50) NOT NULL,
    subject character varying(255) NOT NULL,
    description text,
    due_at timestamp with time zone,
    completed_at timestamp with time zone,
    owner_user_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    branch_id uuid,
    completed_by uuid,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    lock_version integer DEFAULT 0 NOT NULL,
    CONSTRAINT crm_activities_single_relation_check CHECK (((lead_id IS NULL) OR (opportunity_id IS NULL))),
    CONSTRAINT crm_activities_type_check CHECK (((activity_type)::text = ANY ((ARRAY['CALL'::character varying, 'MEETING'::character varying, 'EMAIL'::character varying, 'DEMO'::character varying, 'VISIT'::character varying])::text[])))
);


ALTER TABLE public.crm_activities OWNER TO erp_user;

--
-- Name: crm_campaign_members; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.crm_campaign_members (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    campaign_id uuid NOT NULL,
    lead_id uuid,
    customer_id uuid,
    status character varying(30) DEFAULT 'ADDED'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    consent boolean DEFAULT false NOT NULL,
    added_by uuid,
    excluded_at timestamp with time zone,
    excluded_by uuid,
    CONSTRAINT crm_campaign_members_check CHECK (((lead_id IS NOT NULL) OR (customer_id IS NOT NULL))),
    CONSTRAINT crm_campaign_members_status_check CHECK (((status)::text = ANY ((ARRAY['ADDED'::character varying, 'EXCLUDED'::character varying])::text[])))
);


ALTER TABLE public.crm_campaign_members OWNER TO erp_user;

--
-- Name: crm_campaigns; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.crm_campaigns (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    status character varying(30) DEFAULT 'DRAFT'::character varying NOT NULL,
    starts_at timestamp with time zone,
    ends_at timestamp with time zone,
    budget numeric(18,4) DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    channel character varying(100),
    consent_required boolean DEFAULT true NOT NULL,
    owner_user_id uuid,
    cancel_reason text,
    lock_version integer DEFAULT 0 NOT NULL,
    CONSTRAINT crm_campaigns_cancel_reason_check CHECK ((((status)::text <> 'CANCELLED'::text) OR (btrim(COALESCE(cancel_reason, ''::text)) <> ''::text))),
    CONSTRAINT crm_campaigns_dates_check CHECK (((starts_at IS NULL) OR (ends_at IS NULL) OR (ends_at >= starts_at))),
    CONSTRAINT crm_campaigns_status_check CHECK (((status)::text = ANY ((ARRAY['DRAFT'::character varying, 'ACTIVE'::character varying, 'COMPLETED'::character varying, 'CANCELLED'::character varying])::text[])))
);


ALTER TABLE public.crm_campaigns OWNER TO erp_user;

--
-- Name: crm_conversions; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.crm_conversions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    lead_id uuid,
    opportunity_id uuid,
    customer_id uuid NOT NULL,
    quotation_id uuid,
    converted_by uuid,
    converted_at timestamp with time zone DEFAULT now() NOT NULL,
    party_id uuid,
    mode character varying(10),
    CONSTRAINT crm_conversions_mode_check CHECK (((mode IS NULL) OR ((mode)::text = ANY ((ARRAY['CREATE'::character varying, 'REUSE'::character varying])::text[])))),
    CONSTRAINT crm_conversions_origin_check CHECK (((lead_id IS NOT NULL) OR (opportunity_id IS NOT NULL)))
);


ALTER TABLE public.crm_conversions OWNER TO erp_user;

--
-- Name: crm_entity_tags; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.crm_entity_tags (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    tag_id uuid NOT NULL,
    entity_type character varying(100) NOT NULL,
    entity_id uuid NOT NULL
);


ALTER TABLE public.crm_entity_tags OWNER TO erp_user;

--
-- Name: crm_leads; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.crm_leads (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    source_id uuid,
    party_id uuid,
    person_name character varying(255),
    organization_name character varying(255),
    email public.citext,
    phone character varying(100),
    status character varying(30) DEFAULT 'NEW'::character varying NOT NULL,
    score numeric(8,4),
    owner_user_id uuid,
    converted_at timestamp with time zone,
    converted_customer_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    lead_number character varying(50) NOT NULL,
    "position" character varying(255),
    interest character varying(255),
    discard_reason text,
    branch_id uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    campaign_id uuid,
    score_grade character(1),
    score_hits jsonb DEFAULT '[]'::jsonb NOT NULL,
    CONSTRAINT crm_leads_score_grade_check CHECK (((score_grade IS NULL) OR (score_grade = ANY (ARRAY['A'::bpchar, 'B'::bpchar, 'C'::bpchar, 'D'::bpchar])))),
    CONSTRAINT crm_leads_status_check CHECK (((status)::text = ANY ((ARRAY['NEW'::character varying, 'CONTACTED'::character varying, 'QUALIFIED'::character varying, 'DISQUALIFIED'::character varying, 'NURTURING'::character varying, 'CONVERTED'::character varying])::text[])))
);


ALTER TABLE public.crm_leads OWNER TO erp_user;

--
-- Name: crm_opportunities; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.crm_opportunities (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid,
    lead_id uuid,
    pipeline_id uuid NOT NULL,
    stage_id uuid NOT NULL,
    source_id uuid,
    name character varying(255) NOT NULL,
    status character varying(30) DEFAULT 'OPEN'::character varying NOT NULL,
    expected_value numeric(18,4) DEFAULT 0 NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    probability numeric(8,4) DEFAULT 0 NOT NULL,
    expected_close_date date,
    owner_user_id uuid,
    won_at timestamp with time zone,
    lost_at timestamp with time zone,
    loss_reason text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    opportunity_number character varying(50) NOT NULL,
    won_evidence text,
    won_by uuid,
    branch_id uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    CONSTRAINT crm_opportunities_probability_check CHECK (((probability >= (0)::numeric) AND (probability <= (100)::numeric))),
    CONSTRAINT crm_opportunities_status_check CHECK (((status)::text = ANY (ARRAY[('OPEN'::character varying)::text, ('WON'::character varying)::text, ('LOST'::character varying)::text])))
);


ALTER TABLE public.crm_opportunities OWNER TO erp_user;

--
-- Name: crm_pipeline_stages; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.crm_pipeline_stages (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    pipeline_id uuid NOT NULL,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    "position" integer NOT NULL,
    probability numeric(8,4) DEFAULT 0 NOT NULL,
    active boolean DEFAULT true NOT NULL,
    CONSTRAINT crm_pipeline_stages_probability_check CHECK (((probability >= (0)::numeric) AND (probability <= (100)::numeric)))
);


ALTER TABLE public.crm_pipeline_stages OWNER TO erp_user;

--
-- Name: crm_pipelines; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.crm_pipelines (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    active boolean DEFAULT true NOT NULL
);


ALTER TABLE public.crm_pipelines OWNER TO erp_user;

--
-- Name: crm_settings; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.crm_settings (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    configuration jsonb DEFAULT '{}'::jsonb NOT NULL,
    activated_at timestamp with time zone,
    deactivated_at timestamp with time zone,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_crm_activation_dates CHECK (((deactivated_at IS NULL) OR (activated_at IS NULL) OR (deactivated_at >= activated_at)))
);


ALTER TABLE public.crm_settings OWNER TO erp_user;

--
-- Name: crm_sources; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.crm_sources (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    active boolean DEFAULT true NOT NULL
);


ALTER TABLE public.crm_sources OWNER TO erp_user;

--
-- Name: crm_tags; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.crm_tags (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL
);


ALTER TABLE public.crm_tags OWNER TO erp_user;

--
-- Name: custom_field_definitions; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.custom_field_definitions (
    id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    entity_type character varying(50) NOT NULL,
    field_key character varying(100) NOT NULL,
    label character varying(150) NOT NULL,
    field_type character varying(255) DEFAULT 'text'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT custom_field_definitions_field_type_check CHECK (((field_type)::text = ANY ((ARRAY['text'::character varying, 'number'::character varying, 'date'::character varying, 'boolean'::character varying])::text[])))
);


ALTER TABLE public.custom_field_definitions OWNER TO erp_user;

--
-- Name: custom_field_values; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.custom_field_values (
    id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    field_id uuid NOT NULL,
    entity_type character varying(50) NOT NULL,
    entity_id uuid NOT NULL,
    value text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.custom_field_values OWNER TO erp_user;

--
-- Name: customers; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.customers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    party_id uuid NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    credit_limit numeric(12,2),
    payment_terms_days integer,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    preferred_currency character varying(3) DEFAULT 'PEN'::character varying NOT NULL,
    retention_agent boolean DEFAULT false NOT NULL,
    CONSTRAINT chk_customers_preferred_currency CHECK (((preferred_currency)::text = ANY ((ARRAY['PEN'::character varying, 'USD'::character varying])::text[])))
);


ALTER TABLE public.customers OWNER TO erp_user;

--
-- Name: dashboard_definitions; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.dashboard_definitions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    code character varying(150) NOT NULL,
    name character varying(255) NOT NULL,
    domain character varying(100) NOT NULL,
    configuration jsonb DEFAULT '{}'::jsonb NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.dashboard_definitions OWNER TO erp_user;

--
-- Name: deliverables; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.deliverables (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    project_id uuid,
    work_order_id uuid,
    task_id uuid,
    name character varying(255) NOT NULL,
    description text,
    status character varying(30) DEFAULT 'DRAFT'::character varying NOT NULL,
    due_date date,
    accepted_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    document_ref text,
    review_note text,
    created_by uuid,
    reviewed_by uuid,
    reviewed_at timestamp with time zone,
    delivered_at timestamp with time zone,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    document_id uuid,
    CONSTRAINT deliverables_document_check CHECK ((((status)::text = ANY ((ARRAY['DRAFT'::character varying, 'RETURNED'::character varying])::text[])) OR (btrim(COALESCE(document_ref, ''::text)) <> ''::text))),
    CONSTRAINT deliverables_review_note_check CHECK ((((status)::text <> 'RETURNED'::text) OR (btrim(COALESCE(review_note, ''::text)) <> ''::text))),
    CONSTRAINT deliverables_status_check CHECK (((status)::text = ANY ((ARRAY['DRAFT'::character varying, 'IN_REVIEW'::character varying, 'RETURNED'::character varying, 'APPROVED'::character varying, 'DELIVERED'::character varying])::text[])))
);


ALTER TABLE public.deliverables OWNER TO erp_user;

--
-- Name: document_links; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.document_links (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    document_id uuid NOT NULL,
    entity_type character varying(150) NOT NULL,
    entity_id uuid NOT NULL,
    link_type character varying(50) DEFAULT 'ATTACHMENT'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.document_links OWNER TO erp_user;

--
-- Name: document_versions; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.document_versions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    document_id uuid NOT NULL,
    file_object_id uuid NOT NULL,
    version integer NOT NULL,
    checksum character(64),
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.document_versions OWNER TO erp_user;

--
-- Name: documents; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.documents (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    document_type character varying(100) NOT NULL,
    title character varying(255) NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    classification character varying(50) DEFAULT 'PRIVATE'::character varying NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    visibility character varying(20) DEFAULT 'INTERNAL'::character varying NOT NULL,
    branch_id uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    archived_at timestamp with time zone,
    archived_by uuid,
    CONSTRAINT chk_document_classification CHECK (((classification)::text = ANY (ARRAY[('PRIVATE'::character varying)::text, ('INTERNAL'::character varying)::text, ('PUBLIC'::character varying)::text]))),
    CONSTRAINT chk_document_visibility CHECK (((visibility)::text = ANY ((ARRAY['INTERNAL'::character varying, 'PORTAL'::character varying])::text[]))),
    CONSTRAINT documents_archived_check CHECK ((((status)::text = 'ARCHIVED'::text) = (archived_at IS NOT NULL))),
    CONSTRAINT documents_status_check CHECK (((status)::text = ANY ((ARRAY['ACTIVE'::character varying, 'QUARANTINED'::character varying, 'ARCHIVED'::character varying])::text[])))
);


ALTER TABLE public.documents OWNER TO erp_user;

--
-- Name: employees; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.employees (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    person_party_id uuid NOT NULL,
    user_id uuid,
    employee_code character varying(100),
    "position" character varying(255),
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.employees OWNER TO erp_user;

--
-- Name: evidences; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.evidences (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    document_id uuid NOT NULL,
    entity_type character varying(150) NOT NULL,
    entity_id uuid NOT NULL,
    evidence_type character varying(100) NOT NULL,
    description text,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.evidences OWNER TO erp_user;

--
-- Name: exchange_rates; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.exchange_rates (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    rate_date date NOT NULL,
    currency character(3) DEFAULT 'USD'::bpchar NOT NULL,
    buy numeric(10,4) NOT NULL,
    sell numeric(10,4) NOT NULL,
    source character varying(50) DEFAULT 'Manual'::character varying NOT NULL,
    created_by uuid,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT exchange_rates_positive_check CHECK (((buy > (0)::numeric) AND (sell > (0)::numeric)))
);


ALTER TABLE public.exchange_rates OWNER TO erp_user;

--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.failed_jobs OWNER TO erp_user;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: erp_user
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO erp_user;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: erp_user
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: file_objects; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.file_objects (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    storage_provider character varying(100) NOT NULL,
    bucket character varying(255) NOT NULL,
    object_key text NOT NULL,
    original_name character varying(500) NOT NULL,
    mime_type character varying(255),
    size_bytes bigint NOT NULL,
    sha256 character(64),
    encrypted boolean DEFAULT true NOT NULL,
    antivirus_status character varying(30) DEFAULT 'PENDING'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    antivirus_engine character varying(30),
    scanned_at timestamp with time zone,
    CONSTRAINT chk_file_av_status CHECK (((antivirus_status)::text = ANY (ARRAY[('PENDING'::character varying)::text, ('CLEAN'::character varying)::text, ('INFECTED'::character varying)::text, ('FAILED'::character varying)::text]))),
    CONSTRAINT file_objects_size_bytes_check CHECK ((size_bytes >= 0))
);


ALTER TABLE public.file_objects OWNER TO erp_user;

--
-- Name: financial_accounts; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.financial_accounts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    account_type character varying(50) NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    configuration jsonb DEFAULT '{}'::jsonb NOT NULL,
    bank_name character varying(150),
    account_number character varying(60),
    cci character varying(40),
    ledger_account character varying(30),
    fund_amount numeric(18,4),
    expense_limit numeric(18,4),
    custodian_user_id uuid,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    lock_version integer DEFAULT 0 NOT NULL,
    CONSTRAINT financial_accounts_account_type_check CHECK (((account_type)::text = ANY ((ARRAY['CASH'::character varying, 'BANK'::character varying, 'DETRACTION'::character varying, 'GATEWAY'::character varying, 'OTHER'::character varying])::text[]))),
    CONSTRAINT financial_accounts_currency_check CHECK (((currency)::text = ANY ((ARRAY['PEN'::character varying, 'USD'::character varying])::text[]))),
    CONSTRAINT financial_accounts_kind_rules_check CHECK (((((account_type)::text <> 'DETRACTION'::text) OR ((currency)::text = 'PEN'::text)) AND ((fund_amount IS NULL) OR (((account_type)::text = 'CASH'::text) AND (fund_amount >= (0)::numeric))) AND ((expense_limit IS NULL) OR (((account_type)::text = 'CASH'::text) AND (expense_limit > (0)::numeric))))),
    CONSTRAINT financial_accounts_status_check CHECK (((status)::text = ANY ((ARRAY['ACTIVE'::character varying, 'INACTIVE'::character varying])::text[])))
);


ALTER TABLE public.financial_accounts OWNER TO erp_user;

--
-- Name: fiscal_configurations; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.fiscal_configurations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    country_code character varying(10) DEFAULT 'PE'::character varying NOT NULL,
    tax_identifier character varying(50),
    environment character varying(30) DEFAULT 'BETA'::character varying NOT NULL,
    provider_code character varying(100),
    configuration jsonb DEFAULT '{}'::jsonb NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT fiscal_configurations_environment_check CHECK (((environment)::text = ANY (ARRAY[('BETA'::character varying)::text, ('PRODUCTION'::character varying)::text])))
);


ALTER TABLE public.fiscal_configurations OWNER TO erp_user;

--
-- Name: fiscal_documents; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.fiscal_documents (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    invoice_id uuid,
    fiscal_series_id uuid NOT NULL,
    document_type character varying(50) NOT NULL,
    series character varying(20) NOT NULL,
    number bigint NOT NULL,
    status character varying(30) DEFAULT 'PENDING'::character varying NOT NULL,
    sunat_status character varying(50),
    xml_hash character(64),
    cdr_code character varying(100),
    sent_at timestamp with time zone,
    accepted_at timestamp with time zone,
    rejected_at timestamp with time zone,
    provider_response jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.fiscal_documents OWNER TO erp_user;

--
-- Name: fiscal_notes; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.fiscal_notes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    fiscal_document_id uuid NOT NULL,
    note_type character varying(30) NOT NULL,
    reason_code character varying(50) NOT NULL,
    reason text,
    amount numeric(18,4) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT fiscal_notes_amount_check CHECK ((amount >= (0)::numeric)),
    CONSTRAINT fiscal_notes_note_type_check CHECK (((note_type)::text = ANY (ARRAY[('CREDIT'::character varying)::text, ('DEBIT'::character varying)::text])))
);


ALTER TABLE public.fiscal_notes OWNER TO erp_user;

--
-- Name: fiscal_series; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.fiscal_series (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    fiscal_configuration_id uuid NOT NULL,
    document_type character varying(50) NOT NULL,
    series character varying(20) NOT NULL,
    next_number bigint DEFAULT 1 NOT NULL,
    active boolean DEFAULT true NOT NULL,
    CONSTRAINT fiscal_series_next_number_check CHECK ((next_number > 0))
);


ALTER TABLE public.fiscal_series OWNER TO erp_user;

--
-- Name: fiscal_void_requests; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.fiscal_void_requests (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    invoice_id uuid NOT NULL,
    invoice_number character varying(100) NOT NULL,
    reason text NOT NULL,
    ticket character varying(100),
    status character varying(20) DEFAULT 'PENDING'::character varying NOT NULL,
    response text,
    requested_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    resolved_at timestamp with time zone,
    CONSTRAINT fiscal_void_requests_reason_check CHECK ((btrim(reason) <> ''::text)),
    CONSTRAINT fiscal_void_requests_status_check CHECK (((status)::text = ANY ((ARRAY['PENDING'::character varying, 'ACCEPTED'::character varying, 'REJECTED'::character varying])::text[])))
);


ALTER TABLE public.fiscal_void_requests OWNER TO erp_user;

--
-- Name: idempotency_keys; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.idempotency_keys (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    user_id uuid,
    idempotency_key character varying(255) NOT NULL,
    request_hash character varying(128) NOT NULL,
    response_status integer,
    response_body jsonb,
    expires_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.idempotency_keys OWNER TO erp_user;

--
-- Name: integration_attempts; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.integration_attempts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    connection_id uuid,
    outbox_event_id uuid,
    attempt_number integer NOT NULL,
    status character varying(30) NOT NULL,
    response_code integer,
    response_body text,
    error_code character varying(100),
    attempted_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.integration_attempts OWNER TO erp_user;

--
-- Name: integration_connections; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.integration_connections (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(100) NOT NULL,
    provider_code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    credential_id uuid,
    configuration jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.integration_connections OWNER TO erp_user;

--
-- Name: integration_dead_letters; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.integration_dead_letters (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    source_type character varying(100) NOT NULL,
    source_id uuid,
    error_code character varying(100) NOT NULL,
    payload jsonb,
    attempts integer DEFAULT 0 NOT NULL,
    resolved_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.integration_dead_letters OWNER TO erp_user;

--
-- Name: invoice_items; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.invoice_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    invoice_id uuid NOT NULL,
    service_id uuid,
    description text NOT NULL,
    quantity numeric(18,4) NOT NULL,
    unit_price numeric(18,4) NOT NULL,
    tax_rate numeric(8,4) DEFAULT 0 NOT NULL,
    tax_amount numeric(18,4) DEFAULT 0 NOT NULL,
    line_total numeric(18,4) DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    "position" integer DEFAULT 1 NOT NULL,
    billable_event_id uuid,
    CONSTRAINT invoice_items_quantity_check CHECK ((quantity > (0)::numeric))
);


ALTER TABLE public.invoice_items OWNER TO erp_user;

--
-- Name: invoices; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.invoices (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    contract_id uuid,
    invoice_number character varying(100),
    invoice_type character varying(30) NOT NULL,
    status character varying(30) DEFAULT 'DRAFT'::character varying NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    issue_date date,
    due_date date,
    subtotal numeric(18,4) DEFAULT 0 NOT NULL,
    tax_total numeric(18,4) DEFAULT 0 NOT NULL,
    total numeric(18,4) DEFAULT 0 NOT NULL,
    balance_due numeric(18,4) DEFAULT 0 NOT NULL,
    fiscal_document_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    series character varying(20),
    number bigint,
    customer_tax_id character varying(50),
    customer_name character varying(255),
    related_invoice_id uuid,
    exchange_rate numeric(12,6),
    fiscal_status character varying(30) DEFAULT 'NOT_SENT'::character varying NOT NULL,
    fiscal_response text,
    reason text,
    issued_at timestamp with time zone,
    owner_user_id uuid,
    created_by uuid,
    branch_id uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    withholding_mode character varying(20) DEFAULT 'AUTO'::character varying NOT NULL,
    withholding_type character varying(20),
    withholding_code character varying(10),
    withholding_rate numeric(6,3),
    withholding_amount numeric(18,4),
    withholding_status character varying(20),
    withholding_number character varying(100),
    withholding_date date,
    withholding_registered_by uuid,
    CONSTRAINT invoices_credit_note_check CHECK ((((invoice_type)::text <> 'CREDIT_NOTE'::text) OR ((related_invoice_id IS NOT NULL) AND (btrim(COALESCE(reason, ''::text)) <> ''::text)))),
    CONSTRAINT invoices_fiscal_status_check CHECK (((fiscal_status)::text = ANY ((ARRAY['NOT_SENT'::character varying, 'CONTINGENCY'::character varying, 'PENDING'::character varying, 'ACCEPTED'::character varying, 'REJECTED'::character varying])::text[]))),
    CONSTRAINT invoices_invoice_type_check CHECK (((invoice_type)::text = ANY ((ARRAY['INVOICE'::character varying, 'RECEIPT'::character varying, 'CREDIT_NOTE'::character varying, 'OTHER'::character varying])::text[]))),
    CONSTRAINT invoices_numbered_check CHECK ((((status)::text = ANY ((ARRAY['DRAFT'::character varying, 'CANCELLED'::character varying])::text[])) OR ((invoice_number IS NOT NULL) AND (issue_date IS NOT NULL)))),
    CONSTRAINT invoices_status_check CHECK (((status)::text = ANY ((ARRAY['DRAFT'::character varying, 'ISSUED'::character varying, 'CORRECTED'::character varying, 'VOID'::character varying, 'PAID'::character varying, 'PARTIALLY_PAID'::character varying, 'OVERDUE'::character varying, 'CANCELLED'::character varying])::text[]))),
    CONSTRAINT invoices_withholding_check CHECK ((((withholding_mode)::text = ANY ((ARRAY['AUTO'::character varying, 'NONE'::character varying, 'DETRACTION'::character varying, 'RETENTION'::character varying])::text[])) AND ((withholding_type IS NULL) OR ((withholding_type)::text = ANY ((ARRAY['DETRACTION'::character varying, 'RETENTION'::character varying])::text[]))) AND ((withholding_type IS NULL) = (withholding_amount IS NULL)) AND ((withholding_type IS NULL) = (withholding_status IS NULL)) AND ((withholding_amount IS NULL) OR (withholding_amount > (0)::numeric)) AND ((withholding_status IS NULL) OR ((withholding_status)::text = ANY ((ARRAY['PENDING'::character varying, 'RECEIVED'::character varying])::text[]))) AND (((withholding_status)::text IS DISTINCT FROM 'RECEIVED'::text) OR (btrim((COALESCE(withholding_number, ''::character varying))::text) <> ''::text))))
);


ALTER TABLE public.invoices OWNER TO erp_user;

--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


ALTER TABLE public.job_batches OWNER TO erp_user;

--
-- Name: jobs; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


ALTER TABLE public.jobs OWNER TO erp_user;

--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: erp_user
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.jobs_id_seq OWNER TO erp_user;

--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: erp_user
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE public.migrations OWNER TO erp_user;

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: erp_user
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.migrations_id_seq OWNER TO erp_user;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: erp_user
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: module_catalog; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.module_catalog (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    category character varying(100) NOT NULL,
    is_core boolean DEFAULT false NOT NULL,
    active boolean DEFAULT true NOT NULL,
    display_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    permission_prefixes jsonb DEFAULT '[]'::jsonb NOT NULL
);


ALTER TABLE public.module_catalog OWNER TO erp_user;

--
-- Name: notification_preferences; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.notification_preferences (
    tenant_id uuid NOT NULL,
    user_id uuid NOT NULL,
    in_app boolean DEFAULT true NOT NULL,
    email boolean DEFAULT true NOT NULL,
    whatsapp boolean DEFAULT true NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.notification_preferences OWNER TO erp_user;

--
-- Name: notifications; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.notifications (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    user_id uuid,
    channel character varying(30) NOT NULL,
    subject character varying(255),
    body text NOT NULL,
    status character varying(30) DEFAULT 'PENDING'::character varying NOT NULL,
    sent_at timestamp with time zone,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    event character varying(60),
    link character varying(300),
    read_at timestamp with time zone,
    actor_user_id uuid
);


ALTER TABLE public.notifications OWNER TO erp_user;

--
-- Name: order_items; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.order_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    order_id uuid NOT NULL,
    service_id uuid,
    description text NOT NULL,
    quantity numeric(18,4) NOT NULL,
    unit_price numeric(18,4) NOT NULL,
    line_total numeric(18,4) DEFAULT 0 NOT NULL,
    service_snapshot jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT order_items_quantity_check CHECK ((quantity > (0)::numeric)),
    CONSTRAINT order_items_unit_price_check CHECK ((unit_price >= (0)::numeric))
);


ALTER TABLE public.order_items OWNER TO erp_user;

--
-- Name: orders; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.orders (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    contract_id uuid,
    quotation_id uuid,
    order_number character varying(100) NOT NULL,
    status character varying(30) DEFAULT 'DRAFT'::character varying NOT NULL,
    order_date date DEFAULT CURRENT_DATE NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    total numeric(18,4) DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    notes text,
    created_by uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    branch_id uuid,
    CONSTRAINT orders_status_check CHECK (((status)::text = ANY (ARRAY[('DRAFT'::character varying)::text, ('CONFIRMED'::character varying)::text, ('IN_PROGRESS'::character varying)::text, ('COMPLETED'::character varying)::text, ('CANCELLED'::character varying)::text])))
);


ALTER TABLE public.orders OWNER TO erp_user;

--
-- Name: organizations; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.organizations (
    party_id uuid NOT NULL,
    legal_name character varying(255) NOT NULL,
    trade_name character varying(255),
    tax_identifier character varying(100),
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT chk_org_tax_identifier_unique_hint CHECK (((tax_identifier IS NULL) OR (length(TRIM(BOTH FROM tax_identifier)) > 0)))
);


ALTER TABLE public.organizations OWNER TO erp_user;

--
-- Name: outbox_events; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.outbox_events (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    aggregate_type character varying(150) NOT NULL,
    aggregate_id uuid,
    event_type character varying(150) NOT NULL,
    payload jsonb NOT NULL,
    correlation_id uuid,
    occurred_at timestamp with time zone DEFAULT now() NOT NULL,
    published_at timestamp with time zone,
    status character varying(30) DEFAULT 'PENDING'::character varying NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    CONSTRAINT outbox_events_status_check CHECK (((status)::text = ANY (ARRAY[('PENDING'::character varying)::text, ('PUBLISHED'::character varying)::text, ('FAILED'::character varying)::text])))
);


ALTER TABLE public.outbox_events OWNER TO erp_user;

--
-- Name: parties; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.parties (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    party_type character varying(30) NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    lock_version integer DEFAULT 0 NOT NULL,
    CONSTRAINT parties_party_type_check CHECK (((party_type)::text = ANY (ARRAY[('PERSON'::character varying)::text, ('ORGANIZATION'::character varying)::text])))
);


ALTER TABLE public.parties OWNER TO erp_user;

--
-- Name: payable_movements; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.payable_movements (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    payable_id uuid NOT NULL,
    movement_type character varying(30) NOT NULL,
    amount numeric(18,4) NOT NULL,
    reference character varying(255),
    supplier_payment_id uuid,
    created_by uuid,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT payable_movements_reversal_check CHECK ((((movement_type)::text <> 'PAYMENT_REVERSAL'::text) OR (supplier_payment_id IS NOT NULL))),
    CONSTRAINT payable_movements_sign_check CHECK (((amount <> (0)::numeric) AND ((amount > (0)::numeric) = ((movement_type)::text = ANY ((ARRAY['CHARGE'::character varying, 'PAYMENT_REVERSAL'::character varying])::text[]))))),
    CONSTRAINT payable_movements_type_check CHECK (((movement_type)::text = ANY ((ARRAY['CHARGE'::character varying, 'PAYMENT'::character varying, 'DETRACTION'::character varying, 'RETENTION_4TA'::character varying, 'PAYMENT_REVERSAL'::character varying])::text[])))
);


ALTER TABLE public.payable_movements OWNER TO erp_user;

--
-- Name: payables; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.payables (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    supplier_invoice_id uuid NOT NULL,
    supplier_id uuid NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    due_date date NOT NULL,
    original_amount numeric(18,4) NOT NULL,
    balance_amount numeric(18,4) DEFAULT 0 NOT NULL,
    detraction_amount numeric(18,4) DEFAULT 0 NOT NULL,
    detraction_deposited boolean DEFAULT false NOT NULL,
    status character varying(30) DEFAULT 'OPEN'::character varying NOT NULL,
    owner_user_id uuid,
    branch_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT payables_balance_check CHECK (((original_amount > (0)::numeric) AND (balance_amount >= (0)::numeric) AND (balance_amount <= original_amount))),
    CONSTRAINT payables_currency_check CHECK (((currency)::text = ANY ((ARRAY['PEN'::character varying, 'USD'::character varying])::text[]))),
    CONSTRAINT payables_status_check CHECK (((status)::text = ANY ((ARRAY['OPEN'::character varying, 'PARTIAL'::character varying, 'PAID'::character varying, 'CANCELLED'::character varying])::text[])))
);


ALTER TABLE public.payables OWNER TO erp_user;

--
-- Name: payment_allocations; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.payment_allocations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    payment_id uuid NOT NULL,
    receivable_id uuid NOT NULL,
    amount numeric(18,4) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    fx_difference_pen numeric(18,4) DEFAULT 0 NOT NULL,
    CONSTRAINT payment_allocations_amount_check CHECK ((amount > (0)::numeric))
);


ALTER TABLE public.payment_allocations OWNER TO erp_user;

--
-- Name: payments; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.payments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid,
    payment_number character varying(100) NOT NULL,
    amount numeric(18,4) NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    payment_method character varying(50) NOT NULL,
    financial_account_id uuid,
    status character varying(30) DEFAULT 'PENDING'::character varying NOT NULL,
    provider_reference character varying(255),
    paid_at timestamp with time zone,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    reference character varying(255),
    source character varying(30) DEFAULT 'BACKOFFICE'::character varying NOT NULL,
    refunded_amount numeric(18,4) DEFAULT 0 NOT NULL,
    reconciled boolean DEFAULT false NOT NULL,
    reconciled_at timestamp with time zone,
    reconciled_by uuid,
    exchange_rate numeric(12,6),
    created_by uuid,
    branch_id uuid,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    lock_version integer DEFAULT 0 NOT NULL,
    CONSTRAINT chk_payment_provider_reference CHECK ((((status)::text <> 'CONFIRMED'::text) OR (payment_method IS NOT NULL))),
    CONSTRAINT payments_amount_check CHECK ((amount > (0)::numeric)),
    CONSTRAINT payments_refunded_check CHECK (((refunded_amount >= (0)::numeric) AND (refunded_amount <= amount))),
    CONSTRAINT payments_source_check CHECK (((source)::text = ANY ((ARRAY['BACKOFFICE'::character varying, 'GATEWAY'::character varying, 'PORTAL'::character varying])::text[]))),
    CONSTRAINT payments_status_check CHECK (((status)::text = ANY ((ARRAY['PENDING'::character varying, 'CONFIRMED'::character varying, 'PARTIALLY_ALLOCATED'::character varying, 'ALLOCATED'::character varying, 'REFUNDED'::character varying, 'FAILED'::character varying, 'CANCELLED'::character varying])::text[])))
);


ALTER TABLE public.payments OWNER TO erp_user;

--
-- Name: permissions; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.permissions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    code character varying(150) NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.permissions OWNER TO erp_user;

--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id uuid NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_agent character varying(500),
    ip_address character varying(45),
    mfa boolean DEFAULT false NOT NULL
);


ALTER TABLE public.personal_access_tokens OWNER TO erp_user;

--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: erp_user
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.personal_access_tokens_id_seq OWNER TO erp_user;

--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: erp_user
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: persons; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.persons (
    party_id uuid NOT NULL,
    given_names character varying(255) NOT NULL,
    family_name character varying(255) NOT NULL,
    document_type character varying(50),
    document_number character varying(100),
    birth_date date,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT chk_person_document_unique_hint CHECK (((document_number IS NULL) OR (length(TRIM(BOTH FROM document_number)) > 0)))
);


ALTER TABLE public.persons OWNER TO erp_user;

--
-- Name: platform_audit_logs; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.platform_audit_logs (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id uuid,
    action character varying(100) NOT NULL,
    entity_type character varying(150),
    entity_id uuid,
    ip_address inet,
    user_agent text,
    before_data jsonb,
    after_data jsonb,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.platform_audit_logs OWNER TO erp_user;

--
-- Name: users; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.users (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    email public.citext NOT NULL,
    password_hash text NOT NULL,
    name character varying(255) NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    mfa_enabled boolean DEFAULT false NOT NULL,
    last_login_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    is_super_admin boolean DEFAULT false NOT NULL,
    CONSTRAINT users_status_check CHECK (((status)::text = ANY (ARRAY[('INVITED'::character varying)::text, ('ACTIVE'::character varying)::text, ('SUSPENDED'::character varying)::text, ('DISABLED'::character varying)::text])))
);


ALTER TABLE public.users OWNER TO erp_user;

--
-- Name: platform_audit_view; Type: VIEW; Schema: public; Owner: erp_user
--

CREATE VIEW public.platform_audit_view AS
 SELECT l.id,
    l.created_at,
    l.action,
    l.entity_type,
    l.entity_id,
    l.ip_address,
    l.user_agent,
    l.before_data,
    l.after_data,
    l.metadata,
    l.user_id,
    u.name AS user_name,
    (u.email)::text AS user_email
   FROM (public.platform_audit_logs l
     LEFT JOIN public.users u ON ((u.id = l.user_id)));


ALTER VIEW public.platform_audit_view OWNER TO erp_user;

--
-- Name: platform_settings; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.platform_settings (
    key character varying(100) NOT NULL,
    value jsonb NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.platform_settings OWNER TO erp_user;

--
-- Name: portal_notices; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.portal_notices (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid,
    body text NOT NULL,
    link text,
    expires_at timestamp with time zone,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    withdrawn_at timestamp with time zone,
    withdrawn_by uuid,
    CONSTRAINT portal_notices_body_check CHECK (((btrim(body) <> ''::text) AND (length(body) <= 500))),
    CONSTRAINT portal_notices_link_check CHECK (((link IS NULL) OR (link ~ '^(https?://|/)'::text))),
    CONSTRAINT portal_notices_withdrawn_check CHECK (((withdrawn_at IS NULL) = (withdrawn_by IS NULL)))
);


ALTER TABLE public.portal_notices OWNER TO erp_user;

--
-- Name: portal_notifications; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.portal_notifications (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    portal_user_id uuid NOT NULL,
    channel character varying(30) NOT NULL,
    title character varying(255) NOT NULL,
    body text NOT NULL,
    read_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.portal_notifications OWNER TO erp_user;

--
-- Name: portal_requests; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.portal_requests (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    request_type character varying(100) NOT NULL,
    subject character varying(255) NOT NULL,
    description text,
    status character varying(30) DEFAULT 'SUBMITTED'::character varying NOT NULL,
    crm_opportunity_id uuid,
    quotation_id uuid,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    request_number character varying(100),
    service_id uuid,
    resolution_note text,
    lock_version integer DEFAULT 0 NOT NULL,
    taken_by uuid,
    branch_id uuid,
    route character varying(10) DEFAULT 'ERP'::character varying NOT NULL,
    CONSTRAINT portal_requests_route_check CHECK ((((route)::text = ANY ((ARRAY['ERP'::character varying, 'CRM'::character varying])::text[])) AND (((route)::text = 'ERP'::text) OR (crm_opportunity_id IS NOT NULL)))),
    CONSTRAINT portal_requests_status_check CHECK (((status)::text = ANY ((ARRAY['SUBMITTED'::character varying, 'IN_REVIEW'::character varying, 'QUOTED'::character varying, 'REJECTED'::character varying])::text[])))
);


ALTER TABLE public.portal_requests OWNER TO erp_user;

--
-- Name: portal_users; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.portal_users (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    user_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    status character varying(30) DEFAULT 'INVITED'::character varying NOT NULL,
    last_login_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    role character varying(20) DEFAULT 'PORTAL_VIEWER'::character varying NOT NULL,
    invite_code_hash text,
    invite_expires_at timestamp with time zone,
    invited_by uuid,
    invited_by_type character varying(10),
    invited_at timestamp with time zone,
    activated_at timestamp with time zone,
    deactivated_at timestamp with time zone,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    lock_version integer DEFAULT 0 NOT NULL,
    CONSTRAINT portal_users_invite_check CHECK ((((status)::text = 'INVITED'::text) OR (invite_code_hash IS NULL))),
    CONSTRAINT portal_users_invited_by_type_check CHECK (((invited_by_type IS NULL) OR ((invited_by_type)::text = ANY ((ARRAY['STAFF'::character varying, 'PORTAL'::character varying])::text[])))),
    CONSTRAINT portal_users_role_check CHECK (((role)::text = ANY ((ARRAY['PORTAL_ADMIN'::character varying, 'PORTAL_FINANCE'::character varying, 'PORTAL_VIEWER'::character varying])::text[]))),
    CONSTRAINT portal_users_status_check CHECK (((status)::text = ANY ((ARRAY['INVITED'::character varying, 'ACTIVE'::character varying, 'INACTIVE'::character varying])::text[])))
);


ALTER TABLE public.portal_users OWNER TO erp_user;

--
-- Name: projects; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.projects (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    contract_id uuid,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    status character varying(30) DEFAULT 'DRAFT'::character varying NOT NULL,
    start_date date,
    end_date date,
    budget numeric(18,4) DEFAULT 0 NOT NULL,
    manager_user_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    contracted_service_id uuid,
    budget_revenue numeric(18,4) DEFAULT 0 NOT NULL,
    budget_cost numeric(18,4) DEFAULT 0 NOT NULL,
    budget_hours numeric(12,2) DEFAULT 0 NOT NULL,
    hold_reason text,
    cancel_reason text,
    created_by uuid,
    branch_id uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    CONSTRAINT projects_cancel_reason_check CHECK ((((status)::text <> 'CANCELLED'::text) OR (btrim(COALESCE(cancel_reason, ''::text)) <> ''::text))),
    CONSTRAINT projects_dates_check CHECK (((start_date IS NULL) OR (end_date IS NULL) OR (end_date >= start_date))),
    CONSTRAINT projects_hold_reason_check CHECK ((((status)::text <> 'ON_HOLD'::text) OR (btrim(COALESCE(hold_reason, ''::text)) <> ''::text))),
    CONSTRAINT projects_status_check CHECK (((status)::text = ANY ((ARRAY['DRAFT'::character varying, 'PLANNED'::character varying, 'IN_PROGRESS'::character varying, 'ON_HOLD'::character varying, 'COMPLETED'::character varying, 'CANCELLED'::character varying])::text[])))
);


ALTER TABLE public.projects OWNER TO erp_user;

--
-- Name: purchase_order_items; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.purchase_order_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    purchase_order_id uuid NOT NULL,
    "position" integer DEFAULT 1 NOT NULL,
    description text NOT NULL,
    quantity numeric(14,3) NOT NULL,
    unit_cost numeric(18,4) NOT NULL,
    account_code character varying(30) NOT NULL,
    CONSTRAINT purchase_order_items_values_check CHECK (((quantity > (0)::numeric) AND (unit_cost > (0)::numeric) AND (btrim(description) <> ''::text)))
);


ALTER TABLE public.purchase_order_items OWNER TO erp_user;

--
-- Name: purchase_orders; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.purchase_orders (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    order_number character varying(50) NOT NULL,
    supplier_id uuid NOT NULL,
    cost_center_id uuid NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    needed_by date,
    notes text,
    subtotal numeric(18,4) DEFAULT 0 NOT NULL,
    tax numeric(18,4) DEFAULT 0 NOT NULL,
    total numeric(18,4) DEFAULT 0 NOT NULL,
    status character varying(30) DEFAULT 'DRAFT'::character varying NOT NULL,
    reason text,
    budget_warning text,
    approved_by uuid,
    approved_at timestamp with time zone,
    owner_user_id uuid,
    created_by uuid,
    branch_id uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT purchase_orders_amounts_check CHECK (((subtotal >= (0)::numeric) AND (tax >= (0)::numeric) AND (total >= subtotal))),
    CONSTRAINT purchase_orders_approved_check CHECK ((((status)::text <> ALL ((ARRAY['APPROVED'::character varying, 'PARTIALLY_RECEIVED'::character varying, 'RECEIVED'::character varying])::text[])) OR (approved_at IS NOT NULL))),
    CONSTRAINT purchase_orders_currency_check CHECK (((currency)::text = ANY ((ARRAY['PEN'::character varying, 'USD'::character varying])::text[]))),
    CONSTRAINT purchase_orders_reason_check CHECK ((((status)::text <> ALL ((ARRAY['REJECTED'::character varying, 'CANCELLED'::character varying])::text[])) OR (btrim(COALESCE(reason, ''::text)) <> ''::text))),
    CONSTRAINT purchase_orders_status_check CHECK (((status)::text = ANY ((ARRAY['DRAFT'::character varying, 'SUBMITTED'::character varying, 'APPROVED'::character varying, 'PARTIALLY_RECEIVED'::character varying, 'RECEIVED'::character varying, 'CLOSED'::character varying, 'REJECTED'::character varying, 'CANCELLED'::character varying])::text[])))
);


ALTER TABLE public.purchase_orders OWNER TO erp_user;

--
-- Name: purchase_receipts; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.purchase_receipts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    purchase_order_id uuid NOT NULL,
    received_pct numeric(6,2) NOT NULL,
    note text,
    received_by uuid,
    received_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT purchase_receipts_pct_check CHECK (((received_pct > (0)::numeric) AND (received_pct <= (100)::numeric)))
);


ALTER TABLE public.purchase_receipts OWNER TO erp_user;

--
-- Name: quotation_approvals; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.quotation_approvals (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    quotation_id uuid NOT NULL,
    approver_user_id uuid,
    decision character varying(30) NOT NULL,
    comments text,
    decided_at timestamp with time zone DEFAULT now() NOT NULL,
    channel character varying(10) DEFAULT 'STAFF'::character varying NOT NULL,
    CONSTRAINT quotation_approvals_channel_check CHECK (((channel)::text = ANY ((ARRAY['STAFF'::character varying, 'PORTAL'::character varying])::text[]))),
    CONSTRAINT quotation_approvals_decision_check CHECK (((decision)::text = ANY (ARRAY[('APPROVED'::character varying)::text, ('REJECTED'::character varying)::text])))
);


ALTER TABLE public.quotation_approvals OWNER TO erp_user;

--
-- Name: quotation_items; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.quotation_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    quotation_id uuid NOT NULL,
    service_id uuid,
    description text NOT NULL,
    quantity numeric(18,4) NOT NULL,
    unit_price numeric(18,4) NOT NULL,
    discount numeric(18,4) DEFAULT 0 NOT NULL,
    tax_rate numeric(8,4) DEFAULT 0 NOT NULL,
    tax_amount numeric(18,4) DEFAULT 0 NOT NULL,
    line_total numeric(18,4) DEFAULT 0 NOT NULL,
    service_snapshot jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT quotation_items_quantity_check CHECK ((quantity > (0)::numeric)),
    CONSTRAINT quotation_items_unit_price_check CHECK ((unit_price >= (0)::numeric))
);


ALTER TABLE public.quotation_items OWNER TO erp_user;

--
-- Name: quotations; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.quotations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    quotation_number character varying(100) NOT NULL,
    status character varying(30) DEFAULT 'DRAFT'::character varying NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    valid_until date,
    subtotal numeric(18,4) DEFAULT 0 NOT NULL,
    tax_total numeric(18,4) DEFAULT 0 NOT NULL,
    discount_total numeric(18,4) DEFAULT 0 NOT NULL,
    total numeric(18,4) DEFAULT 0 NOT NULL,
    notes text,
    source_type character varying(50),
    source_id uuid,
    created_by uuid,
    approved_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    previous_id uuid,
    exchange_rate numeric(10,4),
    lock_version integer DEFAULT 0 NOT NULL,
    branch_id uuid,
    CONSTRAINT quotations_status_check CHECK (((status)::text = ANY (ARRAY[('DRAFT'::character varying)::text, ('SUBMITTED'::character varying)::text, ('APPROVED'::character varying)::text, ('REJECTED'::character varying)::text, ('EXPIRED'::character varying)::text, ('CANCELLED'::character varying)::text])))
);


ALTER TABLE public.quotations OWNER TO erp_user;

--
-- Name: receivable_movements; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.receivable_movements (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    receivable_id uuid NOT NULL,
    movement_type character varying(30) NOT NULL,
    amount numeric(18,4) NOT NULL,
    reference character varying(255),
    payment_id uuid,
    created_by uuid,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT receivable_movements_sign_check CHECK (((amount <> (0)::numeric) AND ((amount > (0)::numeric) = ((movement_type)::text = ANY ((ARRAY['CHARGE'::character varying, 'REVERSAL'::character varying])::text[]))))),
    CONSTRAINT receivable_movements_type_check CHECK (((movement_type)::text = ANY ((ARRAY['CHARGE'::character varying, 'PAYMENT'::character varying, 'REVERSAL'::character varying, 'CREDIT_NOTE'::character varying, 'WITHHOLDING'::character varying, 'VOID'::character varying])::text[])))
);


ALTER TABLE public.receivable_movements OWNER TO erp_user;

--
-- Name: receivables; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.receivables (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    invoice_id uuid NOT NULL,
    original_amount numeric(18,4) NOT NULL,
    balance_amount numeric(18,4) NOT NULL,
    due_date date,
    status character varying(30) DEFAULT 'OPEN'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    owner_user_id uuid,
    branch_id uuid,
    CONSTRAINT receivables_balance_amount_check CHECK ((balance_amount >= (0)::numeric)),
    CONSTRAINT receivables_balance_limit_check CHECK ((balance_amount <= original_amount)),
    CONSTRAINT receivables_original_amount_check CHECK ((original_amount >= (0)::numeric)),
    CONSTRAINT receivables_status_check CHECK (((status)::text = ANY ((ARRAY['OPEN'::character varying, 'PARTIAL'::character varying, 'PAID'::character varying, 'CANCELLED'::character varying])::text[])))
);


ALTER TABLE public.receivables OWNER TO erp_user;

--
-- Name: recurring_service_alerts; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.recurring_service_alerts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    recurring_instance_id uuid NOT NULL,
    alert_type character varying(50) NOT NULL,
    alert_date date NOT NULL,
    sent_at timestamp with time zone,
    status character varying(30) DEFAULT 'PENDING'::character varying NOT NULL
);


ALTER TABLE public.recurring_service_alerts OWNER TO erp_user;

--
-- Name: recurring_service_cycles; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.recurring_service_cycles (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    recurring_instance_id uuid NOT NULL,
    cycle_start date NOT NULL,
    cycle_end date NOT NULL,
    due_date date,
    status character varying(30) DEFAULT 'PENDING'::character varying NOT NULL,
    billable_event_id uuid,
    processed_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    period character varying(20) NOT NULL,
    amount numeric(18,4) DEFAULT 0 NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    error text,
    idempotency_key character varying(150) NOT NULL,
    CONSTRAINT recurring_service_cycles_completed_check CHECK ((((status)::text <> 'COMPLETED'::text) OR (billable_event_id IS NOT NULL))),
    CONSTRAINT recurring_service_cycles_status_check CHECK (((status)::text = ANY (ARRAY[('PENDING'::character varying)::text, ('PROCESSING'::character varying)::text, ('COMPLETED'::character varying)::text, ('FAILED'::character varying)::text, ('SKIPPED'::character varying)::text])))
);


ALTER TABLE public.recurring_service_cycles OWNER TO erp_user;

--
-- Name: recurring_service_instances; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.recurring_service_instances (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    contracted_service_id uuid NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    frequency character varying(30) NOT NULL,
    interval_value integer DEFAULT 1 NOT NULL,
    next_cycle_date date,
    suspended_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    customer_id uuid,
    unit_price numeric(18,4) DEFAULT 0 NOT NULL,
    quantity numeric(18,4) DEFAULT 1 NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    start_date date,
    end_date date,
    status_reason text,
    owner_user_id uuid,
    branch_id uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    CONSTRAINT recurring_service_instances_amounts_check CHECK (((unit_price >= (0)::numeric) AND (quantity > (0)::numeric))),
    CONSTRAINT recurring_service_instances_currency_check CHECK (((currency)::text = ANY ((ARRAY['PEN'::character varying, 'USD'::character varying])::text[]))),
    CONSTRAINT recurring_service_instances_interval_value_check CHECK ((interval_value > 0)),
    CONSTRAINT recurring_service_instances_status_check CHECK (((status)::text = ANY ((ARRAY['ACTIVE'::character varying, 'SUSPENDED'::character varying, 'EXPIRED'::character varying, 'CANCELLED'::character varying])::text[]))),
    CONSTRAINT recurring_service_instances_term_check CHECK (((end_date IS NULL) OR (start_date IS NULL) OR (end_date >= start_date)))
);


ALTER TABLE public.recurring_service_instances OWNER TO erp_user;

--
-- Name: recurring_service_prices; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.recurring_service_prices (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    recurring_instance_id uuid NOT NULL,
    amount numeric(18,4) NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    valid_from date NOT NULL,
    valid_to date,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT recurring_service_prices_amount_check CHECK ((amount >= (0)::numeric)),
    CONSTRAINT recurring_service_prices_check CHECK (((valid_to IS NULL) OR (valid_to >= valid_from)))
);


ALTER TABLE public.recurring_service_prices OWNER TO erp_user;

--
-- Name: refunds; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.refunds (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    payment_id uuid NOT NULL,
    amount numeric(18,4) NOT NULL,
    reason text,
    status character varying(30) DEFAULT 'PENDING'::character varying NOT NULL,
    provider_reference character varying(255),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    created_by uuid,
    CONSTRAINT refunds_amount_check CHECK ((amount > (0)::numeric)),
    CONSTRAINT refunds_reason_check CHECK ((btrim(COALESCE(reason, ''::text)) <> ''::text))
);


ALTER TABLE public.refunds OWNER TO erp_user;

--
-- Name: report_definitions; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.report_definitions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    code character varying(150) NOT NULL,
    name character varying(255) NOT NULL,
    domain character varying(100) NOT NULL,
    configuration jsonb DEFAULT '{}'::jsonb NOT NULL,
    active boolean DEFAULT true NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.report_definitions OWNER TO erp_user;

--
-- Name: report_exports; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.report_exports (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    report_definition_id uuid,
    requested_by uuid,
    format character varying(30) NOT NULL,
    filters jsonb DEFAULT '{}'::jsonb NOT NULL,
    status character varying(30) DEFAULT 'PENDING'::character varying NOT NULL,
    document_id uuid,
    error_message text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    completed_at timestamp with time zone
);


ALTER TABLE public.report_exports OWNER TO erp_user;

--
-- Name: resource_assignments; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.resource_assignments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    resource_id uuid NOT NULL,
    project_id uuid,
    work_order_id uuid,
    start_at timestamp with time zone,
    end_at timestamp with time zone,
    quantity numeric(18,4) DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.resource_assignments OWNER TO erp_user;

--
-- Name: resources; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.resources (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    resource_type character varying(50) NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    cost_rate numeric(18,4) DEFAULT 0 NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL
);


ALTER TABLE public.resources OWNER TO erp_user;

--
-- Name: role_permissions; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.role_permissions (
    role_id uuid NOT NULL,
    permission_id uuid NOT NULL
);


ALTER TABLE public.role_permissions OWNER TO erp_user;

--
-- Name: role_scopes; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.role_scopes (
    role_id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    resource character varying(50) NOT NULL,
    scope character varying(30) DEFAULT 'OWN'::character varying NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT role_scopes_resource_check CHECK (((resource)::text = ANY ((ARRAY['quotations'::character varying, 'contracts'::character varying, 'orders'::character varying, 'portal'::character varying, 'leads'::character varying, 'opportunities'::character varying, 'activities'::character varying, 'contracted_services'::character varying, 'work_orders'::character varying, 'projects'::character varying, 'time_entries'::character varying, 'cost_entries'::character varying, 'agenda'::character varying, 'billing'::character varying, 'receivables'::character varying, 'payments'::character varying, 'recurring'::character varying, 'purchases'::character varying, 'payables'::character varying, 'budgets'::character varying, 'tickets'::character varying, 'warranty'::character varying, 'documents'::character varying])::text[]))),
    CONSTRAINT role_scopes_scope_check CHECK (((scope)::text = ANY ((ARRAY['OWN'::character varying, 'TEAM'::character varying, 'BRANCH'::character varying, 'TENANT'::character varying])::text[])))
);


ALTER TABLE public.role_scopes OWNER TO erp_user;

--
-- Name: roles; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.roles (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    is_system boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.roles OWNER TO erp_user;

--
-- Name: saas_plans; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.saas_plans (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    active boolean DEFAULT true NOT NULL,
    limits jsonb DEFAULT '{}'::jsonb NOT NULL,
    modules jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    description text,
    display_order integer DEFAULT 0 NOT NULL,
    is_public boolean DEFAULT true NOT NULL,
    monthly_price numeric(18,4),
    annual_price numeric(18,4),
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    has_trial boolean DEFAULT false NOT NULL,
    trial_days integer DEFAULT 0 NOT NULL,
    requires_payment_upfront boolean DEFAULT false NOT NULL,
    auto_renew_default boolean DEFAULT true NOT NULL,
    default_billing_period character varying(20) DEFAULT 'MONTHLY'::character varying NOT NULL,
    features jsonb DEFAULT '{}'::jsonb NOT NULL,
    support_level character varying(30) DEFAULT 'STANDARD'::character varying NOT NULL,
    support_tickets_month integer,
    support_sla_hours integer,
    support_channels jsonb DEFAULT '{"email": false, "portal": false, "whatsapp": false, "priority_attention": false}'::jsonb NOT NULL,
    consumption jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT saas_plans_annual_price_check CHECK (((annual_price IS NULL) OR (annual_price >= (0)::numeric))),
    CONSTRAINT saas_plans_default_billing_period_check CHECK (((default_billing_period)::text = ANY ((ARRAY['MONTHLY'::character varying, 'ANNUAL'::character varying])::text[]))),
    CONSTRAINT saas_plans_monthly_price_check CHECK (((monthly_price IS NULL) OR (monthly_price >= (0)::numeric))),
    CONSTRAINT saas_plans_support_level_check CHECK (((support_level)::text = ANY ((ARRAY['BASIC'::character varying, 'STANDARD'::character varying, 'PREMIUM'::character varying, 'DEDICATED'::character varying])::text[])))
);


ALTER TABLE public.saas_plans OWNER TO erp_user;

--
-- Name: service_categories; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.service_categories (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    parent_id uuid,
    name character varying(255) NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.service_categories OWNER TO erp_user;

--
-- Name: service_prices; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.service_prices (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    service_id uuid NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    amount numeric(18,4) NOT NULL,
    valid_from date,
    valid_to date,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT service_prices_amount_check CHECK ((amount >= (0)::numeric)),
    CONSTRAINT service_prices_check CHECK (((valid_to IS NULL) OR (valid_from IS NULL) OR (valid_to >= valid_from)))
);


ALTER TABLE public.service_prices OWNER TO erp_user;

--
-- Name: service_recurring_configs; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.service_recurring_configs (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    service_id uuid NOT NULL,
    frequency character varying(30) NOT NULL,
    interval_value integer DEFAULT 1 NOT NULL,
    active boolean DEFAULT true NOT NULL,
    configuration jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT service_recurring_configs_frequency_check CHECK (((frequency)::text = ANY (ARRAY[('DAILY'::character varying)::text, ('WEEKLY'::character varying)::text, ('MONTHLY'::character varying)::text, ('QUARTERLY'::character varying)::text, ('YEARLY'::character varying)::text, ('CUSTOM'::character varying)::text]))),
    CONSTRAINT service_recurring_configs_interval_value_check CHECK ((interval_value > 0))
);


ALTER TABLE public.service_recurring_configs OWNER TO erp_user;

--
-- Name: service_service_links; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.service_service_links (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    source_service_id uuid NOT NULL,
    target_service_id uuid NOT NULL,
    link_type character varying(50) NOT NULL,
    CONSTRAINT service_service_links_check CHECK ((source_service_id <> target_service_id))
);


ALTER TABLE public.service_service_links OWNER TO erp_user;

--
-- Name: service_template_items; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.service_template_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    template_id uuid NOT NULL,
    service_id uuid NOT NULL,
    quantity numeric(18,4) DEFAULT 1 NOT NULL,
    CONSTRAINT service_template_items_quantity_check CHECK ((quantity > (0)::numeric))
);


ALTER TABLE public.service_template_items OWNER TO erp_user;

--
-- Name: service_templates; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.service_templates (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.service_templates OWNER TO erp_user;

--
-- Name: services; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.services (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    category_id uuid,
    code character varying(100) NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    status character varying(30) DEFAULT 'DRAFT'::character varying NOT NULL,
    unit character varying(100),
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    billing_mode character varying(20) DEFAULT 'ONE_TIME'::character varying NOT NULL,
    warranty_months smallint DEFAULT '0'::smallint NOT NULL,
    lock_version integer DEFAULT 0 NOT NULL,
    warranty_conditions text,
    CONSTRAINT services_billing_mode_check CHECK (((billing_mode)::text = ANY ((ARRAY['ONE_TIME'::character varying, 'RECURRING'::character varying, 'PER_EVENT'::character varying])::text[]))),
    CONSTRAINT services_status_check CHECK (((status)::text = ANY ((ARRAY['DRAFT'::character varying, 'ACTIVE'::character varying, 'INACTIVE'::character varying, 'ARCHIVED'::character varying])::text[])))
);


ALTER TABLE public.services OWNER TO erp_user;

--
-- Name: supplier_invoices; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.supplier_invoices (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    supplier_id uuid NOT NULL,
    supplier_name character varying(255) NOT NULL,
    supplier_tax_id character varying(50),
    doc_type character varying(30) DEFAULT 'FACTURA'::character varying NOT NULL,
    doc_number character varying(30) NOT NULL,
    issue_date date NOT NULL,
    due_date date NOT NULL,
    purchase_order_id uuid,
    cost_center_id uuid NOT NULL,
    expense_account character varying(30) NOT NULL,
    description text,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    exchange_rate numeric(12,6),
    subtotal numeric(18,4) NOT NULL,
    tax numeric(18,4) DEFAULT 0 NOT NULL,
    total numeric(18,4) NOT NULL,
    detraction_code character varying(10),
    detraction_pct numeric(6,2),
    detraction_amount numeric(18,4) DEFAULT 0 NOT NULL,
    retention_4ta numeric(18,4) DEFAULT 0 NOT NULL,
    sunat_validation character varying(30) DEFAULT 'NOT_CHECKED'::character varying NOT NULL,
    status character varying(30) DEFAULT 'REGISTERED'::character varying NOT NULL,
    reason text,
    approved_by uuid,
    approved_at timestamp with time zone,
    owner_user_id uuid,
    created_by uuid,
    branch_id uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT supplier_invoices_amounts_check CHECK (((subtotal > (0)::numeric) AND (tax >= (0)::numeric) AND (total >= subtotal) AND (detraction_amount >= (0)::numeric) AND (retention_4ta >= (0)::numeric))),
    CONSTRAINT supplier_invoices_currency_check CHECK (((currency)::text = ANY ((ARRAY['PEN'::character varying, 'USD'::character varying])::text[]))),
    CONSTRAINT supplier_invoices_dates_check CHECK ((due_date >= issue_date)),
    CONSTRAINT supplier_invoices_doc_number_check CHECK (((doc_number)::text ~ '^[A-Z0-9]{4}-[0-9]{1,8}$'::text)),
    CONSTRAINT supplier_invoices_doc_type_check CHECK (((doc_type)::text = ANY ((ARRAY['FACTURA'::character varying, 'RECIBO_HONORARIOS'::character varying])::text[]))),
    CONSTRAINT supplier_invoices_rate_check CHECK ((((currency)::text = 'PEN'::text) OR (exchange_rate > (0)::numeric))),
    CONSTRAINT supplier_invoices_reason_check CHECK ((((status)::text <> ALL ((ARRAY['REJECTED'::character varying, 'VOIDED'::character varying])::text[])) OR (btrim(COALESCE(reason, ''::text)) <> ''::text))),
    CONSTRAINT supplier_invoices_status_check CHECK (((status)::text = ANY ((ARRAY['REGISTERED'::character varying, 'APPROVED'::character varying, 'REJECTED'::character varying, 'VOIDED'::character varying])::text[]))),
    CONSTRAINT supplier_invoices_sunat_check CHECK (((sunat_validation)::text = ANY ((ARRAY['NOT_CHECKED'::character varying, 'VALID'::character varying, 'INVALID'::character varying])::text[])))
);


ALTER TABLE public.supplier_invoices OWNER TO erp_user;

--
-- Name: supplier_payment_allocations; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.supplier_payment_allocations (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    supplier_payment_id uuid NOT NULL,
    payable_id uuid NOT NULL,
    amount numeric(18,4) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT supplier_payment_allocations_amount_check CHECK ((amount > (0)::numeric))
);


ALTER TABLE public.supplier_payment_allocations OWNER TO erp_user;

--
-- Name: supplier_payments; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.supplier_payments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    payment_number character varying(50) NOT NULL,
    supplier_id uuid NOT NULL,
    financial_account_id uuid NOT NULL,
    amount numeric(18,4) NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    detraction_amount numeric(18,4) DEFAULT 0 NOT NULL,
    detraction_number character varying(100),
    detraction_account_id uuid,
    payment_method character varying(30) NOT NULL,
    reference character varying(255),
    paid_at date NOT NULL,
    status character varying(30) DEFAULT 'PAID'::character varying NOT NULL,
    owner_user_id uuid,
    created_by uuid,
    branch_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    reversed_at timestamp with time zone,
    reversed_by uuid,
    reversal_reason text,
    CONSTRAINT supplier_payments_amounts_check CHECK (((amount >= (0)::numeric) AND (detraction_amount >= (0)::numeric) AND ((amount + detraction_amount) > (0)::numeric))),
    CONSTRAINT supplier_payments_currency_check CHECK (((currency)::text = ANY ((ARRAY['PEN'::character varying, 'USD'::character varying])::text[]))),
    CONSTRAINT supplier_payments_detraction_check CHECK (((detraction_amount = (0)::numeric) OR ((btrim((COALESCE(detraction_number, ''::character varying))::text) <> ''::text) AND (detraction_account_id IS NOT NULL)))),
    CONSTRAINT supplier_payments_method_check CHECK (((payment_method)::text = ANY ((ARRAY['TRANSFERENCIA'::character varying, 'CHEQUE'::character varying, 'EFECTIVO'::character varying])::text[]))),
    CONSTRAINT supplier_payments_reversed_check CHECK ((((status)::text = 'PAID'::text) OR ((reversed_at IS NOT NULL) AND (btrim(COALESCE(reversal_reason, ''::text)) <> ''::text) AND (detraction_amount = (0)::numeric)))),
    CONSTRAINT supplier_payments_status_check CHECK (((status)::text = ANY ((ARRAY['PAID'::character varying, 'REVERSED'::character varying])::text[])))
);


ALTER TABLE public.supplier_payments OWNER TO erp_user;

--
-- Name: suppliers; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.suppliers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    party_id uuid NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    detraction_account character varying(30)
);


ALTER TABLE public.suppliers OWNER TO erp_user;

--
-- Name: support_calendars; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.support_calendars (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(150) DEFAULT 'Horario de atención'::character varying NOT NULL,
    timezone character varying(64) DEFAULT 'America/Lima'::character varying NOT NULL,
    hours jsonb NOT NULL,
    premium_factor numeric(5,4) DEFAULT 0.5 NOT NULL,
    lock_version integer DEFAULT 0 NOT NULL,
    updated_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT support_calendars_factor_check CHECK (((premium_factor > (0)::numeric) AND (premium_factor <= (1)::numeric))),
    CONSTRAINT support_calendars_hours_check CHECK ((jsonb_typeof(hours) = 'object'::text))
);


ALTER TABLE public.support_calendars OWNER TO erp_user;

--
-- Name: support_holidays; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.support_holidays (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    calendar_id uuid NOT NULL,
    holiday_date date NOT NULL,
    name character varying(150) NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    removed_at timestamp with time zone,
    removed_by uuid,
    CONSTRAINT support_holidays_name_check CHECK ((btrim((name)::text) <> ''::text))
);


ALTER TABLE public.support_holidays OWNER TO erp_user;

--
-- Name: support_sla_runs; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.support_sla_runs (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    ticket_id uuid NOT NULL,
    sla_id uuid NOT NULL,
    response_due_at timestamp with time zone,
    resolution_due_at timestamp with time zone,
    response_breached boolean DEFAULT false NOT NULL,
    resolution_breached boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.support_sla_runs OWNER TO erp_user;

--
-- Name: support_slas; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.support_slas (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    priority character varying(30) NOT NULL,
    response_minutes integer DEFAULT 0 NOT NULL,
    resolution_minutes integer DEFAULT 0 NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    calendar_mode character varying(10) DEFAULT 'BUSINESS'::character varying NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT support_slas_calendar_mode_check CHECK (((calendar_mode)::text = ANY ((ARRAY['BUSINESS'::character varying, '24X7'::character varying])::text[]))),
    CONSTRAINT support_slas_minutes_check CHECK (((response_minutes > 0) AND (resolution_minutes >= response_minutes) AND (resolution_minutes <= 43200))),
    CONSTRAINT support_slas_priority_check CHECK (((priority)::text = ANY ((ARRAY['P1'::character varying, 'P2'::character varying, 'P3'::character varying, 'P4'::character varying])::text[])))
);


ALTER TABLE public.support_slas OWNER TO erp_user;

--
-- Name: support_ticket_assignments; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.support_ticket_assignments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    ticket_id uuid NOT NULL,
    assigned_user_id uuid NOT NULL,
    assigned_at timestamp with time zone DEFAULT now() NOT NULL,
    unassigned_at timestamp with time zone
);


ALTER TABLE public.support_ticket_assignments OWNER TO erp_user;

--
-- Name: support_ticket_comments; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.support_ticket_comments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    ticket_id uuid NOT NULL,
    author_user_id uuid,
    body text NOT NULL,
    internal boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    author_type character varying(10) DEFAULT 'STAFF'::character varying NOT NULL,
    CONSTRAINT support_ticket_comments_author_type_check CHECK (((author_type)::text = ANY ((ARRAY['STAFF'::character varying, 'PORTAL'::character varying])::text[]))),
    CONSTRAINT support_ticket_comments_body_check CHECK ((btrim(body) <> ''::text)),
    CONSTRAINT support_ticket_comments_portal_check CHECK ((NOT (internal AND ((author_type)::text = 'PORTAL'::text))))
);


ALTER TABLE public.support_ticket_comments OWNER TO erp_user;

--
-- Name: support_ticket_events; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.support_ticket_events (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    ticket_id uuid NOT NULL,
    event_type character varying(100) NOT NULL,
    from_status character varying(30),
    to_status character varying(30),
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.support_ticket_events OWNER TO erp_user;

--
-- Name: support_tickets; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.support_tickets (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    contract_id uuid,
    ticket_number character varying(100) NOT NULL,
    subject character varying(255) NOT NULL,
    description text,
    priority character varying(30),
    status character varying(30) DEFAULT 'OPEN'::character varying NOT NULL,
    sla_id uuid,
    assigned_user_id uuid,
    opened_at timestamp with time zone DEFAULT now() NOT NULL,
    resolved_at timestamp with time zone,
    closed_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    channel character varying(20) DEFAULT 'BACKOFFICE'::character varying NOT NULL,
    category character varying(20) DEFAULT 'INCIDENT'::character varying NOT NULL,
    service_id uuid,
    team_id uuid,
    branch_id uuid,
    created_by uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    triaged_at timestamp with time zone,
    sla_calendar_mode character varying(10),
    sla_factor numeric(5,4),
    sla_response_minutes integer,
    sla_resolution_minutes integer,
    response_due_at timestamp with time zone,
    resolution_due_at timestamp with time zone,
    first_response_at timestamp with time zone,
    paused_at timestamp with time zone,
    paused_seconds bigint DEFAULT 0 NOT NULL,
    resolution text,
    reopen_reason text,
    work_order_id uuid,
    sla_started_at timestamp with time zone,
    CONSTRAINT support_tickets_assignee_check CHECK ((((status)::text <> ALL ((ARRAY['ASSIGNED'::character varying, 'IN_PROGRESS'::character varying, 'WAITING_CUSTOMER'::character varying])::text[])) OR (assigned_user_id IS NOT NULL))),
    CONSTRAINT support_tickets_calendar_mode_check CHECK (((sla_calendar_mode IS NULL) OR ((sla_calendar_mode)::text = ANY ((ARRAY['BUSINESS'::character varying, '24X7'::character varying])::text[])))),
    CONSTRAINT support_tickets_category_check CHECK (((category)::text = ANY ((ARRAY['INCIDENT'::character varying, 'REQUEST'::character varying, 'QUESTION'::character varying])::text[]))),
    CONSTRAINT support_tickets_channel_check CHECK (((channel)::text = ANY ((ARRAY['BACKOFFICE'::character varying, 'PORTAL'::character varying, 'EMAIL'::character varying, 'PHONE'::character varying])::text[]))),
    CONSTRAINT support_tickets_pause_check CHECK ((((paused_at IS NULL) OR ((status)::text = 'WAITING_CUSTOMER'::text)) AND (paused_seconds >= 0))),
    CONSTRAINT support_tickets_priority_check CHECK (((priority IS NULL) OR ((priority)::text = ANY ((ARRAY['P1'::character varying, 'P2'::character varying, 'P3'::character varying, 'P4'::character varying])::text[])))),
    CONSTRAINT support_tickets_reopen_check CHECK ((((status)::text <> 'REOPENED'::text) OR (btrim(COALESCE(reopen_reason, ''::text)) <> ''::text))),
    CONSTRAINT support_tickets_resolution_check CHECK ((((status)::text <> ALL ((ARRAY['RESOLVED'::character varying, 'CLOSED'::character varying])::text[])) OR (btrim(COALESCE(resolution, ''::text)) <> ''::text))),
    CONSTRAINT support_tickets_status_check CHECK (((status)::text = ANY ((ARRAY['OPEN'::character varying, 'TRIAGED'::character varying, 'ASSIGNED'::character varying, 'IN_PROGRESS'::character varying, 'WAITING_CUSTOMER'::character varying, 'RESOLVED'::character varying, 'CLOSED'::character varying, 'REOPENED'::character varying])::text[]))),
    CONSTRAINT support_tickets_subject_check CHECK ((btrim((subject)::text) <> ''::text)),
    CONSTRAINT support_tickets_triaged_check CHECK ((((status)::text = 'OPEN'::text) OR (priority IS NOT NULL)))
);


ALTER TABLE public.support_tickets OWNER TO erp_user;

--
-- Name: task_checklist_items; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.task_checklist_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    task_checklist_id uuid NOT NULL,
    checklist_item_id uuid NOT NULL,
    completed boolean DEFAULT false NOT NULL,
    completed_by uuid,
    completed_at timestamp with time zone,
    evidence_document_id uuid
);


ALTER TABLE public.task_checklist_items OWNER TO erp_user;

--
-- Name: task_checklists; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.task_checklists (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    task_id uuid NOT NULL,
    checklist_id uuid NOT NULL,
    status character varying(30) DEFAULT 'PENDING'::character varying NOT NULL,
    completed_at timestamp with time zone
);


ALTER TABLE public.task_checklists OWNER TO erp_user;

--
-- Name: tasks; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.tasks (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    project_id uuid,
    work_order_id uuid,
    parent_task_id uuid,
    code character varying(100),
    title character varying(255) NOT NULL,
    description text,
    status character varying(30) DEFAULT 'TODO'::character varying NOT NULL,
    priority character varying(30) DEFAULT 'NORMAL'::character varying NOT NULL,
    assigned_user_id uuid,
    due_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    estimate_hours numeric(10,2) DEFAULT 0 NOT NULL,
    required_check character varying(255),
    required_check_done boolean DEFAULT false NOT NULL,
    blocked_reason text,
    created_by uuid,
    CONSTRAINT tasks_check CHECK (((project_id IS NOT NULL) OR (work_order_id IS NOT NULL))),
    CONSTRAINT tasks_priority_check CHECK (((priority)::text = ANY ((ARRAY['LOW'::character varying, 'NORMAL'::character varying, 'HIGH'::character varying])::text[]))),
    CONSTRAINT tasks_required_check_check CHECK ((((status)::text <> 'DONE'::text) OR (required_check IS NULL) OR required_check_done)),
    CONSTRAINT tasks_status_check CHECK (((status)::text = ANY (ARRAY[('TODO'::character varying)::text, ('IN_PROGRESS'::character varying)::text, ('BLOCKED'::character varying)::text, ('DONE'::character varying)::text, ('CANCELLED'::character varying)::text])))
);


ALTER TABLE public.tasks OWNER TO erp_user;

--
-- Name: teams; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.teams (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT teams_status_check CHECK (((status)::text = ANY ((ARRAY['ACTIVE'::character varying, 'INACTIVE'::character varying])::text[])))
);


ALTER TABLE public.teams OWNER TO erp_user;

--
-- Name: tenant_modules; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.tenant_modules (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    module_code character varying(100) NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    configuration jsonb DEFAULT '{}'::jsonb NOT NULL,
    activated_at timestamp with time zone,
    deactivated_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.tenant_modules OWNER TO erp_user;

--
-- Name: tenant_settings; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.tenant_settings (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    setting_key character varying(150) NOT NULL,
    setting_value jsonb DEFAULT '{}'::jsonb NOT NULL,
    sensitive boolean DEFAULT false NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.tenant_settings OWNER TO erp_user;

--
-- Name: tenant_status_log; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.tenant_status_log (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    from_status character varying(30),
    to_status character varying(30) NOT NULL,
    reason text,
    user_id uuid,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL
);


ALTER TABLE public.tenant_status_log OWNER TO erp_user;

--
-- Name: tenant_subscription_payments; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.tenant_subscription_payments (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    subscription_id uuid NOT NULL,
    amount numeric(12,2) NOT NULL,
    currency character(3) DEFAULT 'PEN'::bpchar NOT NULL,
    payment_method character varying(100) NOT NULL,
    reference character varying(100),
    notes text,
    months integer,
    previous_ends_at timestamp with time zone,
    new_ends_at timestamp with time zone NOT NULL,
    created_by uuid,
    created_by_name character varying(255),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT tenant_subscription_payments_amount_check CHECK ((amount >= (0)::numeric)),
    CONSTRAINT tenant_subscription_payments_extends CHECK (((previous_ends_at IS NULL) OR (new_ends_at > previous_ends_at))),
    CONSTRAINT tenant_subscription_payments_months_check CHECK (((months IS NULL) OR ((months >= 1) AND (months <= 120)))),
    CONSTRAINT tenant_subscription_payments_payment_method_check CHECK ((btrim((payment_method)::text) <> ''::text))
);


ALTER TABLE public.tenant_subscription_payments OWNER TO erp_user;

--
-- Name: tenant_usage_counters; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.tenant_usage_counters (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    metric_code character varying(100) NOT NULL,
    period_start date NOT NULL,
    period_end date NOT NULL,
    quantity numeric(20,4) DEFAULT 0 NOT NULL
);


ALTER TABLE public.tenant_usage_counters OWNER TO erp_user;

--
-- Name: tenant_user_branches; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.tenant_user_branches (
    tenant_id uuid NOT NULL,
    user_id uuid NOT NULL,
    branch_id uuid NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.tenant_user_branches OWNER TO erp_user;

--
-- Name: tenant_users; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.tenant_users (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    user_id uuid NOT NULL,
    default_branch_id uuid,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    team_id uuid,
    hourly_cost numeric(18,4) DEFAULT 0 NOT NULL,
    CONSTRAINT tenant_users_hourly_cost_check CHECK ((hourly_cost >= (0)::numeric))
);


ALTER TABLE public.tenant_users OWNER TO erp_user;

--
-- Name: tenants; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.tenants (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    code public.citext NOT NULL,
    name character varying(255) NOT NULL,
    legal_name character varying(255),
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    timezone character varying(80) DEFAULT 'America/Lima'::character varying NOT NULL,
    locale character varying(20) DEFAULT 'es-PE'::character varying NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    configuration jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    login_slug public.citext,
    CONSTRAINT tenants_login_slug_format CHECK (((login_slug IS NULL) OR (((login_slug)::text ~ '^[a-z0-9]([a-z0-9-]{1,38}[a-z0-9])$'::text) AND (POSITION(('--'::text) IN ((login_slug)::text)) = 0)))),
    CONSTRAINT tenants_status_check CHECK (((status)::text = ANY (ARRAY[('PROVISIONING'::character varying)::text, ('ACTIVE'::character varying)::text, ('SUSPENDED'::character varying)::text, ('MAINTENANCE'::character varying)::text, ('CLOSED'::character varying)::text])))
);


ALTER TABLE public.tenants OWNER TO erp_user;

--
-- Name: time_entries; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.time_entries (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    user_id uuid,
    employee_id uuid,
    project_id uuid,
    work_order_id uuid,
    task_id uuid,
    started_at timestamp with time zone NOT NULL,
    ended_at timestamp with time zone NOT NULL,
    hours numeric(12,4) NOT NULL,
    billable boolean DEFAULT true NOT NULL,
    hourly_cost numeric(18,4) DEFAULT 0 NOT NULL,
    approved boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    status character varying(30) DEFAULT 'SUBMITTED'::character varying NOT NULL,
    description text,
    approved_by uuid,
    approved_at timestamp with time zone,
    reject_reason text,
    branch_id uuid,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    lock_version integer DEFAULT 0 NOT NULL,
    CONSTRAINT time_entries_check CHECK ((ended_at >= started_at)),
    CONSTRAINT time_entries_context_check CHECK (((project_id IS NOT NULL) OR (work_order_id IS NOT NULL))),
    CONSTRAINT time_entries_duration_check CHECK (((ended_at > started_at) AND (ended_at <= (started_at + '12:00:00'::interval)))),
    CONSTRAINT time_entries_hours_check CHECK ((hours >= (0)::numeric)),
    CONSTRAINT time_entries_reject_reason_check CHECK ((((status)::text <> 'REJECTED'::text) OR (btrim(COALESCE(reject_reason, ''::text)) <> ''::text))),
    CONSTRAINT time_entries_self_approval_check CHECK (((approved_by IS NULL) OR (user_id IS NULL) OR (approved_by <> user_id))),
    CONSTRAINT time_entries_status_check CHECK (((status)::text = ANY ((ARRAY['SUBMITTED'::character varying, 'APPROVED'::character varying, 'REJECTED'::character varying])::text[])))
);


ALTER TABLE public.time_entries OWNER TO erp_user;

--
-- Name: user_roles; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.user_roles (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    user_id uuid NOT NULL,
    role_id uuid NOT NULL,
    branch_id uuid,
    scope character varying(30) DEFAULT 'TENANT'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT user_roles_scope_check CHECK (((scope)::text = ANY (ARRAY[('OWN'::character varying)::text, ('TEAM'::character varying)::text, ('BRANCH'::character varying)::text, ('TENANT'::character varying)::text])))
);


ALTER TABLE public.user_roles OWNER TO erp_user;

--
-- Name: warranty_claims; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.warranty_claims (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    contract_id uuid,
    warranty_policy_id uuid,
    claim_number character varying(100) NOT NULL,
    status character varying(30) DEFAULT 'SUBMITTED'::character varying NOT NULL,
    description text NOT NULL,
    opened_at timestamp with time zone DEFAULT now() NOT NULL,
    resolved_at timestamp with time zone,
    contracted_service_id uuid NOT NULL,
    service_id uuid,
    ticket_id uuid,
    created_by uuid,
    branch_id uuid,
    lock_version integer DEFAULT 0 NOT NULL,
    coverage_checked_at timestamp with time zone,
    coverage_valid boolean,
    coverage_base_date date,
    coverage_until date,
    coverage_policy text,
    coverage_base text,
    reject_reason text,
    resolution text,
    reviewed_by uuid,
    reviewed_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT warranty_claims_coverage_check CHECK ((((status)::text <> ALL ((ARRAY['APPROVED'::character varying, 'RESOLVED'::character varying])::text[])) OR ((coverage_valid IS TRUE) AND (coverage_checked_at IS NOT NULL)))),
    CONSTRAINT warranty_claims_description_check CHECK ((btrim(description) <> ''::text)),
    CONSTRAINT warranty_claims_reject_check CHECK ((((status)::text <> 'REJECTED'::text) OR (btrim(COALESCE(reject_reason, ''::text)) <> ''::text))),
    CONSTRAINT warranty_claims_resolution_check CHECK ((((status)::text <> 'RESOLVED'::text) OR (btrim(COALESCE(resolution, ''::text)) <> ''::text))),
    CONSTRAINT warranty_claims_review_check CHECK ((((status)::text = 'SUBMITTED'::text) OR (coverage_checked_at IS NOT NULL))),
    CONSTRAINT warranty_claims_status_check CHECK (((status)::text = ANY ((ARRAY['SUBMITTED'::character varying, 'UNDER_REVIEW'::character varying, 'APPROVED'::character varying, 'REJECTED'::character varying, 'RESOLVED'::character varying])::text[])))
);


ALTER TABLE public.warranty_claims OWNER TO erp_user;

--
-- Name: warranty_policies; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.warranty_policies (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    service_id uuid,
    duration_days integer DEFAULT 0 NOT NULL,
    terms text,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.warranty_policies OWNER TO erp_user;

--
-- Name: webhook_deliveries; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.webhook_deliveries (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    endpoint_id uuid NOT NULL,
    event_id uuid,
    status character varying(30) DEFAULT 'PENDING'::character varying NOT NULL,
    attempt_count integer DEFAULT 0 NOT NULL,
    response_code integer,
    next_attempt_at timestamp with time zone,
    delivered_at timestamp with time zone,
    last_error text,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.webhook_deliveries OWNER TO erp_user;

--
-- Name: webhook_endpoints; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.webhook_endpoints (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    url text NOT NULL,
    secret_credential_id uuid,
    active boolean DEFAULT true NOT NULL,
    event_types jsonb DEFAULT '[]'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.webhook_endpoints OWNER TO erp_user;

--
-- Name: webhook_inbound_events; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.webhook_inbound_events (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid,
    provider_code character varying(100) NOT NULL,
    external_event_id character varying(255) NOT NULL,
    signature_valid boolean DEFAULT false NOT NULL,
    replay_detected boolean DEFAULT false NOT NULL,
    payload jsonb NOT NULL,
    received_at timestamp with time zone DEFAULT now() NOT NULL,
    processed_at timestamp with time zone,
    status character varying(30) DEFAULT 'RECEIVED'::character varying NOT NULL
);


ALTER TABLE public.webhook_inbound_events OWNER TO erp_user;

--
-- Name: work_order_checklist_items; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.work_order_checklist_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    work_order_id uuid NOT NULL,
    "position" integer NOT NULL,
    label character varying(500) NOT NULL,
    required boolean DEFAULT false NOT NULL,
    done boolean DEFAULT false NOT NULL,
    done_by uuid,
    done_at timestamp with time zone,
    evidence_note text,
    evidence_document_id uuid,
    evidence_required boolean DEFAULT false NOT NULL,
    CONSTRAINT work_order_checklist_items_evidence_check CHECK ((NOT (done AND evidence_required AND (evidence_document_id IS NULL))))
);


ALTER TABLE public.work_order_checklist_items OWNER TO erp_user;

--
-- Name: work_orders; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.work_orders (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    customer_id uuid NOT NULL,
    contracted_service_id uuid,
    order_id uuid,
    code character varying(100) NOT NULL,
    status character varying(30) DEFAULT 'PLANNED'::character varying NOT NULL,
    priority character varying(30) DEFAULT 'NORMAL'::character varying NOT NULL,
    scheduled_start timestamp with time zone,
    scheduled_end timestamp with time zone,
    description text,
    assigned_user_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    title character varying(255) NOT NULL,
    contract_id uuid,
    service_id uuid,
    location character varying(255),
    blocked_reason text,
    cancel_reason text,
    started_at timestamp with time zone,
    completed_at timestamp with time zone,
    created_by uuid,
    branch_id uuid,
    checklist_id uuid,
    checklist_name character varying(255),
    checklist_status character varying(30),
    checklist_reviewed_by uuid,
    checklist_reviewed_at timestamp with time zone,
    checklist_review_note text,
    lock_version integer DEFAULT 0 NOT NULL,
    latitude numeric(9,6),
    longitude numeric(9,6),
    CONSTRAINT work_orders_assignee_check CHECK ((((status)::text <> ALL ((ARRAY['ASSIGNED'::character varying, 'IN_PROGRESS'::character varying])::text[])) OR (assigned_user_id IS NOT NULL))),
    CONSTRAINT work_orders_blocked_reason_check CHECK ((((status)::text <> 'BLOCKED'::text) OR (btrim(COALESCE(blocked_reason, ''::text)) <> ''::text))),
    CONSTRAINT work_orders_cancel_reason_check CHECK ((((status)::text <> 'CANCELLED'::text) OR (btrim(COALESCE(cancel_reason, ''::text)) <> ''::text))),
    CONSTRAINT work_orders_checklist_status_check CHECK (((checklist_status IS NULL) OR ((checklist_status)::text = ANY ((ARRAY['PENDING'::character varying, 'IN_PROGRESS'::character varying, 'COMPLETED'::character varying, 'APPROVED'::character varying])::text[])))),
    CONSTRAINT work_orders_coordinates_check CHECK ((((latitude IS NULL) AND (longitude IS NULL)) OR ((latitude IS NOT NULL) AND (longitude IS NOT NULL) AND ((latitude >= ('-90'::integer)::numeric) AND (latitude <= (90)::numeric)) AND ((longitude >= ('-180'::integer)::numeric) AND (longitude <= (180)::numeric))))),
    CONSTRAINT work_orders_priority_check CHECK (((priority)::text = ANY ((ARRAY['LOW'::character varying, 'NORMAL'::character varying, 'HIGH'::character varying])::text[]))),
    CONSTRAINT work_orders_schedule_check CHECK (((scheduled_start IS NULL) OR (scheduled_end IS NULL) OR (scheduled_end >= scheduled_start))),
    CONSTRAINT work_orders_status_check CHECK (((status)::text = ANY ((ARRAY['PLANNED'::character varying, 'ASSIGNED'::character varying, 'IN_PROGRESS'::character varying, 'BLOCKED'::character varying, 'COMPLETED'::character varying, 'CANCELLED'::character varying])::text[])))
);


ALTER TABLE public.work_orders OWNER TO erp_user;

--
-- Name: workflow_definitions; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.workflow_definitions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    code character varying(150) NOT NULL,
    name character varying(255) NOT NULL,
    entity_type character varying(150) NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    active boolean DEFAULT true NOT NULL,
    configuration jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.workflow_definitions OWNER TO erp_user;

--
-- Name: workflow_runs; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.workflow_runs (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    workflow_definition_id uuid NOT NULL,
    entity_id uuid NOT NULL,
    status character varying(30) DEFAULT 'RUNNING'::character varying NOT NULL,
    context jsonb DEFAULT '{}'::jsonb NOT NULL,
    started_at timestamp with time zone DEFAULT now() NOT NULL,
    completed_at timestamp with time zone
);


ALTER TABLE public.workflow_runs OWNER TO erp_user;

--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: acc_accounts acc_accounts_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_accounts
    ADD CONSTRAINT acc_accounts_code_key UNIQUE (tenant_id, code);


--
-- Name: acc_accounts acc_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_accounts
    ADD CONSTRAINT acc_accounts_pkey PRIMARY KEY (id);


--
-- Name: acc_fiscal_years acc_fiscal_years_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_fiscal_years
    ADD CONSTRAINT acc_fiscal_years_pkey PRIMARY KEY (id);


--
-- Name: acc_fiscal_years acc_fiscal_years_unique; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_fiscal_years
    ADD CONSTRAINT acc_fiscal_years_unique UNIQUE (tenant_id, fiscal_year);


--
-- Name: acc_journal_entries acc_journal_entries_number_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_journal_entries
    ADD CONSTRAINT acc_journal_entries_number_key UNIQUE (tenant_id, entry_number);


--
-- Name: acc_journal_entries acc_journal_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_journal_entries
    ADD CONSTRAINT acc_journal_entries_pkey PRIMARY KEY (id);


--
-- Name: acc_journal_lines acc_journal_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_journal_lines
    ADD CONSTRAINT acc_journal_lines_pkey PRIMARY KEY (id);


--
-- Name: acc_periods acc_periods_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_periods
    ADD CONSTRAINT acc_periods_key UNIQUE (tenant_id, period);


--
-- Name: acc_periods acc_periods_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_periods
    ADD CONSTRAINT acc_periods_pkey PRIMARY KEY (id);


--
-- Name: advances advances_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.advances
    ADD CONSTRAINT advances_pkey PRIMARY KEY (id);


--
-- Name: agenda_events agenda_events_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.agenda_events
    ADD CONSTRAINT agenda_events_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: bank_movements bank_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_movements
    ADD CONSTRAINT bank_movements_pkey PRIMARY KEY (id);


--
-- Name: bank_statement_lines bank_statement_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_statement_lines
    ADD CONSTRAINT bank_statement_lines_pkey PRIMARY KEY (id);


--
-- Name: bank_statements bank_statements_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_statements
    ADD CONSTRAINT bank_statements_pkey PRIMARY KEY (id);


--
-- Name: bank_transfers bank_transfers_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_transfers
    ADD CONSTRAINT bank_transfers_pkey PRIMARY KEY (id);


--
-- Name: billable_events billable_events_idempotency_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.billable_events
    ADD CONSTRAINT billable_events_idempotency_key UNIQUE (tenant_id, idempotency_key);


--
-- Name: billable_events billable_events_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.billable_events
    ADD CONSTRAINT billable_events_pkey PRIMARY KEY (id);


--
-- Name: branches branches_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.branches
    ADD CONSTRAINT branches_pkey PRIMARY KEY (id);


--
-- Name: branches branches_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.branches
    ADD CONSTRAINT branches_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: budget_lines budget_lines_budget_id_period_month_category_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.budget_lines
    ADD CONSTRAINT budget_lines_budget_id_period_month_category_key UNIQUE (budget_id, period_month, category);


--
-- Name: budget_lines budget_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.budget_lines
    ADD CONSTRAINT budget_lines_pkey PRIMARY KEY (id);


--
-- Name: budgets budgets_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.budgets
    ADD CONSTRAINT budgets_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: cash_counts cash_counts_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_counts
    ADD CONSTRAINT cash_counts_pkey PRIMARY KEY (id);


--
-- Name: cash_expense_reclassifications cash_expense_reclassifications_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expense_reclassifications
    ADD CONSTRAINT cash_expense_reclassifications_pkey PRIMARY KEY (id);


--
-- Name: cash_expenses cash_expenses_classified_check; Type: CHECK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE public.cash_expenses
    ADD CONSTRAINT cash_expenses_classified_check CHECK (((cost_center_id IS NOT NULL) AND (expense_account IS NOT NULL))) NOT VALID;


--
-- Name: cash_expenses cash_expenses_number_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expenses
    ADD CONSTRAINT cash_expenses_number_key UNIQUE (tenant_id, expense_number);


--
-- Name: cash_expenses cash_expenses_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expenses
    ADD CONSTRAINT cash_expenses_pkey PRIMARY KEY (id);


--
-- Name: checklist_items checklist_items_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.checklist_items
    ADD CONSTRAINT checklist_items_pkey PRIMARY KEY (id);


--
-- Name: checklists checklists_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.checklists
    ADD CONSTRAINT checklists_pkey PRIMARY KEY (id);


--
-- Name: companies companies_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.companies
    ADD CONSTRAINT companies_pkey PRIMARY KEY (id);


--
-- Name: companies companies_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.companies
    ADD CONSTRAINT companies_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: company_branding company_branding_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.company_branding
    ADD CONSTRAINT company_branding_pkey PRIMARY KEY (tenant_id);


--
-- Name: contacts contacts_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contacts
    ADD CONSTRAINT contacts_pkey PRIMARY KEY (id);


--
-- Name: contract_items contract_items_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_items
    ADD CONSTRAINT contract_items_pkey PRIMARY KEY (id);


--
-- Name: contract_signature_requests contract_signature_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_signature_requests
    ADD CONSTRAINT contract_signature_requests_pkey PRIMARY KEY (id);


--
-- Name: contract_signature_signers contract_signature_signers_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_signature_signers
    ADD CONSTRAINT contract_signature_signers_pkey PRIMARY KEY (id);


--
-- Name: contract_signature_signers contract_signature_signers_unique; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_signature_signers
    ADD CONSTRAINT contract_signature_signers_unique UNIQUE (request_id, side);


--
-- Name: contracted_services contracted_services_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracted_services
    ADD CONSTRAINT contracted_services_pkey PRIMARY KEY (id);


--
-- Name: contracts contracts_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT contracts_pkey PRIMARY KEY (id);


--
-- Name: contracts contracts_tenant_id_contract_number_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT contracts_tenant_id_contract_number_key UNIQUE (tenant_id, contract_number);


--
-- Name: cost_centers cost_centers_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_centers
    ADD CONSTRAINT cost_centers_pkey PRIMARY KEY (id);


--
-- Name: cost_centers cost_centers_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_centers
    ADD CONSTRAINT cost_centers_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: cost_entries cost_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_entries
    ADD CONSTRAINT cost_entries_pkey PRIMARY KEY (id);


--
-- Name: credential_access_logs credential_access_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.credential_access_logs
    ADD CONSTRAINT credential_access_logs_pkey PRIMARY KEY (id);


--
-- Name: credential_records credential_records_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.credential_records
    ADD CONSTRAINT credential_records_pkey PRIMARY KEY (id);


--
-- Name: credential_versions credential_versions_credential_id_version_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.credential_versions
    ADD CONSTRAINT credential_versions_credential_id_version_key UNIQUE (credential_id, version);


--
-- Name: credential_versions credential_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.credential_versions
    ADD CONSTRAINT credential_versions_pkey PRIMARY KEY (id);


--
-- Name: crm_activities crm_activities_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_activities
    ADD CONSTRAINT crm_activities_pkey PRIMARY KEY (id);


--
-- Name: crm_campaign_members crm_campaign_members_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_campaign_members
    ADD CONSTRAINT crm_campaign_members_pkey PRIMARY KEY (id);


--
-- Name: crm_campaigns crm_campaigns_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_campaigns
    ADD CONSTRAINT crm_campaigns_pkey PRIMARY KEY (id);


--
-- Name: crm_campaigns crm_campaigns_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_campaigns
    ADD CONSTRAINT crm_campaigns_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: crm_conversions crm_conversions_lead_id_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_conversions
    ADD CONSTRAINT crm_conversions_lead_id_key UNIQUE (lead_id);


--
-- Name: crm_conversions crm_conversions_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_conversions
    ADD CONSTRAINT crm_conversions_pkey PRIMARY KEY (id);


--
-- Name: crm_entity_tags crm_entity_tags_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_entity_tags
    ADD CONSTRAINT crm_entity_tags_pkey PRIMARY KEY (id);


--
-- Name: crm_entity_tags crm_entity_tags_tag_id_entity_type_entity_id_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_entity_tags
    ADD CONSTRAINT crm_entity_tags_tag_id_entity_type_entity_id_key UNIQUE (tag_id, entity_type, entity_id);


--
-- Name: crm_leads crm_leads_discard_reason_check; Type: CHECK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE public.crm_leads
    ADD CONSTRAINT crm_leads_discard_reason_check CHECK ((((status)::text <> 'DISQUALIFIED'::text) OR (length(TRIM(BOTH FROM COALESCE(discard_reason, ''::text))) > 0))) NOT VALID;


--
-- Name: crm_leads crm_leads_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_leads
    ADD CONSTRAINT crm_leads_pkey PRIMARY KEY (id);


--
-- Name: crm_opportunities crm_opportunities_closing_check; Type: CHECK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE public.crm_opportunities
    ADD CONSTRAINT crm_opportunities_closing_check CHECK (((((status)::text <> 'WON'::text) OR (length(TRIM(BOTH FROM COALESCE(won_evidence, ''::text))) > 0)) AND (((status)::text <> 'LOST'::text) OR (length(TRIM(BOTH FROM COALESCE(loss_reason, ''::text))) > 0)))) NOT VALID;


--
-- Name: crm_opportunities crm_opportunities_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_opportunities
    ADD CONSTRAINT crm_opportunities_pkey PRIMARY KEY (id);


--
-- Name: crm_pipeline_stages crm_pipeline_stages_pipeline_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_pipeline_stages
    ADD CONSTRAINT crm_pipeline_stages_pipeline_id_code_key UNIQUE (pipeline_id, code);


--
-- Name: crm_pipeline_stages crm_pipeline_stages_pipeline_id_position_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_pipeline_stages
    ADD CONSTRAINT crm_pipeline_stages_pipeline_id_position_key UNIQUE (pipeline_id, "position");


--
-- Name: crm_pipeline_stages crm_pipeline_stages_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_pipeline_stages
    ADD CONSTRAINT crm_pipeline_stages_pkey PRIMARY KEY (id);


--
-- Name: crm_pipelines crm_pipelines_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_pipelines
    ADD CONSTRAINT crm_pipelines_pkey PRIMARY KEY (id);


--
-- Name: crm_pipelines crm_pipelines_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_pipelines
    ADD CONSTRAINT crm_pipelines_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: crm_settings crm_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_settings
    ADD CONSTRAINT crm_settings_pkey PRIMARY KEY (id);


--
-- Name: crm_settings crm_settings_tenant_id_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_settings
    ADD CONSTRAINT crm_settings_tenant_id_key UNIQUE (tenant_id);


--
-- Name: crm_sources crm_sources_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_sources
    ADD CONSTRAINT crm_sources_pkey PRIMARY KEY (id);


--
-- Name: crm_sources crm_sources_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_sources
    ADD CONSTRAINT crm_sources_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: crm_tags crm_tags_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_tags
    ADD CONSTRAINT crm_tags_pkey PRIMARY KEY (id);


--
-- Name: crm_tags crm_tags_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_tags
    ADD CONSTRAINT crm_tags_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: custom_field_definitions custom_field_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.custom_field_definitions
    ADD CONSTRAINT custom_field_definitions_pkey PRIMARY KEY (id);


--
-- Name: custom_field_definitions custom_field_definitions_tenant_id_entity_type_field_key_unique; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.custom_field_definitions
    ADD CONSTRAINT custom_field_definitions_tenant_id_entity_type_field_key_unique UNIQUE (tenant_id, entity_type, field_key);


--
-- Name: custom_field_values custom_field_values_field_id_entity_id_unique; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.custom_field_values
    ADD CONSTRAINT custom_field_values_field_id_entity_id_unique UNIQUE (field_id, entity_id);


--
-- Name: custom_field_values custom_field_values_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.custom_field_values
    ADD CONSTRAINT custom_field_values_pkey PRIMARY KEY (id);


--
-- Name: customers customers_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_pkey PRIMARY KEY (id);


--
-- Name: customers customers_tenant_id_party_id_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_tenant_id_party_id_key UNIQUE (tenant_id, party_id);


--
-- Name: dashboard_definitions dashboard_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.dashboard_definitions
    ADD CONSTRAINT dashboard_definitions_pkey PRIMARY KEY (id);


--
-- Name: dashboard_definitions dashboard_definitions_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.dashboard_definitions
    ADD CONSTRAINT dashboard_definitions_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: deliverables deliverables_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.deliverables
    ADD CONSTRAINT deliverables_pkey PRIMARY KEY (id);


--
-- Name: document_links document_links_document_id_entity_type_entity_id_link_type_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.document_links
    ADD CONSTRAINT document_links_document_id_entity_type_entity_id_link_type_key UNIQUE (document_id, entity_type, entity_id, link_type);


--
-- Name: document_links document_links_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.document_links
    ADD CONSTRAINT document_links_pkey PRIMARY KEY (id);


--
-- Name: document_versions document_versions_document_id_version_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.document_versions
    ADD CONSTRAINT document_versions_document_id_version_key UNIQUE (document_id, version);


--
-- Name: document_versions document_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.document_versions
    ADD CONSTRAINT document_versions_pkey PRIMARY KEY (id);


--
-- Name: documents documents_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_pkey PRIMARY KEY (id);


--
-- Name: documents documents_title_check; Type: CHECK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE public.documents
    ADD CONSTRAINT documents_title_check CHECK ((btrim((title)::text) <> ''::text)) NOT VALID;


--
-- Name: employees employees_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_pkey PRIMARY KEY (id);


--
-- Name: employees employees_tenant_id_employee_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_tenant_id_employee_code_key UNIQUE (tenant_id, employee_code);


--
-- Name: evidences evidences_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.evidences
    ADD CONSTRAINT evidences_pkey PRIMARY KEY (id);


--
-- Name: exchange_rates exchange_rates_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.exchange_rates
    ADD CONSTRAINT exchange_rates_pkey PRIMARY KEY (id);


--
-- Name: exchange_rates exchange_rates_tenant_id_currency_rate_date_unique; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.exchange_rates
    ADD CONSTRAINT exchange_rates_tenant_id_currency_rate_date_unique UNIQUE (tenant_id, currency, rate_date);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: file_objects file_objects_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.file_objects
    ADD CONSTRAINT file_objects_pkey PRIMARY KEY (id);


--
-- Name: file_objects file_objects_tenant_id_storage_provider_bucket_object_key_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.file_objects
    ADD CONSTRAINT file_objects_tenant_id_storage_provider_bucket_object_key_key UNIQUE (tenant_id, storage_provider, bucket, object_key);


--
-- Name: financial_accounts financial_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.financial_accounts
    ADD CONSTRAINT financial_accounts_pkey PRIMARY KEY (id);


--
-- Name: financial_accounts financial_accounts_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.financial_accounts
    ADD CONSTRAINT financial_accounts_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: fiscal_configurations fiscal_configurations_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_configurations
    ADD CONSTRAINT fiscal_configurations_pkey PRIMARY KEY (id);


--
-- Name: fiscal_configurations fiscal_configurations_tenant_id_country_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_configurations
    ADD CONSTRAINT fiscal_configurations_tenant_id_country_code_key UNIQUE (tenant_id, country_code);


--
-- Name: fiscal_documents fiscal_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_documents
    ADD CONSTRAINT fiscal_documents_pkey PRIMARY KEY (id);


--
-- Name: fiscal_documents fiscal_documents_tenant_id_document_type_series_number_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_documents
    ADD CONSTRAINT fiscal_documents_tenant_id_document_type_series_number_key UNIQUE (tenant_id, document_type, series, number);


--
-- Name: fiscal_notes fiscal_notes_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_notes
    ADD CONSTRAINT fiscal_notes_pkey PRIMARY KEY (id);


--
-- Name: fiscal_series fiscal_series_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_series
    ADD CONSTRAINT fiscal_series_pkey PRIMARY KEY (id);


--
-- Name: fiscal_series fiscal_series_tenant_id_document_type_series_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_series
    ADD CONSTRAINT fiscal_series_tenant_id_document_type_series_key UNIQUE (tenant_id, document_type, series);


--
-- Name: fiscal_void_requests fiscal_void_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_void_requests
    ADD CONSTRAINT fiscal_void_requests_pkey PRIMARY KEY (id);


--
-- Name: idempotency_keys idempotency_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.idempotency_keys
    ADD CONSTRAINT idempotency_keys_pkey PRIMARY KEY (id);


--
-- Name: idempotency_keys idempotency_keys_tenant_id_idempotency_key_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.idempotency_keys
    ADD CONSTRAINT idempotency_keys_tenant_id_idempotency_key_key UNIQUE (tenant_id, idempotency_key);


--
-- Name: integration_attempts integration_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.integration_attempts
    ADD CONSTRAINT integration_attempts_pkey PRIMARY KEY (id);


--
-- Name: integration_connections integration_connections_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.integration_connections
    ADD CONSTRAINT integration_connections_pkey PRIMARY KEY (id);


--
-- Name: integration_connections integration_connections_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.integration_connections
    ADD CONSTRAINT integration_connections_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: integration_dead_letters integration_dead_letters_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.integration_dead_letters
    ADD CONSTRAINT integration_dead_letters_pkey PRIMARY KEY (id);


--
-- Name: invoice_items invoice_items_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoice_items
    ADD CONSTRAINT invoice_items_pkey PRIMARY KEY (id);


--
-- Name: invoices invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_pkey PRIMARY KEY (id);


--
-- Name: invoices invoices_tenant_id_invoice_number_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_tenant_id_invoice_number_key UNIQUE (tenant_id, invoice_number);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: module_catalog module_catalog_code_unique; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.module_catalog
    ADD CONSTRAINT module_catalog_code_unique UNIQUE (code);


--
-- Name: module_catalog module_catalog_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.module_catalog
    ADD CONSTRAINT module_catalog_pkey PRIMARY KEY (id);


--
-- Name: notification_preferences notification_preferences_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.notification_preferences
    ADD CONSTRAINT notification_preferences_pkey PRIMARY KEY (tenant_id, user_id);


--
-- Name: notifications notifications_channel_check; Type: CHECK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE public.notifications
    ADD CONSTRAINT notifications_channel_check CHECK (((channel)::text = ANY ((ARRAY['IN_APP'::character varying, 'EMAIL'::character varying])::text[]))) NOT VALID;


--
-- Name: notifications notifications_link_internal; Type: CHECK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE public.notifications
    ADD CONSTRAINT notifications_link_internal CHECK (((link IS NULL) OR (((link)::text ~ '^/[A-Za-z0-9/_?=&.%-]*$'::text) AND ((link)::text !~~ '//%'::text)))) NOT VALID;


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: order_items order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT order_items_pkey PRIMARY KEY (id);


--
-- Name: orders orders_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_pkey PRIMARY KEY (id);


--
-- Name: orders orders_tenant_id_order_number_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_tenant_id_order_number_key UNIQUE (tenant_id, order_number);


--
-- Name: organizations organizations_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_pkey PRIMARY KEY (party_id);


--
-- Name: outbox_events outbox_events_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.outbox_events
    ADD CONSTRAINT outbox_events_pkey PRIMARY KEY (id);


--
-- Name: parties parties_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.parties
    ADD CONSTRAINT parties_pkey PRIMARY KEY (id);


--
-- Name: parties parties_tenant_id_id_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.parties
    ADD CONSTRAINT parties_tenant_id_id_key UNIQUE (tenant_id, id);


--
-- Name: payable_movements payable_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payable_movements
    ADD CONSTRAINT payable_movements_pkey PRIMARY KEY (id);


--
-- Name: payables payables_invoice_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payables
    ADD CONSTRAINT payables_invoice_key UNIQUE (supplier_invoice_id);


--
-- Name: payables payables_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payables
    ADD CONSTRAINT payables_pkey PRIMARY KEY (id);


--
-- Name: payment_allocations payment_allocations_payment_id_receivable_id_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payment_allocations
    ADD CONSTRAINT payment_allocations_payment_id_receivable_id_key UNIQUE (payment_id, receivable_id);


--
-- Name: payment_allocations payment_allocations_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payment_allocations
    ADD CONSTRAINT payment_allocations_pkey PRIMARY KEY (id);


--
-- Name: payments payments_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_pkey PRIMARY KEY (id);


--
-- Name: payments payments_tenant_id_payment_number_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_tenant_id_payment_number_key UNIQUE (tenant_id, payment_number);


--
-- Name: permissions permissions_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_code_key UNIQUE (code);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: persons persons_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.persons
    ADD CONSTRAINT persons_pkey PRIMARY KEY (party_id);


--
-- Name: platform_audit_logs platform_audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.platform_audit_logs
    ADD CONSTRAINT platform_audit_logs_pkey PRIMARY KEY (id);


--
-- Name: platform_settings platform_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.platform_settings
    ADD CONSTRAINT platform_settings_pkey PRIMARY KEY (key);


--
-- Name: portal_notices portal_notices_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_notices
    ADD CONSTRAINT portal_notices_pkey PRIMARY KEY (id);


--
-- Name: portal_notifications portal_notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_notifications
    ADD CONSTRAINT portal_notifications_pkey PRIMARY KEY (id);


--
-- Name: portal_requests portal_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_requests
    ADD CONSTRAINT portal_requests_pkey PRIMARY KEY (id);


--
-- Name: portal_requests portal_requests_tenant_id_request_number_unique; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_requests
    ADD CONSTRAINT portal_requests_tenant_id_request_number_unique UNIQUE (tenant_id, request_number);


--
-- Name: portal_users portal_users_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_users
    ADD CONSTRAINT portal_users_pkey PRIMARY KEY (id);


--
-- Name: portal_users portal_users_tenant_id_customer_id_user_id_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_users
    ADD CONSTRAINT portal_users_tenant_id_customer_id_user_id_key UNIQUE (tenant_id, customer_id, user_id);


--
-- Name: portal_users portal_users_tenant_id_user_id_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_users
    ADD CONSTRAINT portal_users_tenant_id_user_id_key UNIQUE (tenant_id, user_id);


--
-- Name: projects projects_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_pkey PRIMARY KEY (id);


--
-- Name: projects projects_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: purchase_order_items purchase_order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_pkey PRIMARY KEY (id);


--
-- Name: purchase_orders purchase_orders_number_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_number_key UNIQUE (tenant_id, order_number);


--
-- Name: purchase_orders purchase_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_pkey PRIMARY KEY (id);


--
-- Name: purchase_receipts purchase_receipts_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_receipts
    ADD CONSTRAINT purchase_receipts_pkey PRIMARY KEY (id);


--
-- Name: quotation_approvals quotation_approvals_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotation_approvals
    ADD CONSTRAINT quotation_approvals_pkey PRIMARY KEY (id);


--
-- Name: quotation_items quotation_items_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotation_items
    ADD CONSTRAINT quotation_items_pkey PRIMARY KEY (id);


--
-- Name: quotations quotations_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotations
    ADD CONSTRAINT quotations_pkey PRIMARY KEY (id);


--
-- Name: quotations quotations_tenant_id_quotation_number_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotations
    ADD CONSTRAINT quotations_tenant_id_quotation_number_key UNIQUE (tenant_id, quotation_number);


--
-- Name: receivable_movements receivable_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.receivable_movements
    ADD CONSTRAINT receivable_movements_pkey PRIMARY KEY (id);


--
-- Name: receivables receivables_invoice_id_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.receivables
    ADD CONSTRAINT receivables_invoice_id_key UNIQUE (invoice_id);


--
-- Name: receivables receivables_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.receivables
    ADD CONSTRAINT receivables_pkey PRIMARY KEY (id);


--
-- Name: recurring_service_alerts recurring_service_alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_alerts
    ADD CONSTRAINT recurring_service_alerts_pkey PRIMARY KEY (id);


--
-- Name: recurring_service_cycles recurring_service_cycles_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_cycles
    ADD CONSTRAINT recurring_service_cycles_pkey PRIMARY KEY (id);


--
-- Name: recurring_service_cycles recurring_service_cycles_recurring_instance_id_cycle_start__key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_cycles
    ADD CONSTRAINT recurring_service_cycles_recurring_instance_id_cycle_start__key UNIQUE (recurring_instance_id, cycle_start, cycle_end);


--
-- Name: recurring_service_instances recurring_service_instances_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_instances
    ADD CONSTRAINT recurring_service_instances_pkey PRIMARY KEY (id);


--
-- Name: recurring_service_prices recurring_service_prices_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_prices
    ADD CONSTRAINT recurring_service_prices_pkey PRIMARY KEY (id);


--
-- Name: refunds refunds_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.refunds
    ADD CONSTRAINT refunds_pkey PRIMARY KEY (id);


--
-- Name: report_definitions report_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.report_definitions
    ADD CONSTRAINT report_definitions_pkey PRIMARY KEY (id);


--
-- Name: report_definitions report_definitions_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.report_definitions
    ADD CONSTRAINT report_definitions_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: report_exports report_exports_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.report_exports
    ADD CONSTRAINT report_exports_pkey PRIMARY KEY (id);


--
-- Name: resource_assignments resource_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.resource_assignments
    ADD CONSTRAINT resource_assignments_pkey PRIMARY KEY (id);


--
-- Name: resources resources_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.resources
    ADD CONSTRAINT resources_pkey PRIMARY KEY (id);


--
-- Name: resources resources_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.resources
    ADD CONSTRAINT resources_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: role_permissions role_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_pkey PRIMARY KEY (role_id, permission_id);


--
-- Name: role_scopes role_scopes_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.role_scopes
    ADD CONSTRAINT role_scopes_pkey PRIMARY KEY (role_id, resource);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: roles roles_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: saas_plans saas_plans_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.saas_plans
    ADD CONSTRAINT saas_plans_code_key UNIQUE (code);


--
-- Name: saas_plans saas_plans_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.saas_plans
    ADD CONSTRAINT saas_plans_pkey PRIMARY KEY (id);


--
-- Name: service_categories service_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_categories
    ADD CONSTRAINT service_categories_pkey PRIMARY KEY (id);


--
-- Name: service_categories service_categories_tenant_id_name_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_categories
    ADD CONSTRAINT service_categories_tenant_id_name_key UNIQUE (tenant_id, name);


--
-- Name: service_prices service_prices_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_prices
    ADD CONSTRAINT service_prices_pkey PRIMARY KEY (id);


--
-- Name: service_recurring_configs service_recurring_configs_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_recurring_configs
    ADD CONSTRAINT service_recurring_configs_pkey PRIMARY KEY (id);


--
-- Name: service_service_links service_service_links_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_service_links
    ADD CONSTRAINT service_service_links_pkey PRIMARY KEY (id);


--
-- Name: service_service_links service_service_links_source_service_id_target_service_id_l_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_service_links
    ADD CONSTRAINT service_service_links_source_service_id_target_service_id_l_key UNIQUE (source_service_id, target_service_id, link_type);


--
-- Name: service_template_items service_template_items_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_template_items
    ADD CONSTRAINT service_template_items_pkey PRIMARY KEY (id);


--
-- Name: service_template_items service_template_items_template_id_service_id_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_template_items
    ADD CONSTRAINT service_template_items_template_id_service_id_key UNIQUE (template_id, service_id);


--
-- Name: service_templates service_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_templates
    ADD CONSTRAINT service_templates_pkey PRIMARY KEY (id);


--
-- Name: service_templates service_templates_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_templates
    ADD CONSTRAINT service_templates_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: services services_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.services
    ADD CONSTRAINT services_pkey PRIMARY KEY (id);


--
-- Name: services services_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.services
    ADD CONSTRAINT services_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: supplier_invoices supplier_invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_invoices
    ADD CONSTRAINT supplier_invoices_pkey PRIMARY KEY (id);


--
-- Name: supplier_payment_allocations supplier_payment_allocations_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payment_allocations
    ADD CONSTRAINT supplier_payment_allocations_key UNIQUE (supplier_payment_id, payable_id);


--
-- Name: supplier_payment_allocations supplier_payment_allocations_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payment_allocations
    ADD CONSTRAINT supplier_payment_allocations_pkey PRIMARY KEY (id);


--
-- Name: supplier_payments supplier_payments_number_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payments
    ADD CONSTRAINT supplier_payments_number_key UNIQUE (tenant_id, payment_number);


--
-- Name: supplier_payments supplier_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payments
    ADD CONSTRAINT supplier_payments_pkey PRIMARY KEY (id);


--
-- Name: suppliers suppliers_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_pkey PRIMARY KEY (id);


--
-- Name: suppliers suppliers_tenant_id_party_id_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_tenant_id_party_id_key UNIQUE (tenant_id, party_id);


--
-- Name: support_calendars support_calendars_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_calendars
    ADD CONSTRAINT support_calendars_pkey PRIMARY KEY (id);


--
-- Name: support_calendars support_calendars_tenant_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_calendars
    ADD CONSTRAINT support_calendars_tenant_key UNIQUE (tenant_id);


--
-- Name: support_holidays support_holidays_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_holidays
    ADD CONSTRAINT support_holidays_pkey PRIMARY KEY (id);


--
-- Name: support_sla_runs support_sla_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_sla_runs
    ADD CONSTRAINT support_sla_runs_pkey PRIMARY KEY (id);


--
-- Name: support_slas support_slas_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_slas
    ADD CONSTRAINT support_slas_pkey PRIMARY KEY (id);


--
-- Name: support_ticket_assignments support_ticket_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_ticket_assignments
    ADD CONSTRAINT support_ticket_assignments_pkey PRIMARY KEY (id);


--
-- Name: support_ticket_comments support_ticket_comments_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_ticket_comments
    ADD CONSTRAINT support_ticket_comments_pkey PRIMARY KEY (id);


--
-- Name: support_ticket_events support_ticket_events_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_ticket_events
    ADD CONSTRAINT support_ticket_events_pkey PRIMARY KEY (id);


--
-- Name: support_tickets support_tickets_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_pkey PRIMARY KEY (id);


--
-- Name: support_tickets support_tickets_tenant_id_ticket_number_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_tenant_id_ticket_number_key UNIQUE (tenant_id, ticket_number);


--
-- Name: task_checklist_items task_checklist_items_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.task_checklist_items
    ADD CONSTRAINT task_checklist_items_pkey PRIMARY KEY (id);


--
-- Name: task_checklists task_checklists_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.task_checklists
    ADD CONSTRAINT task_checklists_pkey PRIMARY KEY (id);


--
-- Name: tasks tasks_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_pkey PRIMARY KEY (id);


--
-- Name: teams teams_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.teams
    ADD CONSTRAINT teams_pkey PRIMARY KEY (id);


--
-- Name: tenant_modules tenant_modules_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_modules
    ADD CONSTRAINT tenant_modules_pkey PRIMARY KEY (id);


--
-- Name: tenant_modules tenant_modules_tenant_id_module_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_modules
    ADD CONSTRAINT tenant_modules_tenant_id_module_code_key UNIQUE (tenant_id, module_code);


--
-- Name: tenant_settings tenant_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_settings
    ADD CONSTRAINT tenant_settings_pkey PRIMARY KEY (id);


--
-- Name: tenant_settings tenant_settings_tenant_id_setting_key_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_settings
    ADD CONSTRAINT tenant_settings_tenant_id_setting_key_key UNIQUE (tenant_id, setting_key);


--
-- Name: tenant_status_log tenant_status_log_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_status_log
    ADD CONSTRAINT tenant_status_log_pkey PRIMARY KEY (id);


--
-- Name: tenant_subscription_payments tenant_subscription_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_subscription_payments
    ADD CONSTRAINT tenant_subscription_payments_pkey PRIMARY KEY (id);


--
-- Name: tenant_subscriptions tenant_subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_subscriptions
    ADD CONSTRAINT tenant_subscriptions_pkey PRIMARY KEY (id);


--
-- Name: tenant_usage_counters tenant_usage_counters_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_usage_counters
    ADD CONSTRAINT tenant_usage_counters_pkey PRIMARY KEY (id);


--
-- Name: tenant_usage_counters tenant_usage_counters_tenant_id_metric_code_period_start_pe_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_usage_counters
    ADD CONSTRAINT tenant_usage_counters_tenant_id_metric_code_period_start_pe_key UNIQUE (tenant_id, metric_code, period_start, period_end);


--
-- Name: tenant_user_branches tenant_user_branches_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_user_branches
    ADD CONSTRAINT tenant_user_branches_pkey PRIMARY KEY (tenant_id, user_id, branch_id);


--
-- Name: tenant_users tenant_users_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_users
    ADD CONSTRAINT tenant_users_pkey PRIMARY KEY (id);


--
-- Name: tenant_users tenant_users_tenant_id_user_id_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_users
    ADD CONSTRAINT tenant_users_tenant_id_user_id_key UNIQUE (tenant_id, user_id);


--
-- Name: tenants tenants_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenants
    ADD CONSTRAINT tenants_code_key UNIQUE (code);


--
-- Name: tenants tenants_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenants
    ADD CONSTRAINT tenants_pkey PRIMARY KEY (id);


--
-- Name: time_entries time_entries_no_overlap; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.time_entries
    ADD CONSTRAINT time_entries_no_overlap EXCLUDE USING gist (tenant_id WITH =, user_id WITH =, tstzrange(started_at, ended_at) WITH &&) WHERE (((status)::text <> 'REJECTED'::text));


--
-- Name: time_entries time_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.time_entries
    ADD CONSTRAINT time_entries_pkey PRIMARY KEY (id);


--
-- Name: companies uq_companies_tenant_id_id; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.companies
    ADD CONSTRAINT uq_companies_tenant_id_id UNIQUE (tenant_id, id);


--
-- Name: contracts uq_contracts_tenant_id_id; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT uq_contracts_tenant_id_id UNIQUE (tenant_id, id);


--
-- Name: customers uq_customers_tenant_id_id; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT uq_customers_tenant_id_id UNIQUE (tenant_id, id);


--
-- Name: orders uq_orders_tenant_id_id; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT uq_orders_tenant_id_id UNIQUE (tenant_id, id);


--
-- Name: parties uq_parties_tenant_id_id; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.parties
    ADD CONSTRAINT uq_parties_tenant_id_id UNIQUE (tenant_id, id);


--
-- Name: quotations uq_quotations_tenant_id_id; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotations
    ADD CONSTRAINT uq_quotations_tenant_id_id UNIQUE (tenant_id, id);


--
-- Name: service_categories uq_service_categories_tenant_id_id; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_categories
    ADD CONSTRAINT uq_service_categories_tenant_id_id UNIQUE (tenant_id, id);


--
-- Name: user_roles user_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_pkey PRIMARY KEY (id);


--
-- Name: user_roles user_roles_tenant_id_user_id_role_id_branch_id_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_tenant_id_user_id_role_id_branch_id_key UNIQUE (tenant_id, user_id, role_id, branch_id);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: warranty_claims warranty_claims_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_pkey PRIMARY KEY (id);


--
-- Name: warranty_claims warranty_claims_tenant_id_claim_number_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_tenant_id_claim_number_key UNIQUE (tenant_id, claim_number);


--
-- Name: warranty_policies warranty_policies_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_policies
    ADD CONSTRAINT warranty_policies_pkey PRIMARY KEY (id);


--
-- Name: webhook_deliveries webhook_deliveries_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.webhook_deliveries
    ADD CONSTRAINT webhook_deliveries_pkey PRIMARY KEY (id);


--
-- Name: webhook_endpoints webhook_endpoints_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.webhook_endpoints
    ADD CONSTRAINT webhook_endpoints_pkey PRIMARY KEY (id);


--
-- Name: webhook_inbound_events webhook_inbound_events_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.webhook_inbound_events
    ADD CONSTRAINT webhook_inbound_events_pkey PRIMARY KEY (id);


--
-- Name: webhook_inbound_events webhook_inbound_events_provider_code_external_event_id_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.webhook_inbound_events
    ADD CONSTRAINT webhook_inbound_events_provider_code_external_event_id_key UNIQUE (provider_code, external_event_id);


--
-- Name: work_order_checklist_items work_order_checklist_items_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_order_checklist_items
    ADD CONSTRAINT work_order_checklist_items_pkey PRIMARY KEY (id);


--
-- Name: work_order_checklist_items work_order_checklist_items_work_order_id_position_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_order_checklist_items
    ADD CONSTRAINT work_order_checklist_items_work_order_id_position_key UNIQUE (work_order_id, "position");


--
-- Name: work_orders work_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_pkey PRIMARY KEY (id);


--
-- Name: work_orders work_orders_tenant_id_code_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_tenant_id_code_key UNIQUE (tenant_id, code);


--
-- Name: workflow_definitions workflow_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.workflow_definitions
    ADD CONSTRAINT workflow_definitions_pkey PRIMARY KEY (id);


--
-- Name: workflow_definitions workflow_definitions_tenant_id_code_version_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.workflow_definitions
    ADD CONSTRAINT workflow_definitions_tenant_id_code_version_key UNIQUE (tenant_id, code, version);


--
-- Name: workflow_runs workflow_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.workflow_runs
    ADD CONSTRAINT workflow_runs_pkey PRIMARY KEY (id);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: checklist_items_current_position_key; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX checklist_items_current_position_key ON public.checklist_items USING btree (checklist_id, "position") WHERE (removed_at IS NULL);


--
-- Name: checklists_tenant_name_key; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX checklists_tenant_name_key ON public.checklists USING btree (tenant_id, lower(btrim((name)::text)));


--
-- Name: credential_access_logs_credential_idx; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX credential_access_logs_credential_idx ON public.credential_access_logs USING btree (credential_id, created_at DESC);


--
-- Name: custom_field_values_tenant_id_entity_type_entity_id_index; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX custom_field_values_tenant_id_entity_type_entity_id_index ON public.custom_field_values USING btree (tenant_id, entity_type, entity_id);


--
-- Name: idx_acc_journal_entries_period; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_acc_journal_entries_period ON public.acc_journal_entries USING btree (tenant_id, period, entry_date);


--
-- Name: idx_acc_journal_entries_source; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_acc_journal_entries_source ON public.acc_journal_entries USING btree (tenant_id, source_id) WHERE (source_id IS NOT NULL);


--
-- Name: idx_acc_journal_lines_account; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_acc_journal_lines_account ON public.acc_journal_lines USING btree (tenant_id, account_code);


--
-- Name: idx_acc_journal_lines_cost_center; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_acc_journal_lines_cost_center ON public.acc_journal_lines USING btree (tenant_id, cost_center_id) WHERE (cost_center_id IS NOT NULL);


--
-- Name: idx_acc_journal_lines_entry; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_acc_journal_lines_entry ON public.acc_journal_lines USING btree (entry_id, "position");


--
-- Name: idx_agenda_events_assignee; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_agenda_events_assignee ON public.agenda_events USING btree (tenant_id, assigned_user_id, starts_at);


--
-- Name: idx_agenda_events_tenant_start; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_agenda_events_tenant_start ON public.agenda_events USING btree (tenant_id, starts_at);


--
-- Name: idx_audit_logs_entity; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_audit_logs_entity ON public.audit_logs USING btree (entity_type, entity_id);


--
-- Name: idx_audit_logs_tenant_created; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_audit_logs_tenant_created ON public.audit_logs USING btree (tenant_id, created_at DESC);


--
-- Name: idx_bank_movements_account; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_bank_movements_account ON public.bank_movements USING btree (account_id, movement_date, created_at);


--
-- Name: idx_bank_movements_pending; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_bank_movements_pending ON public.bank_movements USING btree (tenant_id, account_id) WHERE (NOT reconciled);


--
-- Name: idx_bank_statement_lines_statement; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_bank_statement_lines_statement ON public.bank_statement_lines USING btree (statement_id, "position");


--
-- Name: idx_bank_statements_account; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_bank_statements_account ON public.bank_statements USING btree (account_id, created_at);


--
-- Name: idx_bank_transfers_from; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_bank_transfers_from ON public.bank_transfers USING btree (tenant_id, from_account_id, transfer_date);


--
-- Name: idx_bank_transfers_to; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_bank_transfers_to ON public.bank_transfers USING btree (tenant_id, to_account_id, transfer_date);


--
-- Name: idx_billable_events_customer; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_billable_events_customer ON public.billable_events USING btree (tenant_id, customer_id);


--
-- Name: idx_billable_events_invoice; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_billable_events_invoice ON public.billable_events USING btree (invoice_id) WHERE (invoice_id IS NOT NULL);


--
-- Name: idx_billable_events_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_billable_events_tenant_status ON public.billable_events USING btree (tenant_id, status);


--
-- Name: idx_branches_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_branches_tenant ON public.branches USING btree (tenant_id);


--
-- Name: idx_cash_counts_account; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_cash_counts_account ON public.cash_counts USING btree (account_id, created_at);


--
-- Name: idx_cash_expense_reclassifications_expense; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_cash_expense_reclassifications_expense ON public.cash_expense_reclassifications USING btree (cash_expense_id, created_at);


--
-- Name: idx_cash_expenses_account; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_cash_expenses_account ON public.cash_expenses USING btree (account_id, expense_date);


--
-- Name: idx_checklists_service; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_checklists_service ON public.checklists USING btree (tenant_id, service_id);


--
-- Name: idx_companies_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_companies_tenant ON public.companies USING btree (tenant_id);


--
-- Name: idx_contacts_tenant_party; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_contacts_tenant_party ON public.contacts USING btree (tenant_id, party_id);


--
-- Name: idx_contract_signature_requests_customer; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_contract_signature_requests_customer ON public.contract_signature_requests USING btree (tenant_id, customer_id, status);


--
-- Name: idx_contracted_services_branch; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_contracted_services_branch ON public.contracted_services USING btree (tenant_id, branch_id);


--
-- Name: idx_contracted_services_contract; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_contracted_services_contract ON public.contracted_services USING btree (contract_id);


--
-- Name: idx_contracted_services_owner; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_contracted_services_owner ON public.contracted_services USING btree (tenant_id, owner_user_id);


--
-- Name: idx_contracted_services_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_contracted_services_tenant_status ON public.contracted_services USING btree (tenant_id, status);


--
-- Name: idx_contracts_tenant_branch; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_contracts_tenant_branch ON public.contracts USING btree (tenant_id, branch_id);


--
-- Name: idx_contracts_tenant_creator; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_contracts_tenant_creator ON public.contracts USING btree (tenant_id, created_by);


--
-- Name: idx_contracts_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_contracts_tenant_status ON public.contracts USING btree (tenant_id, status);


--
-- Name: idx_cost_entries_project; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_cost_entries_project ON public.cost_entries USING btree (project_id) WHERE (project_id IS NOT NULL);


--
-- Name: idx_cost_entries_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_cost_entries_status ON public.cost_entries USING btree (tenant_id, status);


--
-- Name: idx_cost_entries_tenant_date; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_cost_entries_tenant_date ON public.cost_entries USING btree (tenant_id, incurred_at);


--
-- Name: idx_credential_access_logs_tenant_created; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_credential_access_logs_tenant_created ON public.credential_access_logs USING btree (tenant_id, created_at DESC);


--
-- Name: idx_credential_records_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_credential_records_tenant_status ON public.credential_records USING btree (tenant_id, status);


--
-- Name: idx_crm_activities_lead; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_crm_activities_lead ON public.crm_activities USING btree (lead_id) WHERE (lead_id IS NOT NULL);


--
-- Name: idx_crm_activities_opportunity; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_crm_activities_opportunity ON public.crm_activities USING btree (opportunity_id) WHERE (opportunity_id IS NOT NULL);


--
-- Name: idx_crm_activities_owner; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_crm_activities_owner ON public.crm_activities USING btree (tenant_id, owner_user_id);


--
-- Name: idx_crm_activities_tenant_due; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_crm_activities_tenant_due ON public.crm_activities USING btree (tenant_id, due_at);


--
-- Name: idx_crm_campaign_members_lead; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_crm_campaign_members_lead ON public.crm_campaign_members USING btree (lead_id) WHERE (lead_id IS NOT NULL);


--
-- Name: idx_crm_leads_tenant_branch; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_crm_leads_tenant_branch ON public.crm_leads USING btree (tenant_id, branch_id);


--
-- Name: idx_crm_leads_tenant_owner; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_crm_leads_tenant_owner ON public.crm_leads USING btree (tenant_id, owner_user_id);


--
-- Name: idx_crm_leads_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_crm_leads_tenant_status ON public.crm_leads USING btree (tenant_id, status);


--
-- Name: idx_crm_opportunities_tenant_branch; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_crm_opportunities_tenant_branch ON public.crm_opportunities USING btree (tenant_id, branch_id);


--
-- Name: idx_crm_opportunities_tenant_owner; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_crm_opportunities_tenant_owner ON public.crm_opportunities USING btree (tenant_id, owner_user_id);


--
-- Name: idx_crm_opportunities_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_crm_opportunities_tenant_status ON public.crm_opportunities USING btree (tenant_id, status);


--
-- Name: idx_customers_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_customers_tenant ON public.customers USING btree (tenant_id);


--
-- Name: idx_deliverables_document; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_deliverables_document ON public.deliverables USING btree (document_id) WHERE (document_id IS NOT NULL);


--
-- Name: idx_deliverables_project; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_deliverables_project ON public.deliverables USING btree (project_id) WHERE (project_id IS NOT NULL);


--
-- Name: idx_document_links_entity; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_document_links_entity ON public.document_links USING btree (tenant_id, entity_type, entity_id);


--
-- Name: idx_documents_tenant_created; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_documents_tenant_created ON public.documents USING btree (tenant_id, created_at DESC);


--
-- Name: idx_documents_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_documents_tenant_status ON public.documents USING btree (tenant_id, status);


--
-- Name: idx_employees_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_employees_tenant ON public.employees USING btree (tenant_id);


--
-- Name: idx_file_objects_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_file_objects_tenant ON public.file_objects USING btree (tenant_id);


--
-- Name: idx_fiscal_documents_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_fiscal_documents_tenant_status ON public.fiscal_documents USING btree (tenant_id, status);


--
-- Name: idx_fiscal_void_requests_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_fiscal_void_requests_tenant ON public.fiscal_void_requests USING btree (tenant_id, created_at);


--
-- Name: idx_idempotency_tenant_key; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_idempotency_tenant_key ON public.idempotency_keys USING btree (tenant_id, idempotency_key);


--
-- Name: idx_invoices_branch; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_invoices_branch ON public.invoices USING btree (tenant_id, branch_id);


--
-- Name: idx_invoices_customer; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_invoices_customer ON public.invoices USING btree (tenant_id, customer_id);


--
-- Name: idx_invoices_owner; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_invoices_owner ON public.invoices USING btree (tenant_id, owner_user_id);


--
-- Name: idx_invoices_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_invoices_tenant_status ON public.invoices USING btree (tenant_id, status);


--
-- Name: idx_invoices_withholding_pending; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_invoices_withholding_pending ON public.invoices USING btree (tenant_id) WHERE ((withholding_status)::text = 'PENDING'::text);


--
-- Name: idx_notifications_inbox; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_notifications_inbox ON public.notifications USING btree (tenant_id, user_id, created_at DESC) WHERE ((channel)::text = 'IN_APP'::text);


--
-- Name: idx_notifications_user_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_notifications_user_status ON public.notifications USING btree (user_id, status);


--
-- Name: idx_orders_tenant_branch; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_orders_tenant_branch ON public.orders USING btree (tenant_id, branch_id);


--
-- Name: idx_orders_tenant_creator; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_orders_tenant_creator ON public.orders USING btree (tenant_id, created_by);


--
-- Name: idx_orders_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_orders_tenant_status ON public.orders USING btree (tenant_id, status);


--
-- Name: idx_outbox_status_occurred; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_outbox_status_occurred ON public.outbox_events USING btree (status, occurred_at);


--
-- Name: idx_parties_tenant_type; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_parties_tenant_type ON public.parties USING btree (tenant_id, party_type);


--
-- Name: idx_payable_movements_payable; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_payable_movements_payable ON public.payable_movements USING btree (payable_id, created_at);


--
-- Name: idx_payables_due; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_payables_due ON public.payables USING btree (tenant_id, due_date);


--
-- Name: idx_payables_owner; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_payables_owner ON public.payables USING btree (tenant_id, owner_user_id);


--
-- Name: idx_payables_supplier; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_payables_supplier ON public.payables USING btree (tenant_id, supplier_id);


--
-- Name: idx_payables_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_payables_tenant_status ON public.payables USING btree (tenant_id, status);


--
-- Name: idx_payments_creator; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_payments_creator ON public.payments USING btree (tenant_id, created_by);


--
-- Name: idx_payments_customer; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_payments_customer ON public.payments USING btree (tenant_id, customer_id);


--
-- Name: idx_payments_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_payments_tenant_status ON public.payments USING btree (tenant_id, status);


--
-- Name: idx_portal_notices_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_portal_notices_tenant ON public.portal_notices USING btree (tenant_id, created_at DESC);


--
-- Name: idx_portal_requests_tenant_branch; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_portal_requests_tenant_branch ON public.portal_requests USING btree (tenant_id, branch_id);


--
-- Name: idx_portal_requests_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_portal_requests_tenant_status ON public.portal_requests USING btree (tenant_id, status);


--
-- Name: idx_portal_users_customer; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_portal_users_customer ON public.portal_users USING btree (tenant_id, customer_id, status);


--
-- Name: idx_projects_branch; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_projects_branch ON public.projects USING btree (tenant_id, branch_id);


--
-- Name: idx_projects_manager; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_projects_manager ON public.projects USING btree (tenant_id, manager_user_id);


--
-- Name: idx_projects_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_projects_tenant_status ON public.projects USING btree (tenant_id, status);


--
-- Name: idx_purchase_order_items_order; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_purchase_order_items_order ON public.purchase_order_items USING btree (purchase_order_id, "position");


--
-- Name: idx_purchase_orders_branch; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_purchase_orders_branch ON public.purchase_orders USING btree (tenant_id, branch_id);


--
-- Name: idx_purchase_orders_cost_center; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_purchase_orders_cost_center ON public.purchase_orders USING btree (tenant_id, cost_center_id);


--
-- Name: idx_purchase_orders_owner; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_purchase_orders_owner ON public.purchase_orders USING btree (tenant_id, owner_user_id);


--
-- Name: idx_purchase_orders_supplier; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_purchase_orders_supplier ON public.purchase_orders USING btree (tenant_id, supplier_id);


--
-- Name: idx_purchase_orders_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_purchase_orders_tenant_status ON public.purchase_orders USING btree (tenant_id, status);


--
-- Name: idx_purchase_receipts_order; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_purchase_receipts_order ON public.purchase_receipts USING btree (purchase_order_id, received_at);


--
-- Name: idx_quotations_tenant_branch; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_quotations_tenant_branch ON public.quotations USING btree (tenant_id, branch_id);


--
-- Name: idx_quotations_tenant_creator; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_quotations_tenant_creator ON public.quotations USING btree (tenant_id, created_by);


--
-- Name: idx_quotations_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_quotations_tenant_status ON public.quotations USING btree (tenant_id, status);


--
-- Name: idx_receivable_movements_receivable; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_receivable_movements_receivable ON public.receivable_movements USING btree (receivable_id, created_at);


--
-- Name: idx_receivables_customer; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_receivables_customer ON public.receivables USING btree (tenant_id, customer_id);


--
-- Name: idx_receivables_due; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_receivables_due ON public.receivables USING btree (tenant_id, due_date);


--
-- Name: idx_receivables_owner; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_receivables_owner ON public.receivables USING btree (tenant_id, owner_user_id);


--
-- Name: idx_receivables_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_receivables_tenant_status ON public.receivables USING btree (tenant_id, status);


--
-- Name: idx_recurring_cycles_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_recurring_cycles_tenant_status ON public.recurring_service_cycles USING btree (tenant_id, status);


--
-- Name: idx_recurring_instances_branch; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_recurring_instances_branch ON public.recurring_service_instances USING btree (tenant_id, branch_id);


--
-- Name: idx_recurring_instances_next_cycle; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_recurring_instances_next_cycle ON public.recurring_service_instances USING btree (tenant_id, next_cycle_date);


--
-- Name: idx_recurring_instances_owner; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_recurring_instances_owner ON public.recurring_service_instances USING btree (tenant_id, owner_user_id);


--
-- Name: idx_recurring_instances_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_recurring_instances_tenant_status ON public.recurring_service_instances USING btree (tenant_id, status);


--
-- Name: idx_report_exports_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_report_exports_tenant_status ON public.report_exports USING btree (tenant_id, status);


--
-- Name: idx_service_prices_service; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_service_prices_service ON public.service_prices USING btree (tenant_id, service_id);


--
-- Name: idx_services_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_services_tenant_status ON public.services USING btree (tenant_id, status);


--
-- Name: idx_supplier_invoices_order; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_supplier_invoices_order ON public.supplier_invoices USING btree (purchase_order_id) WHERE (purchase_order_id IS NOT NULL);


--
-- Name: idx_supplier_invoices_owner; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_supplier_invoices_owner ON public.supplier_invoices USING btree (tenant_id, owner_user_id);


--
-- Name: idx_supplier_invoices_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_supplier_invoices_tenant_status ON public.supplier_invoices USING btree (tenant_id, status);


--
-- Name: idx_supplier_payment_allocations_payable; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_supplier_payment_allocations_payable ON public.supplier_payment_allocations USING btree (payable_id);


--
-- Name: idx_supplier_payments_paid_at; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_supplier_payments_paid_at ON public.supplier_payments USING btree (tenant_id, paid_at);


--
-- Name: idx_supplier_payments_supplier; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_supplier_payments_supplier ON public.supplier_payments USING btree (tenant_id, supplier_id);


--
-- Name: idx_suppliers_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_suppliers_tenant ON public.suppliers USING btree (tenant_id);


--
-- Name: idx_support_ticket_comments_ticket; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_support_ticket_comments_ticket ON public.support_ticket_comments USING btree (ticket_id, created_at);


--
-- Name: idx_support_tickets_customer; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_support_tickets_customer ON public.support_tickets USING btree (tenant_id, customer_id);


--
-- Name: idx_support_tickets_due; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_support_tickets_due ON public.support_tickets USING btree (tenant_id, resolution_due_at);


--
-- Name: idx_support_tickets_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_support_tickets_tenant_status ON public.support_tickets USING btree (tenant_id, status);


--
-- Name: idx_tasks_assignee; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tasks_assignee ON public.tasks USING btree (tenant_id, assigned_user_id);


--
-- Name: idx_tasks_project; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tasks_project ON public.tasks USING btree (project_id) WHERE (project_id IS NOT NULL);


--
-- Name: idx_tasks_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tasks_tenant_status ON public.tasks USING btree (tenant_id, status);


--
-- Name: idx_tenant_modules_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tenant_modules_tenant ON public.tenant_modules USING btree (tenant_id);


--
-- Name: idx_tenant_status_log_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tenant_status_log_tenant ON public.tenant_status_log USING btree (tenant_id, created_at);


--
-- Name: idx_tenant_usage_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tenant_usage_tenant ON public.tenant_usage_counters USING btree (tenant_id);


--
-- Name: idx_tenant_user_branches_branch; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tenant_user_branches_branch ON public.tenant_user_branches USING btree (tenant_id, branch_id);


--
-- Name: idx_tenant_users_team; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tenant_users_team ON public.tenant_users USING btree (tenant_id, team_id);


--
-- Name: idx_tenant_users_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tenant_users_tenant ON public.tenant_users USING btree (tenant_id);


--
-- Name: idx_tenant_users_user; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tenant_users_user ON public.tenant_users USING btree (user_id);


--
-- Name: idx_time_entries_project; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_time_entries_project ON public.time_entries USING btree (project_id) WHERE (project_id IS NOT NULL);


--
-- Name: idx_time_entries_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_time_entries_status ON public.time_entries USING btree (tenant_id, status);


--
-- Name: idx_time_entries_tenant_date; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_time_entries_tenant_date ON public.time_entries USING btree (tenant_id, started_at);


--
-- Name: idx_time_entries_user; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_time_entries_user ON public.time_entries USING btree (tenant_id, user_id);


--
-- Name: idx_user_roles_tenant_user; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_user_roles_tenant_user ON public.user_roles USING btree (tenant_id, user_id);


--
-- Name: idx_warranty_claims_contracted; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_warranty_claims_contracted ON public.warranty_claims USING btree (tenant_id, contracted_service_id);


--
-- Name: idx_webhook_deliveries_pending; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_webhook_deliveries_pending ON public.webhook_deliveries USING btree (status, next_attempt_at);


--
-- Name: idx_work_order_checklist_items_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_work_order_checklist_items_tenant ON public.work_order_checklist_items USING btree (tenant_id);


--
-- Name: idx_work_orders_assignee; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_work_orders_assignee ON public.work_orders USING btree (tenant_id, assigned_user_id);


--
-- Name: idx_work_orders_branch; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_work_orders_branch ON public.work_orders USING btree (tenant_id, branch_id);


--
-- Name: idx_work_orders_contracted_service; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_work_orders_contracted_service ON public.work_orders USING btree (contracted_service_id) WHERE (contracted_service_id IS NOT NULL);


--
-- Name: idx_work_orders_creator; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_work_orders_creator ON public.work_orders USING btree (tenant_id, created_by);


--
-- Name: idx_work_orders_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_work_orders_tenant_status ON public.work_orders USING btree (tenant_id, status);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: platform_audit_logs_action_index; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX platform_audit_logs_action_index ON public.platform_audit_logs USING btree (action);


--
-- Name: platform_audit_logs_created_at_index; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX platform_audit_logs_created_at_index ON public.platform_audit_logs USING btree (created_at);


--
-- Name: platform_audit_logs_user_id_index; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX platform_audit_logs_user_id_index ON public.platform_audit_logs USING btree (user_id);


--
-- Name: quotations_previous_id_index; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX quotations_previous_id_index ON public.quotations USING btree (previous_id);


--
-- Name: support_holidays_current_key; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX support_holidays_current_key ON public.support_holidays USING btree (calendar_id, holiday_date) WHERE (removed_at IS NULL);


--
-- Name: support_slas_tenant_priority_key; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX support_slas_tenant_priority_key ON public.support_slas USING btree (tenant_id, priority);


--
-- Name: support_tickets_work_order_key; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX support_tickets_work_order_key ON public.support_tickets USING btree (work_order_id) WHERE (work_order_id IS NOT NULL);


--
-- Name: tenant_subscription_payments_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX tenant_subscription_payments_tenant ON public.tenant_subscription_payments USING btree (tenant_id, created_at DESC);


--
-- Name: tenants_login_slug_unique; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX tenants_login_slug_unique ON public.tenants USING btree (login_slug);


--
-- Name: uq_crm_campaign_members_lead; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX uq_crm_campaign_members_lead ON public.crm_campaign_members USING btree (campaign_id, lead_id) WHERE (lead_id IS NOT NULL);


--
-- Name: uq_recurring_instances_contracted_service; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX uq_recurring_instances_contracted_service ON public.recurring_service_instances USING btree (contracted_service_id);


--
-- Name: ux_acc_journal_entries_reversal; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_acc_journal_entries_reversal ON public.acc_journal_entries USING btree (reversal_of) WHERE (reversal_of IS NOT NULL);


--
-- Name: ux_acc_journal_entries_source; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_acc_journal_entries_source ON public.acc_journal_entries USING btree (tenant_id, source_type, source_id) WHERE (((status)::text = 'POSTED'::text) AND (source_id IS NOT NULL) AND ((source_type)::text <> ALL ((ARRAY['MANUAL'::character varying, 'REVERSAL'::character varying, 'IMPORT'::character varying])::text[])));


--
-- Name: ux_bank_movements_payment; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_bank_movements_payment ON public.bank_movements USING btree (ref_id) WHERE ((ref_type)::text = 'PAYMENT'::text);


--
-- Name: ux_bank_movements_statement_line; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_bank_movements_statement_line ON public.bank_movements USING btree (statement_line_id) WHERE (statement_line_id IS NOT NULL);


--
-- Name: ux_bank_movements_transfer; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_bank_movements_transfer ON public.bank_movements USING btree (ref_id, account_id) WHERE ((ref_type)::text = 'TRANSFER'::text);


--
-- Name: ux_bank_statement_lines_fingerprint; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_bank_statement_lines_fingerprint ON public.bank_statement_lines USING btree (account_id, fingerprint);


--
-- Name: ux_bank_statement_lines_movement; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_bank_statement_lines_movement ON public.bank_statement_lines USING btree (movement_id) WHERE (movement_id IS NOT NULL);


--
-- Name: ux_billable_events_source; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_billable_events_source ON public.billable_events USING btree (tenant_id, source_type, source_id) WHERE (source_id IS NOT NULL);


--
-- Name: ux_budget_lines_center_account; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_budget_lines_center_account ON public.budget_lines USING btree (budget_id, cost_center_id, account_code, period_month) WHERE (cost_center_id IS NOT NULL);


--
-- Name: ux_budgets_year; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_budgets_year ON public.budgets USING btree (tenant_id, fiscal_year);


--
-- Name: ux_contract_signature_pending; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_contract_signature_pending ON public.contract_signature_requests USING btree (contract_id) WHERE ((status)::text = 'PENDING'::text);


--
-- Name: ux_contracts_one_per_quotation; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_contracts_one_per_quotation ON public.contracts USING btree (tenant_id, quotation_id) WHERE (quotation_id IS NOT NULL);


--
-- Name: ux_crm_conversions_opportunity; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_crm_conversions_opportunity ON public.crm_conversions USING btree (opportunity_id) WHERE (opportunity_id IS NOT NULL);


--
-- Name: ux_crm_leads_tenant_number; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_crm_leads_tenant_number ON public.crm_leads USING btree (tenant_id, lead_number);


--
-- Name: ux_crm_opportunities_lead; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_crm_opportunities_lead ON public.crm_opportunities USING btree (lead_id) WHERE (lead_id IS NOT NULL);


--
-- Name: ux_crm_opportunities_tenant_number; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_crm_opportunities_tenant_number ON public.crm_opportunities USING btree (tenant_id, opportunity_number);


--
-- Name: ux_fiscal_void_requests_live; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_fiscal_void_requests_live ON public.fiscal_void_requests USING btree (invoice_id) WHERE ((status)::text = ANY ((ARRAY['PENDING'::character varying, 'ACCEPTED'::character varying])::text[]));


--
-- Name: ux_invoices_one_credit_note; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_invoices_one_credit_note ON public.invoices USING btree (related_invoice_id) WHERE ((related_invoice_id IS NOT NULL) AND ((invoice_type)::text = 'CREDIT_NOTE'::text));


--
-- Name: ux_invoices_series_number; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_invoices_series_number ON public.invoices USING btree (tenant_id, invoice_type, series, number) WHERE (number IS NOT NULL);


--
-- Name: ux_orders_one_per_contract; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_orders_one_per_contract ON public.orders USING btree (tenant_id, contract_id) WHERE (contract_id IS NOT NULL);


--
-- Name: ux_orders_one_per_quotation; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_orders_one_per_quotation ON public.orders USING btree (tenant_id, quotation_id) WHERE (quotation_id IS NOT NULL);


--
-- Name: ux_portal_users_user; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_portal_users_user ON public.portal_users USING btree (user_id);


--
-- Name: ux_recurring_cycles_key; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_recurring_cycles_key ON public.recurring_service_cycles USING btree (tenant_id, idempotency_key);


--
-- Name: ux_supplier_invoices_document; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_supplier_invoices_document ON public.supplier_invoices USING btree (tenant_id, supplier_id, doc_type, doc_number) WHERE ((status)::text <> 'VOIDED'::text);


--
-- Name: ux_teams_tenant_name; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX ux_teams_tenant_name ON public.teams USING btree (tenant_id, lower((name)::text));


--
-- Name: warranty_claims_ticket_key; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE UNIQUE INDEX warranty_claims_ticket_key ON public.warranty_claims USING btree (ticket_id) WHERE (ticket_id IS NOT NULL);


--
-- Name: notifications notifications_read_once; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER notifications_read_once BEFORE UPDATE ON public.notifications FOR EACH ROW EXECUTE FUNCTION public.notifications_read_once();


--
-- Name: acc_accounts trg_acc_accounts_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_acc_accounts_protect BEFORE UPDATE ON public.acc_accounts FOR EACH ROW EXECUTE FUNCTION public.protect_acc_account();


--
-- Name: acc_accounts trg_acc_accounts_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_acc_accounts_updated_at BEFORE UPDATE ON public.acc_accounts FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: acc_fiscal_years trg_acc_fiscal_years_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_acc_fiscal_years_updated_at BEFORE UPDATE ON public.acc_fiscal_years FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: acc_journal_entries trg_acc_journal_entries_balanced; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE CONSTRAINT TRIGGER trg_acc_journal_entries_balanced AFTER INSERT ON public.acc_journal_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.check_acc_entry_balanced();


--
-- Name: acc_journal_entries trg_acc_journal_entries_period; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_acc_journal_entries_period BEFORE INSERT ON public.acc_journal_entries FOR EACH ROW EXECUTE FUNCTION public.check_acc_entry_period();


--
-- Name: acc_journal_entries trg_acc_journal_entries_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_acc_journal_entries_protect BEFORE UPDATE ON public.acc_journal_entries FOR EACH ROW EXECUTE FUNCTION public.protect_acc_journal_entry();


--
-- Name: acc_journal_lines trg_acc_journal_lines_balanced; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE CONSTRAINT TRIGGER trg_acc_journal_lines_balanced AFTER INSERT ON public.acc_journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.check_acc_entry_balanced();


--
-- Name: acc_journal_lines trg_acc_journal_lines_check; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_acc_journal_lines_check BEFORE INSERT ON public.acc_journal_lines FOR EACH ROW EXECUTE FUNCTION public.check_acc_journal_line();


--
-- Name: acc_periods trg_acc_periods_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_acc_periods_updated_at BEFORE UPDATE ON public.acc_periods FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: bank_movements trg_bank_movements_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_bank_movements_protect BEFORE UPDATE ON public.bank_movements FOR EACH ROW EXECUTE FUNCTION public.protect_bank_movement();


--
-- Name: bank_movements trg_bank_movements_transfer; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_bank_movements_transfer BEFORE INSERT ON public.bank_movements FOR EACH ROW EXECUTE FUNCTION public.check_transfer_movement();


--
-- Name: bank_statement_lines trg_bank_statement_lines_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_bank_statement_lines_protect BEFORE UPDATE ON public.bank_statement_lines FOR EACH ROW EXECUTE FUNCTION public.protect_bank_statement_line();


--
-- Name: bank_transfers trg_bank_transfers_check; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_bank_transfers_check BEFORE INSERT ON public.bank_transfers FOR EACH ROW EXECUTE FUNCTION public.check_bank_transfer();


--
-- Name: billable_events trg_billable_events_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_billable_events_updated_at BEFORE UPDATE ON public.billable_events FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: branches trg_branches_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_branches_updated_at BEFORE UPDATE ON public.branches FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: budget_lines trg_budget_lines_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_budget_lines_protect BEFORE INSERT OR UPDATE ON public.budget_lines FOR EACH ROW EXECUTE FUNCTION public.protect_budget_lines();


--
-- Name: budgets trg_budgets_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_budgets_updated_at BEFORE UPDATE ON public.budgets FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: cash_expense_reclassifications trg_cash_expense_reclassifications_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_cash_expense_reclassifications_protect BEFORE UPDATE ON public.cash_expense_reclassifications FOR EACH ROW EXECUTE FUNCTION public.protect_cash_expense_reclassification();


--
-- Name: cash_expenses trg_cash_expenses_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_cash_expenses_protect BEFORE UPDATE ON public.cash_expenses FOR EACH ROW EXECUTE FUNCTION public.protect_cash_expense_classification();


--
-- Name: checklist_items trg_checklist_items_update; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_checklist_items_update BEFORE UPDATE ON public.checklist_items FOR EACH ROW EXECUTE FUNCTION public.check_checklist_item_update();


--
-- Name: checklists trg_checklists_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_checklists_updated_at BEFORE UPDATE ON public.checklists FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: companies trg_companies_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_companies_updated_at BEFORE UPDATE ON public.companies FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: contract_signature_requests trg_contract_signature_requests_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_contract_signature_requests_protect BEFORE UPDATE ON public.contract_signature_requests FOR EACH ROW EXECUTE FUNCTION public.protect_contract_signature();


--
-- Name: contract_signature_requests trg_contract_signature_requests_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_contract_signature_requests_updated_at BEFORE UPDATE ON public.contract_signature_requests FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: contract_signature_signers trg_contract_signature_signers_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_contract_signature_signers_protect BEFORE UPDATE ON public.contract_signature_signers FOR EACH ROW EXECUTE FUNCTION public.protect_contract_signature();


--
-- Name: contracted_services trg_contracted_services_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_contracted_services_updated_at BEFORE UPDATE ON public.contracted_services FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: contracts trg_contracts_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_contracts_updated_at BEFORE UPDATE ON public.contracts FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: cost_centers trg_cost_centers_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_cost_centers_protect BEFORE UPDATE ON public.cost_centers FOR EACH ROW EXECUTE FUNCTION public.protect_cost_center();


--
-- Name: cost_centers trg_cost_centers_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_cost_centers_updated_at BEFORE UPDATE ON public.cost_centers FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: credential_records trg_credential_records_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_credential_records_updated_at BEFORE UPDATE ON public.credential_records FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: crm_campaigns trg_crm_campaigns_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_crm_campaigns_updated_at BEFORE UPDATE ON public.crm_campaigns FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: crm_leads trg_crm_leads_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_crm_leads_updated_at BEFORE UPDATE ON public.crm_leads FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: crm_opportunities trg_crm_opportunities_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_crm_opportunities_updated_at BEFORE UPDATE ON public.crm_opportunities FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: crm_settings trg_crm_settings_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_crm_settings_updated_at BEFORE UPDATE ON public.crm_settings FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: customers trg_customers_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_customers_updated_at BEFORE UPDATE ON public.customers FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: dashboard_definitions trg_dashboard_definitions_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_dashboard_definitions_updated_at BEFORE UPDATE ON public.dashboard_definitions FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: documents trg_documents_check_update; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_documents_check_update BEFORE UPDATE ON public.documents FOR EACH ROW EXECUTE FUNCTION public.check_document_update();


--
-- Name: documents trg_documents_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_documents_updated_at BEFORE UPDATE ON public.documents FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: employees trg_employees_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_employees_updated_at BEFORE UPDATE ON public.employees FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: financial_accounts trg_financial_accounts_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_financial_accounts_protect BEFORE UPDATE ON public.financial_accounts FOR EACH ROW EXECUTE FUNCTION public.protect_financial_account();


--
-- Name: financial_accounts trg_financial_accounts_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_financial_accounts_updated_at BEFORE UPDATE ON public.financial_accounts FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: fiscal_configurations trg_fiscal_configurations_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_fiscal_configurations_updated_at BEFORE UPDATE ON public.fiscal_configurations FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: integration_connections trg_integration_connections_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_integration_connections_updated_at BEFORE UPDATE ON public.integration_connections FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: invoice_items trg_invoice_items_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_invoice_items_protect BEFORE INSERT OR DELETE OR UPDATE ON public.invoice_items FOR EACH ROW EXECUTE FUNCTION public.protect_issued_invoice_items();


--
-- Name: invoices trg_invoices_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_invoices_protect BEFORE UPDATE ON public.invoices FOR EACH ROW EXECUTE FUNCTION public.protect_issued_invoice();


--
-- Name: invoices trg_invoices_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_invoices_updated_at BEFORE UPDATE ON public.invoices FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: module_catalog trg_module_catalog_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_module_catalog_updated_at BEFORE UPDATE ON public.module_catalog FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: orders trg_orders_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_orders_updated_at BEFORE UPDATE ON public.orders FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: parties trg_parties_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_parties_updated_at BEFORE UPDATE ON public.parties FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: payable_movements trg_payable_movements_apply; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_payable_movements_apply AFTER INSERT ON public.payable_movements FOR EACH ROW EXECUTE FUNCTION public.apply_payable_movement();


--
-- Name: payables trg_payables_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_payables_protect BEFORE UPDATE ON public.payables FOR EACH ROW EXECUTE FUNCTION public.protect_payable_balance();


--
-- Name: payables trg_payables_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_payables_updated_at BEFORE UPDATE ON public.payables FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: portal_requests trg_portal_requests_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_portal_requests_updated_at BEFORE UPDATE ON public.portal_requests FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: portal_users trg_portal_users_check; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_portal_users_check BEFORE INSERT OR UPDATE ON public.portal_users FOR EACH ROW EXECUTE FUNCTION public.check_portal_user();


--
-- Name: portal_users trg_portal_users_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_portal_users_updated_at BEFORE UPDATE ON public.portal_users FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: projects trg_projects_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_projects_updated_at BEFORE UPDATE ON public.projects FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: purchase_order_items trg_purchase_order_items_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_purchase_order_items_protect BEFORE INSERT OR DELETE OR UPDATE ON public.purchase_order_items FOR EACH ROW EXECUTE FUNCTION public.protect_purchase_order_items();


--
-- Name: purchase_orders trg_purchase_orders_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_purchase_orders_protect BEFORE UPDATE ON public.purchase_orders FOR EACH ROW EXECUTE FUNCTION public.protect_purchase_order();


--
-- Name: purchase_orders trg_purchase_orders_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_purchase_orders_updated_at BEFORE UPDATE ON public.purchase_orders FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: purchase_receipts trg_purchase_receipts_check; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_purchase_receipts_check BEFORE INSERT ON public.purchase_receipts FOR EACH ROW EXECUTE FUNCTION public.check_purchase_receipt();


--
-- Name: quotations trg_quotations_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_quotations_updated_at BEFORE UPDATE ON public.quotations FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: receivable_movements trg_receivable_movements_apply; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_receivable_movements_apply AFTER INSERT ON public.receivable_movements FOR EACH ROW EXECUTE FUNCTION public.apply_receivable_movement();


--
-- Name: receivables trg_receivables_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_receivables_protect BEFORE UPDATE ON public.receivables FOR EACH ROW EXECUTE FUNCTION public.protect_receivable_balance();


--
-- Name: receivables trg_receivables_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_receivables_updated_at BEFORE UPDATE ON public.receivables FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: recurring_service_instances trg_recurring_service_instances_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_recurring_service_instances_updated_at BEFORE UPDATE ON public.recurring_service_instances FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: report_definitions trg_report_definitions_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_report_definitions_updated_at BEFORE UPDATE ON public.report_definitions FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: roles trg_roles_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_roles_updated_at BEFORE UPDATE ON public.roles FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: service_categories trg_service_categories_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_service_categories_updated_at BEFORE UPDATE ON public.service_categories FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: services trg_services_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_services_updated_at BEFORE UPDATE ON public.services FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: supplier_invoices trg_supplier_invoices_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_supplier_invoices_protect BEFORE UPDATE ON public.supplier_invoices FOR EACH ROW EXECUTE FUNCTION public.protect_supplier_invoice();


--
-- Name: supplier_invoices trg_supplier_invoices_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_supplier_invoices_updated_at BEFORE UPDATE ON public.supplier_invoices FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: supplier_payments trg_supplier_payments_protect; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_supplier_payments_protect BEFORE UPDATE ON public.supplier_payments FOR EACH ROW EXECUTE FUNCTION public.protect_supplier_payment();


--
-- Name: suppliers trg_suppliers_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_suppliers_updated_at BEFORE UPDATE ON public.suppliers FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: support_holidays trg_support_holidays_update; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_support_holidays_update BEFORE UPDATE ON public.support_holidays FOR EACH ROW EXECUTE FUNCTION public.check_support_holiday_update();


--
-- Name: support_tickets trg_support_tickets_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_support_tickets_updated_at BEFORE UPDATE ON public.support_tickets FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: tasks trg_tasks_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_tasks_updated_at BEFORE UPDATE ON public.tasks FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: tenant_modules trg_tenant_modules_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_tenant_modules_updated_at BEFORE UPDATE ON public.tenant_modules FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: tenant_settings trg_tenant_settings_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_tenant_settings_updated_at BEFORE UPDATE ON public.tenant_settings FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: tenant_subscriptions trg_tenant_subscriptions_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_tenant_subscriptions_updated_at BEFORE UPDATE ON public.tenant_subscriptions FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: tenant_users trg_tenant_users_no_super_admin; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_tenant_users_no_super_admin BEFORE INSERT OR UPDATE OF user_id ON public.tenant_users FOR EACH ROW EXECUTE FUNCTION public.prevent_super_admin_membership();


--
-- Name: tenant_users trg_tenant_users_not_portal; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_tenant_users_not_portal BEFORE INSERT OR UPDATE OF user_id ON public.tenant_users FOR EACH ROW EXECUTE FUNCTION public.check_tenant_user_not_portal();


--
-- Name: tenant_users trg_tenant_users_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_tenant_users_updated_at BEFORE UPDATE ON public.tenant_users FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: tenants trg_tenants_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_tenants_updated_at BEFORE UPDATE ON public.tenants FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: users trg_users_super_admin_without_tenant; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_users_super_admin_without_tenant BEFORE UPDATE OF is_super_admin ON public.users FOR EACH ROW WHEN ((new.is_super_admin AND (NOT old.is_super_admin))) EXECUTE FUNCTION public.prevent_member_becoming_super_admin();


--
-- Name: users trg_users_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_users_updated_at BEFORE UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: warranty_claims trg_warranty_claims_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_warranty_claims_updated_at BEFORE UPDATE ON public.warranty_claims FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: work_orders trg_work_orders_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_work_orders_updated_at BEFORE UPDATE ON public.work_orders FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: workflow_definitions trg_workflow_definitions_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_workflow_definitions_updated_at BEFORE UPDATE ON public.workflow_definitions FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: acc_accounts acc_accounts_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_accounts
    ADD CONSTRAINT acc_accounts_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: acc_accounts acc_accounts_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_accounts
    ADD CONSTRAINT acc_accounts_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: acc_fiscal_years acc_fiscal_years_closed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_fiscal_years
    ADD CONSTRAINT acc_fiscal_years_closed_by_fkey FOREIGN KEY (closed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: acc_fiscal_years acc_fiscal_years_closing_entry_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_fiscal_years
    ADD CONSTRAINT acc_fiscal_years_closing_entry_id_fkey FOREIGN KEY (closing_entry_id) REFERENCES public.acc_journal_entries(id) ON DELETE RESTRICT;


--
-- Name: acc_fiscal_years acc_fiscal_years_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_fiscal_years
    ADD CONSTRAINT acc_fiscal_years_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: acc_journal_entries acc_journal_entries_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_journal_entries
    ADD CONSTRAINT acc_journal_entries_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: acc_journal_entries acc_journal_entries_reversal_of_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_journal_entries
    ADD CONSTRAINT acc_journal_entries_reversal_of_fkey FOREIGN KEY (reversal_of) REFERENCES public.acc_journal_entries(id) ON DELETE RESTRICT;


--
-- Name: acc_journal_entries acc_journal_entries_reversed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_journal_entries
    ADD CONSTRAINT acc_journal_entries_reversed_by_fkey FOREIGN KEY (reversed_by) REFERENCES public.acc_journal_entries(id) ON DELETE RESTRICT;


--
-- Name: acc_journal_entries acc_journal_entries_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_journal_entries
    ADD CONSTRAINT acc_journal_entries_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: acc_journal_lines acc_journal_lines_account_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_journal_lines
    ADD CONSTRAINT acc_journal_lines_account_fkey FOREIGN KEY (tenant_id, account_code) REFERENCES public.acc_accounts(tenant_id, code) ON DELETE RESTRICT;


--
-- Name: acc_journal_lines acc_journal_lines_cost_center_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_journal_lines
    ADD CONSTRAINT acc_journal_lines_cost_center_id_fkey FOREIGN KEY (cost_center_id) REFERENCES public.cost_centers(id) ON DELETE RESTRICT;


--
-- Name: acc_journal_lines acc_journal_lines_entry_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_journal_lines
    ADD CONSTRAINT acc_journal_lines_entry_id_fkey FOREIGN KEY (entry_id) REFERENCES public.acc_journal_entries(id) ON DELETE CASCADE;


--
-- Name: acc_journal_lines acc_journal_lines_party_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_journal_lines
    ADD CONSTRAINT acc_journal_lines_party_id_fkey FOREIGN KEY (party_id) REFERENCES public.parties(id) ON DELETE SET NULL;


--
-- Name: acc_journal_lines acc_journal_lines_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_journal_lines
    ADD CONSTRAINT acc_journal_lines_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: acc_periods acc_periods_closed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_periods
    ADD CONSTRAINT acc_periods_closed_by_fkey FOREIGN KEY (closed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: acc_periods acc_periods_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.acc_periods
    ADD CONSTRAINT acc_periods_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: advances advances_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.advances
    ADD CONSTRAINT advances_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: advances advances_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.advances
    ADD CONSTRAINT advances_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: agenda_events agenda_events_assigned_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.agenda_events
    ADD CONSTRAINT agenda_events_assigned_user_id_fkey FOREIGN KEY (assigned_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: agenda_events agenda_events_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.agenda_events
    ADD CONSTRAINT agenda_events_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: agenda_events agenda_events_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.agenda_events
    ADD CONSTRAINT agenda_events_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: agenda_events agenda_events_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.agenda_events
    ADD CONSTRAINT agenda_events_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: agenda_events agenda_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.agenda_events
    ADD CONSTRAINT agenda_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: audit_logs audit_logs_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE SET NULL;


--
-- Name: audit_logs audit_logs_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: bank_movements bank_movements_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_movements
    ADD CONSTRAINT bank_movements_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.financial_accounts(id) ON DELETE RESTRICT;


--
-- Name: bank_movements bank_movements_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_movements
    ADD CONSTRAINT bank_movements_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: bank_movements bank_movements_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_movements
    ADD CONSTRAINT bank_movements_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: bank_statement_lines bank_statement_lines_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_statement_lines
    ADD CONSTRAINT bank_statement_lines_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.financial_accounts(id) ON DELETE RESTRICT;


--
-- Name: bank_statement_lines bank_statement_lines_movement_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_statement_lines
    ADD CONSTRAINT bank_statement_lines_movement_id_fkey FOREIGN KEY (movement_id) REFERENCES public.bank_movements(id) ON DELETE RESTRICT;


--
-- Name: bank_statement_lines bank_statement_lines_resolved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_statement_lines
    ADD CONSTRAINT bank_statement_lines_resolved_by_fkey FOREIGN KEY (resolved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: bank_statement_lines bank_statement_lines_statement_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_statement_lines
    ADD CONSTRAINT bank_statement_lines_statement_id_fkey FOREIGN KEY (statement_id) REFERENCES public.bank_statements(id) ON DELETE CASCADE;


--
-- Name: bank_statement_lines bank_statement_lines_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_statement_lines
    ADD CONSTRAINT bank_statement_lines_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: bank_statements bank_statements_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_statements
    ADD CONSTRAINT bank_statements_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.financial_accounts(id) ON DELETE RESTRICT;


--
-- Name: bank_statements bank_statements_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_statements
    ADD CONSTRAINT bank_statements_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE SET NULL;


--
-- Name: bank_statements bank_statements_imported_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_statements
    ADD CONSTRAINT bank_statements_imported_by_fkey FOREIGN KEY (imported_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: bank_statements bank_statements_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_statements
    ADD CONSTRAINT bank_statements_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: bank_transfers bank_transfers_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_transfers
    ADD CONSTRAINT bank_transfers_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: bank_transfers bank_transfers_from_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_transfers
    ADD CONSTRAINT bank_transfers_from_account_id_fkey FOREIGN KEY (from_account_id) REFERENCES public.financial_accounts(id) ON DELETE RESTRICT;


--
-- Name: bank_transfers bank_transfers_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_transfers
    ADD CONSTRAINT bank_transfers_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: bank_transfers bank_transfers_to_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.bank_transfers
    ADD CONSTRAINT bank_transfers_to_account_id_fkey FOREIGN KEY (to_account_id) REFERENCES public.financial_accounts(id) ON DELETE RESTRICT;


--
-- Name: billable_events billable_events_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.billable_events
    ADD CONSTRAINT billable_events_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: billable_events billable_events_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.billable_events
    ADD CONSTRAINT billable_events_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE SET NULL;


--
-- Name: billable_events billable_events_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.billable_events
    ADD CONSTRAINT billable_events_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: billable_events billable_events_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.billable_events
    ADD CONSTRAINT billable_events_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: billable_events billable_events_invoice_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.billable_events
    ADD CONSTRAINT billable_events_invoice_id_fkey FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE SET NULL;


--
-- Name: billable_events billable_events_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.billable_events
    ADD CONSTRAINT billable_events_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: billable_events billable_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.billable_events
    ADD CONSTRAINT billable_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: branches branches_company_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.branches
    ADD CONSTRAINT branches_company_id_fkey FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE RESTRICT;


--
-- Name: branches branches_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.branches
    ADD CONSTRAINT branches_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: budget_lines budget_lines_budget_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.budget_lines
    ADD CONSTRAINT budget_lines_budget_id_fkey FOREIGN KEY (budget_id) REFERENCES public.budgets(id) ON DELETE CASCADE;


--
-- Name: budget_lines budget_lines_cost_center_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.budget_lines
    ADD CONSTRAINT budget_lines_cost_center_id_fkey FOREIGN KEY (cost_center_id) REFERENCES public.cost_centers(id) ON DELETE RESTRICT;


--
-- Name: budget_lines budget_lines_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.budget_lines
    ADD CONSTRAINT budget_lines_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: budgets budgets_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.budgets
    ADD CONSTRAINT budgets_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: budgets budgets_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.budgets
    ADD CONSTRAINT budgets_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: budgets budgets_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.budgets
    ADD CONSTRAINT budgets_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: cash_counts cash_counts_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_counts
    ADD CONSTRAINT cash_counts_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.financial_accounts(id) ON DELETE RESTRICT;


--
-- Name: cash_counts cash_counts_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_counts
    ADD CONSTRAINT cash_counts_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: cash_counts cash_counts_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_counts
    ADD CONSTRAINT cash_counts_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: cash_expense_reclassifications cash_expense_reclassifications_cash_expense_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expense_reclassifications
    ADD CONSTRAINT cash_expense_reclassifications_cash_expense_id_fkey FOREIGN KEY (cash_expense_id) REFERENCES public.cash_expenses(id) ON DELETE RESTRICT;


--
-- Name: cash_expense_reclassifications cash_expense_reclassifications_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expense_reclassifications
    ADD CONSTRAINT cash_expense_reclassifications_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: cash_expense_reclassifications cash_expense_reclassifications_new_cost_center_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expense_reclassifications
    ADD CONSTRAINT cash_expense_reclassifications_new_cost_center_id_fkey FOREIGN KEY (new_cost_center_id) REFERENCES public.cost_centers(id) ON DELETE RESTRICT;


--
-- Name: cash_expense_reclassifications cash_expense_reclassifications_new_entry_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expense_reclassifications
    ADD CONSTRAINT cash_expense_reclassifications_new_entry_id_fkey FOREIGN KEY (new_entry_id) REFERENCES public.acc_journal_entries(id) ON DELETE RESTRICT;


--
-- Name: cash_expense_reclassifications cash_expense_reclassifications_old_cost_center_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expense_reclassifications
    ADD CONSTRAINT cash_expense_reclassifications_old_cost_center_id_fkey FOREIGN KEY (old_cost_center_id) REFERENCES public.cost_centers(id) ON DELETE RESTRICT;


--
-- Name: cash_expense_reclassifications cash_expense_reclassifications_reversed_entry_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expense_reclassifications
    ADD CONSTRAINT cash_expense_reclassifications_reversed_entry_id_fkey FOREIGN KEY (reversed_entry_id) REFERENCES public.acc_journal_entries(id) ON DELETE RESTRICT;


--
-- Name: cash_expense_reclassifications cash_expense_reclassifications_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expense_reclassifications
    ADD CONSTRAINT cash_expense_reclassifications_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: cash_expenses cash_expenses_account_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expenses
    ADD CONSTRAINT cash_expenses_account_fkey FOREIGN KEY (tenant_id, expense_account) REFERENCES public.acc_accounts(tenant_id, code) ON DELETE RESTRICT NOT VALID;


--
-- Name: cash_expenses cash_expenses_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expenses
    ADD CONSTRAINT cash_expenses_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.financial_accounts(id) ON DELETE RESTRICT;


--
-- Name: cash_expenses cash_expenses_cost_center_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expenses
    ADD CONSTRAINT cash_expenses_cost_center_id_fkey FOREIGN KEY (cost_center_id) REFERENCES public.cost_centers(id) ON DELETE RESTRICT;


--
-- Name: cash_expenses cash_expenses_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expenses
    ADD CONSTRAINT cash_expenses_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: cash_expenses cash_expenses_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cash_expenses
    ADD CONSTRAINT cash_expenses_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: checklist_items checklist_items_checklist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.checklist_items
    ADD CONSTRAINT checklist_items_checklist_id_fkey FOREIGN KEY (checklist_id) REFERENCES public.checklists(id) ON DELETE CASCADE;


--
-- Name: checklist_items checklist_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.checklist_items
    ADD CONSTRAINT checklist_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: checklists checklists_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.checklists
    ADD CONSTRAINT checklists_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: checklists checklists_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.checklists
    ADD CONSTRAINT checklists_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE SET NULL;


--
-- Name: checklists checklists_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.checklists
    ADD CONSTRAINT checklists_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: companies companies_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.companies
    ADD CONSTRAINT companies_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: company_branding company_branding_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.company_branding
    ADD CONSTRAINT company_branding_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: company_branding company_branding_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.company_branding
    ADD CONSTRAINT company_branding_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: contacts contacts_party_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contacts
    ADD CONSTRAINT contacts_party_id_fkey FOREIGN KEY (party_id) REFERENCES public.parties(id) ON DELETE RESTRICT;


--
-- Name: contacts contacts_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contacts
    ADD CONSTRAINT contacts_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: contract_items contract_items_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_items
    ADD CONSTRAINT contract_items_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE CASCADE;


--
-- Name: contract_items contract_items_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_items
    ADD CONSTRAINT contract_items_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE RESTRICT;


--
-- Name: contract_items contract_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_items
    ADD CONSTRAINT contract_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: contract_signature_requests contract_signature_requests_cancelled_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_signature_requests
    ADD CONSTRAINT contract_signature_requests_cancelled_by_fkey FOREIGN KEY (cancelled_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: contract_signature_requests contract_signature_requests_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_signature_requests
    ADD CONSTRAINT contract_signature_requests_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE RESTRICT;


--
-- Name: contract_signature_requests contract_signature_requests_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_signature_requests
    ADD CONSTRAINT contract_signature_requests_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: contract_signature_requests contract_signature_requests_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_signature_requests
    ADD CONSTRAINT contract_signature_requests_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: contract_signature_requests contract_signature_requests_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_signature_requests
    ADD CONSTRAINT contract_signature_requests_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: contract_signature_signers contract_signature_signers_portal_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_signature_signers
    ADD CONSTRAINT contract_signature_signers_portal_user_id_fkey FOREIGN KEY (portal_user_id) REFERENCES public.portal_users(id) ON DELETE RESTRICT;


--
-- Name: contract_signature_signers contract_signature_signers_request_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_signature_signers
    ADD CONSTRAINT contract_signature_signers_request_id_fkey FOREIGN KEY (request_id) REFERENCES public.contract_signature_requests(id) ON DELETE RESTRICT;


--
-- Name: contract_signature_signers contract_signature_signers_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_signature_signers
    ADD CONSTRAINT contract_signature_signers_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: contract_signature_signers contract_signature_signers_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_signature_signers
    ADD CONSTRAINT contract_signature_signers_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: contracted_services contracted_services_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracted_services
    ADD CONSTRAINT contracted_services_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: contracted_services contracted_services_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracted_services
    ADD CONSTRAINT contracted_services_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE RESTRICT;


--
-- Name: contracted_services contracted_services_contract_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracted_services
    ADD CONSTRAINT contracted_services_contract_item_id_fkey FOREIGN KEY (contract_item_id) REFERENCES public.contract_items(id) ON DELETE SET NULL;


--
-- Name: contracted_services contracted_services_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracted_services
    ADD CONSTRAINT contracted_services_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: contracted_services contracted_services_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracted_services
    ADD CONSTRAINT contracted_services_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: contracted_services contracted_services_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracted_services
    ADD CONSTRAINT contracted_services_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE RESTRICT;


--
-- Name: contracted_services contracted_services_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracted_services
    ADD CONSTRAINT contracted_services_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: contracts contracts_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT contracts_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: contracts contracts_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT contracts_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: contracts contracts_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT contracts_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: contracts contracts_quotation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT contracts_quotation_id_fkey FOREIGN KEY (quotation_id) REFERENCES public.quotations(id) ON DELETE SET NULL;


--
-- Name: contracts contracts_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracts
    ADD CONSTRAINT contracts_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: cost_centers cost_centers_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_centers
    ADD CONSTRAINT cost_centers_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: cost_centers cost_centers_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_centers
    ADD CONSTRAINT cost_centers_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: cost_centers cost_centers_manager_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_centers
    ADD CONSTRAINT cost_centers_manager_user_id_fkey FOREIGN KEY (manager_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: cost_centers cost_centers_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_centers
    ADD CONSTRAINT cost_centers_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: cost_entries cost_entries_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_entries
    ADD CONSTRAINT cost_entries_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: cost_entries cost_entries_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_entries
    ADD CONSTRAINT cost_entries_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: cost_entries cost_entries_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_entries
    ADD CONSTRAINT cost_entries_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: cost_entries cost_entries_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_entries
    ADD CONSTRAINT cost_entries_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: cost_entries cost_entries_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_entries
    ADD CONSTRAINT cost_entries_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE SET NULL;


--
-- Name: cost_entries cost_entries_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_entries
    ADD CONSTRAINT cost_entries_task_id_fkey FOREIGN KEY (task_id) REFERENCES public.tasks(id) ON DELETE CASCADE;


--
-- Name: cost_entries cost_entries_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_entries
    ADD CONSTRAINT cost_entries_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: cost_entries cost_entries_work_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_entries
    ADD CONSTRAINT cost_entries_work_order_id_fkey FOREIGN KEY (work_order_id) REFERENCES public.work_orders(id) ON DELETE CASCADE;


--
-- Name: credential_access_logs credential_access_logs_credential_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.credential_access_logs
    ADD CONSTRAINT credential_access_logs_credential_id_fkey FOREIGN KEY (credential_id) REFERENCES public.credential_records(id) ON DELETE RESTRICT;


--
-- Name: credential_access_logs credential_access_logs_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.credential_access_logs
    ADD CONSTRAINT credential_access_logs_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: credential_access_logs credential_access_logs_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.credential_access_logs
    ADD CONSTRAINT credential_access_logs_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: credential_records credential_records_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.credential_records
    ADD CONSTRAINT credential_records_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: credential_records credential_records_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.credential_records
    ADD CONSTRAINT credential_records_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: credential_versions credential_versions_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.credential_versions
    ADD CONSTRAINT credential_versions_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: credential_versions credential_versions_credential_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.credential_versions
    ADD CONSTRAINT credential_versions_credential_id_fkey FOREIGN KEY (credential_id) REFERENCES public.credential_records(id) ON DELETE CASCADE;


--
-- Name: credential_versions credential_versions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.credential_versions
    ADD CONSTRAINT credential_versions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: crm_activities crm_activities_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_activities
    ADD CONSTRAINT crm_activities_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: crm_activities crm_activities_completed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_activities
    ADD CONSTRAINT crm_activities_completed_by_fkey FOREIGN KEY (completed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: crm_activities crm_activities_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_activities
    ADD CONSTRAINT crm_activities_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.crm_leads(id) ON DELETE CASCADE;


--
-- Name: crm_activities crm_activities_opportunity_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_activities
    ADD CONSTRAINT crm_activities_opportunity_id_fkey FOREIGN KEY (opportunity_id) REFERENCES public.crm_opportunities(id) ON DELETE CASCADE;


--
-- Name: crm_activities crm_activities_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_activities
    ADD CONSTRAINT crm_activities_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: crm_activities crm_activities_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_activities
    ADD CONSTRAINT crm_activities_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: crm_campaign_members crm_campaign_members_added_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_campaign_members
    ADD CONSTRAINT crm_campaign_members_added_by_fkey FOREIGN KEY (added_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: crm_campaign_members crm_campaign_members_campaign_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_campaign_members
    ADD CONSTRAINT crm_campaign_members_campaign_id_fkey FOREIGN KEY (campaign_id) REFERENCES public.crm_campaigns(id) ON DELETE CASCADE;


--
-- Name: crm_campaign_members crm_campaign_members_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_campaign_members
    ADD CONSTRAINT crm_campaign_members_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: crm_campaign_members crm_campaign_members_excluded_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_campaign_members
    ADD CONSTRAINT crm_campaign_members_excluded_by_fkey FOREIGN KEY (excluded_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: crm_campaign_members crm_campaign_members_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_campaign_members
    ADD CONSTRAINT crm_campaign_members_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.crm_leads(id) ON DELETE CASCADE;


--
-- Name: crm_campaign_members crm_campaign_members_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_campaign_members
    ADD CONSTRAINT crm_campaign_members_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: crm_campaigns crm_campaigns_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_campaigns
    ADD CONSTRAINT crm_campaigns_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: crm_campaigns crm_campaigns_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_campaigns
    ADD CONSTRAINT crm_campaigns_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: crm_conversions crm_conversions_converted_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_conversions
    ADD CONSTRAINT crm_conversions_converted_by_fkey FOREIGN KEY (converted_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: crm_conversions crm_conversions_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_conversions
    ADD CONSTRAINT crm_conversions_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: crm_conversions crm_conversions_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_conversions
    ADD CONSTRAINT crm_conversions_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.crm_leads(id) ON DELETE RESTRICT;


--
-- Name: crm_conversions crm_conversions_opportunity_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_conversions
    ADD CONSTRAINT crm_conversions_opportunity_id_fkey FOREIGN KEY (opportunity_id) REFERENCES public.crm_opportunities(id) ON DELETE SET NULL;


--
-- Name: crm_conversions crm_conversions_party_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_conversions
    ADD CONSTRAINT crm_conversions_party_id_fkey FOREIGN KEY (party_id) REFERENCES public.parties(id) ON DELETE RESTRICT;


--
-- Name: crm_conversions crm_conversions_quotation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_conversions
    ADD CONSTRAINT crm_conversions_quotation_id_fkey FOREIGN KEY (quotation_id) REFERENCES public.quotations(id) ON DELETE SET NULL;


--
-- Name: crm_conversions crm_conversions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_conversions
    ADD CONSTRAINT crm_conversions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: crm_entity_tags crm_entity_tags_tag_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_entity_tags
    ADD CONSTRAINT crm_entity_tags_tag_id_fkey FOREIGN KEY (tag_id) REFERENCES public.crm_tags(id) ON DELETE CASCADE;


--
-- Name: crm_entity_tags crm_entity_tags_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_entity_tags
    ADD CONSTRAINT crm_entity_tags_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: crm_leads crm_leads_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_leads
    ADD CONSTRAINT crm_leads_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: crm_leads crm_leads_campaign_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_leads
    ADD CONSTRAINT crm_leads_campaign_id_fkey FOREIGN KEY (campaign_id) REFERENCES public.crm_campaigns(id) ON DELETE SET NULL;


--
-- Name: crm_leads crm_leads_converted_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_leads
    ADD CONSTRAINT crm_leads_converted_customer_id_fkey FOREIGN KEY (converted_customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: crm_leads crm_leads_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_leads
    ADD CONSTRAINT crm_leads_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: crm_leads crm_leads_party_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_leads
    ADD CONSTRAINT crm_leads_party_id_fkey FOREIGN KEY (party_id) REFERENCES public.parties(id) ON DELETE SET NULL;


--
-- Name: crm_leads crm_leads_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_leads
    ADD CONSTRAINT crm_leads_source_id_fkey FOREIGN KEY (source_id) REFERENCES public.crm_sources(id) ON DELETE SET NULL;


--
-- Name: crm_leads crm_leads_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_leads
    ADD CONSTRAINT crm_leads_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: crm_opportunities crm_opportunities_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_opportunities
    ADD CONSTRAINT crm_opportunities_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: crm_opportunities crm_opportunities_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_opportunities
    ADD CONSTRAINT crm_opportunities_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: crm_opportunities crm_opportunities_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_opportunities
    ADD CONSTRAINT crm_opportunities_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.crm_leads(id) ON DELETE SET NULL;


--
-- Name: crm_opportunities crm_opportunities_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_opportunities
    ADD CONSTRAINT crm_opportunities_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: crm_opportunities crm_opportunities_pipeline_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_opportunities
    ADD CONSTRAINT crm_opportunities_pipeline_id_fkey FOREIGN KEY (pipeline_id) REFERENCES public.crm_pipelines(id) ON DELETE RESTRICT;


--
-- Name: crm_opportunities crm_opportunities_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_opportunities
    ADD CONSTRAINT crm_opportunities_source_id_fkey FOREIGN KEY (source_id) REFERENCES public.crm_sources(id) ON DELETE SET NULL;


--
-- Name: crm_opportunities crm_opportunities_stage_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_opportunities
    ADD CONSTRAINT crm_opportunities_stage_id_fkey FOREIGN KEY (stage_id) REFERENCES public.crm_pipeline_stages(id) ON DELETE RESTRICT;


--
-- Name: crm_opportunities crm_opportunities_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_opportunities
    ADD CONSTRAINT crm_opportunities_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: crm_opportunities crm_opportunities_won_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_opportunities
    ADD CONSTRAINT crm_opportunities_won_by_fkey FOREIGN KEY (won_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: crm_pipeline_stages crm_pipeline_stages_pipeline_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_pipeline_stages
    ADD CONSTRAINT crm_pipeline_stages_pipeline_id_fkey FOREIGN KEY (pipeline_id) REFERENCES public.crm_pipelines(id) ON DELETE CASCADE;


--
-- Name: crm_pipeline_stages crm_pipeline_stages_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_pipeline_stages
    ADD CONSTRAINT crm_pipeline_stages_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: crm_pipelines crm_pipelines_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_pipelines
    ADD CONSTRAINT crm_pipelines_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: crm_settings crm_settings_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_settings
    ADD CONSTRAINT crm_settings_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: crm_sources crm_sources_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_sources
    ADD CONSTRAINT crm_sources_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: crm_tags crm_tags_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_tags
    ADD CONSTRAINT crm_tags_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: custom_field_definitions custom_field_definitions_tenant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.custom_field_definitions
    ADD CONSTRAINT custom_field_definitions_tenant_id_foreign FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: custom_field_values custom_field_values_field_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.custom_field_values
    ADD CONSTRAINT custom_field_values_field_id_foreign FOREIGN KEY (field_id) REFERENCES public.custom_field_definitions(id) ON DELETE CASCADE;


--
-- Name: custom_field_values custom_field_values_tenant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.custom_field_values
    ADD CONSTRAINT custom_field_values_tenant_id_foreign FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: customers customers_party_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_party_id_fkey FOREIGN KEY (party_id) REFERENCES public.parties(id) ON DELETE RESTRICT;


--
-- Name: customers customers_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: dashboard_definitions dashboard_definitions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.dashboard_definitions
    ADD CONSTRAINT dashboard_definitions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: deliverables deliverables_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.deliverables
    ADD CONSTRAINT deliverables_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: deliverables deliverables_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.deliverables
    ADD CONSTRAINT deliverables_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE RESTRICT;


--
-- Name: deliverables deliverables_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.deliverables
    ADD CONSTRAINT deliverables_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: deliverables deliverables_reviewed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.deliverables
    ADD CONSTRAINT deliverables_reviewed_by_fkey FOREIGN KEY (reviewed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: deliverables deliverables_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.deliverables
    ADD CONSTRAINT deliverables_task_id_fkey FOREIGN KEY (task_id) REFERENCES public.tasks(id) ON DELETE CASCADE;


--
-- Name: deliverables deliverables_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.deliverables
    ADD CONSTRAINT deliverables_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: deliverables deliverables_work_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.deliverables
    ADD CONSTRAINT deliverables_work_order_id_fkey FOREIGN KEY (work_order_id) REFERENCES public.work_orders(id) ON DELETE CASCADE;


--
-- Name: document_links document_links_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.document_links
    ADD CONSTRAINT document_links_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE CASCADE;


--
-- Name: document_links document_links_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.document_links
    ADD CONSTRAINT document_links_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: document_versions document_versions_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.document_versions
    ADD CONSTRAINT document_versions_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: document_versions document_versions_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.document_versions
    ADD CONSTRAINT document_versions_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE CASCADE;


--
-- Name: document_versions document_versions_file_object_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.document_versions
    ADD CONSTRAINT document_versions_file_object_id_fkey FOREIGN KEY (file_object_id) REFERENCES public.file_objects(id) ON DELETE RESTRICT;


--
-- Name: document_versions document_versions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.document_versions
    ADD CONSTRAINT document_versions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: documents documents_archived_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_archived_by_fkey FOREIGN KEY (archived_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: documents documents_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: documents documents_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: documents documents_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: employees employees_person_party_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_person_party_id_fkey FOREIGN KEY (person_party_id) REFERENCES public.parties(id) ON DELETE RESTRICT;


--
-- Name: employees employees_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: employees employees_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT employees_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: evidences evidences_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.evidences
    ADD CONSTRAINT evidences_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: evidences evidences_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.evidences
    ADD CONSTRAINT evidences_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE RESTRICT;


--
-- Name: evidences evidences_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.evidences
    ADD CONSTRAINT evidences_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: exchange_rates exchange_rates_tenant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.exchange_rates
    ADD CONSTRAINT exchange_rates_tenant_id_foreign FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: file_objects file_objects_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.file_objects
    ADD CONSTRAINT file_objects_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: financial_accounts financial_accounts_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.financial_accounts
    ADD CONSTRAINT financial_accounts_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: financial_accounts financial_accounts_custodian_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.financial_accounts
    ADD CONSTRAINT financial_accounts_custodian_user_id_fkey FOREIGN KEY (custodian_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: financial_accounts financial_accounts_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.financial_accounts
    ADD CONSTRAINT financial_accounts_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: fiscal_configurations fiscal_configurations_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_configurations
    ADD CONSTRAINT fiscal_configurations_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: fiscal_documents fiscal_documents_fiscal_series_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_documents
    ADD CONSTRAINT fiscal_documents_fiscal_series_id_fkey FOREIGN KEY (fiscal_series_id) REFERENCES public.fiscal_series(id) ON DELETE RESTRICT;


--
-- Name: fiscal_documents fiscal_documents_invoice_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_documents
    ADD CONSTRAINT fiscal_documents_invoice_id_fkey FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE SET NULL;


--
-- Name: fiscal_documents fiscal_documents_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_documents
    ADD CONSTRAINT fiscal_documents_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: fiscal_notes fiscal_notes_fiscal_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_notes
    ADD CONSTRAINT fiscal_notes_fiscal_document_id_fkey FOREIGN KEY (fiscal_document_id) REFERENCES public.fiscal_documents(id) ON DELETE RESTRICT;


--
-- Name: fiscal_notes fiscal_notes_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_notes
    ADD CONSTRAINT fiscal_notes_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: fiscal_series fiscal_series_fiscal_configuration_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_series
    ADD CONSTRAINT fiscal_series_fiscal_configuration_id_fkey FOREIGN KEY (fiscal_configuration_id) REFERENCES public.fiscal_configurations(id) ON DELETE CASCADE;


--
-- Name: fiscal_series fiscal_series_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_series
    ADD CONSTRAINT fiscal_series_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: fiscal_void_requests fiscal_void_requests_invoice_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_void_requests
    ADD CONSTRAINT fiscal_void_requests_invoice_id_fkey FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE RESTRICT;


--
-- Name: fiscal_void_requests fiscal_void_requests_requested_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_void_requests
    ADD CONSTRAINT fiscal_void_requests_requested_by_fkey FOREIGN KEY (requested_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: fiscal_void_requests fiscal_void_requests_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.fiscal_void_requests
    ADD CONSTRAINT fiscal_void_requests_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: billable_events fk_billable_events_customer_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.billable_events
    ADD CONSTRAINT fk_billable_events_customer_same_tenant FOREIGN KEY (tenant_id, customer_id) REFERENCES public.customers(tenant_id, id) ON DELETE RESTRICT;


--
-- Name: branches fk_branches_company_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.branches
    ADD CONSTRAINT fk_branches_company_same_tenant FOREIGN KEY (tenant_id, company_id) REFERENCES public.companies(tenant_id, id) ON DELETE RESTRICT;


--
-- Name: contacts fk_contacts_party_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contacts
    ADD CONSTRAINT fk_contacts_party_same_tenant FOREIGN KEY (tenant_id, party_id) REFERENCES public.parties(tenant_id, id) ON DELETE RESTRICT;


--
-- Name: contract_items fk_contract_items_contract_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contract_items
    ADD CONSTRAINT fk_contract_items_contract_same_tenant FOREIGN KEY (tenant_id, contract_id) REFERENCES public.contracts(tenant_id, id) ON DELETE CASCADE;


--
-- Name: contracted_services fk_contracted_services_customer_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracted_services
    ADD CONSTRAINT fk_contracted_services_customer_same_tenant FOREIGN KEY (tenant_id, customer_id) REFERENCES public.customers(tenant_id, id) ON DELETE RESTRICT;


--
-- Name: crm_opportunities fk_crm_opportunities_customer_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_opportunities
    ADD CONSTRAINT fk_crm_opportunities_customer_same_tenant FOREIGN KEY (tenant_id, customer_id) REFERENCES public.customers(tenant_id, id) ON DELETE SET NULL;


--
-- Name: customers fk_customers_party_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT fk_customers_party_same_tenant FOREIGN KEY (tenant_id, party_id) REFERENCES public.parties(tenant_id, id) ON DELETE RESTRICT;


--
-- Name: employees fk_employees_party_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.employees
    ADD CONSTRAINT fk_employees_party_same_tenant FOREIGN KEY (tenant_id, person_party_id) REFERENCES public.parties(tenant_id, id) ON DELETE RESTRICT;


--
-- Name: invoices fk_invoices_customer_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT fk_invoices_customer_same_tenant FOREIGN KEY (tenant_id, customer_id) REFERENCES public.customers(tenant_id, id) ON DELETE RESTRICT;


--
-- Name: order_items fk_order_items_order_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT fk_order_items_order_same_tenant FOREIGN KEY (tenant_id, order_id) REFERENCES public.orders(tenant_id, id) ON DELETE CASCADE;


--
-- Name: projects fk_projects_customer_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT fk_projects_customer_same_tenant FOREIGN KEY (tenant_id, customer_id) REFERENCES public.customers(tenant_id, id) ON DELETE RESTRICT;


--
-- Name: quotation_items fk_quotation_items_quotation_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotation_items
    ADD CONSTRAINT fk_quotation_items_quotation_same_tenant FOREIGN KEY (tenant_id, quotation_id) REFERENCES public.quotations(tenant_id, id) ON DELETE CASCADE;


--
-- Name: receivables fk_receivables_customer_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.receivables
    ADD CONSTRAINT fk_receivables_customer_same_tenant FOREIGN KEY (tenant_id, customer_id) REFERENCES public.customers(tenant_id, id) ON DELETE RESTRICT;


--
-- Name: service_categories fk_service_categories_parent_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_categories
    ADD CONSTRAINT fk_service_categories_parent_same_tenant FOREIGN KEY (tenant_id, parent_id) REFERENCES public.service_categories(tenant_id, id) ON DELETE SET NULL;


--
-- Name: services fk_services_category_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.services
    ADD CONSTRAINT fk_services_category_same_tenant FOREIGN KEY (tenant_id, category_id) REFERENCES public.service_categories(tenant_id, id) ON DELETE SET NULL;


--
-- Name: suppliers fk_suppliers_party_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT fk_suppliers_party_same_tenant FOREIGN KEY (tenant_id, party_id) REFERENCES public.parties(tenant_id, id) ON DELETE RESTRICT;


--
-- Name: support_tickets fk_support_tickets_customer_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT fk_support_tickets_customer_same_tenant FOREIGN KEY (tenant_id, customer_id) REFERENCES public.customers(tenant_id, id) ON DELETE RESTRICT;


--
-- Name: tenant_users fk_tenant_users_default_branch; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_users
    ADD CONSTRAINT fk_tenant_users_default_branch FOREIGN KEY (default_branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: warranty_claims fk_warranty_claims_customer_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT fk_warranty_claims_customer_same_tenant FOREIGN KEY (tenant_id, customer_id) REFERENCES public.customers(tenant_id, id) ON DELETE RESTRICT;


--
-- Name: work_orders fk_work_orders_customer_same_tenant; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT fk_work_orders_customer_same_tenant FOREIGN KEY (tenant_id, customer_id) REFERENCES public.customers(tenant_id, id) ON DELETE RESTRICT;


--
-- Name: idempotency_keys idempotency_keys_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.idempotency_keys
    ADD CONSTRAINT idempotency_keys_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: idempotency_keys idempotency_keys_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.idempotency_keys
    ADD CONSTRAINT idempotency_keys_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: integration_attempts integration_attempts_connection_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.integration_attempts
    ADD CONSTRAINT integration_attempts_connection_id_fkey FOREIGN KEY (connection_id) REFERENCES public.integration_connections(id) ON DELETE SET NULL;


--
-- Name: integration_attempts integration_attempts_outbox_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.integration_attempts
    ADD CONSTRAINT integration_attempts_outbox_event_id_fkey FOREIGN KEY (outbox_event_id) REFERENCES public.outbox_events(id) ON DELETE SET NULL;


--
-- Name: integration_attempts integration_attempts_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.integration_attempts
    ADD CONSTRAINT integration_attempts_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: integration_connections integration_connections_credential_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.integration_connections
    ADD CONSTRAINT integration_connections_credential_id_fkey FOREIGN KEY (credential_id) REFERENCES public.credential_records(id) ON DELETE SET NULL;


--
-- Name: integration_connections integration_connections_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.integration_connections
    ADD CONSTRAINT integration_connections_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: integration_dead_letters integration_dead_letters_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.integration_dead_letters
    ADD CONSTRAINT integration_dead_letters_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: invoice_items invoice_items_billable_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoice_items
    ADD CONSTRAINT invoice_items_billable_event_id_fkey FOREIGN KEY (billable_event_id) REFERENCES public.billable_events(id) ON DELETE SET NULL;


--
-- Name: invoice_items invoice_items_invoice_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoice_items
    ADD CONSTRAINT invoice_items_invoice_id_fkey FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE CASCADE;


--
-- Name: invoice_items invoice_items_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoice_items
    ADD CONSTRAINT invoice_items_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE SET NULL;


--
-- Name: invoice_items invoice_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoice_items
    ADD CONSTRAINT invoice_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: invoices invoices_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: invoices invoices_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE SET NULL;


--
-- Name: invoices invoices_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: invoices invoices_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: invoices invoices_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: invoices invoices_related_invoice_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_related_invoice_id_fkey FOREIGN KEY (related_invoice_id) REFERENCES public.invoices(id) ON DELETE RESTRICT;


--
-- Name: invoices invoices_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: invoices invoices_withholding_registered_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_withholding_registered_by_fkey FOREIGN KEY (withholding_registered_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: notification_preferences notification_preferences_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.notification_preferences
    ADD CONSTRAINT notification_preferences_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: notification_preferences notification_preferences_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.notification_preferences
    ADD CONSTRAINT notification_preferences_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: notifications notifications_actor_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_actor_user_id_fkey FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: notifications notifications_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: notifications notifications_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: order_items order_items_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT order_items_order_id_fkey FOREIGN KEY (order_id) REFERENCES public.orders(id) ON DELETE CASCADE;


--
-- Name: order_items order_items_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT order_items_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE RESTRICT;


--
-- Name: order_items order_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT order_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: orders orders_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: orders orders_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE SET NULL;


--
-- Name: orders orders_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: orders orders_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: orders orders_quotation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_quotation_id_fkey FOREIGN KEY (quotation_id) REFERENCES public.quotations(id) ON DELETE SET NULL;


--
-- Name: orders orders_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: organizations organizations_party_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.organizations
    ADD CONSTRAINT organizations_party_id_fkey FOREIGN KEY (party_id) REFERENCES public.parties(id) ON DELETE CASCADE;


--
-- Name: outbox_events outbox_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.outbox_events
    ADD CONSTRAINT outbox_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: parties parties_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.parties
    ADD CONSTRAINT parties_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: payable_movements payable_movements_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payable_movements
    ADD CONSTRAINT payable_movements_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: payable_movements payable_movements_payable_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payable_movements
    ADD CONSTRAINT payable_movements_payable_id_fkey FOREIGN KEY (payable_id) REFERENCES public.payables(id) ON DELETE CASCADE;


--
-- Name: payable_movements payable_movements_payment_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payable_movements
    ADD CONSTRAINT payable_movements_payment_fkey FOREIGN KEY (supplier_payment_id) REFERENCES public.supplier_payments(id) ON DELETE SET NULL;


--
-- Name: payable_movements payable_movements_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payable_movements
    ADD CONSTRAINT payable_movements_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: payables payables_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payables
    ADD CONSTRAINT payables_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: payables payables_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payables
    ADD CONSTRAINT payables_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: payables payables_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payables
    ADD CONSTRAINT payables_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE RESTRICT;


--
-- Name: payables payables_supplier_invoice_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payables
    ADD CONSTRAINT payables_supplier_invoice_id_fkey FOREIGN KEY (supplier_invoice_id) REFERENCES public.supplier_invoices(id) ON DELETE RESTRICT;


--
-- Name: payables payables_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payables
    ADD CONSTRAINT payables_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: payment_allocations payment_allocations_payment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payment_allocations
    ADD CONSTRAINT payment_allocations_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payments(id) ON DELETE CASCADE;


--
-- Name: payment_allocations payment_allocations_receivable_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payment_allocations
    ADD CONSTRAINT payment_allocations_receivable_id_fkey FOREIGN KEY (receivable_id) REFERENCES public.receivables(id) ON DELETE RESTRICT;


--
-- Name: payment_allocations payment_allocations_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payment_allocations
    ADD CONSTRAINT payment_allocations_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: payments payments_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: payments payments_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: payments payments_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: payments payments_financial_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_financial_account_id_fkey FOREIGN KEY (financial_account_id) REFERENCES public.financial_accounts(id) ON DELETE SET NULL;


--
-- Name: payments payments_reconciled_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_reconciled_by_fkey FOREIGN KEY (reconciled_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: payments payments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: persons persons_party_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.persons
    ADD CONSTRAINT persons_party_id_fkey FOREIGN KEY (party_id) REFERENCES public.parties(id) ON DELETE CASCADE;


--
-- Name: portal_notices portal_notices_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_notices
    ADD CONSTRAINT portal_notices_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: portal_notices portal_notices_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_notices
    ADD CONSTRAINT portal_notices_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: portal_notices portal_notices_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_notices
    ADD CONSTRAINT portal_notices_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: portal_notices portal_notices_withdrawn_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_notices
    ADD CONSTRAINT portal_notices_withdrawn_by_fkey FOREIGN KEY (withdrawn_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: portal_notifications portal_notifications_portal_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_notifications
    ADD CONSTRAINT portal_notifications_portal_user_id_fkey FOREIGN KEY (portal_user_id) REFERENCES public.portal_users(id) ON DELETE CASCADE;


--
-- Name: portal_notifications portal_notifications_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_notifications
    ADD CONSTRAINT portal_notifications_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: portal_requests portal_requests_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_requests
    ADD CONSTRAINT portal_requests_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: portal_requests portal_requests_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_requests
    ADD CONSTRAINT portal_requests_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: portal_requests portal_requests_crm_opportunity_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_requests
    ADD CONSTRAINT portal_requests_crm_opportunity_id_fkey FOREIGN KEY (crm_opportunity_id) REFERENCES public.crm_opportunities(id) ON DELETE RESTRICT;


--
-- Name: portal_requests portal_requests_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_requests
    ADD CONSTRAINT portal_requests_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: portal_requests portal_requests_service_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_requests
    ADD CONSTRAINT portal_requests_service_id_foreign FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE SET NULL;


--
-- Name: portal_requests portal_requests_taken_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_requests
    ADD CONSTRAINT portal_requests_taken_by_fkey FOREIGN KEY (taken_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: portal_requests portal_requests_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_requests
    ADD CONSTRAINT portal_requests_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: portal_users portal_users_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_users
    ADD CONSTRAINT portal_users_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: portal_users portal_users_invited_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_users
    ADD CONSTRAINT portal_users_invited_by_fkey FOREIGN KEY (invited_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: portal_users portal_users_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_users
    ADD CONSTRAINT portal_users_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: portal_users portal_users_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_users
    ADD CONSTRAINT portal_users_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: projects projects_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: projects projects_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE SET NULL;


--
-- Name: projects projects_contracted_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_contracted_service_id_fkey FOREIGN KEY (contracted_service_id) REFERENCES public.contracted_services(id) ON DELETE SET NULL;


--
-- Name: projects projects_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: projects projects_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: projects projects_manager_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_manager_user_id_fkey FOREIGN KEY (manager_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: projects projects_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: purchase_order_items purchase_order_items_purchase_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_purchase_order_id_fkey FOREIGN KEY (purchase_order_id) REFERENCES public.purchase_orders(id) ON DELETE CASCADE;


--
-- Name: purchase_order_items purchase_order_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: purchase_orders purchase_orders_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_cost_center_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_cost_center_id_fkey FOREIGN KEY (cost_center_id) REFERENCES public.cost_centers(id) ON DELETE RESTRICT;


--
-- Name: purchase_orders purchase_orders_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE RESTRICT;


--
-- Name: purchase_orders purchase_orders_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: purchase_receipts purchase_receipts_purchase_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_receipts
    ADD CONSTRAINT purchase_receipts_purchase_order_id_fkey FOREIGN KEY (purchase_order_id) REFERENCES public.purchase_orders(id) ON DELETE CASCADE;


--
-- Name: purchase_receipts purchase_receipts_received_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_receipts
    ADD CONSTRAINT purchase_receipts_received_by_fkey FOREIGN KEY (received_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: purchase_receipts purchase_receipts_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.purchase_receipts
    ADD CONSTRAINT purchase_receipts_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: quotation_approvals quotation_approvals_approver_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotation_approvals
    ADD CONSTRAINT quotation_approvals_approver_user_id_fkey FOREIGN KEY (approver_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: quotation_approvals quotation_approvals_quotation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotation_approvals
    ADD CONSTRAINT quotation_approvals_quotation_id_fkey FOREIGN KEY (quotation_id) REFERENCES public.quotations(id) ON DELETE CASCADE;


--
-- Name: quotation_approvals quotation_approvals_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotation_approvals
    ADD CONSTRAINT quotation_approvals_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: quotation_items quotation_items_quotation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotation_items
    ADD CONSTRAINT quotation_items_quotation_id_fkey FOREIGN KEY (quotation_id) REFERENCES public.quotations(id) ON DELETE CASCADE;


--
-- Name: quotation_items quotation_items_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotation_items
    ADD CONSTRAINT quotation_items_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE RESTRICT;


--
-- Name: quotation_items quotation_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotation_items
    ADD CONSTRAINT quotation_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: quotations quotations_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotations
    ADD CONSTRAINT quotations_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: quotations quotations_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotations
    ADD CONSTRAINT quotations_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: quotations quotations_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotations
    ADD CONSTRAINT quotations_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: quotations quotations_previous_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotations
    ADD CONSTRAINT quotations_previous_id_foreign FOREIGN KEY (previous_id) REFERENCES public.quotations(id) ON DELETE SET NULL;


--
-- Name: quotations quotations_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotations
    ADD CONSTRAINT quotations_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: receivable_movements receivable_movements_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.receivable_movements
    ADD CONSTRAINT receivable_movements_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: receivable_movements receivable_movements_payment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.receivable_movements
    ADD CONSTRAINT receivable_movements_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payments(id) ON DELETE SET NULL;


--
-- Name: receivable_movements receivable_movements_receivable_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.receivable_movements
    ADD CONSTRAINT receivable_movements_receivable_id_fkey FOREIGN KEY (receivable_id) REFERENCES public.receivables(id) ON DELETE CASCADE;


--
-- Name: receivable_movements receivable_movements_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.receivable_movements
    ADD CONSTRAINT receivable_movements_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: receivables receivables_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.receivables
    ADD CONSTRAINT receivables_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: receivables receivables_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.receivables
    ADD CONSTRAINT receivables_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: receivables receivables_invoice_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.receivables
    ADD CONSTRAINT receivables_invoice_id_fkey FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE RESTRICT;


--
-- Name: receivables receivables_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.receivables
    ADD CONSTRAINT receivables_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: receivables receivables_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.receivables
    ADD CONSTRAINT receivables_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: recurring_service_alerts recurring_service_alerts_recurring_instance_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_alerts
    ADD CONSTRAINT recurring_service_alerts_recurring_instance_id_fkey FOREIGN KEY (recurring_instance_id) REFERENCES public.recurring_service_instances(id) ON DELETE CASCADE;


--
-- Name: recurring_service_alerts recurring_service_alerts_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_alerts
    ADD CONSTRAINT recurring_service_alerts_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: recurring_service_cycles recurring_service_cycles_billable_event_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_cycles
    ADD CONSTRAINT recurring_service_cycles_billable_event_fkey FOREIGN KEY (billable_event_id) REFERENCES public.billable_events(id) ON DELETE SET NULL;


--
-- Name: recurring_service_cycles recurring_service_cycles_recurring_instance_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_cycles
    ADD CONSTRAINT recurring_service_cycles_recurring_instance_id_fkey FOREIGN KEY (recurring_instance_id) REFERENCES public.recurring_service_instances(id) ON DELETE CASCADE;


--
-- Name: recurring_service_cycles recurring_service_cycles_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_cycles
    ADD CONSTRAINT recurring_service_cycles_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: recurring_service_instances recurring_service_instances_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_instances
    ADD CONSTRAINT recurring_service_instances_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: recurring_service_instances recurring_service_instances_contracted_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_instances
    ADD CONSTRAINT recurring_service_instances_contracted_service_id_fkey FOREIGN KEY (contracted_service_id) REFERENCES public.contracted_services(id) ON DELETE RESTRICT;


--
-- Name: recurring_service_instances recurring_service_instances_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_instances
    ADD CONSTRAINT recurring_service_instances_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: recurring_service_instances recurring_service_instances_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_instances
    ADD CONSTRAINT recurring_service_instances_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: recurring_service_instances recurring_service_instances_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_instances
    ADD CONSTRAINT recurring_service_instances_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: recurring_service_prices recurring_service_prices_recurring_instance_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_prices
    ADD CONSTRAINT recurring_service_prices_recurring_instance_id_fkey FOREIGN KEY (recurring_instance_id) REFERENCES public.recurring_service_instances(id) ON DELETE CASCADE;


--
-- Name: recurring_service_prices recurring_service_prices_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_prices
    ADD CONSTRAINT recurring_service_prices_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: refunds refunds_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.refunds
    ADD CONSTRAINT refunds_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: refunds refunds_payment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.refunds
    ADD CONSTRAINT refunds_payment_id_fkey FOREIGN KEY (payment_id) REFERENCES public.payments(id) ON DELETE RESTRICT;


--
-- Name: refunds refunds_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.refunds
    ADD CONSTRAINT refunds_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: report_definitions report_definitions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.report_definitions
    ADD CONSTRAINT report_definitions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: report_exports report_exports_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.report_exports
    ADD CONSTRAINT report_exports_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.documents(id) ON DELETE SET NULL;


--
-- Name: report_exports report_exports_report_definition_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.report_exports
    ADD CONSTRAINT report_exports_report_definition_id_fkey FOREIGN KEY (report_definition_id) REFERENCES public.report_definitions(id) ON DELETE SET NULL;


--
-- Name: report_exports report_exports_requested_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.report_exports
    ADD CONSTRAINT report_exports_requested_by_fkey FOREIGN KEY (requested_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: report_exports report_exports_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.report_exports
    ADD CONSTRAINT report_exports_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: resource_assignments resource_assignments_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.resource_assignments
    ADD CONSTRAINT resource_assignments_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: resource_assignments resource_assignments_resource_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.resource_assignments
    ADD CONSTRAINT resource_assignments_resource_id_fkey FOREIGN KEY (resource_id) REFERENCES public.resources(id) ON DELETE RESTRICT;


--
-- Name: resource_assignments resource_assignments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.resource_assignments
    ADD CONSTRAINT resource_assignments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: resource_assignments resource_assignments_work_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.resource_assignments
    ADD CONSTRAINT resource_assignments_work_order_id_fkey FOREIGN KEY (work_order_id) REFERENCES public.work_orders(id) ON DELETE CASCADE;


--
-- Name: resources resources_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.resources
    ADD CONSTRAINT resources_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: role_permissions role_permissions_permission_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: role_permissions role_permissions_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: role_scopes role_scopes_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.role_scopes
    ADD CONSTRAINT role_scopes_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: role_scopes role_scopes_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.role_scopes
    ADD CONSTRAINT role_scopes_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: roles roles_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: service_categories service_categories_parent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_categories
    ADD CONSTRAINT service_categories_parent_id_fkey FOREIGN KEY (parent_id) REFERENCES public.service_categories(id) ON DELETE SET NULL;


--
-- Name: service_categories service_categories_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_categories
    ADD CONSTRAINT service_categories_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: service_prices service_prices_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_prices
    ADD CONSTRAINT service_prices_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE RESTRICT;


--
-- Name: service_prices service_prices_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_prices
    ADD CONSTRAINT service_prices_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: service_recurring_configs service_recurring_configs_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_recurring_configs
    ADD CONSTRAINT service_recurring_configs_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE RESTRICT;


--
-- Name: service_recurring_configs service_recurring_configs_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_recurring_configs
    ADD CONSTRAINT service_recurring_configs_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: service_service_links service_service_links_source_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_service_links
    ADD CONSTRAINT service_service_links_source_service_id_fkey FOREIGN KEY (source_service_id) REFERENCES public.services(id) ON DELETE CASCADE;


--
-- Name: service_service_links service_service_links_target_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_service_links
    ADD CONSTRAINT service_service_links_target_service_id_fkey FOREIGN KEY (target_service_id) REFERENCES public.services(id) ON DELETE CASCADE;


--
-- Name: service_service_links service_service_links_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_service_links
    ADD CONSTRAINT service_service_links_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: service_template_items service_template_items_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_template_items
    ADD CONSTRAINT service_template_items_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE RESTRICT;


--
-- Name: service_template_items service_template_items_template_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_template_items
    ADD CONSTRAINT service_template_items_template_id_fkey FOREIGN KEY (template_id) REFERENCES public.service_templates(id) ON DELETE CASCADE;


--
-- Name: service_template_items service_template_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_template_items
    ADD CONSTRAINT service_template_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: service_templates service_templates_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.service_templates
    ADD CONSTRAINT service_templates_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: services services_category_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.services
    ADD CONSTRAINT services_category_id_fkey FOREIGN KEY (category_id) REFERENCES public.service_categories(id) ON DELETE SET NULL;


--
-- Name: services services_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.services
    ADD CONSTRAINT services_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: supplier_invoices supplier_invoices_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_invoices
    ADD CONSTRAINT supplier_invoices_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: supplier_invoices supplier_invoices_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_invoices
    ADD CONSTRAINT supplier_invoices_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: supplier_invoices supplier_invoices_cost_center_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_invoices
    ADD CONSTRAINT supplier_invoices_cost_center_id_fkey FOREIGN KEY (cost_center_id) REFERENCES public.cost_centers(id) ON DELETE RESTRICT;


--
-- Name: supplier_invoices supplier_invoices_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_invoices
    ADD CONSTRAINT supplier_invoices_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: supplier_invoices supplier_invoices_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_invoices
    ADD CONSTRAINT supplier_invoices_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: supplier_invoices supplier_invoices_purchase_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_invoices
    ADD CONSTRAINT supplier_invoices_purchase_order_id_fkey FOREIGN KEY (purchase_order_id) REFERENCES public.purchase_orders(id) ON DELETE RESTRICT;


--
-- Name: supplier_invoices supplier_invoices_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_invoices
    ADD CONSTRAINT supplier_invoices_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE RESTRICT;


--
-- Name: supplier_invoices supplier_invoices_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_invoices
    ADD CONSTRAINT supplier_invoices_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: supplier_payment_allocations supplier_payment_allocations_payable_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payment_allocations
    ADD CONSTRAINT supplier_payment_allocations_payable_id_fkey FOREIGN KEY (payable_id) REFERENCES public.payables(id) ON DELETE RESTRICT;


--
-- Name: supplier_payment_allocations supplier_payment_allocations_supplier_payment_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payment_allocations
    ADD CONSTRAINT supplier_payment_allocations_supplier_payment_id_fkey FOREIGN KEY (supplier_payment_id) REFERENCES public.supplier_payments(id) ON DELETE CASCADE;


--
-- Name: supplier_payment_allocations supplier_payment_allocations_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payment_allocations
    ADD CONSTRAINT supplier_payment_allocations_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: supplier_payments supplier_payments_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payments
    ADD CONSTRAINT supplier_payments_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: supplier_payments supplier_payments_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payments
    ADD CONSTRAINT supplier_payments_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: supplier_payments supplier_payments_detraction_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payments
    ADD CONSTRAINT supplier_payments_detraction_account_id_fkey FOREIGN KEY (detraction_account_id) REFERENCES public.financial_accounts(id) ON DELETE RESTRICT;


--
-- Name: supplier_payments supplier_payments_financial_account_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payments
    ADD CONSTRAINT supplier_payments_financial_account_id_fkey FOREIGN KEY (financial_account_id) REFERENCES public.financial_accounts(id) ON DELETE RESTRICT;


--
-- Name: supplier_payments supplier_payments_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payments
    ADD CONSTRAINT supplier_payments_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: supplier_payments supplier_payments_reversed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payments
    ADD CONSTRAINT supplier_payments_reversed_by_fkey FOREIGN KEY (reversed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: supplier_payments supplier_payments_supplier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payments
    ADD CONSTRAINT supplier_payments_supplier_id_fkey FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE RESTRICT;


--
-- Name: supplier_payments supplier_payments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.supplier_payments
    ADD CONSTRAINT supplier_payments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: suppliers suppliers_party_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_party_id_fkey FOREIGN KEY (party_id) REFERENCES public.parties(id) ON DELETE RESTRICT;


--
-- Name: suppliers suppliers_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: support_calendars support_calendars_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_calendars
    ADD CONSTRAINT support_calendars_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: support_calendars support_calendars_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_calendars
    ADD CONSTRAINT support_calendars_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: support_holidays support_holidays_calendar_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_holidays
    ADD CONSTRAINT support_holidays_calendar_id_fkey FOREIGN KEY (calendar_id) REFERENCES public.support_calendars(id) ON DELETE CASCADE;


--
-- Name: support_holidays support_holidays_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_holidays
    ADD CONSTRAINT support_holidays_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: support_holidays support_holidays_removed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_holidays
    ADD CONSTRAINT support_holidays_removed_by_fkey FOREIGN KEY (removed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: support_holidays support_holidays_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_holidays
    ADD CONSTRAINT support_holidays_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: support_sla_runs support_sla_runs_sla_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_sla_runs
    ADD CONSTRAINT support_sla_runs_sla_id_fkey FOREIGN KEY (sla_id) REFERENCES public.support_slas(id) ON DELETE RESTRICT;


--
-- Name: support_sla_runs support_sla_runs_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_sla_runs
    ADD CONSTRAINT support_sla_runs_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: support_sla_runs support_sla_runs_ticket_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_sla_runs
    ADD CONSTRAINT support_sla_runs_ticket_id_fkey FOREIGN KEY (ticket_id) REFERENCES public.support_tickets(id) ON DELETE CASCADE;


--
-- Name: support_slas support_slas_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_slas
    ADD CONSTRAINT support_slas_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: support_ticket_assignments support_ticket_assignments_assigned_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_ticket_assignments
    ADD CONSTRAINT support_ticket_assignments_assigned_user_id_fkey FOREIGN KEY (assigned_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: support_ticket_assignments support_ticket_assignments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_ticket_assignments
    ADD CONSTRAINT support_ticket_assignments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: support_ticket_assignments support_ticket_assignments_ticket_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_ticket_assignments
    ADD CONSTRAINT support_ticket_assignments_ticket_id_fkey FOREIGN KEY (ticket_id) REFERENCES public.support_tickets(id) ON DELETE CASCADE;


--
-- Name: support_ticket_comments support_ticket_comments_author_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_ticket_comments
    ADD CONSTRAINT support_ticket_comments_author_user_id_fkey FOREIGN KEY (author_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: support_ticket_comments support_ticket_comments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_ticket_comments
    ADD CONSTRAINT support_ticket_comments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: support_ticket_comments support_ticket_comments_ticket_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_ticket_comments
    ADD CONSTRAINT support_ticket_comments_ticket_id_fkey FOREIGN KEY (ticket_id) REFERENCES public.support_tickets(id) ON DELETE CASCADE;


--
-- Name: support_ticket_events support_ticket_events_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_ticket_events
    ADD CONSTRAINT support_ticket_events_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: support_ticket_events support_ticket_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_ticket_events
    ADD CONSTRAINT support_ticket_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: support_ticket_events support_ticket_events_ticket_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_ticket_events
    ADD CONSTRAINT support_ticket_events_ticket_id_fkey FOREIGN KEY (ticket_id) REFERENCES public.support_tickets(id) ON DELETE CASCADE;


--
-- Name: support_tickets support_tickets_assigned_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_assigned_user_id_fkey FOREIGN KEY (assigned_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: support_tickets support_tickets_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: support_tickets support_tickets_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE SET NULL;


--
-- Name: support_tickets support_tickets_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: support_tickets support_tickets_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: support_tickets support_tickets_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE SET NULL;


--
-- Name: support_tickets support_tickets_sla_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_sla_id_fkey FOREIGN KEY (sla_id) REFERENCES public.support_slas(id) ON DELETE SET NULL;


--
-- Name: support_tickets support_tickets_team_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_team_id_fkey FOREIGN KEY (team_id) REFERENCES public.teams(id) ON DELETE SET NULL;


--
-- Name: support_tickets support_tickets_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: support_tickets support_tickets_work_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_work_order_id_fkey FOREIGN KEY (work_order_id) REFERENCES public.work_orders(id) ON DELETE SET NULL;


--
-- Name: task_checklist_items task_checklist_items_checklist_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.task_checklist_items
    ADD CONSTRAINT task_checklist_items_checklist_item_id_fkey FOREIGN KEY (checklist_item_id) REFERENCES public.checklist_items(id) ON DELETE RESTRICT;


--
-- Name: task_checklist_items task_checklist_items_completed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.task_checklist_items
    ADD CONSTRAINT task_checklist_items_completed_by_fkey FOREIGN KEY (completed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: task_checklist_items task_checklist_items_task_checklist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.task_checklist_items
    ADD CONSTRAINT task_checklist_items_task_checklist_id_fkey FOREIGN KEY (task_checklist_id) REFERENCES public.task_checklists(id) ON DELETE CASCADE;


--
-- Name: task_checklist_items task_checklist_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.task_checklist_items
    ADD CONSTRAINT task_checklist_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: task_checklists task_checklists_checklist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.task_checklists
    ADD CONSTRAINT task_checklists_checklist_id_fkey FOREIGN KEY (checklist_id) REFERENCES public.checklists(id) ON DELETE RESTRICT;


--
-- Name: task_checklists task_checklists_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.task_checklists
    ADD CONSTRAINT task_checklists_task_id_fkey FOREIGN KEY (task_id) REFERENCES public.tasks(id) ON DELETE CASCADE;


--
-- Name: task_checklists task_checklists_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.task_checklists
    ADD CONSTRAINT task_checklists_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tasks tasks_assigned_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_assigned_user_id_fkey FOREIGN KEY (assigned_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tasks tasks_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tasks tasks_parent_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_parent_task_id_fkey FOREIGN KEY (parent_task_id) REFERENCES public.tasks(id) ON DELETE SET NULL;


--
-- Name: tasks tasks_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: tasks tasks_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tasks tasks_work_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_work_order_id_fkey FOREIGN KEY (work_order_id) REFERENCES public.work_orders(id) ON DELETE CASCADE;


--
-- Name: teams teams_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.teams
    ADD CONSTRAINT teams_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_modules tenant_modules_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_modules
    ADD CONSTRAINT tenant_modules_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_settings tenant_settings_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_settings
    ADD CONSTRAINT tenant_settings_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_status_log tenant_status_log_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_status_log
    ADD CONSTRAINT tenant_status_log_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_status_log tenant_status_log_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_status_log
    ADD CONSTRAINT tenant_status_log_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tenant_subscription_payments tenant_subscription_payments_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_subscription_payments
    ADD CONSTRAINT tenant_subscription_payments_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tenant_subscription_payments tenant_subscription_payments_subscription_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_subscription_payments
    ADD CONSTRAINT tenant_subscription_payments_subscription_id_fkey FOREIGN KEY (subscription_id) REFERENCES public.tenant_subscriptions(id);


--
-- Name: tenant_subscription_payments tenant_subscription_payments_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_subscription_payments
    ADD CONSTRAINT tenant_subscription_payments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_subscriptions tenant_subscriptions_plan_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_subscriptions
    ADD CONSTRAINT tenant_subscriptions_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES public.saas_plans(id) ON DELETE RESTRICT;


--
-- Name: tenant_subscriptions tenant_subscriptions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_subscriptions
    ADD CONSTRAINT tenant_subscriptions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_usage_counters tenant_usage_counters_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_usage_counters
    ADD CONSTRAINT tenant_usage_counters_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_user_branches tenant_user_branches_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_user_branches
    ADD CONSTRAINT tenant_user_branches_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE CASCADE;


--
-- Name: tenant_user_branches tenant_user_branches_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_user_branches
    ADD CONSTRAINT tenant_user_branches_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_user_branches tenant_user_branches_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_user_branches
    ADD CONSTRAINT tenant_user_branches_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: tenant_users tenant_users_team_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_users
    ADD CONSTRAINT tenant_users_team_id_fkey FOREIGN KEY (team_id) REFERENCES public.teams(id) ON DELETE SET NULL;


--
-- Name: tenant_users tenant_users_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_users
    ADD CONSTRAINT tenant_users_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: tenant_users tenant_users_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.tenant_users
    ADD CONSTRAINT tenant_users_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: time_entries time_entries_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.time_entries
    ADD CONSTRAINT time_entries_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: time_entries time_entries_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.time_entries
    ADD CONSTRAINT time_entries_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: time_entries time_entries_employee_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.time_entries
    ADD CONSTRAINT time_entries_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employees(id) ON DELETE SET NULL;


--
-- Name: time_entries time_entries_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.time_entries
    ADD CONSTRAINT time_entries_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: time_entries time_entries_task_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.time_entries
    ADD CONSTRAINT time_entries_task_id_fkey FOREIGN KEY (task_id) REFERENCES public.tasks(id) ON DELETE CASCADE;


--
-- Name: time_entries time_entries_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.time_entries
    ADD CONSTRAINT time_entries_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: time_entries time_entries_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.time_entries
    ADD CONSTRAINT time_entries_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: time_entries time_entries_work_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.time_entries
    ADD CONSTRAINT time_entries_work_order_id_fkey FOREIGN KEY (work_order_id) REFERENCES public.work_orders(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: user_roles user_roles_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: user_roles user_roles_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: warranty_claims warranty_claims_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: warranty_claims warranty_claims_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE SET NULL;


--
-- Name: warranty_claims warranty_claims_contracted_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_contracted_service_id_fkey FOREIGN KEY (contracted_service_id) REFERENCES public.contracted_services(id) ON DELETE RESTRICT;


--
-- Name: warranty_claims warranty_claims_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: warranty_claims warranty_claims_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: warranty_claims warranty_claims_reviewed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_reviewed_by_fkey FOREIGN KEY (reviewed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: warranty_claims warranty_claims_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE SET NULL;


--
-- Name: warranty_claims warranty_claims_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: warranty_claims warranty_claims_ticket_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_ticket_id_fkey FOREIGN KEY (ticket_id) REFERENCES public.support_tickets(id) ON DELETE RESTRICT;


--
-- Name: warranty_claims warranty_claims_warranty_policy_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_warranty_policy_id_fkey FOREIGN KEY (warranty_policy_id) REFERENCES public.warranty_policies(id) ON DELETE SET NULL;


--
-- Name: warranty_policies warranty_policies_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_policies
    ADD CONSTRAINT warranty_policies_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE SET NULL;


--
-- Name: warranty_policies warranty_policies_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_policies
    ADD CONSTRAINT warranty_policies_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: webhook_deliveries webhook_deliveries_endpoint_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.webhook_deliveries
    ADD CONSTRAINT webhook_deliveries_endpoint_id_fkey FOREIGN KEY (endpoint_id) REFERENCES public.webhook_endpoints(id) ON DELETE CASCADE;


--
-- Name: webhook_deliveries webhook_deliveries_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.webhook_deliveries
    ADD CONSTRAINT webhook_deliveries_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.outbox_events(id) ON DELETE SET NULL;


--
-- Name: webhook_deliveries webhook_deliveries_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.webhook_deliveries
    ADD CONSTRAINT webhook_deliveries_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: webhook_endpoints webhook_endpoints_secret_credential_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.webhook_endpoints
    ADD CONSTRAINT webhook_endpoints_secret_credential_id_fkey FOREIGN KEY (secret_credential_id) REFERENCES public.credential_records(id) ON DELETE SET NULL;


--
-- Name: webhook_endpoints webhook_endpoints_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.webhook_endpoints
    ADD CONSTRAINT webhook_endpoints_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: webhook_inbound_events webhook_inbound_events_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.webhook_inbound_events
    ADD CONSTRAINT webhook_inbound_events_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: work_order_checklist_items work_order_checklist_items_done_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_order_checklist_items
    ADD CONSTRAINT work_order_checklist_items_done_by_fkey FOREIGN KEY (done_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: work_order_checklist_items work_order_checklist_items_evidence_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_order_checklist_items
    ADD CONSTRAINT work_order_checklist_items_evidence_document_id_fkey FOREIGN KEY (evidence_document_id) REFERENCES public.documents(id) ON DELETE RESTRICT;


--
-- Name: work_order_checklist_items work_order_checklist_items_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_order_checklist_items
    ADD CONSTRAINT work_order_checklist_items_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: work_order_checklist_items work_order_checklist_items_work_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_order_checklist_items
    ADD CONSTRAINT work_order_checklist_items_work_order_id_fkey FOREIGN KEY (work_order_id) REFERENCES public.work_orders(id) ON DELETE CASCADE;


--
-- Name: work_orders work_orders_assigned_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_assigned_user_id_fkey FOREIGN KEY (assigned_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_branch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_branch_id_fkey FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_checklist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_checklist_id_fkey FOREIGN KEY (checklist_id) REFERENCES public.checklists(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_checklist_reviewed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_checklist_reviewed_by_fkey FOREIGN KEY (checklist_reviewed_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_contracted_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_contracted_service_id_fkey FOREIGN KEY (contracted_service_id) REFERENCES public.contracted_services(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: work_orders work_orders_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_order_id_fkey FOREIGN KEY (order_id) REFERENCES public.orders(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_service_id_fkey FOREIGN KEY (service_id) REFERENCES public.services(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: workflow_definitions workflow_definitions_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.workflow_definitions
    ADD CONSTRAINT workflow_definitions_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: workflow_runs workflow_runs_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.workflow_runs
    ADD CONSTRAINT workflow_runs_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: workflow_runs workflow_runs_workflow_definition_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.workflow_runs
    ADD CONSTRAINT workflow_runs_workflow_definition_id_fkey FOREIGN KEY (workflow_definition_id) REFERENCES public.workflow_definitions(id) ON DELETE RESTRICT;


--
-- Name: acc_accounts; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.acc_accounts ENABLE ROW LEVEL SECURITY;

--
-- Name: acc_fiscal_years; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.acc_fiscal_years ENABLE ROW LEVEL SECURITY;

--
-- Name: acc_journal_entries; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.acc_journal_entries ENABLE ROW LEVEL SECURITY;

--
-- Name: acc_journal_lines; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.acc_journal_lines ENABLE ROW LEVEL SECURITY;

--
-- Name: acc_periods; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.acc_periods ENABLE ROW LEVEL SECURITY;

--
-- Name: advances; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.advances ENABLE ROW LEVEL SECURITY;

--
-- Name: agenda_events; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.agenda_events ENABLE ROW LEVEL SECURITY;

--
-- Name: audit_logs; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.audit_logs ENABLE ROW LEVEL SECURITY;

--
-- Name: bank_movements; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.bank_movements ENABLE ROW LEVEL SECURITY;

--
-- Name: bank_statement_lines; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.bank_statement_lines ENABLE ROW LEVEL SECURITY;

--
-- Name: bank_statements; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.bank_statements ENABLE ROW LEVEL SECURITY;

--
-- Name: bank_transfers; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.bank_transfers ENABLE ROW LEVEL SECURITY;

--
-- Name: billable_events; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.billable_events ENABLE ROW LEVEL SECURITY;

--
-- Name: branches; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.branches ENABLE ROW LEVEL SECURITY;

--
-- Name: budget_lines; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.budget_lines ENABLE ROW LEVEL SECURITY;

--
-- Name: budgets; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.budgets ENABLE ROW LEVEL SECURITY;

--
-- Name: cash_counts; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.cash_counts ENABLE ROW LEVEL SECURITY;

--
-- Name: cash_expense_reclassifications; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.cash_expense_reclassifications ENABLE ROW LEVEL SECURITY;

--
-- Name: cash_expenses; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.cash_expenses ENABLE ROW LEVEL SECURITY;

--
-- Name: checklist_items; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.checklist_items ENABLE ROW LEVEL SECURITY;

--
-- Name: checklists; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.checklists ENABLE ROW LEVEL SECURITY;

--
-- Name: companies; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.companies ENABLE ROW LEVEL SECURITY;

--
-- Name: company_branding; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.company_branding ENABLE ROW LEVEL SECURITY;

--
-- Name: contacts; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.contacts ENABLE ROW LEVEL SECURITY;

--
-- Name: contract_items; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.contract_items ENABLE ROW LEVEL SECURITY;

--
-- Name: contract_signature_requests; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.contract_signature_requests ENABLE ROW LEVEL SECURITY;

--
-- Name: contract_signature_signers; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.contract_signature_signers ENABLE ROW LEVEL SECURITY;

--
-- Name: contracted_services; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.contracted_services ENABLE ROW LEVEL SECURITY;

--
-- Name: contracts; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.contracts ENABLE ROW LEVEL SECURITY;

--
-- Name: cost_centers; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.cost_centers ENABLE ROW LEVEL SECURITY;

--
-- Name: cost_entries; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.cost_entries ENABLE ROW LEVEL SECURITY;

--
-- Name: credential_access_logs; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.credential_access_logs ENABLE ROW LEVEL SECURITY;

--
-- Name: credential_records; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.credential_records ENABLE ROW LEVEL SECURITY;

--
-- Name: credential_versions; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.credential_versions ENABLE ROW LEVEL SECURITY;

--
-- Name: crm_activities; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.crm_activities ENABLE ROW LEVEL SECURITY;

--
-- Name: crm_campaign_members; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.crm_campaign_members ENABLE ROW LEVEL SECURITY;

--
-- Name: crm_campaigns; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.crm_campaigns ENABLE ROW LEVEL SECURITY;

--
-- Name: crm_conversions; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.crm_conversions ENABLE ROW LEVEL SECURITY;

--
-- Name: crm_entity_tags; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.crm_entity_tags ENABLE ROW LEVEL SECURITY;

--
-- Name: crm_leads; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.crm_leads ENABLE ROW LEVEL SECURITY;

--
-- Name: crm_opportunities; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.crm_opportunities ENABLE ROW LEVEL SECURITY;

--
-- Name: crm_pipeline_stages; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.crm_pipeline_stages ENABLE ROW LEVEL SECURITY;

--
-- Name: crm_pipelines; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.crm_pipelines ENABLE ROW LEVEL SECURITY;

--
-- Name: crm_settings; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.crm_settings ENABLE ROW LEVEL SECURITY;

--
-- Name: crm_sources; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.crm_sources ENABLE ROW LEVEL SECURITY;

--
-- Name: crm_tags; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.crm_tags ENABLE ROW LEVEL SECURITY;

--
-- Name: custom_field_definitions; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.custom_field_definitions ENABLE ROW LEVEL SECURITY;

--
-- Name: custom_field_values; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.custom_field_values ENABLE ROW LEVEL SECURITY;

--
-- Name: customers; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.customers ENABLE ROW LEVEL SECURITY;

--
-- Name: dashboard_definitions; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.dashboard_definitions ENABLE ROW LEVEL SECURITY;

--
-- Name: deliverables; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.deliverables ENABLE ROW LEVEL SECURITY;

--
-- Name: document_links; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.document_links ENABLE ROW LEVEL SECURITY;

--
-- Name: document_versions; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.document_versions ENABLE ROW LEVEL SECURITY;

--
-- Name: documents; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.documents ENABLE ROW LEVEL SECURITY;

--
-- Name: employees; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.employees ENABLE ROW LEVEL SECURITY;

--
-- Name: evidences; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.evidences ENABLE ROW LEVEL SECURITY;

--
-- Name: exchange_rates; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.exchange_rates ENABLE ROW LEVEL SECURITY;

--
-- Name: file_objects; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.file_objects ENABLE ROW LEVEL SECURITY;

--
-- Name: financial_accounts; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.financial_accounts ENABLE ROW LEVEL SECURITY;

--
-- Name: fiscal_configurations; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.fiscal_configurations ENABLE ROW LEVEL SECURITY;

--
-- Name: fiscal_documents; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.fiscal_documents ENABLE ROW LEVEL SECURITY;

--
-- Name: fiscal_notes; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.fiscal_notes ENABLE ROW LEVEL SECURITY;

--
-- Name: fiscal_series; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.fiscal_series ENABLE ROW LEVEL SECURITY;

--
-- Name: fiscal_void_requests; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.fiscal_void_requests ENABLE ROW LEVEL SECURITY;

--
-- Name: idempotency_keys; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.idempotency_keys ENABLE ROW LEVEL SECURITY;

--
-- Name: integration_attempts; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.integration_attempts ENABLE ROW LEVEL SECURITY;

--
-- Name: integration_connections; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.integration_connections ENABLE ROW LEVEL SECURITY;

--
-- Name: integration_dead_letters; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.integration_dead_letters ENABLE ROW LEVEL SECURITY;

--
-- Name: invoice_items; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.invoice_items ENABLE ROW LEVEL SECURITY;

--
-- Name: invoices; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.invoices ENABLE ROW LEVEL SECURITY;

--
-- Name: notification_preferences; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.notification_preferences ENABLE ROW LEVEL SECURITY;

--
-- Name: notifications; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.notifications ENABLE ROW LEVEL SECURITY;

--
-- Name: order_items; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.order_items ENABLE ROW LEVEL SECURITY;

--
-- Name: orders; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.orders ENABLE ROW LEVEL SECURITY;

--
-- Name: organizations; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.organizations ENABLE ROW LEVEL SECURITY;

--
-- Name: outbox_events; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.outbox_events ENABLE ROW LEVEL SECURITY;

--
-- Name: parties; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.parties ENABLE ROW LEVEL SECURITY;

--
-- Name: payable_movements; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.payable_movements ENABLE ROW LEVEL SECURITY;

--
-- Name: payables; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.payables ENABLE ROW LEVEL SECURITY;

--
-- Name: payment_allocations; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.payment_allocations ENABLE ROW LEVEL SECURITY;

--
-- Name: payments; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.payments ENABLE ROW LEVEL SECURITY;

--
-- Name: persons; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.persons ENABLE ROW LEVEL SECURITY;

--
-- Name: portal_notices; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.portal_notices ENABLE ROW LEVEL SECURITY;

--
-- Name: portal_notifications; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.portal_notifications ENABLE ROW LEVEL SECURITY;

--
-- Name: portal_requests; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.portal_requests ENABLE ROW LEVEL SECURITY;

--
-- Name: portal_users; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.portal_users ENABLE ROW LEVEL SECURITY;

--
-- Name: projects; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.projects ENABLE ROW LEVEL SECURITY;

--
-- Name: purchase_order_items; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.purchase_order_items ENABLE ROW LEVEL SECURITY;

--
-- Name: purchase_orders; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.purchase_orders ENABLE ROW LEVEL SECURITY;

--
-- Name: purchase_receipts; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.purchase_receipts ENABLE ROW LEVEL SECURITY;

--
-- Name: quotation_approvals; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.quotation_approvals ENABLE ROW LEVEL SECURITY;

--
-- Name: quotation_items; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.quotation_items ENABLE ROW LEVEL SECURITY;

--
-- Name: quotations; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.quotations ENABLE ROW LEVEL SECURITY;

--
-- Name: receivable_movements; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.receivable_movements ENABLE ROW LEVEL SECURITY;

--
-- Name: receivables; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.receivables ENABLE ROW LEVEL SECURITY;

--
-- Name: recurring_service_alerts; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.recurring_service_alerts ENABLE ROW LEVEL SECURITY;

--
-- Name: recurring_service_cycles; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.recurring_service_cycles ENABLE ROW LEVEL SECURITY;

--
-- Name: recurring_service_instances; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.recurring_service_instances ENABLE ROW LEVEL SECURITY;

--
-- Name: recurring_service_prices; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.recurring_service_prices ENABLE ROW LEVEL SECURITY;

--
-- Name: refunds; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.refunds ENABLE ROW LEVEL SECURITY;

--
-- Name: report_definitions; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.report_definitions ENABLE ROW LEVEL SECURITY;

--
-- Name: report_exports; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.report_exports ENABLE ROW LEVEL SECURITY;

--
-- Name: resource_assignments; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.resource_assignments ENABLE ROW LEVEL SECURITY;

--
-- Name: resources; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.resources ENABLE ROW LEVEL SECURITY;

--
-- Name: role_scopes; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.role_scopes ENABLE ROW LEVEL SECURITY;

--
-- Name: roles; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.roles ENABLE ROW LEVEL SECURITY;

--
-- Name: service_categories; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.service_categories ENABLE ROW LEVEL SECURITY;

--
-- Name: service_prices; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.service_prices ENABLE ROW LEVEL SECURITY;

--
-- Name: service_recurring_configs; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.service_recurring_configs ENABLE ROW LEVEL SECURITY;

--
-- Name: service_service_links; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.service_service_links ENABLE ROW LEVEL SECURITY;

--
-- Name: service_template_items; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.service_template_items ENABLE ROW LEVEL SECURITY;

--
-- Name: service_templates; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.service_templates ENABLE ROW LEVEL SECURITY;

--
-- Name: services; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.services ENABLE ROW LEVEL SECURITY;

--
-- Name: supplier_invoices; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.supplier_invoices ENABLE ROW LEVEL SECURITY;

--
-- Name: supplier_payment_allocations; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.supplier_payment_allocations ENABLE ROW LEVEL SECURITY;

--
-- Name: supplier_payments; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.supplier_payments ENABLE ROW LEVEL SECURITY;

--
-- Name: suppliers; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.suppliers ENABLE ROW LEVEL SECURITY;

--
-- Name: support_calendars; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.support_calendars ENABLE ROW LEVEL SECURITY;

--
-- Name: support_holidays; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.support_holidays ENABLE ROW LEVEL SECURITY;

--
-- Name: support_sla_runs; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.support_sla_runs ENABLE ROW LEVEL SECURITY;

--
-- Name: support_slas; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.support_slas ENABLE ROW LEVEL SECURITY;

--
-- Name: support_ticket_assignments; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.support_ticket_assignments ENABLE ROW LEVEL SECURITY;

--
-- Name: support_ticket_comments; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.support_ticket_comments ENABLE ROW LEVEL SECURITY;

--
-- Name: support_ticket_events; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.support_ticket_events ENABLE ROW LEVEL SECURITY;

--
-- Name: support_tickets; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.support_tickets ENABLE ROW LEVEL SECURITY;

--
-- Name: task_checklist_items; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.task_checklist_items ENABLE ROW LEVEL SECURITY;

--
-- Name: task_checklists; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.task_checklists ENABLE ROW LEVEL SECURITY;

--
-- Name: tasks; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.tasks ENABLE ROW LEVEL SECURITY;

--
-- Name: teams; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.teams ENABLE ROW LEVEL SECURITY;

--
-- Name: acc_accounts tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.acc_accounts USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: acc_fiscal_years tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.acc_fiscal_years USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: acc_journal_entries tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.acc_journal_entries USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: acc_journal_lines tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.acc_journal_lines USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: acc_periods tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.acc_periods USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: advances tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.advances USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: agenda_events tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.agenda_events USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: audit_logs tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.audit_logs USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: bank_movements tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.bank_movements USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: bank_statement_lines tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.bank_statement_lines USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: bank_statements tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.bank_statements USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: bank_transfers tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.bank_transfers USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: billable_events tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.billable_events USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: branches tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.branches USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: budget_lines tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.budget_lines USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: budgets tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.budgets USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: cash_counts tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.cash_counts USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: cash_expense_reclassifications tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.cash_expense_reclassifications USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: cash_expenses tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.cash_expenses USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: checklist_items tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.checklist_items USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: checklists tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.checklists USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: companies tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.companies USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: company_branding tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.company_branding USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: contacts tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.contacts USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: contract_items tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.contract_items USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: contract_signature_requests tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.contract_signature_requests USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: contract_signature_signers tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.contract_signature_signers USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: contracted_services tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.contracted_services USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: contracts tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.contracts USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: cost_centers tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.cost_centers USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: cost_entries tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.cost_entries USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: credential_access_logs tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.credential_access_logs USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: credential_records tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.credential_records USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: credential_versions tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.credential_versions USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: crm_activities tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.crm_activities USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: crm_campaign_members tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.crm_campaign_members USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: crm_campaigns tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.crm_campaigns USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: crm_conversions tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.crm_conversions USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: crm_entity_tags tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.crm_entity_tags USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: crm_leads tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.crm_leads USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: crm_opportunities tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.crm_opportunities USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: crm_pipeline_stages tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.crm_pipeline_stages USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: crm_pipelines tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.crm_pipelines USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: crm_settings tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.crm_settings USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: crm_sources tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.crm_sources USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: crm_tags tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.crm_tags USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: custom_field_definitions tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.custom_field_definitions USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: custom_field_values tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.custom_field_values USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: customers tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.customers USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: dashboard_definitions tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.dashboard_definitions USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: deliverables tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.deliverables USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: document_links tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.document_links USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: document_versions tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.document_versions USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: documents tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.documents USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: employees tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.employees USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: evidences tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.evidences USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: exchange_rates tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.exchange_rates USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: file_objects tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.file_objects USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: financial_accounts tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.financial_accounts USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: fiscal_configurations tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.fiscal_configurations USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: fiscal_documents tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.fiscal_documents USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: fiscal_notes tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.fiscal_notes USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: fiscal_series tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.fiscal_series USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: fiscal_void_requests tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.fiscal_void_requests USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: idempotency_keys tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.idempotency_keys USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: integration_attempts tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.integration_attempts USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: integration_connections tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.integration_connections USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: integration_dead_letters tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.integration_dead_letters USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: invoice_items tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.invoice_items USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: invoices tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.invoices USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: notification_preferences tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.notification_preferences USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: notifications tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.notifications USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: order_items tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.order_items USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: orders tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.orders USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: organizations tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.organizations USING ((EXISTS ( SELECT 1
   FROM public.parties p
  WHERE ((p.id = organizations.party_id) AND (p.tenant_id = public.app_tenant_id()))))) WITH CHECK ((EXISTS ( SELECT 1
   FROM public.parties p
  WHERE ((p.id = organizations.party_id) AND (p.tenant_id = public.app_tenant_id())))));


--
-- Name: outbox_events tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.outbox_events USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: parties tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.parties USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: payable_movements tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.payable_movements USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: payables tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.payables USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: payment_allocations tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.payment_allocations USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: payments tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.payments USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: persons tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.persons USING ((EXISTS ( SELECT 1
   FROM public.parties p
  WHERE ((p.id = persons.party_id) AND (p.tenant_id = public.app_tenant_id()))))) WITH CHECK ((EXISTS ( SELECT 1
   FROM public.parties p
  WHERE ((p.id = persons.party_id) AND (p.tenant_id = public.app_tenant_id())))));


--
-- Name: portal_notices tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.portal_notices USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: portal_notifications tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.portal_notifications USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: portal_requests tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.portal_requests USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: portal_users tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.portal_users USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: projects tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.projects USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: purchase_order_items tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.purchase_order_items USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: purchase_orders tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.purchase_orders USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: purchase_receipts tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.purchase_receipts USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: quotation_approvals tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.quotation_approvals USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: quotation_items tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.quotation_items USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: quotations tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.quotations USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: receivable_movements tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.receivable_movements USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: receivables tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.receivables USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: recurring_service_alerts tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.recurring_service_alerts USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: recurring_service_cycles tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.recurring_service_cycles USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: recurring_service_instances tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.recurring_service_instances USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: recurring_service_prices tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.recurring_service_prices USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: refunds tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.refunds USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: report_definitions tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.report_definitions USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: report_exports tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.report_exports USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: resource_assignments tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.resource_assignments USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: resources tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.resources USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: role_scopes tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.role_scopes USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: roles tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.roles USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: service_categories tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.service_categories USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: service_prices tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.service_prices USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: service_recurring_configs tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.service_recurring_configs USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: service_service_links tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.service_service_links USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: service_template_items tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.service_template_items USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: service_templates tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.service_templates USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: services tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.services USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: supplier_invoices tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.supplier_invoices USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: supplier_payment_allocations tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.supplier_payment_allocations USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: supplier_payments tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.supplier_payments USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: suppliers tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.suppliers USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: support_calendars tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.support_calendars USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: support_holidays tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.support_holidays USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: support_sla_runs tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.support_sla_runs USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: support_slas tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.support_slas USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: support_ticket_assignments tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.support_ticket_assignments USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: support_ticket_comments tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.support_ticket_comments USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: support_ticket_events tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.support_ticket_events USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: support_tickets tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.support_tickets USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: task_checklist_items tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.task_checklist_items USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: task_checklists tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.task_checklists USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: tasks tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.tasks USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: teams tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.teams USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: tenant_modules tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.tenant_modules USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: tenant_settings tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.tenant_settings USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: tenant_subscription_payments tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.tenant_subscription_payments USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: tenant_subscriptions tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.tenant_subscriptions USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: tenant_usage_counters tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.tenant_usage_counters USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: tenant_user_branches tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.tenant_user_branches USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: tenant_users tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.tenant_users USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: time_entries tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.time_entries USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: user_roles tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.user_roles USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: warranty_claims tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.warranty_claims USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: warranty_policies tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.warranty_policies USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: webhook_deliveries tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.webhook_deliveries USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: webhook_endpoints tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.webhook_endpoints USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: webhook_inbound_events tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.webhook_inbound_events USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: work_order_checklist_items tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.work_order_checklist_items USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: work_orders tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.work_orders USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: workflow_definitions tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.workflow_definitions USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: workflow_runs tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.workflow_runs USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: tenant_modules; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.tenant_modules ENABLE ROW LEVEL SECURITY;

--
-- Name: tenant_settings; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.tenant_settings ENABLE ROW LEVEL SECURITY;

--
-- Name: tenant_subscription_payments; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.tenant_subscription_payments ENABLE ROW LEVEL SECURITY;

--
-- Name: tenant_subscriptions; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.tenant_subscriptions ENABLE ROW LEVEL SECURITY;

--
-- Name: tenant_usage_counters; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.tenant_usage_counters ENABLE ROW LEVEL SECURITY;

--
-- Name: tenant_user_branches; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.tenant_user_branches ENABLE ROW LEVEL SECURITY;

--
-- Name: tenant_users; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.tenant_users ENABLE ROW LEVEL SECURITY;

--
-- Name: time_entries; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.time_entries ENABLE ROW LEVEL SECURITY;

--
-- Name: user_roles; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.user_roles ENABLE ROW LEVEL SECURITY;

--
-- Name: warranty_claims; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.warranty_claims ENABLE ROW LEVEL SECURITY;

--
-- Name: warranty_policies; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.warranty_policies ENABLE ROW LEVEL SECURITY;

--
-- Name: webhook_deliveries; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.webhook_deliveries ENABLE ROW LEVEL SECURITY;

--
-- Name: webhook_endpoints; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.webhook_endpoints ENABLE ROW LEVEL SECURITY;

--
-- Name: webhook_inbound_events; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.webhook_inbound_events ENABLE ROW LEVEL SECURITY;

--
-- Name: work_order_checklist_items; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.work_order_checklist_items ENABLE ROW LEVEL SECURITY;

--
-- Name: work_orders; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.work_orders ENABLE ROW LEVEL SECURITY;

--
-- Name: workflow_definitions; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.workflow_definitions ENABLE ROW LEVEL SECURITY;

--
-- Name: workflow_runs; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.workflow_runs ENABLE ROW LEVEL SECURITY;

--
-- Name: SCHEMA public; Type: ACL; Schema: -; Owner: pg_database_owner
--

GRANT USAGE ON SCHEMA public TO erp_app;
GRANT USAGE ON SCHEMA public TO erp_platform;


--
-- Name: FUNCTION platform_dashboard(p_soon_days integer); Type: ACL; Schema: public; Owner: erp_user
--

REVOKE ALL ON FUNCTION public.platform_dashboard(p_soon_days integer) FROM PUBLIC;
GRANT ALL ON FUNCTION public.platform_dashboard(p_soon_days integer) TO erp_platform;


--
-- Name: FUNCTION platform_subscriptions(); Type: ACL; Schema: public; Owner: erp_user
--

REVOKE ALL ON FUNCTION public.platform_subscriptions() FROM PUBLIC;
GRANT ALL ON FUNCTION public.platform_subscriptions() TO erp_platform;


--
-- Name: FUNCTION platform_tenant(p_tenant uuid); Type: ACL; Schema: public; Owner: erp_user
--

REVOKE ALL ON FUNCTION public.platform_tenant(p_tenant uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.platform_tenant(p_tenant uuid) TO erp_platform;


--
-- Name: FUNCTION platform_tenant_rows(p_tenant uuid); Type: ACL; Schema: public; Owner: erp_user
--

REVOKE ALL ON FUNCTION public.platform_tenant_rows(p_tenant uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.platform_tenant_rows(p_tenant uuid) TO erp_platform;


--
-- Name: FUNCTION platform_tenants(); Type: ACL; Schema: public; Owner: erp_user
--

REVOKE ALL ON FUNCTION public.platform_tenants() FROM PUBLIC;
GRANT ALL ON FUNCTION public.platform_tenants() TO erp_platform;


--
-- Name: FUNCTION portal_membership_for_user(p_user_id uuid); Type: ACL; Schema: public; Owner: erp_user
--

REVOKE ALL ON FUNCTION public.portal_membership_for_user(p_user_id uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.portal_membership_for_user(p_user_id uuid) TO erp_app;


--
-- Name: FUNCTION resolve_active_tenant_for_user(p_user_id uuid); Type: ACL; Schema: public; Owner: erp_user
--

REVOKE ALL ON FUNCTION public.resolve_active_tenant_for_user(p_user_id uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.resolve_active_tenant_for_user(p_user_id uuid) TO erp_app;


--
-- Name: FUNCTION resolve_portal_tenant_for_user(p_user_id uuid); Type: ACL; Schema: public; Owner: erp_user
--

REVOKE ALL ON FUNCTION public.resolve_portal_tenant_for_user(p_user_id uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.resolve_portal_tenant_for_user(p_user_id uuid) TO erp_app;


--
-- Name: TABLE companies; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.companies TO erp_app;


--
-- Name: FUNCTION superadmin_companies(p_user_id uuid); Type: ACL; Schema: public; Owner: erp_user
--

REVOKE ALL ON FUNCTION public.superadmin_companies(p_user_id uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.superadmin_companies(p_user_id uuid) TO erp_app;


--
-- Name: TABLE tenant_subscriptions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.tenant_subscriptions TO erp_app;


--
-- Name: FUNCTION superadmin_tenant_subscriptions(p_user_id uuid); Type: ACL; Schema: public; Owner: erp_user
--

REVOKE ALL ON FUNCTION public.superadmin_tenant_subscriptions(p_user_id uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.superadmin_tenant_subscriptions(p_user_id uuid) TO erp_app;


--
-- Name: TABLE acc_accounts; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.acc_accounts TO erp_app;


--
-- Name: TABLE acc_fiscal_years; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.acc_fiscal_years TO erp_app;


--
-- Name: TABLE acc_journal_entries; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.acc_journal_entries TO erp_app;


--
-- Name: TABLE acc_journal_lines; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.acc_journal_lines TO erp_app;


--
-- Name: TABLE acc_periods; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.acc_periods TO erp_app;


--
-- Name: TABLE advances; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.advances TO erp_app;


--
-- Name: TABLE agenda_events; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.agenda_events TO erp_app;


--
-- Name: TABLE audit_logs; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.audit_logs TO erp_app;


--
-- Name: TABLE bank_movements; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.bank_movements TO erp_app;


--
-- Name: TABLE bank_statement_lines; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.bank_statement_lines TO erp_app;


--
-- Name: TABLE bank_statements; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.bank_statements TO erp_app;


--
-- Name: TABLE bank_transfers; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.bank_transfers TO erp_app;


--
-- Name: TABLE billable_events; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.billable_events TO erp_app;


--
-- Name: TABLE branches; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.branches TO erp_app;


--
-- Name: TABLE budget_lines; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.budget_lines TO erp_app;


--
-- Name: TABLE budgets; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.budgets TO erp_app;


--
-- Name: TABLE cache; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.cache TO erp_app;


--
-- Name: TABLE cache_locks; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.cache_locks TO erp_app;


--
-- Name: TABLE cash_counts; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.cash_counts TO erp_app;


--
-- Name: TABLE cash_expense_reclassifications; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.cash_expense_reclassifications TO erp_app;


--
-- Name: COLUMN cash_expense_reclassifications.new_entry_id; Type: ACL; Schema: public; Owner: erp_user
--

GRANT UPDATE(new_entry_id) ON TABLE public.cash_expense_reclassifications TO erp_app;


--
-- Name: TABLE cash_expenses; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.cash_expenses TO erp_app;


--
-- Name: COLUMN cash_expenses.cost_center; Type: ACL; Schema: public; Owner: erp_user
--

GRANT UPDATE(cost_center) ON TABLE public.cash_expenses TO erp_app;


--
-- Name: COLUMN cash_expenses.expense_account; Type: ACL; Schema: public; Owner: erp_user
--

GRANT UPDATE(expense_account) ON TABLE public.cash_expenses TO erp_app;


--
-- Name: COLUMN cash_expenses.cost_center_id; Type: ACL; Schema: public; Owner: erp_user
--

GRANT UPDATE(cost_center_id) ON TABLE public.cash_expenses TO erp_app;


--
-- Name: TABLE checklist_items; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.checklist_items TO erp_app;


--
-- Name: TABLE checklists; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.checklists TO erp_app;


--
-- Name: TABLE company_branding; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.company_branding TO erp_app;


--
-- Name: TABLE contacts; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.contacts TO erp_app;


--
-- Name: TABLE contract_items; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.contract_items TO erp_app;


--
-- Name: TABLE contract_signature_requests; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.contract_signature_requests TO erp_app;


--
-- Name: TABLE contract_signature_signers; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.contract_signature_signers TO erp_app;


--
-- Name: TABLE contracted_services; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.contracted_services TO erp_app;


--
-- Name: TABLE contracts; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.contracts TO erp_app;


--
-- Name: TABLE cost_centers; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.cost_centers TO erp_app;


--
-- Name: TABLE cost_entries; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.cost_entries TO erp_app;


--
-- Name: TABLE credential_access_logs; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.credential_access_logs TO erp_app;


--
-- Name: TABLE credential_records; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.credential_records TO erp_app;


--
-- Name: TABLE credential_versions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.credential_versions TO erp_app;


--
-- Name: TABLE crm_activities; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.crm_activities TO erp_app;


--
-- Name: TABLE crm_campaign_members; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.crm_campaign_members TO erp_app;


--
-- Name: TABLE crm_campaigns; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.crm_campaigns TO erp_app;


--
-- Name: TABLE crm_conversions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.crm_conversions TO erp_app;


--
-- Name: TABLE crm_entity_tags; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.crm_entity_tags TO erp_app;


--
-- Name: TABLE crm_leads; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.crm_leads TO erp_app;


--
-- Name: TABLE crm_opportunities; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.crm_opportunities TO erp_app;


--
-- Name: TABLE crm_pipeline_stages; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.crm_pipeline_stages TO erp_app;


--
-- Name: TABLE crm_pipelines; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.crm_pipelines TO erp_app;


--
-- Name: TABLE crm_settings; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.crm_settings TO erp_app;


--
-- Name: TABLE crm_sources; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.crm_sources TO erp_app;


--
-- Name: TABLE crm_tags; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.crm_tags TO erp_app;


--
-- Name: TABLE custom_field_definitions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.custom_field_definitions TO erp_app;


--
-- Name: TABLE custom_field_values; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.custom_field_values TO erp_app;


--
-- Name: TABLE customers; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.customers TO erp_app;


--
-- Name: TABLE dashboard_definitions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.dashboard_definitions TO erp_app;


--
-- Name: TABLE deliverables; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.deliverables TO erp_app;


--
-- Name: TABLE document_links; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.document_links TO erp_app;


--
-- Name: TABLE document_versions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.document_versions TO erp_app;


--
-- Name: TABLE documents; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.documents TO erp_app;


--
-- Name: TABLE employees; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.employees TO erp_app;


--
-- Name: TABLE evidences; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.evidences TO erp_app;


--
-- Name: TABLE exchange_rates; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.exchange_rates TO erp_app;


--
-- Name: TABLE failed_jobs; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.failed_jobs TO erp_app;


--
-- Name: SEQUENCE failed_jobs_id_seq; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,USAGE ON SEQUENCE public.failed_jobs_id_seq TO erp_app;


--
-- Name: TABLE file_objects; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.file_objects TO erp_app;


--
-- Name: TABLE financial_accounts; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.financial_accounts TO erp_app;


--
-- Name: TABLE fiscal_configurations; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.fiscal_configurations TO erp_app;


--
-- Name: TABLE fiscal_documents; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.fiscal_documents TO erp_app;


--
-- Name: TABLE fiscal_notes; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.fiscal_notes TO erp_app;


--
-- Name: TABLE fiscal_series; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.fiscal_series TO erp_app;


--
-- Name: TABLE fiscal_void_requests; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.fiscal_void_requests TO erp_app;


--
-- Name: TABLE idempotency_keys; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.idempotency_keys TO erp_app;


--
-- Name: TABLE integration_attempts; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.integration_attempts TO erp_app;


--
-- Name: TABLE integration_connections; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.integration_connections TO erp_app;


--
-- Name: TABLE integration_dead_letters; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.integration_dead_letters TO erp_app;


--
-- Name: TABLE invoice_items; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.invoice_items TO erp_app;


--
-- Name: TABLE invoices; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.invoices TO erp_app;


--
-- Name: TABLE job_batches; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.job_batches TO erp_app;


--
-- Name: TABLE jobs; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.jobs TO erp_app;


--
-- Name: SEQUENCE jobs_id_seq; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,USAGE ON SEQUENCE public.jobs_id_seq TO erp_app;


--
-- Name: TABLE migrations; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.migrations TO erp_app;


--
-- Name: SEQUENCE migrations_id_seq; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,USAGE ON SEQUENCE public.migrations_id_seq TO erp_app;


--
-- Name: TABLE module_catalog; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.module_catalog TO erp_app;
GRANT SELECT ON TABLE public.module_catalog TO erp_platform;


--
-- Name: TABLE notification_preferences; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.notification_preferences TO erp_app;


--
-- Name: TABLE notifications; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.notifications TO erp_app;


--
-- Name: COLUMN notifications.read_at; Type: ACL; Schema: public; Owner: erp_user
--

GRANT UPDATE(read_at) ON TABLE public.notifications TO erp_app;


--
-- Name: TABLE order_items; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.order_items TO erp_app;


--
-- Name: TABLE orders; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.orders TO erp_app;


--
-- Name: TABLE organizations; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.organizations TO erp_app;


--
-- Name: TABLE outbox_events; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.outbox_events TO erp_app;


--
-- Name: TABLE parties; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.parties TO erp_app;


--
-- Name: TABLE payable_movements; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.payable_movements TO erp_app;


--
-- Name: TABLE payables; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.payables TO erp_app;


--
-- Name: TABLE payment_allocations; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.payment_allocations TO erp_app;


--
-- Name: TABLE payments; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.payments TO erp_app;


--
-- Name: TABLE permissions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT ON TABLE public.permissions TO erp_app;


--
-- Name: TABLE personal_access_tokens; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.personal_access_tokens TO erp_app;


--
-- Name: SEQUENCE personal_access_tokens_id_seq; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,USAGE ON SEQUENCE public.personal_access_tokens_id_seq TO erp_app;


--
-- Name: TABLE persons; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.persons TO erp_app;


--
-- Name: TABLE platform_audit_logs; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.platform_audit_logs TO erp_app;
GRANT INSERT ON TABLE public.platform_audit_logs TO erp_platform;


--
-- Name: TABLE users; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.users TO erp_app;


--
-- Name: TABLE platform_audit_view; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.platform_audit_view TO erp_app;
GRANT SELECT ON TABLE public.platform_audit_view TO erp_platform;


--
-- Name: TABLE platform_settings; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.platform_settings TO erp_platform;
GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.platform_settings TO erp_console;


--
-- Name: TABLE portal_notices; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.portal_notices TO erp_app;


--
-- Name: TABLE portal_notifications; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.portal_notifications TO erp_app;


--
-- Name: TABLE portal_requests; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.portal_requests TO erp_app;


--
-- Name: TABLE portal_users; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.portal_users TO erp_app;


--
-- Name: TABLE projects; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.projects TO erp_app;


--
-- Name: TABLE purchase_order_items; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.purchase_order_items TO erp_app;


--
-- Name: TABLE purchase_orders; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.purchase_orders TO erp_app;


--
-- Name: TABLE purchase_receipts; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.purchase_receipts TO erp_app;


--
-- Name: TABLE quotation_approvals; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.quotation_approvals TO erp_app;


--
-- Name: TABLE quotation_items; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.quotation_items TO erp_app;


--
-- Name: TABLE quotations; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.quotations TO erp_app;


--
-- Name: TABLE receivable_movements; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.receivable_movements TO erp_app;


--
-- Name: TABLE receivables; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.receivables TO erp_app;


--
-- Name: TABLE recurring_service_alerts; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.recurring_service_alerts TO erp_app;


--
-- Name: TABLE recurring_service_cycles; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.recurring_service_cycles TO erp_app;


--
-- Name: TABLE recurring_service_instances; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.recurring_service_instances TO erp_app;


--
-- Name: TABLE recurring_service_prices; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.recurring_service_prices TO erp_app;


--
-- Name: TABLE refunds; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.refunds TO erp_app;


--
-- Name: TABLE report_definitions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.report_definitions TO erp_app;


--
-- Name: TABLE report_exports; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.report_exports TO erp_app;


--
-- Name: TABLE resource_assignments; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.resource_assignments TO erp_app;


--
-- Name: TABLE resources; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.resources TO erp_app;


--
-- Name: TABLE role_permissions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.role_permissions TO erp_app;


--
-- Name: TABLE role_scopes; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.role_scopes TO erp_app;


--
-- Name: TABLE roles; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.roles TO erp_app;


--
-- Name: TABLE saas_plans; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.saas_plans TO erp_app;
GRANT SELECT ON TABLE public.saas_plans TO erp_platform;


--
-- Name: TABLE service_categories; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.service_categories TO erp_app;


--
-- Name: TABLE service_prices; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.service_prices TO erp_app;


--
-- Name: TABLE service_recurring_configs; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.service_recurring_configs TO erp_app;


--
-- Name: TABLE service_service_links; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.service_service_links TO erp_app;


--
-- Name: TABLE service_template_items; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.service_template_items TO erp_app;


--
-- Name: TABLE service_templates; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.service_templates TO erp_app;


--
-- Name: TABLE services; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.services TO erp_app;


--
-- Name: TABLE supplier_invoices; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.supplier_invoices TO erp_app;


--
-- Name: TABLE supplier_payment_allocations; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.supplier_payment_allocations TO erp_app;


--
-- Name: TABLE supplier_payments; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.supplier_payments TO erp_app;


--
-- Name: COLUMN supplier_payments.status; Type: ACL; Schema: public; Owner: erp_user
--

GRANT UPDATE(status) ON TABLE public.supplier_payments TO erp_app;


--
-- Name: COLUMN supplier_payments.reversed_at; Type: ACL; Schema: public; Owner: erp_user
--

GRANT UPDATE(reversed_at) ON TABLE public.supplier_payments TO erp_app;


--
-- Name: COLUMN supplier_payments.reversed_by; Type: ACL; Schema: public; Owner: erp_user
--

GRANT UPDATE(reversed_by) ON TABLE public.supplier_payments TO erp_app;


--
-- Name: COLUMN supplier_payments.reversal_reason; Type: ACL; Schema: public; Owner: erp_user
--

GRANT UPDATE(reversal_reason) ON TABLE public.supplier_payments TO erp_app;


--
-- Name: TABLE suppliers; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.suppliers TO erp_app;


--
-- Name: TABLE support_calendars; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.support_calendars TO erp_app;


--
-- Name: TABLE support_holidays; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.support_holidays TO erp_app;


--
-- Name: TABLE support_sla_runs; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.support_sla_runs TO erp_app;


--
-- Name: TABLE support_slas; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.support_slas TO erp_app;


--
-- Name: TABLE support_ticket_assignments; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.support_ticket_assignments TO erp_app;


--
-- Name: TABLE support_ticket_comments; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.support_ticket_comments TO erp_app;


--
-- Name: TABLE support_ticket_events; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.support_ticket_events TO erp_app;


--
-- Name: TABLE support_tickets; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.support_tickets TO erp_app;


--
-- Name: TABLE task_checklist_items; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.task_checklist_items TO erp_app;


--
-- Name: TABLE task_checklists; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.task_checklists TO erp_app;


--
-- Name: TABLE tasks; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.tasks TO erp_app;


--
-- Name: TABLE teams; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.teams TO erp_app;


--
-- Name: TABLE tenant_modules; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.tenant_modules TO erp_app;


--
-- Name: TABLE tenant_settings; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.tenant_settings TO erp_app;


--
-- Name: TABLE tenant_status_log; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT ON TABLE public.tenant_status_log TO erp_platform;
GRANT SELECT,INSERT ON TABLE public.tenant_status_log TO erp_app;


--
-- Name: TABLE tenant_subscription_payments; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT ON TABLE public.tenant_subscription_payments TO erp_app;


--
-- Name: TABLE tenant_usage_counters; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.tenant_usage_counters TO erp_app;


--
-- Name: TABLE tenant_user_branches; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE ON TABLE public.tenant_user_branches TO erp_app;


--
-- Name: TABLE tenant_users; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.tenant_users TO erp_app;


--
-- Name: TABLE tenants; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.tenants TO erp_app;


--
-- Name: TABLE time_entries; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.time_entries TO erp_app;


--
-- Name: TABLE user_roles; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.user_roles TO erp_app;


--
-- Name: TABLE warranty_claims; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.warranty_claims TO erp_app;


--
-- Name: TABLE warranty_policies; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.warranty_policies TO erp_app;


--
-- Name: TABLE webhook_deliveries; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.webhook_deliveries TO erp_app;


--
-- Name: TABLE webhook_endpoints; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.webhook_endpoints TO erp_app;


--
-- Name: TABLE webhook_inbound_events; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.webhook_inbound_events TO erp_app;


--
-- Name: TABLE work_order_checklist_items; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,UPDATE ON TABLE public.work_order_checklist_items TO erp_app;


--
-- Name: TABLE work_orders; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.work_orders TO erp_app;


--
-- Name: TABLE workflow_definitions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.workflow_definitions TO erp_app;


--
-- Name: TABLE workflow_runs; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.workflow_runs TO erp_app;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: public; Owner: erp_user
--

ALTER DEFAULT PRIVILEGES FOR ROLE erp_user IN SCHEMA public GRANT SELECT,INSERT,DELETE,UPDATE ON TABLES TO erp_app;


--
-- PostgreSQL database dump complete
--


--
-- PostgreSQL database dump
--


-- Dumped from database version 17.11 (Debian 17.11-1.pgdg13+2)
-- Dumped by pg_dump version 17.11 (Debian 17.11-1.pgdg13+2)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: erp_user
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000001_create_cache_table	1
2	0001_01_01_000002_create_jobs_table	1
4	2026_09_08_085544_create_personal_access_tokens_table	2
5	2026_09_14_023821_create_custom_field_definitions_table	3
6	2026_09_14_023943_create_custom_field_values_table	3
7	2026_09_17_060443_add_preferred_currency_to_customers_table	4
8	2026_09_17_070000_make_customer_credit_terms_nullable	5
9	2026_09_17_114740_change_payment_terms_days_nullable_in_customers_table	6
10	2026_09_18_000000_add_contact_name_and_country_code_to_contacts_table	7
11	2026_09_27_100000_add_pricing_and_info_fields_to_saas_plans_table	8
12	2026_09_27_100100_add_support_fields_to_saas_plans_table	8
13	2026_09_27_100200_create_module_catalog_table	8
14	2026_09_27_100300_add_trigger_and_grants_to_module_catalog_table	8
15	2026_09_28_074201_add_is_super_admin_to_users_table	9
16	2026_10_01_144819_add_columns_to_saas_plans_table	10
17	2026_10_04_000000_drop_requires_payment_upon_trial_from_saas_plans_table	11
18	2026_10_04_000100_create_superadmin_cross_tenant_functions	12
19	2026_10_05_000000_keep_super_admins_out_of_tenants	13
20	2026_10_05_000100_create_platform_audit_logs_table	14
21	2026_10_05_000200_add_permission_prefixes_to_module_catalog_table	15
22	2026_10_05_000300_add_commercial_fields_to_services_and_quotations	16
23	2026_10_05_000400_create_exchange_rates_table	17
24	2026_10_05_000500_add_quotation_approval_permission	18
25	2026_10_05_000600_grant_order_permissions_with_contracts	19
26	2026_10_07_000000_add_sla_level_and_approval_to_contracts	20
27	2026_10_07_000100_add_notes_and_owner_to_orders	21
28	2026_10_07_000200_portal_requests_inbox	22
29	2026_10_08_000000_harden_isolation_audit_and_conversions	23
30	2026_10_08_000100_add_lock_version_to_editable_records	24
31	2026_10_08_000200_add_access_scope_to_roles	25
32	2026_10_08_000300_add_create_permission_to_contracts_and_orders	26
33	2026_10_08_000400_crm_leads_and_pipeline	27
34	2026_10_08_000500_let_sales_manage_identities	28
35	2026_10_08_000600_separate_delete_in_identities	29
36	2026_10_08_000700_add_export_permissions	30
37	2026_10_08_000800_profile_sessions_and_notification_preferences	31
38	2026_10_08_000900_mark_mfa_on_sessions_already_open	32
39	2026_10_08_001000_additional_branches_for_users	33
40	2026_10_08_001100_audit_screen_permissions	34
41	2026_10_09_000100_crm_activities_campaigns_and_scoring	35
42	2026_10_09_000200_score_existing_leads	36
43	2026_10_12_000100_operations_contracted_services_and_work_orders	37
44	2026_10_13_000100_operations_projects_hours_and_costs	38
45	2026_10_13_000200_separate_task_management_from_projects	39
46	2026_10_13_000300_deliverable_document_file	40
47	2026_10_13_000400_operations_agenda	41
48	2026_10_13_000500_field_app_location_and_evidence	42
49	2026_10_13_000600_finance_billing_receivables_and_payments	43
50	2026_10_13_000700_finance_recurring_cycles	44
51	2026_10_13_000800_finance_banks_and_cash	45
52	2026_10_13_000900_finance_electronic_invoicing	46
53	2026_10_13_001000_purchasing_orders_supplier_invoices_and_payables	47
54	2026_10_14_000100_accounting_journal_periods_and_budgets	48
55	2026_10_14_000200_treasury_cash_centers_statement_files_and_transfers	49
56	2026_10_14_000300_separate_supplier_payment_permission	50
57	2026_10_14_000400_contract_cascades_to_contracted_services	51
58	2026_10_14_000500_checklist_templates_screen	52
59	2026_10_14_000600_accounting_year_close	53
60	2026_10_14_000700_cash_reclassification_and_supplier_payment_reversal	54
61	2026_10_15_000100_support_tickets_sla_and_warranty	55
62	2026_10_22_000100_customer_portal	56
63	2026_10_22_000200_contract_signatures	57
64	2026_10_22_000300_documents_module	58
67	2026_10_22_000400_platform_console_role_and_dashboard	59
70	2026_10_23_000100_platform_console_tenants	60
71	2026_10_23_000200_module_catalog_matches_erp_menu	61
72	2026_10_23_000300_in_app_notifications	62
73	2026_10_23_000400_company_branding	63
74	2026_10_27_000100_credit_limit_permission	64
75	2026_10_29_230000_create_platform_settings_table	65
76	2026_10_31_000100_subscription_payments_and_platform_settings_access	66
77	2026_11_01_000100_tenant_login_page	67
78	2026_11_01_000200_credential_vault_screen	68
79	2026_11_01_000300_reports_permissions	69
\.


--
-- Data for Name: module_catalog; Type: TABLE DATA; Schema: public; Owner: erp_user
--

COPY public.module_catalog (id, code, name, description, category, is_core, active, display_order, created_at, updated_at, permission_prefixes) FROM stdin;
d0086684-0d29-4a7e-bebe-7c19e6ff2eed	PROYECTOS	Proyectos	Proyectos, tareas y entregables	OPERACIONES	f	t	6	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["projects", "tasks", "deliverables", "contracted_services"]
e9eb34d0-a415-44ca-9ef7-f338099d1858	TIMESHEETS	Horas y costos	Horas trabajadas y costos	OPERACIONES	f	t	7	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["operations", "time_entries", "cost_entries"]
6a817634-48d9-41ff-9a86-11550cef4844	AGENDA	Agenda	Citas y programación	OPERACIONES	f	t	8	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["agenda"]
9fd3acce-2191-49f9-8d77-0254bf5a4fd6	FINANZAS	Finanzas	Por facturar, comprobantes, cuentas por cobrar, cobros, recurrencia, bancos y caja, facturación electrónica y tipo de cambio	FINANZAS	f	t	9	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["billing", "finance", "receivables", "payments", "fiscal", "recurring", "treasury"]
977f2328-7bd2-4c47-bc70-e94bd679628c	COMPRAS	Compras	Órdenes de compra, comprobantes de proveedor y cuentas por pagar	COMPRAS	f	t	10	2026-10-23 00:59:03+00	2026-10-23 00:59:03+00	["purchases", "payables"]
6d351817-e143-4c16-ad05-d16ec24f7b43	CONTABILIDAD	Contabilidad	Libro diario, mayor y balance, estados financieros, plan de cuentas, centros de costo y presupuestos	CONTABILIDAD	f	t	11	2026-10-23 00:59:03+00	2026-10-23 00:59:03+00	["accounting", "budgets"]
1afceb32-14ea-4dac-9bdb-0112f2614f92	SOPORTE	Soporte	Tickets y SLA, garantías y calendario laboral	SOPORTE	f	t	12	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["tickets", "warranty", "sla"]
88706a66-8602-4361-a363-00dfb7045318	CLIENTES	Clientes y actores	Clientes, proveedores, empleados y contactos	CORE	t	t	1	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["core"]
dd274508-76e7-4643-a09b-97bb9fc60342	SERVICIOS	Servicios	Catálogo de servicios y precios	COMERCIAL	f	t	2	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["services"]
b58612ee-af59-409a-aa8d-7f43b924eede	COTIZACIONES	Cotizaciones	Cotizaciones y solicitudes del portal	COMERCIAL	f	t	3	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["quotations", "portal"]
f37e2815-31a3-45ee-bc9e-a58b90710d22	CONTRATOS	Contratos	Contratos y órdenes	COMERCIAL	f	t	4	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["contracts", "orders"]
74ab6560-a68f-492b-b8ac-92ff3fb59091	ORDENES_TRABAJO	Órdenes de trabajo	Órdenes de trabajo, servicios contratados, checklists y app del técnico	OPERACIONES	f	t	5	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["operations", "work_orders", "contracted_services", "checklists"]
c0441528-3359-4834-bf39-1b461fca73b8	CRM	CRM	Leads, pipeline, actividades, campañas y puntuación	CRM	f	t	13	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["leads", "opportunities", "activities", "campaigns", "scoring"]
50cefc7d-6700-44dd-afff-9eecd17f2954	PORTAL	Portal de clientes	Acceso externo para los clientes: cotizaciones, servicios, documentos, facturas, solicitudes y soporte.	PORTAL	f	t	14	2026-10-22 16:35:29+00	2026-10-23 00:59:03+00	["portal_users"]
e950750e-b0fa-44f5-bfec-2e48ab355023	DOCUMENTOS	Documentos	Gestión documental con versiones	CONTROL	f	t	15	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["documents"]
af8c6b24-eb34-4720-a4e0-e7b654ba9012	PROVEEDORES	Proveedores	Gestión de proveedores	CORE	f	f	99	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["core"]
f8734199-4c53-4599-a065-fb0eb738676b	EMPLEADOS	Empleados	Gestión de empleados	CORE	f	f	99	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["core"]
1e4332c6-6e65-4551-bced-4f7b30809824	TAREAS	Tareas	Actividades y seguimiento	CORE	f	f	99	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["operations"]
4075e948-b95b-427a-9ce9-b3fefd886a30	CHECKLISTS	Checklists	Listas de verificación	CORE	f	f	99	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["operations"]
57396cb2-f633-4670-8007-537e0c4a6958	RECURSOS	Recursos	Personas y recursos	CORE	f	f	99	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["operations"]
54af5c4d-bb80-4fcd-b9f9-b3ebd2545ea8	ENTREGABLES	Entregables	Control de entregas	CORE	f	f	99	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	[]
a05e0c34-3a29-446a-8961-07bfdaa9869b	FACTURACION_ELECTRONICA	Facturación electrónica	Facturación / SUNAT	FINANZAS_FACTURACION	f	f	99	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["billing"]
d49b65d8-5553-4bd4-b41b-45ccc995bf5b	PAGOS_COBRANZAS	Pagos y cobranzas	Gestión de pagos	FINANZAS_FACTURACION	f	f	99	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["billing"]
97c75295-123b-4816-b0c0-6a50b081420b	RENTABILIDAD	Rentabilidad	Costos y margen	FINANZAS_FACTURACION	f	f	99	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	[]
291bb136-32f7-4112-be46-a24041ced2ae	TICKETS	Tickets	Gestión de tickets de soporte	SOPORTE_ATENCION_CLIENTE	f	f	99	2026-10-05 15:32:17+00	2026-10-23 00:59:03+00	["tickets"]
\.


--
-- Data for Name: permissions; Type: TABLE DATA; Schema: public; Owner: erp_user
--

COPY public.permissions (id, code, name, description, created_at) FROM stdin;
3a3f7c4c-c7ab-4dab-a21e-76322d6599c0	system.admin	Administración del sistema	\N	2026-09-07 16:33:36.858468+00
314a37e4-01bf-44ad-a48f-31f9ae0bb276	tenant.manage	Gestionar tenant	\N	2026-09-07 16:33:36.858468+00
df43e60c-50bc-4214-9dd0-5a61d82f5e52	users.view	Ver usuarios	\N	2026-09-07 16:33:36.858468+00
3dd325b6-96f3-4ca3-a9c2-a2ad49158254	users.manage	Gestionar usuarios	\N	2026-09-07 16:33:36.858468+00
50726613-3d9b-49b6-ae50-4d88738ffdc6	roles.view	Ver roles	\N	2026-09-07 16:33:36.858468+00
c89c9dec-efd2-4be9-a010-3363c054b2c2	roles.manage	Gestionar roles	\N	2026-09-07 16:33:36.858468+00
84b65235-d681-45bd-98e0-35acc4a7b3c0	core.view	Ver CORE	\N	2026-09-07 16:33:36.858468+00
8ffb75ce-4a73-46c3-8701-48dbf7aeea13	core.manage	Gestionar CORE	\N	2026-09-07 16:33:36.858468+00
df88e934-8389-431d-8f88-77582856fdc2	services.view	Ver servicios	\N	2026-09-07 16:33:36.858468+00
be47f75b-8bfd-4f16-a4a8-61d81e14a3c5	services.manage	Gestionar servicios	\N	2026-09-07 16:33:36.858468+00
c639c87d-7a26-497e-8e1d-972b166944e4	quotations.view	Ver cotizaciones	\N	2026-09-07 16:33:36.858468+00
81a3dc91-8cc2-4c03-8b84-99a0c9d2ba74	quotations.manage	Gestionar cotizaciones	\N	2026-09-07 16:33:36.858468+00
98ad8ef3-ffe2-4de3-b09a-cbe9a7629ef6	contracts.view	Ver contratos	\N	2026-09-07 16:33:36.858468+00
eb94a10e-c7ec-42b1-b176-1eac2423ec58	contracts.manage	Gestionar contratos	\N	2026-09-07 16:33:36.858468+00
efde44ef-0b5e-4a89-b3a8-72f37a214645	orders.view	Ver órdenes	\N	2026-09-07 16:33:36.858468+00
56be17a9-59a8-433a-b884-bde437edc520	orders.manage	Gestionar órdenes	\N	2026-09-07 16:33:36.858468+00
e3120c60-673f-4670-873d-a4e9e16eae76	operations.view	Ver operaciones	\N	2026-09-07 16:33:36.858468+00
52e49ae0-f34b-4da8-be41-e857cd15ab16	operations.manage	Gestionar operaciones	\N	2026-09-07 16:33:36.858468+00
eb786dd3-6c11-401a-8fb0-f952b80c4fa6	finance.view	Ver finanzas	\N	2026-09-07 16:33:36.858468+00
37bb68d4-eb5d-4318-836f-1fa21b3e9d97	finance.manage	Gestionar finanzas	\N	2026-09-07 16:33:36.858468+00
94fa567f-08cd-49f2-9285-7c097f549532	integrations.view	Ver integraciones	\N	2026-09-07 16:33:36.858468+00
a55994c4-8449-44dc-b07c-4b9b2c4089ff	integrations.manage	Gestionar integraciones	\N	2026-09-07 16:33:36.858468+00
5b720aa4-e4ef-4195-a301-6b8211bb7430	portal.manage	Gestionar portal	\N	2026-09-07 16:33:36.858468+00
b65cd0f0-66da-47c1-afdd-144e3ba37849	audit.view	Ver auditoría	\N	2026-09-07 16:33:36.858468+00
de9f79fd-2a81-46fb-a48d-ea2942d9fff1	quotations.approve	Aprobar cotizaciones	Aprobar o rechazar cotizaciones enviadas a aprobación.	2026-10-06 03:45:20.371524+00
4ef1af59-5111-4676-8235-66a78c7e601c	contracts.approve	Activar contratos	Pasar un contrato de borrador a vigente.	2026-10-07 13:28:49.744321+00
adcda418-863a-4d01-805f-7b12aeb53979	portal.view	Ver solicitudes del portal	Ver la bandeja de solicitudes que los clientes envían desde el portal.	2026-10-07 14:24:59.805692+00
0eaaf0f3-db66-4922-ace1-5b2c41d76054	contracts.create	Crear contratos	Generar el contrato de una cotización aprobada.	2026-10-08 22:18:25.146727+00
e563bf52-16b2-44b9-b6d3-7fb1a6d0f634	orders.create	Crear órdenes	Generar la orden de una cotización aprobada o de un contrato vigente.	2026-10-08 22:18:25.146727+00
a082652f-4b97-40d0-9c0b-cea2a09912bd	leads.view	Ver leads	Ver los leads y sus fichas.	2026-09-07 16:33:36.858468+00
612d4d14-1eb4-4ec7-a66a-cf24c28dc741	leads.manage	Gestionar leads	Crear, editar, contactar, calificar y descartar leads.	2026-09-07 16:33:36.858468+00
ae7d0c28-5533-46a8-8239-f7c19d40169b	opportunities.view	Ver oportunidades	Ver el pipeline de oportunidades y sus fichas.	2026-10-08 23:46:33.713414+00
c1da465f-9c89-45e9-aa45-db5c1447c601	opportunities.manage	Gestionar oportunidades	Crear oportunidades, moverlas de etapa, ganarlas, perderlas y convertirlas en cliente.	2026-10-08 23:46:33.713414+00
a26c1e6d-4c96-4dc4-9087-1b9ce9a813b1	core.delete	Eliminar en CORE	Eliminar datos de contacto y columnas personalizadas de Clientes y actores.	2026-10-09 00:34:02.618551+00
c7952aa0-1ca9-4ebf-8f30-f4dc792048c4	core.export	Exportar clientes y actores	Descargar el listado en Excel o CSV.	2026-10-09 00:51:16.383967+00
748cbab3-354b-47b3-be5b-54235a3dc523	services.export	Exportar servicios	Descargar el listado en Excel o CSV.	2026-10-09 00:51:16.383967+00
8c93297d-f175-44bf-aa3d-fc5bef384252	quotations.export	Exportar cotizaciones	Descargar el listado en Excel o CSV.	2026-10-09 00:51:16.383967+00
949dec0f-5730-414c-a340-09d748a1e96a	contracts.export	Exportar contratos	Descargar el listado en Excel o CSV.	2026-10-09 00:51:16.383967+00
37bac107-493a-484a-b1e6-bc54b2f77f70	orders.export	Exportar órdenes	Descargar el listado en Excel o CSV.	2026-10-09 00:51:16.383967+00
dd0822f8-6e64-4f91-8351-e5c8086f754d	portal.export	Exportar solicitudes del portal	Descargar el listado en Excel o CSV.	2026-10-09 00:51:16.383967+00
6419fd57-8c9d-4a21-9bde-cb9a3d342275	leads.export	Exportar leads	Descargar el listado en Excel o CSV.	2026-10-09 00:51:16.383967+00
6e9c9256-8e3b-4fd1-b7dc-7f6ff17fddfb	opportunities.export	Exportar oportunidades	Descargar el listado en Excel o CSV.	2026-10-09 00:51:16.383967+00
bf4dc54d-9cb5-4cb6-9d3f-c5b6511b41c3	users.export	Exportar usuarios	Descargar el listado en Excel o CSV.	2026-10-09 00:51:16.383967+00
e74725b4-f688-4fa6-9631-93b7c804fc64	audit.export	Exportar auditoría	Descargar el listado en Excel o CSV.	2026-10-09 01:55:09.527095+00
e0c9cf56-18c6-42b2-93cc-a31154308537	activities.view	Ver actividades	Llamadas, reuniones, correos, demos y visitas del CRM.	2026-10-10 03:02:35.54304+00
c758aa53-f8ec-417f-8ef7-2c7b83a7bddb	activities.manage	Gestionar actividades	Registrar, editar y marcar como realizadas las actividades.	2026-10-10 03:02:35.54304+00
8272bb26-1fc3-40b4-9f6c-8de67a38c73b	activities.export	Exportar actividades	Descargar el listado en Excel o CSV.	2026-10-10 03:02:35.54304+00
bceeb720-68bc-4fe5-bac2-eddd42857f87	campaigns.view	Ver campañas	Campañas, sus miembros y sus resultados.	2026-10-10 03:02:35.54304+00
0c3bde48-f59a-444a-b04e-fe534b291251	campaigns.manage	Gestionar campañas	Crear, editar, lanzar, finalizar y cancelar campañas, y sus miembros.	2026-10-10 03:02:35.54304+00
49ece5f9-f244-4532-a169-68b8a83b8e46	campaigns.export	Exportar campañas	Descargar el listado en Excel o CSV.	2026-10-10 03:02:35.54304+00
f1724b06-7c5e-45da-b8d2-0ad2ca090afd	scoring.manage	Configurar la puntuación de leads	Cambiar los puntos de cada criterio y recalcular el puntaje de todos los leads.	2026-10-10 03:02:35.54304+00
7606f555-21db-4956-85f4-0124291eb6ce	contracted_services.view	Ver servicios contratados	Servicios que el cliente tiene contratados y su estado.	2026-10-13 03:48:11.334326+00
97b318ca-a5aa-48b5-9a51-148734210619	contracted_services.manage	Gestionar servicios contratados	Activar, suspender, reactivar, completar y cancelar servicios contratados.	2026-10-13 03:48:11.334326+00
b9da49a9-6188-41e9-80b8-627f7f8bbee8	contracted_services.export	Exportar servicios contratados	Descargar el listado en Excel o CSV.	2026-10-13 03:48:11.334326+00
a8aff9af-acc8-4fc0-b800-a3fce584ecd0	work_orders.view	Ver órdenes de trabajo	Órdenes de trabajo, su checklist y su historial.	2026-10-13 03:48:11.334326+00
4371667e-09d7-4d44-98a9-e46c75fdd3d0	recurring.view	Ver servicios recurrentes	Instancias recurrentes, su vigencia y sus ciclos.	2026-09-07 16:33:36.858468+00
605eef2a-2ab5-42b9-bf82-bf9bde1415a1	tickets.view	Ver tickets	Tickets de soporte, su SLA y su conversación.	2026-09-07 16:33:36.858468+00
2261f2d5-1af0-4a0c-b68d-9a39885fd104	documents.view	Ver documentos	Documentos de la empresa, sus versiones y su registro de accesos; descargar por enlace temporal.	2026-09-07 16:33:36.858468+00
facf4dc5-76a0-46ec-9631-876dce52337b	credentials.view	Ver credenciales	Ver los datos generales de las credenciales y su historial, nunca el valor.	2026-09-07 16:33:36.858468+00
96713678-973d-4351-a884-3365bffd14fc	reports.view	Ver reportes	Ver los reportes de los módulos que el rol puede ver, con su mismo alcance.	2026-09-07 16:33:36.858468+00
5f7e41b0-4003-4fcc-a979-94d006f4ccbf	reports.export	Exportar reportes	Exportar un reporte a Excel o CSV. Queda en auditoría.	2026-09-07 16:33:36.858468+00
364b75ac-bb6c-4ec4-86a1-1f991250cc47	work_orders.create	Crear órdenes de trabajo	Crear una orden de trabajo desde un servicio contratado activo.	2026-10-13 03:48:11.334326+00
a4b1d2a4-aded-4a4d-aa88-24398c52b961	work_orders.manage	Gestionar órdenes de trabajo	Editar, iniciar, bloquear, completar y cancelar órdenes de trabajo, y marcar su checklist.	2026-10-13 03:48:11.334326+00
dad650bd-85e8-49bb-819c-afefd92d523b	work_orders.approve	Asignar órdenes de trabajo y aprobar checklists	Asignar o reasignar el técnico y la fecha, y aprobar o devolver el checklist.	2026-10-13 03:48:11.334326+00
fcb3052c-c168-486f-a1bc-17b6463386cd	work_orders.export	Exportar órdenes de trabajo	Descargar el listado en Excel o CSV.	2026-10-13 03:48:11.334326+00
8fea7b39-4643-4c11-869b-83e45ba22e53	projects.view	Ver proyectos	Proyectos, sus tareas, su avance y su margen.	2026-10-13 04:50:21.158337+00
cd800987-9c10-45c4-baf3-4447ff3362b7	projects.create	Crear proyectos	Crear un proyecto desde un servicio contratado activo.	2026-10-13 04:50:21.158337+00
c2e07ccb-bde7-47e4-b633-7379b26aab0d	projects.manage	Gestionar proyectos	Editar el proyecto, planificarlo, iniciarlo, pausarlo y cancelarlo, y gestionar sus tareas.	2026-10-13 04:50:21.158337+00
dacadb69-cba8-460a-a9b4-8915549e02a8	projects.approve	Cerrar proyectos	Cerrar un proyecto con sus tareas cerradas y sus entregables aprobados.	2026-10-13 04:50:21.158337+00
c2b70ceb-3d69-4db1-872d-4bba3b8a706f	projects.export	Exportar proyectos	Descargar el listado en Excel o CSV.	2026-10-13 04:50:21.158337+00
025cddba-3968-4db6-b17f-2bc874a76ded	time_entries.view	Ver horas	Registros de horas de proyectos y órdenes de trabajo.	2026-10-13 04:50:21.158337+00
c4f58d70-d19a-48c4-a417-db70cb403ffa	time_entries.create	Registrar horas	Registrar las horas propias en un proyecto o en una orden de trabajo.	2026-10-13 04:50:21.158337+00
4e19b936-ced1-4702-80a0-0f7d59353cc6	time_entries.approve	Aprobar horas	Aprobar o rechazar las horas de otras personas.	2026-10-13 04:50:21.158337+00
7be18680-2400-484f-8c55-db3fcce4f1aa	time_entries.export	Exportar horas	Descargar el listado en Excel o CSV.	2026-10-13 04:50:21.158337+00
c732b92c-06ad-45b6-a751-d3c2b747d614	cost_entries.view	Ver costos	Costos directos de proyectos y órdenes de trabajo.	2026-10-13 04:50:21.158337+00
7f1e33bc-cd02-4609-a0ef-43eabd05ce2a	cost_entries.create	Registrar costos	Registrar un costo directo en un proyecto.	2026-10-13 04:50:21.158337+00
577d1898-c5b8-46c3-b361-ec9e7355b2ed	cost_entries.approve	Aprobar costos	Aprobar o rechazar costos.	2026-10-13 04:50:21.158337+00
220a6bd0-b01b-466f-85a2-3983752ac15f	cost_entries.export	Exportar costos	Descargar el listado en Excel o CSV.	2026-10-13 04:50:21.158337+00
96ef3bbb-6b33-4ad3-9ba0-ecdf9e1c9343	deliverables.view	Ver entregables	Entregables de los proyectos y su estado.	2026-10-13 04:50:21.158337+00
1542eebf-5323-4182-9898-d8ab0615b23d	deliverables.manage	Gestionar entregables	Crear y editar entregables, enviarlos a revisión y entregarlos al cliente.	2026-10-13 04:50:21.158337+00
6eb8f747-f0cb-4cdb-a2b2-1df1142a33a0	deliverables.approve	Revisar entregables	Aprobar o devolver un entregable en revisión.	2026-10-13 04:50:21.158337+00
36116e1b-682c-4e15-8495-90359e057222	tasks.manage	Gestionar tareas de proyectos	Crear, editar y mover las tareas de un proyecto y marcar su control obligatorio.	2026-10-13 05:08:31.313881+00
1e1b8ca7-1c1e-461f-a99f-7e638780e397	agenda.view	Ver agenda	Agenda semanal: eventos y órdenes de trabajo programadas.	2026-10-13 17:20:57.575185+00
3bc31ffe-af6e-4c73-b9f6-14f861ed51a0	agenda.create	Crear eventos de agenda	Agendar reuniones, visitas, mantenimientos y sesiones.	2026-10-13 17:20:57.575185+00
2a091839-f77d-4655-b91a-ff462276efe3	agenda.manage	Gestionar eventos de agenda	Editar un evento, marcarlo realizado o cancelarlo.	2026-10-13 17:20:57.575185+00
d30dee30-ca9d-4324-a241-ee0d18db4617	billing.create	Preparar comprobantes	Registrar eventos facturables y generar el comprobante en borrador a partir de ellos.	2026-10-13 19:28:30.929833+00
adc8be2f-54ea-474c-8654-971154107d8b	billing.approve	Emitir comprobantes	Emitir un comprobante, corregirlo con nota de crédito y reintentar su envío.	2026-10-13 19:28:30.929833+00
b21704f4-cd34-4b7d-834a-88935406ada5	billing.export	Exportar facturación	Descargar eventos facturables y comprobantes en Excel o CSV.	2026-10-13 19:28:30.929833+00
a1f3c631-ec72-456b-bb80-77ec02c07cc1	receivables.view	Ver cuentas por cobrar	Cuentas por cobrar, su saldo, sus movimientos y la antigüedad de la deuda.	2026-10-13 19:28:30.929833+00
a63e319e-01d5-49f7-8371-389acc701ce5	receivables.manage	Gestionar cuentas por cobrar	Enviar recordatorios de cobranza y registrar constancias sobre una cuenta.	2026-10-13 19:28:30.929833+00
c28a15a3-6aa4-4f39-b2de-3ba5496cbbfa	receivables.export	Exportar cuentas por cobrar	Descargar el listado en Excel o CSV.	2026-10-13 19:28:30.929833+00
1ebdc5b3-9364-4fd1-8eeb-9a6a51637362	payments.view	Ver cobros	Cobros registrados, a qué cuentas se aplicaron y sus reembolsos.	2026-10-13 19:28:30.929833+00
ff4d371c-528d-435a-9525-c33d6dd94b8a	payments.create	Registrar cobros	Registrar un cobro de un cliente.	2026-10-13 19:28:30.929833+00
4de8af6a-0793-44b2-809a-791d748aea98	payments.manage	Aplicar cobros	Aplicar un cobro a las cuentas por cobrar del cliente.	2026-10-13 19:28:30.929833+00
30723a0e-3963-417c-b8db-c7e191d585eb	payments.approve	Conciliar y reembolsar cobros	Conciliar un cobro con el banco y reembolsarlo.	2026-10-13 19:28:30.929833+00
7ea57e91-a817-4de9-95b5-a045b3e79f0a	payments.export	Exportar cobros	Descargar el listado en Excel o CSV.	2026-10-13 19:28:30.929833+00
82d092a3-1f81-462f-946b-34b44f09d528	fiscal.manage	Gestionar tipo de cambio	Registrar o corregir el tipo de cambio del día.	2026-10-13 19:28:30.929833+00
0bd7113d-2eb9-4924-95c9-f91ac802384c	billing.view	Ver facturación	Eventos facturables y comprobantes.	2026-09-07 16:33:36.858468+00
e101d9ee-5b02-4073-89a4-a4de4463d2c4	billing.manage	Gestionar facturación	Anular un evento facturable y descartar un comprobante en borrador.	2026-09-07 16:33:36.858468+00
acf917c0-443b-40b6-89ff-52520c7d35cb	recurring.create	Procesar ciclos recurrentes	Procesar los ciclos vencidos: cada uno deja su evento facturable.	2026-10-13 20:42:25.148548+00
0a957c42-b0b7-45ce-8b91-880483d58711	recurring.export	Exportar servicios recurrentes	Descargar el listado en Excel o CSV.	2026-10-13 20:42:25.148548+00
b3ae6384-c337-4186-a1f1-b3ea6c6a29e4	recurring.manage	Gestionar servicios recurrentes	Renovar, suspender, reactivar y cancelar una instancia recurrente.	2026-09-07 16:33:36.858468+00
1b7a3162-ee22-4be1-ae01-813d5c9ffb4e	treasury.view	Ver bancos y caja	Cuentas, saldos, movimientos, extractos y caja chica.	2026-10-13 20:55:31.271204+00
916155c7-50fb-4e2c-92e4-cf44f44041b7	treasury.create	Operar bancos y caja	Transferir entre cuentas, importar extractos, registrar gastos de caja chica, reponer el fondo y hacer arqueos.	2026-10-13 20:55:31.271204+00
eed97b9c-e20e-4fb1-991f-626c350eeac1	treasury.manage	Gestionar cuentas y conciliar	Crear y editar cuentas, y conciliar a mano las líneas de un extracto.	2026-10-13 20:55:31.271204+00
f198814a-4d09-4736-bea1-bfff21731767	treasury.export	Exportar bancos y caja	Descargar movimientos y gastos de caja chica en Excel o CSV.	2026-10-13 20:55:31.271204+00
52551302-4513-4f7a-b661-47ec75bad5f2	fiscal.configure	Configurar facturación electrónica	Proveedor, RUC emisor, series, detracciones y emisión en contingencia.	2026-10-13 21:17:05.661629+00
671c5ca2-7a9b-4511-97dc-80fcc2c20687	fiscal.view	Ver facturación electrónica y tipo de cambio	Configuración fiscal, contingencia, bajas, monedas y tipo de cambio registrado.	2026-10-13 19:28:30.929833+00
22a69950-c30c-45ac-9dcc-30e1217095c6	purchases.view	Ver órdenes de compra	Órdenes de compra, su recepción y lo facturado de cada una.	2026-10-14 02:52:05.830942+00
afc442a9-7e97-4cf0-9cf5-795837d87ebb	purchases.create	Crear órdenes de compra	Registrar una orden de compra en borrador.	2026-10-14 02:52:05.830942+00
af28887a-ceb0-47ea-a6b3-9002f06c1b65	purchases.manage	Gestionar órdenes de compra	Editar el borrador, enviarlo a aprobación, registrar la recepción, cerrar y anular.	2026-10-14 02:52:05.830942+00
287e044b-8246-467b-a68a-6bbc38213fa6	purchases.approve	Aprobar órdenes de compra	Aprobar o rechazar una orden de compra enviada a aprobación.	2026-10-14 02:52:05.830942+00
f431375c-661b-43d8-862f-592274eefe4c	purchases.export	Exportar órdenes de compra	Descargar el listado en Excel o CSV.	2026-10-14 02:52:05.830942+00
9eb4c805-ffd2-41d0-a2c0-4246a581c8fa	payables.view	Ver comprobantes de proveedor y cuentas por pagar	Comprobantes de proveedor, cuentas por pagar y pagos a proveedores.	2026-10-14 02:52:05.830942+00
9da60748-f1e1-42e1-8887-b979bf7fc512	payables.create	Registrar comprobantes de proveedor	Registrar el comprobante que entrega un proveedor.	2026-10-14 02:52:05.830942+00
2bd63088-6407-4cd8-8271-d3f3e9045e7f	payables.manage	Anular comprobantes de proveedor	Anular un comprobante de proveedor registrado o rechazado.	2026-10-14 02:52:05.830942+00
f860fb4d-cac6-4c1f-bb19-c6e8da1e436f	payables.export	Exportar cuentas por pagar	Descargar comprobantes de proveedor, cuentas por pagar y pagos en Excel o CSV.	2026-10-14 02:52:05.830942+00
1133852b-4692-499d-b98e-aa6a0aee64c3	accounting.view	Ver contabilidad	Libro diario, mayor y balance de comprobación, estados financieros, plan de cuentas y periodos.	2026-10-14 05:08:00.070524+00
e1da20fb-4a93-4f23-983c-89e48be0d46f	accounting.create	Registrar asientos manuales	Contabilizar un asiento manual cuadrado en un periodo abierto.	2026-10-14 05:08:00.070524+00
48000575-0cb3-448f-8cfc-9e8398e52706	accounting.manage	Gestionar el plan de cuentas	Crear cuentas contables, cambiar su nombre y activarlas o desactivarlas.	2026-10-14 05:08:00.070524+00
3a5908d0-50c3-412d-81a3-37dbdac0c896	accounting.approve	Extornar asientos y cerrar periodos	Extornar un asiento con motivo, cerrar y reabrir periodos contables.	2026-10-14 05:08:00.070524+00
71446979-048b-4ae5-bf21-76db6e1b3e4f	accounting.export	Exportar contabilidad	Descargar el libro diario, el mayor, el balance de comprobación y el plan de cuentas.	2026-10-14 05:08:00.070524+00
0d09772a-c6b4-4505-9ef5-98e4768cf361	budgets.view	Ver centros de costo y presupuestos	Presupuesto por centro de costo frente a lo ejecutado y lo comprometido.	2026-10-14 05:08:00.070524+00
d8727a31-a60b-415d-b89c-27526b6b29e5	budgets.approve	Aprobar presupuestos	Aprobar el presupuesto del año.	2026-10-14 05:08:00.070524+00
1cd826aa-42d4-4eb1-997d-27c74f798658	budgets.export	Exportar presupuestos	Descargar la ejecución por centro de costo y su detalle.	2026-10-14 05:08:00.070524+00
5975112b-5fa7-4bad-ade5-868f35ce1dde	budgets.manage	Gestionar centros de costo y presupuestos	Crear y editar centros de costo, preparar el presupuesto y reabrirlo para ajustarlo.	2026-10-14 02:52:05.830942+00
a53ae172-ccc9-4300-99b2-831d64a8949b	payables.pay	Pagar a proveedores	Registrar y revertir pagos a proveedores desde una cuenta de banco o caja.	2026-10-14 18:24:51.423157+00
03d1d717-9013-4261-999d-db9a60342587	payables.approve	Aprobar comprobantes de proveedor	Aprobar o rechazar un comprobante de proveedor para pago.	2026-10-14 02:52:05.830942+00
7a2d0349-56ff-489a-991e-c67ac8cf2e91	checklists.view	Ver plantillas de checklist	Plantillas de checklist que se copian a las órdenes de trabajo.	2026-10-14 18:47:15.426761+00
f5405cec-395c-4562-9cdd-887b3b8044ab	checklists.manage	Gestionar plantillas de checklist	Crear, editar, duplicar, importar, desactivar y reactivar plantillas de checklist.	2026-10-14 18:47:15.426761+00
50e05b58-1da9-404f-b310-0074e9744cc7	checklists.export	Exportar plantillas de checklist	Descargar las plantillas y sus ítems en Excel o CSV.	2026-10-14 18:47:15.426761+00
ac80c337-01c3-4940-8097-1bf6d2bec294	tickets.manage	Atender tickets	Clasificar, asignar, responder, cambiar de estado, resolver, cerrar y reabrir tickets.	2026-09-07 16:33:36.858468+00
156f236c-e182-4675-a847-e5a90c7d4059	tickets.create	Crear tickets	Registrar tickets de soporte para un cliente.	2026-10-15 05:33:30.510064+00
7322b7d0-1aba-457b-a8cc-9bd88ccdfcf6	tickets.approve	Escalar tickets	Subir la prioridad de un ticket y recalcular su SLA.	2026-10-15 05:33:30.510064+00
e5326a7a-0752-418f-bb8d-73141a0d6f23	tickets.export	Exportar tickets	Descargar el listado de tickets en Excel o CSV.	2026-10-15 05:33:30.510064+00
36c9f8f4-9655-4aec-a620-9c9e958882ad	warranty.view	Ver reclamos de garantía	Reclamos de garantía y su cobertura.	2026-10-15 05:33:30.510064+00
474a7fbb-35fb-4509-9a2e-6672bb32fbe7	warranty.create	Registrar reclamos de garantía	Registrar un reclamo de garantía de un servicio contratado.	2026-10-15 05:33:30.510064+00
3beaa8c5-c1ce-4cb2-afba-0bf4f763fe2a	warranty.manage	Gestionar reclamos de garantía	Validar la cobertura y marcar resuelto un reclamo de garantía.	2026-10-15 05:33:30.510064+00
cf520ca0-a660-4b1b-8eeb-4a07d2deb6d9	warranty.approve	Aprobar reclamos de garantía	Aprobar o rechazar la cobertura de un reclamo de garantía.	2026-10-15 05:33:30.510064+00
cd35fb88-f1de-4f35-accf-c9bbae77115e	warranty.export	Exportar reclamos de garantía	Descargar el listado de reclamos en Excel o CSV.	2026-10-15 05:33:30.510064+00
0d7b6e74-194a-43c7-a474-9fc352c498f3	sla.view	Ver calendario laboral y SLA	Horario de atención, feriados y tiempos de SLA por prioridad.	2026-10-15 05:33:30.510064+00
1d27de9a-c2d2-4067-8d54-8149fd149353	sla.manage	Gestionar calendario laboral y SLA	Cambiar el horario de atención, los feriados y los tiempos de SLA.	2026-10-15 05:33:30.510064+00
4c2863a1-1b7c-440c-8a88-d27b66528f3c	portal_users.view	Ver usuarios del portal	Usuarios del portal de clientes y avisos publicados.	2026-10-22 16:35:28.610476+00
5c7192f8-e7fb-4bcc-889b-41cb8091b545	portal_users.create	Invitar usuarios del portal	Invitar a una persona de un cliente al portal.	2026-10-22 16:35:28.610476+00
2ac050c8-740e-4d77-ab63-264c5e60d139	portal_users.manage	Gestionar usuarios del portal	Cambiar el rol, desactivar o reactivar usuarios del portal, reenviar invitaciones y publicar o retirar avisos.	2026-10-22 16:35:28.610476+00
8e2bb9e1-7706-4ca2-8bf4-d954c852b0ca	portal_users.export	Exportar usuarios del portal	Descargar el listado de usuarios del portal en Excel o CSV.	2026-10-22 16:35:28.610476+00
b82490b4-1ce3-463a-bfbc-a224de33c5d5	documents.manage	Gestionar documentos	Editar título y categoría, mostrar u ocultar un documento en el portal del cliente y archivarlo.	2026-09-07 16:33:36.858468+00
420e2264-4168-4a0e-88e7-fbb6d241fd24	documents.create	Subir documentos	Subir un documento vinculado a un cliente, contrato, proyecto u orden de trabajo, y subir versiones nuevas.	2026-10-22 19:53:27.430853+00
9d43cbeb-eaec-4e5e-826a-2dfdc52b02ad	documents.export	Exportar documentos	Descargar el listado de documentos en Excel o CSV.	2026-10-22 19:53:27.430853+00
bc659651-e12e-4380-b1c9-99577a79f9d5	credit.approve	Aprobar sobre el límite de crédito	Aprobar una cotización o activar un contrato aunque el cliente supere su límite de crédito, cuando la empresa lo bloquea. Siempre con motivo.	2026-10-27 07:43:01.2514+00
81abd3a4-5d43-4741-b86b-e6d268fe5640	credentials.export	Exportar credenciales	Exportar el listado del Vault (solo datos generales, nunca los valores).	2026-11-01 20:48:46.593905+00
00439cbb-2e2f-44d5-bf05-7d72de9b4032	credentials.manage	Crear y rotar credenciales	Guardar una credencial nueva y cambiar su valor (nueva versión).	2026-09-07 16:33:36.858468+00
b1d322b8-2a82-407d-9464-ae227bb08145	credentials.use	Usar credenciales	Copiar el valor al portapapeles sin verlo en pantalla. Pide motivo y queda registrado.	2026-09-15 05:23:34+00
a2d3bf4f-51c7-4db2-9d3a-29cda5ee5d2a	credentials.reveal	Revelar credenciales	Ver el valor durante 20 segundos. Pide motivo y queda registrado.	2026-09-15 05:23:34+00
9f1f94c1-c01d-41c2-bf18-a3ca65ba5809	credentials.delete	Revocar credenciales	Dejar una credencial sin uso. No se borra su historial.	2026-09-15 05:23:34+00
\.


--
-- Data for Name: saas_plans; Type: TABLE DATA; Schema: public; Owner: erp_user
--

COPY public.saas_plans (id, code, name, active, limits, modules, created_at, updated_at, description, display_order, is_public, monthly_price, annual_price, currency, has_trial, trial_days, requires_payment_upfront, auto_renew_default, default_billing_period, features, support_level, support_tickets_month, support_sla_hours, support_channels, consumption) FROM stdin;
88caead2-f8c5-4782-bb8e-400d54335c13	BASE	Plan Base	t	{"sites": 3, "users": 10, "clients": 100, "branches": 1, "products": 100, "projects": 10, "services": 50, "crm_leads": 500, "documents": 1000, "max_users": 20, "work_orders": 100, "portal_users": 20, "project_users": 20, "timesheet_hours": 1000, "crm_opportunities": 200}	{"AGENDA": true, "PORTAL": true, "CLIENTES": true, "CONTRATOS": true, "SERVICIOS": true, "DOCUMENTOS": true, "COTIZACIONES": true}	2026-09-07 16:33:36.858468+00	2026-10-23 00:59:03+00	Para pequeños emprendedores.	1	t	99.0000	990.0000	PEN	t	30	f	t	MONTHLY	{"api": false, "mfa": false, "audit": true, "automations": false, "integrations": false, "multi_tenant": true, "client_portal": false, "advanced_reports": false, "roles_permissions": true, "electronic_billing": false}	STANDARD	10	24	{"email": false, "portal": false, "whatsapp": false, "priority_attention": false}	{"storage_gb": 20, "files_count": 5000, "exports_month": 100, "backups_included": 7, "api_requests_month": 10000, "documents_generated": 3000}
bdcd4660-6077-4a9c-9434-0c4c4514364c	PRO	Plan Profesional	t	{}	{"CRM": true, "AGENDA": true, "PORTAL": true, "COMPRAS": true, "CLIENTES": true, "FINANZAS": true, "CONTRATOS": true, "PROYECTOS": true, "SERVICIOS": true, "DOCUMENTOS": true, "TIMESHEETS": true, "CONTABILIDAD": true, "COTIZACIONES": true, "ORDENES_TRABAJO": true}	2026-09-07 16:33:36.858468+00	2026-10-23 00:59:03+00	Para empresas en constante crecimiento y optimización.	2	t	199.0000	1990.0000	PEN	f	0	f	t	MONTHLY	{}	STANDARD	\N	\N	{"email": false, "portal": false, "whatsapp": false, "priority_attention": false}	{}
01a0f803-8fd8-7127-86f5-c4bdfe64100d	Plan6	Plan 6	t	{"sites": 3, "users": 18, "clients": 100, "branches": 1, "products": 100, "projects": 10, "services": 50, "crm_leads": 500, "documents": 1000, "work_orders": 100, "portal_users": 20, "project_users": 20, "timesheet_hours": 1000, "crm_opportunities": 200}	{"AGENDA": true, "COMPRAS": true, "CLIENTES": true, "FINANZAS": true, "CONTRATOS": true, "PROYECTOS": true, "SERVICIOS": true, "DOCUMENTOS": true, "CONTABILIDAD": true, "COTIZACIONES": true, "ORDENES_TRABAJO": true}	2026-10-01 15:09:38+00	2026-10-23 00:59:03+00	Plan Enterprise	1	f	200.0000	400.0000	PEN	t	30	f	t	MONTHLY	{"api": false, "mfa": true, "audit": true, "automations": false, "integrations": false, "multi_tenant": true, "client_portal": false, "advanced_reports": false, "roles_permissions": true, "electronic_billing": false}	STANDARD	100	24	{"email": true, "portal": true, "whatsapp": false, "priority_attention": false}	{}
4eb52f95-87a2-40d9-bc7f-f210a23acde1	ENTERPRISE	Plan Enterprise	t	{}	{"CRM": true, "AGENDA": true, "PORTAL": true, "COMPRAS": true, "SOPORTE": true, "CLIENTES": true, "FINANZAS": true, "CONTRATOS": true, "PROYECTOS": true, "SERVICIOS": true, "DOCUMENTOS": true, "TIMESHEETS": true, "CONTABILIDAD": true, "COTIZACIONES": true, "ORDENES_TRABAJO": true}	2026-09-07 16:33:36.858468+00	2026-10-23 00:59:03+00	Para empresas ya consolidadas.	3	t	349.0000	3490.0000	PEN	t	15	t	t	MONTHLY	{}	STANDARD	\N	\N	{"email": false, "portal": false, "whatsapp": false, "priority_attention": false}	{}
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: erp_user
--

SELECT pg_catalog.setval('public.migrations_id_seq', 79, true);


--
-- PostgreSQL database dump complete
--


