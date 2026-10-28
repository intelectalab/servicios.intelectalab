<?php

namespace App\Console\Commands;

use App\Http\Controllers\Auth\PasswordResetController;
use App\Mail\SuperAdminInvitationMail;
use App\Models\User;
use App\Services\PlatformAuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Da o quita el acceso de Super Administrador. Solo desde el servidor: ninguna pantalla ni
 * petición puede marcar a alguien como superadmin.
 *
 * - Dar: crea la cuenta (sin empresa, con verificación en dos pasos) y le envía un código por
 *   3 días para crear su contraseña; si ya es superadmin y no ha entrado, reenvía la invitación.
 * - Quitar (--revoke): la desactiva y cierra sus sesiones; nunca a la última activa.
 *
 * Cada acción queda en la auditoría de plataforma a nombre de --by (un superadmin activo).
 */
class SuperAdminAccess extends Command
{
    public const INVITATION_DAYS = 3;

    protected $signature = 'platform:super-admin {email} {--by= : correo del superadmin que lo autoriza} {--name= : nombre de la persona} {--revoke : quitarle el acceso}';

    protected $description = 'Da (invitación por correo) o quita el acceso de Super Administrador de la plataforma';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('El correo no es válido.');

            return self::FAILURE;
        }

        $by = User::where('email', mb_strtolower(trim((string) $this->option('by'))))->where('is_super_admin', true)->where('status', 'ACTIVE')->first();
        if (! $by) {
            $this->error('Indica con --by el correo de un Super Administrador activo: queda como quien lo autoriza.');

            return self::FAILURE;
        }

        return $this->option('revoke') ? $this->revoke($email, $by) : $this->grant($email, $by);
    }

    private function grant(string $email, User $by): int
    {
        $user = User::where('email', $email)->first();

        if ($user && ! $user->is_super_admin) {
            // Una cuenta de plataforma no puede estar en una empresa ni en el portal (la base lo impide).
            $this->error('Ese correo ya tiene una cuenta en el sistema (de una empresa o del portal). Usa otro correo para la plataforma.');

            return self::FAILURE;
        }
        if ($user && $user->last_login_at) {
            $this->error('Esa persona ya es Super Administrador y ya entró: si olvidó su contraseña, que use "¿Olvidó su contraseña?".');

            return self::FAILURE;
        }

        $created = ! $user;
        if ($created) {
            $user = DB::transaction(function () use ($email) {
                $user = User::create([
                    'email' => $email,
                    // Sin contraseña usable hasta que la cree con el código.
                    'password_hash' => Hash::make(Str::random(48)),
                    'name' => trim((string) $this->option('name')) ?: Str::before($email, '@'),
                    'status' => 'ACTIVE',
                    'mfa_enabled' => true,
                ]);
                $user->forceFill(['is_super_admin' => true])->save();

                return $user;
            });
        } elseif ($user->status !== 'ACTIVE') {
            $user->forceFill(['status' => 'ACTIVE'])->save();
        }

        $code = PasswordResetController::issueCode($user, self::INVITATION_DAYS * 24 * 60);
        $url = rtrim((string) config('app.frontend_url'), '/').'/reset-password?email='.rawurlencode($user->email);
        try {
            Mail::to($user->email)->send(new SuperAdminInvitationMail($by->name, $code, self::INVITATION_DAYS, $url));
        } catch (Throwable $e) {
            report($e);
            $this->error("La cuenta quedó lista, pero el correo no salió: {$e->getMessage()}. Vuelve a correr el comando para reenviarlo.");

            return self::FAILURE;
        }

        PlatformAuditLogger::log($by->id, $created ? 'platform.super_admin_invited' : 'platform.super_admin_reinvited', 'User', $user->id,
            afterData: ['email' => $user->email, 'name' => $user->name], metadata: ['via' => 'console']);
        $this->info(($created ? 'Super Administrador creado' : 'Invitación reenviada')." para {$user->email}: el código vence en ".self::INVITATION_DAYS.' días.');

        return self::SUCCESS;
    }

    private function revoke(string $email, User $by): int
    {
        $user = User::where('email', $email)->where('is_super_admin', true)->first();
        if (! $user || $user->status !== 'ACTIVE') {
            $this->error('No hay un Super Administrador activo con ese correo.');

            return self::FAILURE;
        }
        if (User::where('is_super_admin', true)->where('status', 'ACTIVE')->count() <= 1) {
            $this->error('Es el único Super Administrador activo: la plataforma no puede quedarse sin ninguno.');

            return self::FAILURE;
        }

        $user->forceFill(['status' => 'DISABLED'])->save();
        $user->tokens()->delete();
        PlatformAuditLogger::log($by->id, 'platform.super_admin_revoked', 'User', $user->id,
            beforeData: ['status' => 'ACTIVE'], afterData: ['status' => 'DISABLED'], metadata: ['email' => $user->email, 'via' => 'console']);
        $this->info("Acceso quitado a {$user->email}: cuenta desactivada y sesiones cerradas.");

        return self::SUCCESS;
    }
}
