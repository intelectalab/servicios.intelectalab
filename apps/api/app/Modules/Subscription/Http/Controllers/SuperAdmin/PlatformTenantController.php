<?php

namespace App\Modules\Subscription\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Controller;
use App\Mail\TenantInvitationMail;
use App\Mail\TenantLoginLinkMail;
use App\Models\User;
use App\Modules\Subscription\Models\SaasPlan;
use App\Modules\Subscription\Support\ModuleCatalog;
use App\Modules\Subscription\Support\TenantPlanModules;
use App\Modules\Subscription\Support\TenantProvisioner;
use App\Services\PlatformAuditLogger;
use App\Services\PlatformWrites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tenants de la consola de plataforma. Las lecturas van con el usuario de la
 * consola (solo datos de plataforma); los cambios los hace la app dentro del
 * contexto de la empresa, en una transacción, y quedan en la auditoría de
 * plataforma. Lo que toca planes o suscripciones va con PlatformWrites (la base
 * lo exige). Desactivar o cerrar nunca borra datos.
 */
class PlatformTenantController extends Controller
{
    private const CONSOLE = PlatformDashboardController::CONNECTION;

    /** Estados de la empresa y a cuáles puede pasar. */
    public const TRANSITIONS = [
        'PROVISIONING' => ['ACTIVE', 'CLOSED'],
        'ACTIVE' => ['SUSPENDED', 'MAINTENANCE', 'CLOSED'],
        'SUSPENDED' => ['ACTIVE', 'CLOSED'],
        'MAINTENANCE' => ['ACTIVE', 'SUSPENDED', 'CLOSED'],
        'CLOSED' => ['ACTIVE'],
    ];

