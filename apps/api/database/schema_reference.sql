--
-- PostgreSQL database dump
--

\restrict 4NW8HgLqknTjuqbit0HBh08eKoVjMa1MaryJaDwANiZoZCD20PCjyC0A2Rvfdqd

-- Dumped from database version 17.11 (Debian 17.11-1.pgdg13+2)
-- Dumped by pg_dump version 17.11

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
-- Name: citext; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS citext WITH SCHEMA public;


--
-- Name: EXTENSION citext; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION citext IS 'data type for case-insensitive character strings';


--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


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
    category character varying(100) NOT NULL,
    amount numeric(18,4) DEFAULT 0 NOT NULL,
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
    updated_at timestamp with time zone DEFAULT now() NOT NULL
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
-- Name: checklist_items; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.checklist_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    checklist_id uuid NOT NULL,
    "position" integer NOT NULL,
    label character varying(500) NOT NULL,
    required boolean DEFAULT false NOT NULL
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
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.checklists OWNER TO erp_user;

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
-- Name: contacts; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.contacts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    party_id uuid NOT NULL,
    contact_type character varying(50) NOT NULL,
    value character varying(255) NOT NULL,
    is_primary boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
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
    updated_at timestamp with time zone DEFAULT now() NOT NULL
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
    active boolean DEFAULT true NOT NULL
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
    CONSTRAINT cost_entries_amount_check CHECK ((amount >= (0)::numeric))
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
    created_at timestamp with time zone DEFAULT now() NOT NULL
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
    CONSTRAINT chk_credential_expiry CHECK (((expires_at IS NULL) OR (expires_at > created_at))),
    CONSTRAINT credential_records_status_check CHECK (((status)::text = ANY (ARRAY[('ACTIVE'::character varying)::text, ('EXPIRED'::character varying)::text, ('REVOKED'::character varying)::text, ('ROTATION_REQUIRED'::character varying)::text])))
);


ALTER TABLE public.credential_records OWNER TO erp_user;

--
-- Name: TABLE credential_records; Type: COMMENT; Schema: public; Owner: erp_user
--

COMMENT ON TABLE public.credential_records IS 'Metadatos de secretos; valores secretos viven cifrados en credential_versions.';


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
    created_at timestamp with time zone DEFAULT now() NOT NULL
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
    created_at timestamp with time zone DEFAULT now() NOT NULL
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
    CONSTRAINT crm_campaign_members_check CHECK (((lead_id IS NOT NULL) OR (customer_id IS NOT NULL)))
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
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.crm_campaigns OWNER TO erp_user;

--
-- Name: crm_conversions; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.crm_conversions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    lead_id uuid NOT NULL,
    opportunity_id uuid,
    customer_id uuid NOT NULL,
    quotation_id uuid,
    converted_by uuid,
    converted_at timestamp with time zone DEFAULT now() NOT NULL
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
    CONSTRAINT crm_leads_status_check CHECK (((status)::text = ANY (ARRAY[('NEW'::character varying)::text, ('QUALIFIED'::character varying)::text, ('DISQUALIFIED'::character varying)::text, ('NURTURING'::character varying)::text, ('CONVERTED'::character varying)::text])))
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
-- Name: TABLE crm_settings; Type: COMMENT; Schema: public; Owner: erp_user
--

COMMENT ON TABLE public.crm_settings IS 'Configuración específica del CRM por tenant; CRM OFF conserva datos.';


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
-- Name: customers; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.customers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    party_id uuid NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    credit_limit numeric(18,4),
    payment_terms_days integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
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
    status character varying(30) DEFAULT 'PENDING'::character varying NOT NULL,
    due_date date,
    accepted_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL
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
    CONSTRAINT chk_document_classification CHECK (((classification)::text = ANY (ARRAY[('PRIVATE'::character varying)::text, ('INTERNAL'::character varying)::text, ('PUBLIC'::character varying)::text])))
);


ALTER TABLE public.documents OWNER TO erp_user;

--
-- Name: TABLE documents; Type: COMMENT; Schema: public; Owner: erp_user
--

