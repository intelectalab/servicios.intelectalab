<?php

namespace App\Modules\Portal\Support;

use App\Mail\PortalInvitationMail;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Usuarios del portal: invitar, reenviar la invitación, cambiar rol o estado y activar.
 *
 * Los usa la empresa (Administración → Usuarios del portal) y el Portal Admin del cliente
 * (Portal → Usuarios). Las reglas son las mismas para los dos (RN-POR-03): como mucho 10
 * usuarios vigentes por cliente, siempre queda un Portal Admin activo y nadie se desactiva a sí
 * mismo. El correo es único en la plataforma: una cuenta del sistema no puede ser del portal.
 */
class PortalInvitations
{
    public const MAX_USERS = 10;

    public const INVITE_DAYS = 7;

    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** @return object la fila de `portal_users` */
    public static function invite(string $tenantId, string $customerId, array $data, string $byUserId, string $byType): object
    {
        $email = mb_strtolower(trim($data['email']));
        if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => ['Ese correo ya tiene una cuenta en la plataforma.']]);
        }

        $code = self::code();
        $portalUser = DB::transaction(function () use ($tenantId, $customerId, $data, $email, $byUserId, $byType, $code) {
            // Bloquea al cliente: dos invitaciones a la vez no pasan del tope.
            $customer = DB::table('customers')->where('tenant_id', $tenantId)->where('id', $customerId)->lockForUpdate()->first(['id', 'status']);
            if (! $customer || $customer->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['customer_id' => ['Elige un cliente activo de la empresa.']]);
            }
            if (self::current($customerId) >= self::MAX_USERS) {
                throw ValidationException::withMessages(['email' => ['El cliente alcanzó el máximo de '.self::MAX_USERS.' usuarios del portal.']]);
            }

            // Nace con una contraseña aleatoria que nadie conoce: la crea la persona al activar.
            $user = User::create([
                'email' => $email,
                'name' => trim($data['name']),
                'password_hash' => Hash::make(Str::random(40)),
                'status' => 'ACTIVE',
                'mfa_enabled' => true,
            ]);

            $id = (string) Str::uuid();
            DB::table('portal_users')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $user->id, 'customer_id' => $customerId,
                'role' => $data['role'], 'status' => 'INVITED',
                'invite_code_hash' => Hash::make($code), 'invite_expires_at' => now()->addDays(self::INVITE_DAYS),
                'invited_by' => $byUserId, 'invited_by_type' => $byType, 'invited_at' => now(), 'created_at' => now(),
            ]);

            AuditLogger::log(
                tenantId: $tenantId, userId: $byUserId, action: 'portal_user.invite', entityType: 'PortalUser', entityId: $id,
                metadata: ['email' => $email, 'role' => $data['role'], 'customer_id' => $customerId, 'by' => $byType, 'to' => 'INVITED'],
            );

            return DB::table('portal_users')->where('id', $id)->first();
        });

        self::sendInvitation($tenantId, $portalUser, $code);

        return $portalUser;
    }

    /** Un código nuevo, por 7 días más; el anterior deja de servir. */
    public static function resend(object $portalUser, string $byUserId): void
    {
        if ($portalUser->status !== 'INVITED') {
            throw ValidationException::withMessages(['status' => ['Solo se reenvía una invitación pendiente.']]);
        }

        $code = self::code();
        DB::transaction(function () use ($portalUser, $code, $byUserId) {
            DB::table('portal_users')->where('id', $portalUser->id)->update([
                'invite_code_hash' => Hash::make($code), 'invite_expires_at' => now()->addDays(self::INVITE_DAYS),
            ]);
            AuditLogger::log(tenantId: $portalUser->tenant_id, userId: $byUserId, action: 'portal_user.resend', entityType: 'PortalUser', entityId: $portalUser->id);
        });

        self::sendInvitation($portalUser->tenant_id, $portalUser, $code);
    }

    /** Cambiar rol o estado (desactivar, reactivar), con las reglas del portal. */
    public static function update(object $portalUser, array $data, string $byUserId, bool $byPortal): object
    {
        $role = $data['role'] ?? $portalUser->role;
        $status = $data['status'] ?? $portalUser->status;

        if ($status === 'INVITED' && $portalUser->status !== 'INVITED') {
            throw ValidationException::withMessages(['status' => ['Una cuenta activada no vuelve a quedar invitada.']]);
        }
        if ($status === 'ACTIVE' && $portalUser->status === 'INVITED') {
            throw ValidationException::withMessages(['status' => ['La persona activa su acceso con el código de su invitación.']]);
        }
        if ($byPortal && $portalUser->user_id === $byUserId && ($status === 'INACTIVE' || $role !== $portalUser->role)) {
            throw ValidationException::withMessages(['status' => [$status === 'INACTIVE' ? 'No puedes desactivar tu propio usuario.' : 'No puedes cambiar tu propio rol.']]);
        }

        return DB::transaction(function () use ($portalUser, $role, $status, $byUserId, $byPortal) {
            // Bloquea a los usuarios del cliente: la regla del Portal Admin se mira sin carreras.
            $all = DB::table('portal_users')->where('customer_id', $portalUser->customer_id)->lockForUpdate()->get(['id', 'role', 'status']);
            $wasAdmin = $portalUser->role === 'PORTAL_ADMIN' && $portalUser->status === 'ACTIVE';
            $staysAdmin = $role === 'PORTAL_ADMIN' && $status === 'ACTIVE';
            $otherAdmins = $all->where('id', '!=', $portalUser->id)->where('role', 'PORTAL_ADMIN')->where('status', 'ACTIVE')->count();
            if ($wasAdmin && ! $staysAdmin && $otherAdmins === 0) {
                throw ValidationException::withMessages(['role' => ['Debe quedar al menos un Portal Admin activo en el cliente.']]);
            }
            if ($status !== 'INACTIVE' && $portalUser->status === 'INACTIVE'
                && $all->where('id', '!=', $portalUser->id)->where('status', '!=', 'INACTIVE')->count() >= self::MAX_USERS) {
                throw ValidationException::withMessages(['status' => ['El cliente alcanzó el máximo de '.self::MAX_USERS.' usuarios del portal.']]);
            }

            $changes = ['role' => $role, 'status' => $status];
            if ($status === 'INACTIVE' && $portalUser->status !== 'INACTIVE') {
                // Desactivada: su invitación deja de servir y sus sesiones se cierran.
                $changes += ['deactivated_at' => now(), 'invite_code_hash' => null, 'invite_expires_at' => null];
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $portalUser->user_id)->delete();
            }
            if ($status === 'ACTIVE' && $portalUser->status === 'INACTIVE') {
                $changes['deactivated_at'] = null;
            }
            DB::table('portal_users')->where('id', $portalUser->id)->update($changes);

            AuditLogger::log(
                tenantId: $portalUser->tenant_id, userId: $byUserId, action: 'portal_user.update', entityType: 'PortalUser', entityId: $portalUser->id,
                beforeData: ['role' => $portalUser->role, 'status' => $portalUser->status],
                afterData: ['role' => $role, 'status' => $status],
                metadata: array_filter([
                    'from' => $status !== $portalUser->status ? $portalUser->status : null,
                    'to' => $status !== $portalUser->status ? $status : null,
                    'by' => $byPortal ? 'PORTAL' : 'STAFF',
                ]),
            );

            return DB::table('portal_users')->where('id', $portalUser->id)->first();
        });
    }

    /** La persona invitada activa su acceso con el código y crea su contraseña. */
    public static function activate(string $email, string $code, string $password): void
    {
        $invalid = fn () => ValidationException::withMessages(['code' => ['El código no es válido o ya venció. Pide que te reenvíen la invitación.']]);

        $user = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
        $membership = $user ? DB::selectOne('SELECT tenant_id, status FROM portal_membership_for_user(?)', [$user->id]) : null;
        if (! $membership || $membership->status !== 'INVITED') {
            throw $invalid();
        }

        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $membership->tenant_id]);
        $portalUser = DB::table('portal_users')->where('user_id', $user->id)->first();
        if (! $portalUser->invite_code_hash || now()->greaterThan($portalUser->invite_expires_at)
            || ! Hash::check(strtoupper(trim($code)), $portalUser->invite_code_hash)) {
            throw $invalid();
        }

        DB::transaction(function () use ($user, $portalUser, $password) {
            $user->forceFill(['password_hash' => Hash::make($password), 'status' => 'ACTIVE'])->save();
            DB::table('portal_users')->where('id', $portalUser->id)->update([
                'status' => 'ACTIVE', 'activated_at' => now(), 'invite_code_hash' => null, 'invite_expires_at' => null,
            ]);
            AuditLogger::log(
                tenantId: $portalUser->tenant_id, userId: $user->id, action: 'portal_user.activate', entityType: 'PortalUser', entityId: $portalUser->id,
                metadata: ['from' => 'INVITED', 'to' => 'ACTIVE'],
            );
        });
    }

    /** Usuarios vigentes (invitados o activos) de un cliente. */
    public static function current(string $customerId): int
    {
        return DB::table('portal_users')->where('customer_id', $customerId)->where('status', '!=', 'INACTIVE')->count();
    }

    private static function code(): string
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return $code;
    }

    /** El correo sale después de guardar; si falla, la invitación queda y se puede reenviar. */
    private static function sendInvitation(string $tenantId, object $portalUser, string $code): void
    {
        $email = DB::table('users')->where('id', $portalUser->user_id)->value('email');
        $url = rtrim((string) config('app.frontend_url'), '/').'/portal/activate';

        try {
            Mail::to($email)->send((new PortalInvitationMail(
                $code, PortalCustomer::companyName($tenantId), PortalCustomer::name($portalUser->customer_id), self::INVITE_DAYS, $url,
            ))->withBrand($tenantId));
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar la invitación al portal.', ['portal_user_id' => $portalUser->id, 'error' => $e->getMessage()]);
        }
    }
}
