<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Usuarios de la empresa (Administración). Una cuenta (users) pertenece a la
 * empresa por tenant_users y recibe su rol por user_roles; el empleado de CORE
 * es otra entidad y solo se vincula (employees.user_id).
 */
class UserController extends Controller
{
    /** Usuarios de la empresa y lo que el formulario necesita: roles, sedes y empleados por vincular. */
    public function index(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $roles = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.tenant_id', $tenantId)
            ->orderBy('roles.name')
            ->get(['user_roles.user_id', 'roles.id', 'roles.code', 'roles.name'])
            ->groupBy('user_id');

        $employees = DB::table('employees')
            ->join('persons', 'persons.party_id', '=', 'employees.person_party_id')
            ->where('employees.tenant_id', $tenantId)
            ->orderBy('persons.given_names')
            ->get(['employees.id', 'employees.user_id', 'employees.employee_code', 'persons.given_names', 'persons.family_name'])
            ->map(fn ($employee) => [
                'id' => $employee->id,
                'user_id' => $employee->user_id,
                'name' => trim("{$employee->given_names} {$employee->family_name}"),
                'code' => $employee->employee_code,
            ]);

        $extraBranches = DB::table('tenant_user_branches')
            ->join('branches', 'branches.id', '=', 'tenant_user_branches.branch_id')
            ->where('tenant_user_branches.tenant_id', $tenantId)
            ->orderBy('branches.name')
            ->get(['tenant_user_branches.user_id', 'branches.id', 'branches.name'])
            ->groupBy('user_id');

        $users = DB::table('tenant_users')
            ->join('users', 'users.id', '=', 'tenant_users.user_id')
            ->leftJoin('branches', 'branches.id', '=', 'tenant_users.default_branch_id')
            ->leftJoin('teams', 'teams.id', '=', 'tenant_users.team_id')
            ->where('tenant_users.tenant_id', $tenantId)
            ->orderBy('users.name')
            ->get([
                'users.id', 'users.name', 'users.email', 'users.mfa_enabled', 'users.last_login_at',
                'tenant_users.status', 'tenant_users.default_branch_id', 'branches.name as branch_name',
                'tenant_users.team_id', 'teams.name as team_name', 'tenant_users.hourly_cost',
            ])
            ->map(function ($user) use ($roles, $employees, $extraBranches) {
                $role = ($roles[$user->id] ?? collect())->first();
                $isAdmin = $role && strtoupper($role->code) === 'ADMIN';

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'role' => $role ? ['id' => $role->id, 'name' => $role->name, 'is_admin' => $isAdmin] : null,
                    'branch' => $user->default_branch_id ? ['id' => $user->default_branch_id, 'name' => $user->branch_name] : null,
                    'team' => $user->team_id ? ['id' => $user->team_id, 'name' => $user->team_name] : null,
                    'hourly_cost' => (float) $user->hourly_cost,
                    // Sedes adicionales: amplían lo que la persona ve con el alcance "Sede".
                    'extra_branches' => ($extraBranches[$user->id] ?? collect())->map(fn ($branch) => ['id' => $branch->id, 'name' => $branch->name])->values(),
                    'employee_id' => $employees->firstWhere('user_id', $user->id)['id'] ?? null,
                    // El administrador entra con verificación en dos pasos mientras la regla esté encendida.
                    'mfa' => (bool) $user->mfa_enabled || ($isAdmin && config('auth.admin_mfa_required')),
                    'mfa_enabled' => (bool) $user->mfa_enabled,
                    'last_login_at' => $user->last_login_at,
                ];
            });

        return response()->json([
            'users' => $users,
            // La pantalla bloquea la casilla del rol Administrador mientras la regla esté encendida.
            'admin_mfa_required' => (bool) config('auth.admin_mfa_required'),
            'roles' => DB::table('roles')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'code', 'name']),
            'branches' => DB::table('branches')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name']),
            'teams' => DB::table('teams')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name']),
            'employees' => $employees->values(),
            'limit' => $this->userLimit($tenantId),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $this->validated($request, $tenantId);

