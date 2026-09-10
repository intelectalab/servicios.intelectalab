# ERP SaaS Multi-Tenant para Empresas de Servicios

ERP modular, multi-tenant, para empresas de servicios. Backend en Laravel 12 / PHP 8.4, frontend en Next.js, base de datos PostgreSQL 17 con Row Level Security (RLS) para aislamiento entre tenants.

## Stack

- **Backend:** Laravel 12 / PHP 8.4
- **Frontend:** Next.js + React + Tailwind CSS
- **Base de datos:** PostgreSQL 17 (con RLS activo)
- **Cache/colas:** Redis 8
- **Autenticación:** Laravel Sanctum
- **Contenedores:** Docker

## Prerrequisitos

- Git
- Docker + Docker Compose
- PHP 8.4 con extensiones `pdo_pgsql`, `pgsql` habilitadas
- Composer
- Node.js (LTS) + npm

## Instalación

### 1. Levantar la infraestructura (Postgres + Redis)

```bash
docker compose up -d postgres redis
docker compose ps   # ambos deben estar "healthy"
```

### 2. Restaurar el esquema de base de datos

Si es la primera vez que levantas el proyecto, restaura la estructura de tablas:

```bash
psql -h 127.0.0.1 -U erp_user -d erp_saas -f database/schema_reference.sql
```

### 3. Backend (Laravel)

```bash
cd apps/api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Backend disponible en `http://localhost:8000`.

### 4. Frontend (Next.js)

```bash
cd apps/web
npm install
npm run dev
```

Frontend disponible en `http://localhost:3000`.

## ⚠️⚠️ Importante: dos usuarios de base de datos distintos

Este proyecto usa **Row Level Security (RLS)** en PostgreSQL para aislar los datos entre tenants. Por eso se necesitan dos usuarios de base de datos con roles distintos:

- **`erp_user`**: dueño de las tablas. Úsalo solo para correr migraciones (`php artisan migrate`). Este usuario **se salta el RLS** por ser el dueño, así que nunca debe usarlo la aplicación en funcionamiento normal.
- **`erp_app`**: usuario de la aplicación día a día. Este es el que debe estar configurado en tu `.env` (`DB_USERNAME=erp_app`) para que el RLS funcione correctamente.

Para crear `erp_app` en un ambiente nuevo:

```bash
psql -h 127.0.0.1 -U erp_user -d erp_saas -c "CREATE ROLE erp_app WITH LOGIN PASSWORD 'erp_app_password';"
psql -h 127.0.0.1 -U erp_user -d erp_saas -c "GRANT USAGE ON SCHEMA public TO erp_app;"
psql -h 127.0.0.1 -U erp_user -d erp_saas -c "GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO erp_app;"
psql -h 127.0.0.1 -U erp_user -d erp_saas -c "GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO erp_app;"
```

## Arquitectura de seguridad (ya implementada)

1. **Autenticación:** Laravel Sanctum (tokens de API).
2. **TenantContext:** middleware `tenant.context` que resuelve el tenant del usuario autenticado (vía la función SQL `resolve_active_tenant_for_user`, con `SECURITY DEFINER`) y ejecuta `SET app.tenant_id` en la conexión — de ahí en adelante, el RLS de PostgreSQL filtra automáticamente todas las consultas por tenant.
3. **RBAC:** middleware `permission:codigo.permiso` que verifica, vía `User::hasPermission()`, si el usuario tiene el permiso requerido (a través de `user_roles` → `role_permissions` → `permissions`).
4. **Audit Log:** servicio `App\Services\AuditLogger`, usado en acciones sensibles (login, logout, y las que se agreguen a futuro) para dejar trazabilidad en la tabla `audit_logs`.

## Tests

```bash
cd apps/api
php artisan test
```

Incluye `TenantIsolationTest`, que verifica de forma automatizada que un usuario de un tenant no puede ver datos de otro tenant (Prueba Obligatoria #1 del proyecto).

## Estructura del proyecto

```
erp-saas/
├── apps/
│   ├── web/        (frontend Next.js)
│   └── api/        (backend Laravel)
├── modules/        (organización interna del backend por dominio de negocio)
├── database/       (migraciones, seeders, schema_reference.sql)
├── infrastructure/ (Docker, Terraform)
└── .github/workflows/ (CI)
```