<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LoginThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetController extends Controller
{
    private const CODE_TTL_MINUTES = 15;
    private const CODE_MAX_ATTEMPTS = 5;
    // Sin 0/O ni 1/I/L, para que el código se pueda copiar a mano sin confusiones.
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * Envía por correo un código de un solo uso para crear una nueva contraseña.
     * Siempre responde igual exista o no el correo, para no filtrar
     * qué correos están registrados (enumeración de usuarios).
     */
    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $code = self::issueCode($user, self::CODE_TTL_MINUTES);
            try {
                Mail::to($user->email)->send(new PasswordResetCodeMail($code, self::CODE_TTL_MINUTES));
            } catch (TransportExceptionInterface $e) {
                // Se registra, pero la respuesta es la misma: un error aquí delataría que el correo existe.
                report($e);
            }
        }

        return response()->json([
            'message' => 'Si el correo existe, enviamos un código de 6 caracteres.',
        ]);
    }

    /**
     * Valida el código y actualiza la contraseña del usuario.
     */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
        ]);

        $key = self::cacheKey($data['email']);
        $challenge = Cache::get($key);

        if (! $challenge) {
            throw ValidationException::withMessages([
                'token' => ['El código no es válido o ya venció. Solicite uno nuevo.'],
            ]);
        }

        if (! Hash::check(strtoupper(trim($data['token'])), $challenge['code_hash'])) {
            $challenge['attempts']++;

            if ($challenge['attempts'] >= self::CODE_MAX_ATTEMPTS) {
                Cache::forget($key);

                throw ValidationException::withMessages([
                    'token' => ['Demasiados intentos. Solicite un código nuevo.'],
                ]);
            }

            Cache::put($key, $challenge, $challenge['expires_at']);

            throw ValidationException::withMessages([
                'token' => ['El código no es correcto.'],
            ]);
        }

        Cache::forget($key);

        $user = User::findOrFail($challenge['user_id']);
        $user->forceFill([
            'password_hash' => Hash::make($data['password']),
        ])->save();

        // Quien restablece la contraseña puede haberla perdido: se cierran todas sus sesiones.
        $user->tokens()->delete();

        // Restablecer la contraseña desbloquea la cuenta.
        LoginThrottle::clear($user->email);

        $tenantId = DB::selectOne(
            'SELECT resolve_active_tenant_for_user(?) AS tenant_id',
            [$user->id]
        )?->tenant_id ?? DB::selectOne('SELECT resolve_portal_tenant_for_user(?) AS tenant_id', [$user->id])?->tenant_id;

        if ($tenantId) {
            DB::statement("SET app.tenant_id = '{$tenantId}'");

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $user->id,
                action: 'auth.password_reset',
                entityType: 'User',
                entityId: $user->id,
            );
        }

        return response()->json([
            'message' => 'Tu contraseña se actualizó correctamente.',
        ]);
    }

    /**
     * Código de un solo uso para crear la contraseña (lo usa también la invitación del
     * administrador de una empresa aprovisionada). Un código nuevo reemplaza al anterior.
     */
    public static function issueCode(User $user, int $minutes): string
    {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        $expiresAt = now()->addMinutes($minutes);
        Cache::put(self::cacheKey($user->email), [
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => $expiresAt,
        ], $expiresAt);

        return $code;
    }

    private static function cacheKey(string $email): string
    {
        return 'pwd-reset:'.sha1(mb_strtolower(trim($email)));
    }
}
