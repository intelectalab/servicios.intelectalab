<?php

namespace App\Modules\Subscription\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Modules\Subscription\Support\PlatformSettings;
use App\Services\PlatformAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Configuración de la plataforma: canales de soporte y renovación que ven todas las empresas. */
class PlatformSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'support' => PlatformSettings::support(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'support' => ['required', 'array'],
            // Número con código de país: de 8 a 15 dígitos, con espacios, guiones o "+" opcionales.
            'support.whatsapp' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9\s\-]{6,24}$/'],
            'support.email' => ['required', 'email', 'max:255'],
            'support.schedule' => ['nullable', 'string', 'max:255'],
        ], [
            'support.whatsapp.required' => 'Indica el WhatsApp de soporte.',
            'support.whatsapp.regex' => 'Escribe el número con código de país, solo dígitos (ej. +51 987 654 321).',
            'support.email.required' => 'Indica el correo de soporte.',
            'support.email.email' => 'El correo no es válido.',
        ]);

        $whatsapp = trim($data['support']['whatsapp']);
        $digits = strlen(preg_replace('/\D/', '', $whatsapp));
        if ($digits < 8 || $digits > 15) {
            return response()->json([
                'message' => 'El WhatsApp debe tener de 8 a 15 dígitos con el código de país.',
                'errors' => ['support.whatsapp' => ['El WhatsApp debe tener de 8 a 15 dígitos con el código de país.']],
            ], 422);
        }

        $before = PlatformSettings::support();
        $after = [
            'whatsapp' => $whatsapp,
            'email' => mb_strtolower(trim($data['support']['email'])),
            'schedule' => trim((string) ($data['support']['schedule'] ?? '')),
        ];

        PlatformSettings::set(PlatformSettings::SUPPORT_KEY, $after);

        PlatformAuditLogger::log(
            $request->user()->id,
            'platform.settings_updated',
            'PlatformSettings',
            null,
            $before,
            $after,
            ['setting' => 'support'],
            PlatformDashboardController::CONNECTION,
        );

        return response()->json([
            'message' => 'Configuración de la plataforma actualizada correctamente.',
            'support' => $after,
        ]);
    }
}
