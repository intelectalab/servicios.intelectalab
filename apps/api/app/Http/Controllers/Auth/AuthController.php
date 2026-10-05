<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\MfaCodeMail;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LoginThrottle;
use App\Services\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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

        $tenantId = $this->resolveActiveTenant($user);

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
        $token = $user->createToken('api-token', ['*'], now()->addMinutes($minutes))->plainTextToken;

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
     * Auditoría de eventos de acceso. audit_logs es por tenant: sin tenant no se registra.
     */
    private function audit(User $user, string $action, array $metadata = []): void
    {
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

    public function logout(Request $request)
    {
        // Esta ruta no pasa por tenant.context: cerrar sesión tiene que funcionar
        // aunque la empresa esté suspendida o cerrada.
        $this->audit($request->user(), 'auth.logout');

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }
}