        if (User::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages(['email' => ['Ya existe un usuario con ese correo.']]);
        }

        $limit = $this->userLimit($tenantId);
        if ($limit !== null && $this->activeUsers($tenantId) >= $limit) {
            throw ValidationException::withMessages(['email' => ["El plan permite {$limit} usuarios activos."]]);
        }

        $user = DB::transaction(function () use ($data, $tenantId) {
            // Nace con una contraseña aleatoria que nadie conoce: la define la persona con el código que recibe.
            $user = User::create([
                'email' => $data['email'],
                'name' => $data['name'],
                'password_hash' => Hash::make(Str::random(40)),
                'status' => 'ACTIVE',
                'mfa_enabled' => (bool) ($data['mfa'] ?? false),
            ]);

            DB::table('tenant_users')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->id,
                'default_branch_id' => $data['branch_id'] ?? null,
                'team_id' => $data['team_id'] ?? null,
                'hourly_cost' => $data['hourly_cost'] ?? 0,
                'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('user_roles')->insert([
                'tenant_id' => $tenantId, 'user_id' => $user->id, 'role_id' => $data['role_id'],
                'scope' => 'TENANT', 'created_at' => now(),
            ]);
            $this->linkEmployee($tenantId, $user->id, $data['employee_id'] ?? null);
            $this->syncExtraBranches($tenantId, $user->id, $data['extra_branch_ids']);

