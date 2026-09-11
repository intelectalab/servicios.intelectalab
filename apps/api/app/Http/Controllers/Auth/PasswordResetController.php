<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /**
     * Envía el correo con el enlace de restablecimiento.
     * Siempre responde igual exista o no el correo, para no filtrar
     * qué correos están registrados (enumeración de usuarios).
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'Si el correo existe en nuestro sistema, enviaremos un enlace para restablecer tu contraseña.',
        ]);
    }

    /**
     * Valida el token y actualiza la contraseña del usuario.
     */
    public function resetPassword(Request $request)
    {
        $credentials = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $credentials,
            function ($user, string $password) {
                $user->forceFill([
                    'password_hash' => Hash::make($password),
                ])->save();

                $tenantId = DB::selectOne(
                    'SELECT resolve_active_tenant_for_user(?) AS tenant_id',
                    [$user->id]
                )?->tenant_id;

                if ($tenantId) {
                    AuditLogger::log(
                        tenantId: $tenantId,
                        userId: $user->id,
                        action: 'auth.password_reset',
                        entityType: 'User',
                        entityId: $user->id,
                    );
                }
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'message' => 'Tu contraseña se actualizó correctamente.',
        ]);
    }
}
