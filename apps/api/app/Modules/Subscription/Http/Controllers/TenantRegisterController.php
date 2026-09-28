<?php

namespace App\Modules\Subscription\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Company;
use App\Models\User;
use App\Models\TenantUser;
use App\Modules\Subscription\Models\SaasPlan;
use App\Modules\Subscription\Models\TenantSubscription;
use App\Modules\Subscription\Models\TenantModule;
use App\Modules\Subscription\Http\Requests\StoreTenantRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TenantRegisterController extends Controller
{
    public function register(StoreTenantRequest $request)
    {
        $validated = $request->validated();

        return DB::transaction(function () use ($validated) {
            // 1. Crear el Tenant principal
            $tenantCode = 'EMP-' . strtoupper(substr(uniqid(), -6));
            $tenant = Tenant::create([
                'code' => $tenantCode,
                'name' => $validated['razon_social'],
                'legal_name' => $validated['razon_social'],
                'status' => 'ACTIVE',
                'timezone' => 'America/Lima',
                'locale' => 'es-PE',
                'currency' => 'PEN',
                'configuration' => [],
            ]);

            // Establecer el tenant_id en la sesión de base de datos para RLS
            DB::statement("SET app.tenant_id = '{$tenant->id}'");

            // 2. Crear la Company (datos fiscales/tributarios)
            Company::create([
                'tenant_id' => $tenant->id,
                'code' => 'COMP-01',
                'legal_name' => $validated['razon_social'],
                'trade_name' => $validated['nombre_comercial'] ?? $validated['razon_social'],
                'tax_identifier' => $validated['ruc'],
                'tax_country' => 'PE',
                'status' => 'ACTIVE',
                'address' => [
                    'direccion' => $validated['direccion'] ?? '',
                    'departamento' => $validated['departamento'] ?? '',
                    'distrito' => $validated['distrito'] ?? '',
                    'telefono' => $validated['telefono'] ?? '',
                ],
                'configuration' => [],
            ]);

            // 3. Crear el Usuario Administrador global
            $user = User::create([
                'email' => $validated['email'],
                'password_hash' => Hash::make($validated['password']),
                'name' => $validated['nombres'] . ' ' . $validated['apellidos'],
                'status' => 'ACTIVE',
                'mfa_enabled' => false,
            ]);

            // 4. Vincular usuario al tenant en tenant_users
            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'status' => 'ACTIVE',
            ]);

            // 5. Crear el Rol de Administrador para este Tenant (una sola vez)
            $roleId = DB::table('roles')->insertGetId([
                'tenant_id' => $tenant->id,
                'code' => 'ADMIN',
                'name' => 'Administrador',
                'description' => 'Administrador general del tenant según plan',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'id');

            // Asignar el rol al usuario en user_roles
            DB::table('user_roles')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role_id' => $roleId,
                'scope' => 'TENANT',
                'created_at' => now(),
            ]);

            // 6. Buscar el Plan SaaS seleccionado y procesar módulos/permisos
            $plan = SaasPlan::findOrFail($validated['plan_id']);
            
            // Diccionario para relacionar módulos con permisos en la tabla 'permissions'
            $modulePermissionMap = [
                'CLIENTES' => ['customers', 'parties', 'contacts'],
                'PROVEEDORES' => ['suppliers'],
                'EMPLEADOS' => ['employees'],
                'SERVICIOS' => ['services'],
                'COTIZACIONES' => ['quotations'],
                'CRM' => ['crm'],
                'FINANZAS' => ['invoices', 'payments', 'receivables', 'budgets'],
            ];

            // Permisos base obligatorios para que el usuario pueda ver el core y gestionar el tenant
            $permissionCodesToAssign = ['core.view', 'core.manage', 'users.view', 'users.manage', 'tenant.manage'];

            // Recorrer los módulos habilitados en el JSON del plan
            if (!empty($plan->modules) && is_array($plan->modules)) {
                foreach ($plan->modules as $moduleCode => $isEnabled) {
                    if ($isEnabled) {
                        $upperCode = strtoupper($moduleCode);
                        
                        // Si el módulo está mapeado, agregamos sus prefijos de permisos permitidos
                        if (isset($modulePermissionMap[$upperCode])) {
                            foreach ($modulePermissionMap[$upperCode] as $prefix) {
                                $permissionCodesToAssign[] = "{$prefix}.view";
                                $permissionCodesToAssign[] = "{$prefix}.manage";
                            }
                        }

                        // Guardar en la tabla tenant_modules
                        TenantModule::create([
                            'tenant_id' => $tenant->id,
                            'module_code' => $upperCode,
                            'enabled' => true,
                            'activated_at' => now(),
                            'configuration' => [],
                        ]);
                    }
                }
            }

            // Buscar los IDs de todos los permisos calculados y asociarlos al rol en role_permissions
            $permissionIds = DB::table('permissions')
                ->whereIn('code', array_unique($permissionCodesToAssign))
                ->pluck('id');

            foreach ($permissionIds as $permId) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permId,
                ]);
            }

            $hasTrial = $plan->has_trial ?? false;
            $trialDays = $hasTrial ? ($plan->trial_days ?? 30) : 0;
            
            $startsAt = now();
            $endsAt = $trialDays > 0 ? now()->addDays($trialDays) : ($validated['billing_period'] === 'ANNUAL' ? now()->addYear() : now()->addMonth());

            // 7. Registrar la suscripción del tenant
            TenantSubscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => 'ACTIVE',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'configuration' => [
                    'billing_period' => $validated['billing_period'],
                    'is_trial' => $hasTrial,
                    'trial_days' => $trialDays,
                ],
            ]);

            return response()->json([
                'message' => 'Empresa, permisos y cuenta de administrador creadas con éxito.',
                'tenant_code' => $tenant->code,
            ], 201);
        });
    }
}