COMMENT ON TABLE public.documents IS 'Document Service único para archivos, evidencias y adjuntos.';


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
    CONSTRAINT financial_accounts_account_type_check CHECK (((account_type)::text = ANY (ARRAY[('CASH'::character varying)::text, ('BANK'::character varying)::text, ('GATEWAY'::character varying)::text, ('OTHER'::character varying)::text])))
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
-- Name: TABLE fiscal_documents; Type: COMMENT; Schema: public; Owner: erp_user
--

COMMENT ON TABLE public.fiscal_documents IS 'Capa fiscal desacoplada del proveedor para Perú/SUNAT y futura expansión internacional.';


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
    CONSTRAINT invoice_items_quantity_check CHECK ((quantity > (0)::numeric)),
    CONSTRAINT invoice_items_unit_price_check CHECK ((unit_price >= (0)::numeric))
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
    invoice_number character varying(100) NOT NULL,
    invoice_type character varying(30) NOT NULL,
    status character varying(30) DEFAULT 'DRAFT'::character varying NOT NULL,
    currency character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    issue_date date DEFAULT CURRENT_DATE NOT NULL,
    due_date date,
    subtotal numeric(18,4) DEFAULT 0 NOT NULL,
    tax_total numeric(18,4) DEFAULT 0 NOT NULL,
    total numeric(18,4) DEFAULT 0 NOT NULL,
    balance_due numeric(18,4) DEFAULT 0 NOT NULL,
    fiscal_document_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT invoices_invoice_type_check CHECK (((invoice_type)::text = ANY (ARRAY[('INVOICE'::character varying)::text, ('RECEIPT'::character varying)::text, ('OTHER'::character varying)::text]))),
    CONSTRAINT invoices_status_check CHECK (((status)::text = ANY (ARRAY[('DRAFT'::character varying)::text, ('ISSUED'::character varying)::text, ('VOID'::character varying)::text, ('PAID'::character varying)::text, ('PARTIALLY_PAID'::character varying)::text, ('OVERDUE'::character varying)::text, ('CANCELLED'::character varying)::text])))
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
    created_at timestamp with time zone DEFAULT now() NOT NULL
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
-- Name: TABLE outbox_events; Type: COMMENT; Schema: public; Owner: erp_user
--

COMMENT ON TABLE public.outbox_events IS 'Eventos transaccionales para integración asíncrona.';


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
    CONSTRAINT parties_party_type_check CHECK (((party_type)::text = ANY (ARRAY[('PERSON'::character varying)::text, ('ORGANIZATION'::character varying)::text])))
);


ALTER TABLE public.parties OWNER TO erp_user;

--
-- Name: TABLE parties; Type: COMMENT; Schema: public; Owner: erp_user
--

COMMENT ON TABLE public.parties IS 'Identidad canónica CORE. No crear crm_customers/crm_contacts/crm_companies.';


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
    CONSTRAINT chk_payment_provider_reference CHECK ((((status)::text <> 'CONFIRMED'::text) OR (payment_method IS NOT NULL))),
    CONSTRAINT payments_amount_check CHECK ((amount > (0)::numeric)),
    CONSTRAINT payments_status_check CHECK (((status)::text = ANY (ARRAY[('PENDING'::character varying)::text, ('CONFIRMED'::character varying)::text, ('FAILED'::character varying)::text, ('REFUNDED'::character varying)::text, ('CANCELLED'::character varying)::text])))
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
    updated_at timestamp(0) without time zone
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
    status character varying(30) DEFAULT 'OPEN'::character varying NOT NULL,
    crm_opportunity_id uuid,
    quotation_id uuid,
    created_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
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
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    last_login_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL
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
    CONSTRAINT projects_status_check CHECK (((status)::text = ANY (ARRAY[('DRAFT'::character varying)::text, ('PLANNED'::character varying)::text, ('IN_PROGRESS'::character varying)::text, ('COMPLETED'::character varying)::text, ('CANCELLED'::character varying)::text])))
);


ALTER TABLE public.projects OWNER TO erp_user;

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
    CONSTRAINT quotations_status_check CHECK (((status)::text = ANY (ARRAY[('DRAFT'::character varying)::text, ('SUBMITTED'::character varying)::text, ('APPROVED'::character varying)::text, ('REJECTED'::character varying)::text, ('EXPIRED'::character varying)::text, ('CANCELLED'::character varying)::text])))
);


