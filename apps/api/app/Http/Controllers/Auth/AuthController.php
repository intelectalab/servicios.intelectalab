<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\MfaCodeMail;
use App\Models\User;
use App\Modules\Subscription\Support\TenantModules;
use App\Services\AccessScope;
use App\Services\AuditLogger;
use App\Services\LoginThrottle;
use App\Services\PlatformAuditLogger;
use App\Services\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MFA_TTL_MINUTES = 10;
    private const MFA_MAX_ATTEMPTS = 5;

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $remember = (bool) ($credentials['remember'] ?? false);

        $email = $credentials['email'];

        if (LoginThrottle::isLocked($email)) {
            throw ValidationException::withMessages(['email' => [$this->lockedMessage()]]);
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password_hash)) {
            if (LoginThrottle::fail($email)) {
                if ($user) {
                    $this->audit($user, 'auth.account_locked', ['minutes' => LoginThrottle::LOCK_MINUTES]);
                }

                throw ValidationException::withMessages(['email' => [$this->lockedMessage()]]);
            }

            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        if ($user->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'email' => ['Este usuario no está activo.'],
            ]);
        }

        LoginThrottle::clear($email);

        // Las contraseñas guardadas con el algoritmo anterior se actualizan al entrar.
        if (Hash::needsRehash($user->password_hash)) {
            $user->forceFill(['password_hash' => Hash::make($credentials['password'])])->save();
        }

        $tenantId = $this->resolveActiveTenant($user);

        // Sin acceso vigente a ninguna empresa (se le desactivó) no se entrega sesión.
        if (! $user->is_super_admin && ! $tenantId) {
            throw ValidationException::withMessages([
                'email' => ['Este usuario no está activo.'],
            ]);
        }

        // Con verificación en dos pasos no se entrega sesión todavía:
        // se envía un código por correo y el login termina en verifyMfa().
        if ($this->requiresMfa($user, $tenantId)) {
            return $this->startMfaChallenge($user, $remember);
        }

        return $this->issueSession($user, $remember);
    }

    public function verifyMfa(Request $request)
    {
        $data = $request->validate([
            'mfa_token' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);

        $key = 'mfa:'.$data['mfa_token'];
        $challenge = Cache::get($key);

        if (! $challenge) {
            throw ValidationException::withMessages([
                'code' => ['El código venció. Vuelva a iniciar sesión.'],
            ]);
        }

        if (! Hash::check($data['code'], $challenge['code_hash'])) {
            $challenge['attempts']++;

            if ($challenge['attempts'] >= self::MFA_MAX_ATTEMPTS) {
                Cache::forget($key);

                throw ValidationException::withMessages([
                    'code' => ['Demasiados intentos. Vuelva a iniciar sesión.'],
                ]);
            }

            Cache::put($key, $challenge, $challenge['expires_at']);

            throw ValidationException::withMessages([
                'code' => ['El código no es correcto.'],
            ]);
        }

        Cache::forget($key);

        $user = User::find($challenge['user_id']);

        if (! $user || $user->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'code' => ['Este usuario no está activo.'],
            ]);
        }

        return $this->issueSession($user, $challenge['remember'] ?? false, ['mfa' => true]);
    }

    private function startMfaChallenge(User $user, bool $remember)
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token = Str::random(64);
        $expiresAt = now()->addMinutes(self::MFA_TTL_MINUTES);

        Cache::put('mfa:'.$token, [
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'remember' => $remember,
            'expires_at' => $expiresAt,
        ], $expiresAt);

        Mail::to($user->email)->send(new MfaCodeMail($code, self::MFA_TTL_MINUTES));

        return response()->json([
            'mfa_required' => true,
            'mfa_token' => $token,
        ]);
    }

    private function issueSession(User $user, bool $remember, array $auditMetadata = [])
    {
        $this->resolveActiveTenant($user);
        $this->audit($user, 'auth.login', ['email' => $user->email, 'remember' => $remember] + $auditMetadata);

        // "Recordarme" alarga la sesión; sin marcar dura una jornada.
        $minutes = config($remember ? 'sanctum.remember_minutes' : 'sanctum.session_minutes');
        $session = $user->createToken('api-token', ['*'], now()->addMinutes($minutes));
        // Desde dónde se abrió la sesión, para que la persona la reconozca en "Mi perfil".
        $session->accessToken->forceFill([
            'user_agent' => Str::limit((string) request()->userAgent(), 500, ''),
            'ip_address' => request()->ip(),
            'mfa' => (bool) ($auditMetadata['mfa'] ?? false),
        ])->save();
        $token = $session->plainTextToken;

        $user->last_login_at = now();
        $user->save();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_super_admin' => (bool) $user->is_super_admin,
            ],
            'token' => $token,
        ]);
    }

    /**
     * Resuelve el tenant del usuario con la misma función segura que usa el
     * middleware TenantContext, deja app.tenant_id en la conexión y rechaza el
     * acceso, con un mensaje según el estado, si la empresa no está activa.
     */
    private function resolveActiveTenant(User $user): ?string
    {
        // La cuenta de plataforma no opera dentro de ningún tenant.
        if ($user->is_super_admin) {
            return null;
        }

        $tenantId = DB::selectOne(
            'SELECT resolve_active_tenant_for_user(?) AS tenant_id',
            [$user->id]
        )?->tenant_id;

        if (! $tenantId) {
            return null;
        }

        if ($blocked = TenantAccess::blocked($tenantId)) {
            throw ValidationException::withMessages([
                'email' => [$blocked['message']],
            ]);
        }

        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenantId]);

        return (string) $tenantId;
    }

    /**
     * La verificación en dos pasos es obligatoria para la plataforma, los
     * administradores del tenant y los usuarios del portal; el resto la usa
     * solo si tiene mfa_enabled.
     */
    private function requiresMfa(User $user, ?string $tenantId): bool
    {
        if ($user->mfa_enabled || $user->is_super_admin) {
            return true;
        }

        if (! $tenantId) {
            return false;
        }

        $isTenantAdmin = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->id)
            ->where('user_roles.tenant_id', $tenantId)
            ->whereRaw('UPPER(roles.code) = ?', ['ADMIN'])
            ->exists();

        return $isTenantAdmin || DB::table('portal_users')
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->exists();
    }

    /**
     * Auditoría de eventos de acceso: la cuenta de plataforma va a la auditoría
     * de plataforma; el resto a audit_logs, que es por tenant (sin tenant no se registra).
     */
    private function audit(User $user, string $action, array $metadata = []): void
    {
        if ($user->is_super_admin) {
            PlatformAuditLogger::log($user->id, $action, 'User', $user->id, metadata: $metadata);

            return;
        }

        $tenantId = DB::selectOne(
            'SELECT resolve_active_tenant_for_user(?) AS tenant_id',
            [$user->id]
        )?->tenant_id;

        if (! $tenantId) {
            return;
        }

        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenantId]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $user->id,
            action: $action,
            entityType: 'User',
            entityId: $user->id,
            metadata: $metadata,
        );
    }

    private function lockedMessage(): string
    {
        return 'Cuenta bloqueada por '.LoginThrottle::LOCK_MINUTES.' minutos tras '.LoginThrottle::MAX_ATTEMPTS
            .' intentos fallidos. Espere o restablezca su contraseña para desbloquearla.';
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function permissions(Request $request)
    {
        return response()->json([
            'permissions' => $request->user()->permissionCodes(),
        ]);
    }

    /**
     * Lo que el frontend necesita para armar el menú: los módulos que la
     * empresa puede usar (plan) y los permisos del usuario (rol), por separado.
     */
    public function access(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json([
            'modules' => $tenantId ? TenantModules::enabledCodes($tenantId) : [],
            'permissions' => $request->user()->permissionCodes(),
            // Qué alcanza la persona en cada recurso con alcance (lo propio, equipo, sede, empresa).
            'scopes' => (object) AccessScope::all(),
            // Para la cabecera: a qué empresa pertenece la sesión y con qué rol.
            'tenant' => $tenantId ? DB::table('tenants')->where('id', $tenantId)->first(['name', 'code', 'legal_name']) : null,
            // Razón social y RUC de la empresa, para los documentos que se descargan (cotización en PDF).
            'company' => $tenantId
                ? DB::table('companies')->where('tenant_id', $tenantId)->orderBy('created_at')->first(['legal_name', 'tax_identifier'])
                : null,
            'roles' => $tenantId
                ? DB::table('user_roles')
                    ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                    ->where('user_roles.user_id', $request->user()->id)
                    ->where('user_roles.tenant_id', $tenantId)
                    ->orderBy('roles.name')
                    ->pluck('roles.name')
                    ->unique()
                    ->values()
                : [],
        ]);
    }

    /**
     * Cambio de contraseña desde la sesión abierta: pide la actual.
     */
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'different:current_password', Password::min(8)->mixedCase()->numbers()],
        ], [
            'password.different' => 'La nueva contraseña debe ser distinta de la actual.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual no es correcta.'],
            ]);
        }

        $user->forceFill(['password_hash' => Hash::make($data['password'])])->save();

        // Cambiar la contraseña cierra las demás sesiones abiertas; la actual sigue.
        $currentId = $user->currentAccessToken()->id ?? null;
        $user->tokens()->when($currentId, fn ($tokens) => $tokens->where('id', '!=', $currentId))->delete();

        $this->audit($user, 'auth.password_changed');

        return response()->json(['message' => 'Tu contraseña se actualizó correctamente.']);
    }

    /**
     * "Mi perfil": la cuenta, la empresa y el rol con los que trabaja, sus sesiones abiertas
     * y por qué canales quiere avisos. La cuenta de plataforma no tiene empresa ni preferencias.
     */
    public function profile(Request $request)
    {
        $user = $request->user();
        $tenantId = $request->attributes->get('tenant_id');
        $currentId = $user->currentAccessToken()->id ?? null;

        $member = $tenantId
            ? DB::table('tenant_users')
                ->leftJoin('branches', 'branches.id', '=', 'tenant_users.default_branch_id')
                ->leftJoin('teams', 'teams.id', '=', 'tenant_users.team_id')
                ->where('tenant_users.tenant_id', $tenantId)->where('tenant_users.user_id', $user->id)
                ->first(['branches.name as branch', 'teams.name as team'])
            : null;

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'last_login_at' => $user->last_login_at,
            'is_super_admin' => (bool) $user->is_super_admin,
            'tenant' => $tenantId ? DB::table('tenants')->where('id', $tenantId)->value('name') : null,
            'roles' => $tenantId
                ? DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
                    ->where('user_roles.user_id', $user->id)->where('user_roles.tenant_id', $tenantId)
                    ->orderBy('roles.name')->pluck('roles.name')->unique()->values()
                : [],
            'branch' => $member?->branch,
            'team' => $member?->team,
            'mfa' => $this->requiresMfa($user, $tenantId),
            'sessions' => $user->tokens()
                ->where(fn ($open) => $open->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get()
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'current' => $token->id === $currentId,
                    'mobile' => (bool) preg_match('/Mobile|Android|iPhone|iPad/i', (string) $token->user_agent),
                    'browser' => self::browserOf((string) $token->user_agent),
                    'mfa' => (bool) $token->mfa,
                    'started_at' => $token->created_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                ]),
            'notification_preferences' => $tenantId ? $this->notificationPreferences($tenantId, $user->id) : null,
        ]);
    }

    /** Cierra todas las sesiones de la cuenta menos la que hace la petición. */
    public function revokeOtherSessions(Request $request)
    {
        $user = $request->user();
        $currentId = $user->currentAccessToken()->id ?? null;
        abort_unless($currentId, 422, 'No se pudo identificar la sesión actual.');

        $closed = $user->tokens()->where('id', '!=', $currentId)->delete();
        $this->audit($user, 'auth.sessions_revoked', ['sessions' => $closed]);

        return response()->json(['closed' => $closed]);
    }

    /** Canales por los que la persona quiere recibir avisos en su empresa. */
    public function updateNotificationPreferences(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        abort_unless($tenantId, 403, 'La cuenta de plataforma no tiene preferencias de notificación.');

        $data = $request->validate([
            'in_app' => ['required', 'boolean'],
            'email' => ['required', 'boolean'],
            'whatsapp' => ['required', 'boolean'],
        ]);
        $user = $request->user();
        $before = $this->notificationPreferences($tenantId, $user->id);

        DB::table('notification_preferences')->updateOrInsert(
            ['tenant_id' => $tenantId, 'user_id' => $user->id],
            [...$data, 'updated_at' => now()],
        );

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $user->id,
            action: 'user.notification_preferences',
            entityType: 'User',
            entityId: $user->id,
            beforeData: $before,
            afterData: $data,
        );

        return response()->json($this->notificationPreferences($tenantId, $user->id));
    }

    /** Sin preferencias guardadas, los tres canales están activos. */
    private function notificationPreferences(string $tenantId, string $userId): array
    {
        $row = DB::table('notification_preferences')->where('tenant_id', $tenantId)->where('user_id', $userId)->first();

        return [
            'in_app' => (bool) ($row->in_app ?? true),
            'email' => (bool) ($row->email ?? true),
            'whatsapp' => (bool) ($row->whatsapp ?? true),
        ];
    }

    /** "Chrome en Windows": lo justo para reconocer una sesión. */
    private static function browserOf(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
        $system = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            (bool) preg_match('/iPhone|iPad/', $userAgent) => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $browser && $system ? "{$browser} en {$system}" : ($browser ?? $system);
    }

    public function logout(Request $request)
    {
        // Esta ruta no pasa por tenant.context: cerrar sesión tiene que funcionar
        // aunque la empresa esté suspendida o cerrada.
        $this->audit($request->user(), 'auth.logout');

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }
}