<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        if ($user->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'email' => ['Este usuario no está activo.'],
            ]);
        }

        // Resolvemos su tenant usando la misma función segura
        // que ya usa el middleware TenantContext.
        $tenantId = DB::selectOne(
            'SELECT resolve_active_tenant_for_user(?) AS tenant_id',
            [$user->id]
        )?->tenant_id;

        if ($tenantId) {
            DB::statement("SET app.tenant_id = '{$tenantId}'");

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $user->id,
                action: 'auth.login',
                entityType: 'User',
                entityId: $user->id,
                metadata: ['email' => $user->email],
            );
        }

        $token = $user->createToken('api-token')->plainTextToken;

        $user->last_login_at = now();
        $user->save();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => $token,
        ]);
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
        $tenantId = $request->attributes->get('tenant_id');

        if ($tenantId) {
            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'auth.logout',
                entityType: 'User',
                entityId: $request->user()->id,
            );
        }

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }
}