ALTER TABLE public.quotations OWNER TO erp_user;

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
    CONSTRAINT receivables_balance_amount_check CHECK ((balance_amount >= (0)::numeric)),
    CONSTRAINT receivables_original_amount_check CHECK ((original_amount >= (0)::numeric))
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
    CONSTRAINT recurring_service_instances_interval_value_check CHECK ((interval_value > 0))
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
    CONSTRAINT refunds_amount_check CHECK ((amount > (0)::numeric))
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
    updated_at timestamp with time zone DEFAULT now() NOT NULL
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
    CONSTRAINT services_status_check CHECK (((status)::text = ANY (ARRAY[('DRAFT'::character varying)::text, ('ACTIVE'::character varying)::text, ('INACTIVE'::character varying)::text])))
);


ALTER TABLE public.services OWNER TO erp_user;

--
-- Name: suppliers; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.suppliers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    party_id uuid NOT NULL,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.suppliers OWNER TO erp_user;

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
    priority character varying(30),
    response_minutes integer DEFAULT 0 NOT NULL,
    resolution_minutes integer DEFAULT 0 NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
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
    created_at timestamp with time zone DEFAULT now() NOT NULL
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
    priority character varying(30) DEFAULT 'NORMAL'::character varying NOT NULL,
    status character varying(30) DEFAULT 'OPEN'::character varying NOT NULL,
    sla_id uuid,
    assigned_user_id uuid,
    opened_at timestamp with time zone DEFAULT now() NOT NULL,
    resolved_at timestamp with time zone,
    closed_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT support_tickets_status_check CHECK (((status)::text = ANY (ARRAY[('OPEN'::character varying)::text, ('TRIAGED'::character varying)::text, ('ASSIGNED'::character varying)::text, ('IN_PROGRESS'::character varying)::text, ('RESOLVED'::character varying)::text, ('CLOSED'::character varying)::text, ('REOPENED'::character varying)::text])))
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
    CONSTRAINT tasks_check CHECK (((project_id IS NOT NULL) OR (work_order_id IS NOT NULL))),
    CONSTRAINT tasks_status_check CHECK (((status)::text = ANY (ARRAY[('TODO'::character varying)::text, ('IN_PROGRESS'::character varying)::text, ('BLOCKED'::character varying)::text, ('DONE'::character varying)::text, ('CANCELLED'::character varying)::text])))
);


ALTER TABLE public.tasks OWNER TO erp_user;

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
-- Name: TABLE tenant_modules; Type: COMMENT; Schema: public; Owner: erp_user
--

COMMENT ON TABLE public.tenant_modules IS 'Activación/desactivación no destructiva de módulos por tenant; CRM usa module_code=CRM.';


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
-- Name: tenant_users; Type: TABLE; Schema: public; Owner: erp_user
--

CREATE TABLE public.tenant_users (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    tenant_id uuid NOT NULL,
    user_id uuid NOT NULL,
    default_branch_id uuid,
    status character varying(30) DEFAULT 'ACTIVE'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
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
    CONSTRAINT tenants_status_check CHECK (((status)::text = ANY (ARRAY[('PROVISIONING'::character varying)::text, ('ACTIVE'::character varying)::text, ('SUSPENDED'::character varying)::text, ('MAINTENANCE'::character varying)::text, ('CLOSED'::character varying)::text])))
);


ALTER TABLE public.tenants OWNER TO erp_user;

--
-- Name: TABLE tenants; Type: COMMENT; Schema: public; Owner: erp_user
--

COMMENT ON TABLE public.tenants IS 'Tenant SaaS; límite principal de aislamiento lógico.';


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
    CONSTRAINT time_entries_check CHECK ((ended_at >= started_at)),
    CONSTRAINT time_entries_hours_check CHECK ((hours >= (0)::numeric))
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
    CONSTRAINT users_status_check CHECK (((status)::text = ANY (ARRAY[('INVITED'::character varying)::text, ('ACTIVE'::character varying)::text, ('SUSPENDED'::character varying)::text, ('DISABLED'::character varying)::text])))
);