            return $user;
        });

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'user.create',
            entityType: 'User',
            entityId: $user->id,
            metadata: ['email' => $user->email, 'role_id' => $data['role_id'], 'branch_id' => $data['branch_id'] ?? null, 'extra_branch_ids' => $data['extra_branch_ids'], 'team_id' => $data['team_id'] ?? null],
        );

        // Mismo correo que "¿Olvidó su contraseña?": un código para crear la contraseña.
        app(PasswordResetController::class)->forgotPassword(new Request(['email' => $user->email]));

        return response()->json(['id' => $user->id], 201);
    }

    public function update(string $user, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $member = $this->member($tenantId, $user);
        $data = $this->validated($request, $tenantId);

        if (User::where('email', $data['email'])->where('id', '!=', $member->user_id)->exists()) {
            throw ValidationException::withMessages(['email' => ['Ya existe un usuario con ese correo.']]);
        }

        $currentRoleId = DB::table('user_roles')->where('tenant_id', $tenantId)->where('user_id', $member->user_id)->value('role_id');
        $roleChanges = $currentRoleId !== $data['role_id'];

        if ($roleChanges && $member->user_id === $request->user()->id) {
            throw ValidationException::withMessages(['role_id' => ['No puedes cambiar tu propio rol.']]);
        }
        if ($roleChanges && $this->isLastAdmin($tenantId, $member->user_id)) {
            throw ValidationException::withMessages(['role_id' => ['La empresa no puede quedarse sin administrador.']]);
        }

        $extraBefore = DB::table('tenant_user_branches')->where('tenant_id', $tenantId)->where('user_id', $member->user_id)->orderBy('branch_id')->pluck('branch_id')->all();

        DB::transaction(function () use ($data, $tenantId, $member, $roleChanges) {
            User::where('id', $member->user_id)->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'mfa_enabled' => (bool) ($data['mfa'] ?? false),
            ]);
            DB::table('tenant_users')->where('id', $member->id)->update([
                'default_branch_id' => $data['branch_id'] ?? null,
                'team_id' => $data['team_id'] ?? null,
                // Sin indicarlo se conserva el que tenía.
                ...(array_key_exists('hourly_cost', $data) ? ['hourly_cost' => $data['hourly_cost'] ?? 0] : []),
                'updated_at' => now(),
            ]);

            if ($roleChanges) {
                // Un rol por usuario, como en el prototipo.
                DB::table('user_roles')->where('tenant_id', $tenantId)->where('user_id', $member->user_id)->delete();
                DB::table('user_roles')->insert([
                    'tenant_id' => $tenantId, 'user_id' => $member->user_id, 'role_id' => $data['role_id'],
                    'scope' => 'TENANT', 'created_at' => now(),
                ]);
            }
            $this->linkEmployee($tenantId, $member->user_id, $data['employee_id'] ?? null);
            $this->syncExtraBranches($tenantId, $member->user_id, $data['extra_branch_ids']);
        });

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'user.update',
            entityType: 'User',
            entityId: $member->user_id,
            // Rol, sede y equipo deciden qué registros alcanza la persona.
            beforeData: ['role_id' => $currentRoleId, 'branch_id' => $member->default_branch_id, 'extra_branch_ids' => $extraBefore, 'team_id' => $member->team_id],
            afterData: ['role_id' => $data['role_id'], 'branch_id' => $data['branch_id'] ?? null, 'extra_branch_ids' => $data['extra_branch_ids'], 'team_id' => $data['team_id'] ?? null],
        );

        return response()->json(['id' => $member->user_id]);
    }

    /** Activa o desactiva el acceso del usuario a la empresa. */
    public function setStatus(string $user, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $member = $this->member($tenantId, $user);
        $data = $request->validate(['status' => ['required', 'in:ACTIVE,INACTIVE']]);

        if ($data['status'] === 'INACTIVE') {
            if ($member->user_id === $request->user()->id) {
                throw ValidationException::withMessages(['status' => ['No puedes desactivarte a ti mismo.']]);
            }
            if ($this->isLastAdmin($tenantId, $member->user_id)) {
                throw ValidationException::withMessages(['status' => ['La empresa no puede quedarse sin administrador.']]);
            }
        } elseif ($member->status !== 'ACTIVE') {
            $limit = $this->userLimit($tenantId);
            if ($limit !== null && $this->activeUsers($tenantId) >= $limit) {
                throw ValidationException::withMessages(['status' => ["El plan permite {$limit} usuarios activos."]]);
            }
        }

        DB::table('tenant_users')->where('id', $member->id)->update(['status' => $data['status'], 'updated_at' => now()]);

        if ($data['status'] === 'INACTIVE') {
            // Sus sesiones abiertas dejan de servir de inmediato.
            DB::table('personal_access_tokens')->where('tokenable_id', $member->user_id)->delete();
        }

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'user.status',
            entityType: 'User',
            entityId: $member->user_id,
            beforeData: ['status' => $member->status],
            afterData: ['status' => $data['status']],
        );

        return response()->json(['id' => $member->user_id, 'status' => $data['status']]);
    }

    /** Deja a la persona exactamente con esas sedes adicionales. */
    private function syncExtraBranches(string $tenantId, string $userId, array $branchIds): void
    {
        $assigned = DB::table('tenant_user_branches')->where('tenant_id', $tenantId)->where('user_id', $userId);
        (clone $assigned)->whereNotIn('branch_id', $branchIds)->delete();

        foreach (array_diff($branchIds, (clone $assigned)->pluck('branch_id')->all()) as $branchId) {
            DB::table('tenant_user_branches')->insert(['tenant_id' => $tenantId, 'user_id' => $userId, 'branch_id' => $branchId]);
        }
    }

    private function validated(Request $request, string $tenantId): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role_id' => ['required', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'extra_branch_ids' => ['sometimes', 'array', 'max:50'],
            'extra_branch_ids.*' => ['uuid'],
            'team_id' => ['nullable', 'uuid'],
            // Costo por hora de la persona: lo toman sus horas al registrarse (Horas y costos).
            'hourly_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999'],
            'employee_id' => ['nullable', 'uuid'],
            'mfa' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Indica el nombre completo.',
            'email.required' => 'Indica el correo.',
            'email.email' => 'El correo no es válido.',
            'role_id.required' => 'Selecciona un rol.',
        ]);

        $data['email'] = strtolower(trim($data['email']));

        // Rol, sede, equipo y empleado deben ser de esta empresa (las cuatro tablas están bajo RLS).
        if (! DB::table('roles')->where('tenant_id', $tenantId)->where('id', $data['role_id'])->exists()) {
            throw ValidationException::withMessages(['role_id' => ['Selecciona un rol.']]);
        }
        if (! empty($data['branch_id']) && ! DB::table('branches')->where('tenant_id', $tenantId)->where('id', $data['branch_id'])->exists()) {
            throw ValidationException::withMessages(['branch_id' => ['Sede no válida.']]);
        }
        // Sedes adicionales: de esta empresa, sin repetir la principal, y solo si hay principal
        // (lo que la persona crea tiene que nacer en alguna).
        $extra = array_values(array_unique(array_diff($data['extra_branch_ids'] ?? [], [$data['branch_id'] ?? null])));
        if ($extra && empty($data['branch_id'])) {
            throw ValidationException::withMessages(['extra_branch_ids' => ['Elige primero la sede principal.']]);
        }
        if ($extra && DB::table('branches')->where('tenant_id', $tenantId)->whereIn('id', $extra)->count() !== count($extra)) {
            throw ValidationException::withMessages(['extra_branch_ids' => ['Sede no válida.']]);
        }
        sort($extra);
        $data['extra_branch_ids'] = $extra;

        if (! empty($data['team_id']) && ! DB::table('teams')->where('tenant_id', $tenantId)->where('id', $data['team_id'])->exists()) {
            throw ValidationException::withMessages(['team_id' => ['Equipo no válido.']]);
        }
        if (! empty($data['employee_id']) && ! DB::table('employees')->where('tenant_id', $tenantId)->where('id', $data['employee_id'])->exists()) {
            throw ValidationException::withMessages(['employee_id' => ['Empleado no válido.']]);
        }

        return $data;
    }

    /** Membresía del usuario en esta empresa; un usuario de otra empresa "no existe". */
    private function member(string $tenantId, string $userId): object
    {
        $member = Str::isUuid($userId)
            ? DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $userId)->first()
            : null;
        abort_if(! $member, 404);

        return $member;
    }

    /** Deja el empleado indicado vinculado a la cuenta, y solo ese. */
    private function linkEmployee(string $tenantId, string $userId, ?string $employeeId): void
    {
        DB::table('employees')->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->when($employeeId, fn ($query) => $query->where('id', '!=', $employeeId))
            ->update(['user_id' => null]);

        if ($employeeId) {
            $taken = DB::table('employees')->where('id', $employeeId)->whereNotNull('user_id')->where('user_id', '!=', $userId)->exists();
            if ($taken) {
                throw ValidationException::withMessages(['employee_id' => ['Ese empleado ya está vinculado a otro usuario.']]);
            }
            DB::table('employees')->where('tenant_id', $tenantId)->where('id', $employeeId)->update(['user_id' => $userId]);
        }
    }

    /** ¿Es el único administrador activo de la empresa? */
    private function isLastAdmin(string $tenantId, string $userId): bool
    {
        $admins = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('tenant_users', fn ($join) => $join
                ->on('tenant_users.user_id', '=', 'user_roles.user_id')
                ->on('tenant_users.tenant_id', '=', 'user_roles.tenant_id'))
            ->where('user_roles.tenant_id', $tenantId)
            ->where('tenant_users.status', 'ACTIVE')
            ->whereRaw('UPPER(roles.code) = ?', ['ADMIN'])
            ->pluck('user_roles.user_id')
            ->unique();

        return $admins->count() === 1 && $admins->first() === $userId;
    }

    private function activeUsers(string $tenantId): int
    {
        return DB::table('tenant_users')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->count();
    }

    /** Usuarios activos que permite el plan de la empresa; null si no tiene plan o el plan no lo limita. */
    private function userLimit(string $tenantId): ?int
    {
        $limits = DB::table('tenant_subscriptions')
            ->join('saas_plans', 'saas_plans.id', '=', 'tenant_subscriptions.plan_id')
            ->where('tenant_subscriptions.tenant_id', $tenantId)
            ->where('tenant_subscriptions.status', 'ACTIVE')
            ->orderByDesc('tenant_subscriptions.starts_at')
            ->value('saas_plans.limits');

        $users = json_decode($limits ?: '{}', true)['users'] ?? null;

        return is_numeric($users) && $users > 0 ? (int) $users : null;
    }
}
