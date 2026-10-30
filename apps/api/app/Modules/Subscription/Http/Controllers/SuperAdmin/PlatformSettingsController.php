<?php

namespace App\Modules\Subscription\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Modules\Subscription\Support\PlatformSettings;
use App\Services\PlatformAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'support.whatsapp' => ['required', 'string', 'max:50'],
            'support.email' => ['required', 'email', 'max:255'],
            'support.schedule' => ['nullable', 'string', 'max:255'],
        ]);

        $before = PlatformSettings::support();

        $after = [
            'whatsapp' => trim($data['support']['whatsapp']),
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
            ['setting' => 'support']
        );

        return response()->json([
            'message' => 'Configuración de la plataforma actualizada correctamente.',
            'support' => $after,
        ]);
    }
}