ALTER TABLE public.users OWNER TO erp_user;

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
    status character varying(30) DEFAULT 'OPEN'::character varying NOT NULL,
    description text,
    opened_at timestamp with time zone DEFAULT now() NOT NULL,
    resolved_at timestamp with time zone
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
    CONSTRAINT work_orders_status_check CHECK (((status)::text = ANY (ARRAY[('PLANNED'::character varying)::text, ('ASSIGNED'::character varying)::text, ('IN_PROGRESS'::character varying)::text, ('COMPLETED'::character varying)::text, ('CANCELLED'::character varying)::text])))
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
-- Name: advances advances_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.advances
    ADD CONSTRAINT advances_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


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
-- Name: checklist_items checklist_items_checklist_id_position_key; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.checklist_items
    ADD CONSTRAINT checklist_items_checklist_id_position_key UNIQUE (checklist_id, "position");


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
-- Name: crm_leads crm_leads_pkey; Type: CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.crm_leads
    ADD CONSTRAINT crm_leads_pkey PRIMARY KEY (id);


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
-- Name: idx_audit_logs_entity; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_audit_logs_entity ON public.audit_logs USING btree (entity_type, entity_id);


--
-- Name: idx_audit_logs_tenant_created; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_audit_logs_tenant_created ON public.audit_logs USING btree (tenant_id, created_at DESC);


--
-- Name: idx_branches_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_branches_tenant ON public.branches USING btree (tenant_id);


--
-- Name: idx_companies_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_companies_tenant ON public.companies USING btree (tenant_id);


--
-- Name: idx_contacts_tenant_party; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_contacts_tenant_party ON public.contacts USING btree (tenant_id, party_id);


--
-- Name: idx_contracts_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_contracts_tenant_status ON public.contracts USING btree (tenant_id, status);


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
-- Name: idx_crm_activities_tenant_due; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_crm_activities_tenant_due ON public.crm_activities USING btree (tenant_id, due_at);


--
-- Name: idx_crm_leads_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_crm_leads_tenant_status ON public.crm_leads USING btree (tenant_id, status);


--
-- Name: idx_crm_opportunities_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_crm_opportunities_tenant_status ON public.crm_opportunities USING btree (tenant_id, status);


--
-- Name: idx_customers_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_customers_tenant ON public.customers USING btree (tenant_id);


--
-- Name: idx_documents_tenant_created; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_documents_tenant_created ON public.documents USING btree (tenant_id, created_at DESC);


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
-- Name: idx_idempotency_tenant_key; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_idempotency_tenant_key ON public.idempotency_keys USING btree (tenant_id, idempotency_key);


--
-- Name: idx_invoices_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_invoices_tenant_status ON public.invoices USING btree (tenant_id, status);


--
-- Name: idx_notifications_user_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_notifications_user_status ON public.notifications USING btree (user_id, status);


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
-- Name: idx_payments_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_payments_tenant_status ON public.payments USING btree (tenant_id, status);


--
-- Name: idx_portal_requests_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_portal_requests_tenant_status ON public.portal_requests USING btree (tenant_id, status);


--
-- Name: idx_projects_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_projects_tenant_status ON public.projects USING btree (tenant_id, status);


--
-- Name: idx_quotations_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_quotations_tenant_status ON public.quotations USING btree (tenant_id, status);


--
-- Name: idx_receivables_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_receivables_tenant_status ON public.receivables USING btree (tenant_id, status);


--
-- Name: idx_recurring_cycles_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_recurring_cycles_tenant_status ON public.recurring_service_cycles USING btree (tenant_id, status);


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
-- Name: idx_suppliers_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_suppliers_tenant ON public.suppliers USING btree (tenant_id);


--
-- Name: idx_support_tickets_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_support_tickets_tenant_status ON public.support_tickets USING btree (tenant_id, status);


--
-- Name: idx_tasks_tenant_status; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tasks_tenant_status ON public.tasks USING btree (tenant_id, status);


--
-- Name: idx_tenant_modules_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tenant_modules_tenant ON public.tenant_modules USING btree (tenant_id);


--
-- Name: idx_tenant_usage_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tenant_usage_tenant ON public.tenant_usage_counters USING btree (tenant_id);