    /** Días que vale el código de la invitación al administrador. */
    private const INVITATION_DAYS = 3;

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->console('SELECT platform_tenants() AS data')]);
    }

    public function show(string $tenant): JsonResponse
    {
        abort_unless(Str::isUuid($tenant), 404);
        $data = $this->console('SELECT platform_tenant(?) AS data', [$tenant]);
        abort_if($data === null, 404);

        $data['payments'] = $this->payments($tenant);
        // Enlace de ingreso con su marca (/e/…).
        $data['login_slug'] = \App\Services\LoginSlug::of($tenant);

        return response()->json([
            'tenant' => $data,
            'transitions' => self::TRANSITIONS[$data['status']] ?? [],
            'catalog' => ModuleCatalog::modules(self::CONSOLE),
            'plans' => $this->plans(),
        ]);
    }

    /** Catálogo de módulos del ERP con sus reglas (ventana de planes). */
    public function modules(): JsonResponse
    {
        return response()->json(ModuleCatalog::modules(self::CONSOLE));
    }

    /** Planes y módulos para Aprovisionar tenant y Cambiar plan. */
    public function options(): JsonResponse
    {
        return response()->json(['plans' => $this->plans(), 'catalog' => ModuleCatalog::modules(self::CONSOLE)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ruc' => ['required', 'regex:/^(10|20)\d{9}$/', function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                $taken = DB::selectOne('SELECT 1 AS x FROM superadmin_companies(?) WHERE tax_identifier = ? LIMIT 1', [$request->user()->id, $value]);
                if ($taken) {
                    $fail('Ya existe una empresa con ese RUC.');
                }
            }],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:120'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'plan_id' => ['required', 'uuid'],
            'billing_period' => ['required', 'in:MONTHLY,ANNUAL'],
            'trial' => ['required', 'boolean'],
            'crm' => ['sometimes', 'boolean'],
            'portal' => ['sometimes', 'boolean'],
        ], [
            'ruc.regex' => 'RUC inválido: 11 dígitos que empiezan con 10 o 20.',
            'admin_email.unique' => 'Ya existe una cuenta con ese correo.',
        ]);

        $plan = SaasPlan::where('id', $data['plan_id'])->where('active', true)->first();
        if (! $plan) {
            throw ValidationException::withMessages(['plan_id' => ['Elige un plan activo.']]);
        }

        $userId = $request->user()->id;

        // Módulos del plan; CRM y Portal se pueden dejar apagados aunque el plan los incluya.
        $modules = collect(ModuleCatalog::normalizePlanModules($plan->modules ?? []))->keys()
            ->reject(fn (string $code) => ($code === 'CRM' && ! ($data['crm'] ?? true)) || ($code === 'PORTAL' && ! ($data['portal'] ?? true)))
            ->values()->all();

        $created = PlatformWrites::run(function () use ($data, $plan, $modules, $userId) {
            $created = TenantProvisioner::create([
                'name' => $data['name'],
                'legal_name' => $data['legal_name'] ?: $data['name'],
                'trade_name' => $data['name'],
                'ruc' => $data['ruc'],
                'branch' => $data['branch'] ?: 'Principal',
                'admin_name' => $data['admin_name'],
                'admin_email' => mb_strtolower($data['admin_email']),
                // Nadie conoce esta clave: el administrador crea la suya con la invitación.
                'password_hash' => Hash::make(Str::random(64)),
                'plan' => $plan,
                'billing_period' => $data['billing_period'],
                'trial' => (bool) $data['trial'] && (bool) $plan->has_trial,
                'status' => 'PROVISIONING',
                'modules' => $modules,
            ]);
            $tenant = $created['tenant'];

            $this->logStatus($tenant->id, null, 'PROVISIONING', 'Aprovisionada desde la consola', $userId);
            PlatformAuditLogger::log($userId, 'tenant.provisioned', 'Tenant', $tenant->id, afterData: [
                'code' => $tenant->code, 'name' => $tenant->name, 'ruc' => $data['ruc'], 'plan' => $plan->name,
                'billing_period' => $data['billing_period'], 'trial' => $created['hasTrial'], 'modules' => $modules,
                'admin_email' => $created['user']->email,
            ]);

            return $tenant;
        });

        return response()->json(['id' => $created->id, 'code' => $created->code], 201);
    }

    public function transition(Request $request, string $tenant): JsonResponse
    {
        abort_unless(Str::isUuid($tenant), 404);
        $data = $request->validate([
            'from' => ['required', 'string'],
            'to' => ['required', 'in:'.implode(',', array_keys(self::TRANSITIONS))],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($data['to'] !== 'ACTIVE' && $reason === '') {
            throw ValidationException::withMessages(['reason' => ['Indica el motivo: queda en el historial y en la auditoría.']]);
        }

        $userId = $request->user()->id;
        $invite = DB::transaction(function () use ($tenant, $data, $reason, $userId) {
            $row = DB::table('tenants')->where('id', $tenant)->lockForUpdate()->first(['id', 'status']);
            abort_if(! $row, 404);
            if ($row->status !== $data['from']) {
                abort(response()->json(['code' => 'STALE_STATUS', 'message' => 'El estado de la empresa cambió mientras tanto. Vuelve a cargar la ficha.'], 409));
            }
            if (! in_array($data['to'], self::TRANSITIONS[$row->status] ?? [], true)) {
                abort(response()->json(['code' => 'INVALID_TRANSITION', 'message' => 'Ese cambio de estado no está permitido.'], 422));
            }

            DB::table('tenants')->where('id', $tenant)->update(['status' => $data['to'], 'updated_at' => now()]);
            $this->logStatus($tenant, $row->status, $data['to'], $reason ?: null, $userId);
            PlatformAuditLogger::log($userId, 'tenant.status_changed', 'Tenant', $tenant,
                ['status' => $row->status], ['status' => $data['to']], ['reason' => $reason ?: null]);

            // Al habilitarse por primera vez se invita a sus administradores que aún no han entrado.
            return $row->status === 'PROVISIONING' && $data['to'] === 'ACTIVE';
        });

        $invited = $invite ? $this->inviteAdmins($tenant, $userId) : [];

        return response()->json(['status' => $data['to'], 'invited' => $invited]);
    }

    /**
     * Registrar un pago (transferencia, depósito, Yape…) y extender la vigencia hasta la fecha
     * elegida. La fecha va después de la vigencia actual (o de hoy, si ya venció): para un
     * error de digitación se registra otro pago, nunca se acorta. El pago queda en su tabla.
     */
    public function extendSubscription(Request $request, string $tenant): JsonResponse
    {
        abort_unless(Str::isUuid($tenant), 404);
        $data = $request->validate([
            'ends_at' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'payment_method' => ['required', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'months' => ['nullable', 'integer', 'min:1', 'max:120'],
        ], [
            'ends_at.required' => 'Elige hasta qué fecha queda vigente.',
            'amount.required' => 'Indica el monto pagado.',
            'payment_method.required' => 'Indica el medio de pago.',
        ]);

        $user = $request->user();

        $result = PlatformWrites::run(function () use ($tenant, $data, $user) {
            abort_unless(DB::table('tenants')->where('id', $tenant)->lockForUpdate()->exists(), 404);
            $this->enterTenant($tenant);

            $sub = DB::table('tenant_subscriptions')
                ->where('tenant_id', $tenant)
                ->where('status', 'ACTIVE')
                ->orderByDesc('starts_at')
                ->lockForUpdate()
                ->first();

            $newEndsAt = \Illuminate\Support\Carbon::parse($data['ends_at'])->endOfDay();
            $current = $sub?->ends_at ? \Illuminate\Support\Carbon::parse($sub->ends_at) : null;
            $floor = $current && $current->isFuture() ? $current : now();
            if ($newEndsAt->lte($floor)) {
                throw ValidationException::withMessages(['ends_at' => [
                    $current && $current->isFuture()
                        // En hora de Perú, como la muestra la ficha (la base guarda en UTC).
                        ? 'La nueva vigencia debe ser posterior a la actual ('.$current->copy()->timezone('America/Lima')->format('d/m/Y').').'
                        : 'La nueva vigencia debe ser una fecha futura.',
                ]]);
            }

            // Sin suscripción: la del primer plan activo, con sus módulos.
            $plan = $sub ? SaasPlan::find($sub->plan_id) : SaasPlan::where('active', true)->orderBy('display_order')->first();
            abort_unless($plan, 422, 'No hay ningún plan activo para crear la suscripción.');
            $config = $sub ? (json_decode((string) $sub->configuration, true) ?: []) : ['billing_period' => 'MONTHLY', 'trial_days' => 0];
            $config['is_trial'] = false;

            if ($sub) {
                DB::table('tenant_subscriptions')->where('id', $sub->id)->update([
                    'ends_at' => $newEndsAt,
                    'configuration' => json_encode($config),
                    'updated_at' => now(),
                ]);
                $subscriptionId = $sub->id;
            } else {
                $subscriptionId = (string) Str::uuid();
                DB::table('tenant_subscriptions')->insert([
                    'id' => $subscriptionId, 'tenant_id' => $tenant, 'plan_id' => $plan->id, 'status' => 'ACTIVE',
                    'starts_at' => now(), 'ends_at' => $newEndsAt, 'configuration' => json_encode($config),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                TenantPlanModules::apply($tenant, $plan);
            }

            $paymentRecord = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant,
                'subscription_id' => $subscriptionId,
                'amount' => round((float) $data['amount'], 2),
                'currency' => $plan->currency ?: 'PEN',
                'payment_method' => trim($data['payment_method']),
                'reference' => trim((string) ($data['reference'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'months' => isset($data['months']) ? (int) $data['months'] : null,
                'previous_ends_at' => $sub?->ends_at,
                'new_ends_at' => $newEndsAt,
                'created_by' => $user->id,
                'created_by_name' => $user->name,
                'created_at' => now(),
            ];
            DB::table('tenant_subscription_payments')->insert($paymentRecord);
            $paymentRecord = $this->paymentRow((object) $paymentRecord);

            $userId = $user->id;
            PlatformAuditLogger::log(
                $userId,
                'tenant.subscription_extended',
                'Tenant',
                $tenant,
                beforeData: ['ends_at' => $sub?->ends_at],
                afterData: ['ends_at' => $newEndsAt->toIso8601String(), 'amount' => (float) $data['amount']],
                metadata: $paymentRecord
            );

            return [
                'payment' => $paymentRecord,
                'ends_at' => $newEndsAt->toIso8601String(),
            ];
        });

        return response()->json($result);
    }

    /** Cambiar el enlace de ingreso de la empresa (/e/{nombre-corto}); el anterior deja de funcionar. */
    public function updateLoginSlug(Request $request, string $tenant): JsonResponse
    {
        abort_unless(Str::isUuid($tenant), 404);
        abort_unless(DB::table('tenants')->where('id', $tenant)->exists(), 404);
        $data = $request->validate(['login_slug' => ['required', 'string', 'max:40']], ['login_slug.required' => 'Escribe el nombre corto del enlace.']);
        $slug = \App\Services\LoginSlug::validate($data['login_slug'], $tenant);
        $before = \App\Services\LoginSlug::of($tenant);

        DB::table('tenants')->where('id', $tenant)->update(['login_slug' => $slug, 'updated_at' => now()]);
        PlatformAuditLogger::log($request->user()->id, 'tenant.login_slug_changed', 'Tenant', $tenant,
            beforeData: ['login_slug' => $before], afterData: ['login_slug' => $slug]);
        \App\Services\LoginSlug::forget($before, $slug);

        return response()->json(['login_slug' => $slug]);
    }

    /** Enviar a los administradores activos de la empresa su enlace de ingreso con la marca. */
    public function sendLoginLink(Request $request, string $tenant): JsonResponse
    {
        abort_unless(Str::isUuid($tenant), 404);
        $row = DB::table('tenants')->where('id', $tenant)->first(['name', 'status', 'login_slug']);
        abort_if(! $row, 404);
        if ($row->status !== 'ACTIVE' || ! $row->login_slug) {
            throw ValidationException::withMessages(['tenant' => [$row->status !== 'ACTIVE'
                ? 'La empresa no está activa: su enlace de ingreso no funciona todavía.'
                : 'La empresa no tiene enlace de ingreso.']]);
        }

        $emails = DB::transaction(function () use ($tenant) {
            $this->enterTenant($tenant);

            return DB::table('user_roles')
                ->join('roles', fn ($join) => $join->on('roles.id', '=', 'user_roles.role_id')->whereRaw('upper(roles.code) = ?', ['ADMIN']))
                ->join('tenant_users', fn ($join) => $join->on('tenant_users.user_id', '=', 'user_roles.user_id')
                    ->where('tenant_users.tenant_id', $tenant)->where('tenant_users.status', 'ACTIVE'))
                ->join('users', 'users.id', '=', 'user_roles.user_id')
                ->where('user_roles.tenant_id', $tenant)->where('users.status', 'ACTIVE')
                ->distinct()->pluck('users.email');
        });
        if ($emails->isEmpty()) {
            throw ValidationException::withMessages(['tenant' => ['La empresa no tiene administradores activos a quienes enviarlo.']]);
        }

        $url = rtrim((string) config('app.frontend_url'), '/').'/e/'.$row->login_slug;
        $sent = [];
        foreach ($emails as $email) {
            try {
                Mail::to($email)->send((new TenantLoginLinkMail($row->name, $url))->withBrand($tenant));
                $sent[] = $email;
            } catch (Throwable $e) {
                report($e);
            }
        }
        PlatformAuditLogger::log($request->user()->id, 'tenant.login_link_sent', 'Tenant', $tenant,
            afterData: ['login_slug' => $row->login_slug], metadata: ['sent' => $sent]);

        return response()->json(['sent' => $sent, 'failed' => $emails->diff($sent)->values()]);
    }

    public function changePlan(Request $request, string $tenant): JsonResponse
    {
        abort_unless(Str::isUuid($tenant), 404);
        $data = $request->validate([
            'plan_id' => ['required', 'uuid'],
            'billing_period' => ['sometimes', 'in:MONTHLY,ANNUAL'],
        ]);
        $plan = SaasPlan::where('id', $data['plan_id'])->where('active', true)->first();
        if (! $plan) {
            throw ValidationException::withMessages(['plan_id' => ['Elige un plan activo.']]);
        }
        $userId = $request->user()->id;

        $result = PlatformWrites::run(function () use ($tenant, $plan, $data, $userId) {
            abort_unless(DB::table('tenants')->where('id', $tenant)->lockForUpdate()->exists(), 404);
            $this->enterTenant($tenant);

            $limit = (int) ($plan->limits['users'] ?? $plan->limits['max_users'] ?? 0);
            $active = DB::table('tenant_users')->join('users', 'users.id', '=', 'tenant_users.user_id')
                ->where('tenant_users.tenant_id', $tenant)->where('tenant_users.status', 'ACTIVE')->where('users.status', 'ACTIVE')->count();
            if ($limit > 0 && $active > $limit) {
                abort(response()->json([
                    'code' => 'PLAN_LIMIT_REACHED',
                    'message' => "La empresa tiene {$active} usuarios activos y el plan {$plan->name} permite {$limit}. Desactiva usuarios antes de bajar de plan.",
                ], 422));
            }

            $current = DB::table('tenant_subscriptions')->where('tenant_id', $tenant)->where('status', 'ACTIVE')
                ->orderByDesc('starts_at')->lockForUpdate()->first();
            $before = $current ? ['plan_id' => $current->plan_id, 'plan' => SaasPlan::find($current->plan_id)?->name] : null;

            if ($current) {
                // Se cambia el plan de la suscripción vigente; vigencia, periodo y prueba se conservan.
                DB::table('tenant_subscriptions')->where('id', $current->id)->update(['plan_id' => $plan->id, 'updated_at' => now()]);
            } else {
                $period = $data['billing_period'] ?? $plan->default_billing_period ?? 'MONTHLY';
                DB::table('tenant_subscriptions')->insert([
                    'tenant_id' => $tenant, 'plan_id' => $plan->id, 'status' => 'ACTIVE', 'starts_at' => now(),
                    'ends_at' => $period === 'ANNUAL' ? now()->addYear() : now()->addMonth(),
                    'configuration' => json_encode(['billing_period' => $period, 'is_trial' => false, 'trial_days' => 0]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $modules = TenantPlanModules::apply($tenant, $plan);
            PlatformAuditLogger::log($userId, 'tenant.plan_changed', 'Tenant', $tenant, $before,
                ['plan_id' => $plan->id, 'plan' => $plan->name], $modules);

            return $modules;
        });

        return response()->json($result);
    }

    public function applyPlanModules(Request $request, string $tenant): JsonResponse
    {
        abort_unless(Str::isUuid($tenant), 404);
        $userId = $request->user()->id;

        $result = DB::transaction(function () use ($tenant, $userId) {
            abort_unless(DB::table('tenants')->where('id', $tenant)->lockForUpdate()->exists(), 404);
            $this->enterTenant($tenant);
            $planId = DB::table('tenant_subscriptions')->where('tenant_id', $tenant)->where('status', 'ACTIVE')
                ->orderByDesc('starts_at')->value('plan_id');
            if (! $planId) {
                abort(response()->json(['code' => 'NO_SUBSCRIPTION', 'message' => 'La empresa no tiene suscripción: asígnale un plan primero.'], 422));
            }

            $plan = SaasPlan::findOrFail($planId);
            $modules = TenantPlanModules::apply($tenant, $plan);
            PlatformAuditLogger::log($userId, 'tenant.modules_applied', 'Tenant', $tenant, afterData: ['plan' => $plan->name], metadata: $modules);

            return $modules;
        });

        return response()->json($result);
    }

    public function resendInvitation(Request $request, string $tenant, string $user): JsonResponse
    {
        abort_unless(Str::isUuid($tenant) && Str::isUuid($user), 404);
        $status = DB::table('tenants')->where('id', $tenant)->value('status');
        abort_if(! $status, 404);
        if ($status !== 'ACTIVE') {
            abort(response()->json(['code' => 'TENANT_NOT_ACTIVE', 'message' => 'Activa la empresa antes de invitar a su administrador.'], 422));
        }

        $sent = $this->inviteAdmins($tenant, $request->user()->id, $user);
        abort_if($sent === [], 404);

        return response()->json(['invited' => $sent]);
    }

    /**
     * Manda el código para crear su contraseña a los administradores que nunca han entrado
     * (o solo al indicado). Se envía después de confirmar el cambio; si el correo falla, se registra.
     *
     * @return string[] correos invitados
     */
    private function inviteAdmins(string $tenant, string $actorId, ?string $onlyUser = null): array
    {
        $userIds = DB::transaction(function () use ($tenant, $onlyUser) {
            $this->enterTenant($tenant);

            return DB::table('user_roles')
                ->join('roles', fn ($join) => $join->on('roles.id', '=', 'user_roles.role_id')->whereRaw('upper(roles.code) = ?', ['ADMIN']))
                ->join('tenant_users', fn ($join) => $join->on('tenant_users.user_id', '=', 'user_roles.user_id')
                    ->where('tenant_users.tenant_id', $tenant)->where('tenant_users.status', 'ACTIVE'))
                ->where('user_roles.tenant_id', $tenant)
                ->when($onlyUser, fn ($query) => $query->where('user_roles.user_id', $onlyUser))
                ->distinct()->pluck('user_roles.user_id');
        });
        $company = DB::table('tenants')->where('id', $tenant)->value('name');
        $url = rtrim((string) config('app.frontend_url'), '/').'/reset-password';
        $slug = \App\Services\LoginSlug::of($tenant);

        $sent = [];
        foreach (User::whereIn('id', $userIds)->whereNull('last_login_at')->where('status', 'ACTIVE')->get() as $user) {
            $code = PasswordResetController::issueCode($user, self::INVITATION_DAYS * 24 * 60);
            try {
                // Con el correo en el enlace, la pantalla abre con él ya puesto.
                Mail::to($user->email)->send(new TenantInvitationMail($company, $code, self::INVITATION_DAYS,
                    $url.'?email='.rawurlencode($user->email).($slug ? '&empresa='.rawurlencode($slug) : '')));
                $sent[] = $user->email;
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($sent) {
            PlatformAuditLogger::log($actorId, 'tenant.invitation_sent', 'Tenant', $tenant, metadata: ['to' => $sent]);
        }

        return $sent;
    }

    private function plans(): array
    {
        return DB::connection(self::CONSOLE)->table('saas_plans')->where('active', true)->orderBy('display_order')
            ->get(['id', 'code', 'name', 'modules', 'limits', 'monthly_price', 'annual_price', 'currency', 'has_trial', 'trial_days', 'default_billing_period'])
            ->map(fn ($plan) => [
                'id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name, 'currency' => $plan->currency,
                'monthly_price' => $plan->monthly_price, 'annual_price' => $plan->annual_price,
                'has_trial' => (bool) $plan->has_trial, 'trial_days' => $plan->trial_days,
                'default_billing_period' => $plan->default_billing_period ?: 'MONTHLY',
                'modules' => ModuleCatalog::normalizePlanModules(json_decode($plan->modules, true) ?: []),
                'users_limit' => ($limits = json_decode($plan->limits, true) ?: [])['users'] ?? $limits['max_users'] ?? null,
            ])->all();
    }

    private function console(string $sql, array $bindings = []): ?array
    {
        $row = DB::connection(self::CONSOLE)->selectOne($sql, $bindings);

        return $row?->data === null ? null : json_decode($row->data, true);
    }

    /** Pagos de la suscripción, el más reciente primero. La tabla está aislada por empresa: se lee dentro de su contexto. */
    private function payments(string $tenant): array
    {
        return DB::transaction(function () use ($tenant) {
            $this->enterTenant($tenant);

            return DB::table('tenant_subscription_payments')->where('tenant_id', $tenant)
                ->orderByDesc('created_at')->limit(200)->get()
                ->map(fn ($row) => $this->paymentRow($row))->all();
        });
    }

    private function paymentRow(object $row): array
    {
        $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->toIso8601String() : null;

        return [
            'id' => $row->id,
            'amount' => (float) $row->amount,
            'currency' => trim((string) $row->currency),
            'payment_method' => $row->payment_method,
            'reference' => $row->reference,
            'notes' => $row->notes,
            'months' => $row->months !== null ? (int) $row->months : null,
            'previous_ends_at' => $date($row->previous_ends_at),
            'new_ends_at' => $date($row->new_ends_at),
            'created_at' => $date($row->created_at),
            'created_by' => $row->created_by,
            'created_by_name' => $row->created_by_name,
        ];
    }

    private function enterTenant(string $tenant): void
    {
        DB::statement("SELECT set_config('app.tenant_id', ?, true)", [$tenant]);
    }

    private function logStatus(string $tenant, ?string $from, string $to, ?string $reason, string $userId): void
    {
        DB::table('tenant_status_log')->insert([
            'tenant_id' => $tenant, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'user_id' => $userId,
        ]);
    }
}
