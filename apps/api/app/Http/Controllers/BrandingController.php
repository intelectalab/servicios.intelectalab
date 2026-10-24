<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\Branding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Configuración → Marca de la empresa. Leerla puede cualquiera de la empresa (sus documentos la
 * usan); cambiarla pide `tenant.manage`.
 */
class BrandingController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(Branding::of($this->tenant($request)));
    }

    /** Sube (o cambia) el logo: se limpia, se guarda y se proponen sus colores. */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => ['required', 'file', 'max:'.Branding::MAX_UPLOAD_KB, 'mimes:png,jpg,jpeg,webp'],
        ], [
            'logo.required' => 'Elige la imagen del logo.',
            'logo.max' => 'El logo no puede pesar más de 2 MB.',
            'logo.mimes' => 'El logo debe ser una imagen PNG, JPG o WEBP.',
        ]);
        $tenantId = $this->tenant($request);
        [$png, $width, $height, $palette] = Branding::cleanLogo((string) file_get_contents($request->file('logo')->getRealPath()));
        [$primary, $secondary] = Branding::suggested($palette);
        $hash = hash('sha256', $png);

        DB::transaction(function () use ($request, $tenantId, $png, $width, $height, $palette, $primary, $secondary, $hash) {
            $before = DB::table('company_branding')->where('tenant_id', $tenantId)->first(['logo_sha256', 'primary_color', 'secondary_color']);
            // Logo y huella en la misma escritura (la base exige los dos juntos). Base64 solo tiene [A-Za-z0-9+/=].
            $values = [
                'logo' => DB::raw("decode('".base64_encode($png)."', 'base64')"),
                'logo_width' => $width, 'logo_height' => $height, 'logo_sha256' => $hash,
                'palette' => json_encode($palette), 'primary_color' => $primary, 'secondary_color' => $secondary,
                'updated_by' => $request->user()->id, 'updated_at' => now(),
            ];
            if ($before) {
                DB::table('company_branding')->where('tenant_id', $tenantId)->update($values);
            } else {
                DB::table('company_branding')->insert(['tenant_id' => $tenantId, ...$values]);
            }

            AuditLogger::log(
                tenantId: $tenantId, userId: $request->user()->id, action: 'branding.logo', entityType: 'CompanyBranding', entityId: $tenantId,
                beforeData: $before ? (array) $before : null,
                afterData: ['logo_sha256' => $hash, 'primary_color' => $primary, 'secondary_color' => $secondary, 'size' => "{$width}x{$height}"],
            );
        });

        return response()->json(Branding::of($tenantId));
    }

    /** Quita el logo y devuelve los colores a los de GsCreativas (#1B8FC9). */
    public function removeLogo(Request $request)
    {
        $tenantId = $this->tenant($request);
        $updated = DB::table('company_branding')->where('tenant_id', $tenantId)->whereNotNull('logo_sha256')
            ->update([
                'logo' => null, 'logo_sha256' => null, 'logo_width' => null, 'logo_height' => null,
                'palette' => '[]', 'primary_color' => Branding::DEFAULT_PRIMARY, 'secondary_color' => null,
                'updated_by' => $request->user()->id, 'updated_at' => now(),
            ]);
        if ($updated) {
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'branding.logo_removed', entityType: 'CompanyBranding', entityId: $tenantId);
        }

        return response()->json(Branding::of($tenantId));
    }

    /** Colores y datos de contacto del pie de los documentos. */
    public function update(Request $request)
    {
        $data = $request->validate([
            'primary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'address' => ['nullable', 'string', 'max:300'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s.\-]*$/'],
            'email' => ['nullable', 'email:rfc', 'max:160'],
            'website' => ['nullable', 'string', 'max:160', 'regex:/^(https?:\/\/)?[A-Za-z0-9.\-]+\.[A-Za-z]{2,}(\/[^\s]*)?$/'],
        ], [
            'primary_color.regex' => 'El color debe tener el formato #RRGGBB.',
            'secondary_color.regex' => 'El color debe tener el formato #RRGGBB.',
            'phone.regex' => 'El teléfono solo admite números, espacios, +, -, paréntesis y puntos.',
            'email.email' => 'Escribe un correo válido.',
            'website.regex' => 'Escribe una dirección web válida (por ejemplo, www.miempresa.pe).',
        ]);
        $tenantId = $this->tenant($request);
        $values = [
            'primary_color' => Branding::normalizeColor($data['primary_color']),
            'secondary_color' => Branding::normalizeColor($data['secondary_color'] ?? null),
            'address' => $this->text($data['address'] ?? null),
            'phone' => $this->text($data['phone'] ?? null),
            'email' => $this->text($data['email'] ?? null),
            'website' => $this->text($data['website'] ?? null),
            'updated_by' => $request->user()->id,
            'updated_at' => now(),
        ];

        DB::transaction(function () use ($request, $tenantId, $values) {
            $before = DB::table('company_branding')->where('tenant_id', $tenantId)
                ->first(['primary_color', 'secondary_color', 'address', 'phone', 'email', 'website']);
            if ($before) {
                DB::table('company_branding')->where('tenant_id', $tenantId)->update($values);
            } else {
                DB::table('company_branding')->insert(['tenant_id' => $tenantId, ...$values]);
            }
            AuditLogger::log(
                tenantId: $tenantId, userId: $request->user()->id, action: 'branding.update', entityType: 'CompanyBranding', entityId: $tenantId,
                beforeData: $before ? (array) $before : null,
                afterData: collect($values)->except(['updated_by', 'updated_at'])->all(),
            );
        });

        return response()->json(Branding::of($tenantId));
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get('tenant_id');
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