--
-- Name: idx_tenant_users_tenant; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tenant_users_tenant ON public.tenant_users USING btree (tenant_id);


--
-- Name: idx_tenant_users_user; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_tenant_users_user ON public.tenant_users USING btree (user_id);


--
-- Name: idx_time_entries_tenant_date; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_time_entries_tenant_date ON public.time_entries USING btree (tenant_id, started_at);


--
-- Name: idx_user_roles_tenant_user; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_user_roles_tenant_user ON public.user_roles USING btree (tenant_id, user_id);


--
-- Name: idx_webhook_deliveries_pending; Type: INDEX; Schema: public; Owner: erp_user
--

CREATE INDEX idx_webhook_deliveries_pending ON public.webhook_deliveries USING btree (status, next_attempt_at);


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
-- Name: branches trg_branches_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_branches_updated_at BEFORE UPDATE ON public.branches FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: budgets trg_budgets_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_budgets_updated_at BEFORE UPDATE ON public.budgets FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: checklists trg_checklists_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_checklists_updated_at BEFORE UPDATE ON public.checklists FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: companies trg_companies_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_companies_updated_at BEFORE UPDATE ON public.companies FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: contracted_services trg_contracted_services_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_contracted_services_updated_at BEFORE UPDATE ON public.contracted_services FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: contracts trg_contracts_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_contracts_updated_at BEFORE UPDATE ON public.contracts FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


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
-- Name: documents trg_documents_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_documents_updated_at BEFORE UPDATE ON public.documents FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: employees trg_employees_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_employees_updated_at BEFORE UPDATE ON public.employees FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: fiscal_configurations trg_fiscal_configurations_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_fiscal_configurations_updated_at BEFORE UPDATE ON public.fiscal_configurations FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: integration_connections trg_integration_connections_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_integration_connections_updated_at BEFORE UPDATE ON public.integration_connections FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: invoices trg_invoices_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_invoices_updated_at BEFORE UPDATE ON public.invoices FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: orders trg_orders_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_orders_updated_at BEFORE UPDATE ON public.orders FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: parties trg_parties_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_parties_updated_at BEFORE UPDATE ON public.parties FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: portal_requests trg_portal_requests_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_portal_requests_updated_at BEFORE UPDATE ON public.portal_requests FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: projects trg_projects_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_projects_updated_at BEFORE UPDATE ON public.projects FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: quotations trg_quotations_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_quotations_updated_at BEFORE UPDATE ON public.quotations FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


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
-- Name: suppliers trg_suppliers_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_suppliers_updated_at BEFORE UPDATE ON public.suppliers FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


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
-- Name: tenant_users trg_tenant_users_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_tenant_users_updated_at BEFORE UPDATE ON public.tenant_users FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: tenants trg_tenants_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_tenants_updated_at BEFORE UPDATE ON public.tenants FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: users trg_users_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_users_updated_at BEFORE UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: work_orders trg_work_orders_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_work_orders_updated_at BEFORE UPDATE ON public.work_orders FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: workflow_definitions trg_workflow_definitions_updated_at; Type: TRIGGER; Schema: public; Owner: erp_user
--

CREATE TRIGGER trg_workflow_definitions_updated_at BEFORE UPDATE ON public.workflow_definitions FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


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
-- Name: budget_lines budget_lines_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.budget_lines
    ADD CONSTRAINT budget_lines_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


--
-- Name: budgets budgets_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.budgets
    ADD CONSTRAINT budgets_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


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
-- Name: contracted_services contracted_services_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracted_services
    ADD CONSTRAINT contracted_services_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE RESTRICT;


--
-- Name: contracted_services contracted_services_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.contracted_services
    ADD CONSTRAINT contracted_services_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


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
-- Name: cost_centers cost_centers_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.cost_centers
    ADD CONSTRAINT cost_centers_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


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
-- Name: deliverables deliverables_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.deliverables
    ADD CONSTRAINT deliverables_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


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
-- Name: file_objects file_objects_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.file_objects
    ADD CONSTRAINT file_objects_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


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
-- Name: invoices invoices_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE SET NULL;


--
-- Name: invoices invoices_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: invoices invoices_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


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
-- Name: orders orders_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE SET NULL;


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
-- Name: portal_requests portal_requests_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_requests
    ADD CONSTRAINT portal_requests_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: portal_requests portal_requests_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.portal_requests
    ADD CONSTRAINT portal_requests_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


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
-- Name: projects projects_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE SET NULL;


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
-- Name: quotations quotations_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.quotations
    ADD CONSTRAINT quotations_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


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
-- Name: recurring_service_instances recurring_service_instances_contracted_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.recurring_service_instances
    ADD CONSTRAINT recurring_service_instances_contracted_service_id_fkey FOREIGN KEY (contracted_service_id) REFERENCES public.contracted_services(id) ON DELETE RESTRICT;


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
-- Name: support_tickets support_tickets_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE SET NULL;


--
-- Name: support_tickets support_tickets_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: support_tickets support_tickets_sla_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_sla_id_fkey FOREIGN KEY (sla_id) REFERENCES public.support_slas(id) ON DELETE SET NULL;


--
-- Name: support_tickets support_tickets_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.support_tickets
    ADD CONSTRAINT support_tickets_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


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
-- Name: warranty_claims warranty_claims_contract_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES public.contracts(id) ON DELETE SET NULL;


--
-- Name: warranty_claims warranty_claims_customer_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE RESTRICT;


--
-- Name: warranty_claims warranty_claims_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.warranty_claims
    ADD CONSTRAINT warranty_claims_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON DELETE CASCADE;


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
-- Name: work_orders work_orders_assigned_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_assigned_user_id_fkey FOREIGN KEY (assigned_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_contracted_service_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: erp_user
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_contracted_service_id_fkey FOREIGN KEY (contracted_service_id) REFERENCES public.contracted_services(id) ON DELETE SET NULL;


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
-- Name: advances; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.advances ENABLE ROW LEVEL SECURITY;

--
-- Name: audit_logs; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.audit_logs ENABLE ROW LEVEL SECURITY;

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
-- Name: contacts; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.contacts ENABLE ROW LEVEL SECURITY;

--
-- Name: contract_items; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.contract_items ENABLE ROW LEVEL SECURITY;

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
-- Name: outbox_events; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.outbox_events ENABLE ROW LEVEL SECURITY;

--
-- Name: parties; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.parties ENABLE ROW LEVEL SECURITY;

--
-- Name: payment_allocations; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.payment_allocations ENABLE ROW LEVEL SECURITY;

--
-- Name: payments; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.payments ENABLE ROW LEVEL SECURITY;

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
-- Name: suppliers; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.suppliers ENABLE ROW LEVEL SECURITY;

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
-- Name: advances tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.advances USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: audit_logs tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.audit_logs USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


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
-- Name: contacts tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.contacts USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: contract_items tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.contract_items USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


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
-- Name: outbox_events tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.outbox_events USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: parties tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.parties USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: payment_allocations tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.payment_allocations USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: payments tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.payments USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


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
-- Name: suppliers tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.suppliers USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


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
-- Name: tenant_modules tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.tenant_modules USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: tenant_settings tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.tenant_settings USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: tenant_subscriptions tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.tenant_subscriptions USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


--
-- Name: tenant_usage_counters tenant_isolation; Type: POLICY; Schema: public; Owner: erp_user
--

CREATE POLICY tenant_isolation ON public.tenant_usage_counters USING ((tenant_id = public.app_tenant_id())) WITH CHECK ((tenant_id = public.app_tenant_id()));


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
-- Name: tenant_subscriptions; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.tenant_subscriptions ENABLE ROW LEVEL SECURITY;

--
-- Name: tenant_usage_counters; Type: ROW SECURITY; Schema: public; Owner: erp_user
--

ALTER TABLE public.tenant_usage_counters ENABLE ROW LEVEL SECURITY;

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


--
-- Name: TABLE advances; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.advances TO erp_app;


--
-- Name: TABLE audit_logs; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.audit_logs TO erp_app;


--
-- Name: TABLE branches; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.branches TO erp_app;


--
-- Name: TABLE budget_lines; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.budget_lines TO erp_app;


--
-- Name: TABLE budgets; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.budgets TO erp_app;


--
-- Name: TABLE cache; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.cache TO erp_app;


--
-- Name: TABLE cache_locks; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.cache_locks TO erp_app;


--
-- Name: TABLE checklist_items; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.checklist_items TO erp_app;


--
-- Name: TABLE checklists; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.checklists TO erp_app;


--
-- Name: TABLE companies; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.companies TO erp_app;


--
-- Name: TABLE contacts; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.contacts TO erp_app;


--
-- Name: TABLE contract_items; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.contract_items TO erp_app;


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

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.cost_centers TO erp_app;


--
-- Name: TABLE cost_entries; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.cost_entries TO erp_app;


--
-- Name: TABLE credential_access_logs; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.credential_access_logs TO erp_app;


--
-- Name: TABLE credential_records; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.credential_records TO erp_app;


--
-- Name: TABLE credential_versions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.credential_versions TO erp_app;


--
-- Name: TABLE crm_activities; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.crm_activities TO erp_app;


--
-- Name: TABLE crm_campaign_members; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.crm_campaign_members TO erp_app;


--
-- Name: TABLE crm_campaigns; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.crm_campaigns TO erp_app;


--
-- Name: TABLE crm_conversions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.crm_conversions TO erp_app;


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

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.document_links TO erp_app;


--
-- Name: TABLE document_versions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.document_versions TO erp_app;


--
-- Name: TABLE documents; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.documents TO erp_app;


--
-- Name: TABLE employees; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.employees TO erp_app;


--
-- Name: TABLE evidences; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.evidences TO erp_app;


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

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.file_objects TO erp_app;


--
-- Name: TABLE financial_accounts; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.financial_accounts TO erp_app;


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

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.fiscal_series TO erp_app;


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

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.invoices TO erp_app;


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
-- Name: TABLE notifications; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.notifications TO erp_app;


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
-- Name: TABLE payment_allocations; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.payment_allocations TO erp_app;


--
-- Name: TABLE payments; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.payments TO erp_app;


--
-- Name: TABLE permissions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.permissions TO erp_app;


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

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.portal_users TO erp_app;


--
-- Name: TABLE projects; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.projects TO erp_app;


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
-- Name: TABLE receivables; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.receivables TO erp_app;


--
-- Name: TABLE recurring_service_alerts; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.recurring_service_alerts TO erp_app;


--
-- Name: TABLE recurring_service_cycles; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.recurring_service_cycles TO erp_app;


--
-- Name: TABLE recurring_service_instances; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.recurring_service_instances TO erp_app;


--
-- Name: TABLE recurring_service_prices; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.recurring_service_prices TO erp_app;


--
-- Name: TABLE refunds; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.refunds TO erp_app;


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
-- Name: TABLE roles; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.roles TO erp_app;


--
-- Name: TABLE saas_plans; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.saas_plans TO erp_app;


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
-- Name: TABLE suppliers; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.suppliers TO erp_app;


--
-- Name: TABLE support_sla_runs; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.support_sla_runs TO erp_app;


--
-- Name: TABLE support_slas; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.support_slas TO erp_app;


--
-- Name: TABLE support_ticket_assignments; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.support_ticket_assignments TO erp_app;


--
-- Name: TABLE support_ticket_comments; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.support_ticket_comments TO erp_app;


--
-- Name: TABLE support_ticket_events; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.support_ticket_events TO erp_app;


--
-- Name: TABLE support_tickets; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.support_tickets TO erp_app;


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
-- Name: TABLE tenant_modules; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.tenant_modules TO erp_app;


--
-- Name: TABLE tenant_settings; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.tenant_settings TO erp_app;


--
-- Name: TABLE tenant_subscriptions; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.tenant_subscriptions TO erp_app;


--
-- Name: TABLE tenant_usage_counters; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.tenant_usage_counters TO erp_app;


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
-- Name: TABLE users; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.users TO erp_app;


--
-- Name: TABLE warranty_claims; Type: ACL; Schema: public; Owner: erp_user
--

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.warranty_claims TO erp_app;


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
-- PostgreSQL database dump complete
--

\unrestrict 4NW8HgLqknTjuqbit0HBh08eKoVjMa1MaryJaDwANiZoZCD20PCjyC0A2Rvfdqd

