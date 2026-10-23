<?php

namespace App\Modules\Subscription\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\NewSubscriptionMail;
use App\Mail\TenantWelcomeMail;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Subscription\Models\SaasPlan;
use App\Modules\Subscription\Http\Requests\StoreTenantRequest;
use App\Modules\Subscription\Support\TenantProvisioner;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TenantRegisterController extends Controller
{
    public function register(StoreTenantRequest $request)
    {
        $validated = $request->validated();

        $created = DB::transaction(fn () => TenantProvisioner::create([
            'name' => $validated['razon_social'],
            'legal_name' => $validated['razon_social'],
            'trade_name' => $validated['nombre_comercial'] ?? null,
            'ruc' => $validated['ruc'],
            'address' => [
                'direccion' => $validated['direccion'] ?? '',
                'departamento' => $validated['departamento'] ?? '',
                'distrito' => $validated['distrito'] ?? '',
                'telefono' => $validated['telefono'] ?? '',
            ],
            'admin_name' => $validated['nombres'].' '.$validated['apellidos'],
            'admin_email' => $validated['email'],
            'password_hash' => Hash::make($validated['password']),
            'plan' => $plan = SaasPlan::findOrFail($validated['plan_id']),
            'billing_period' => $validated['billing_period'],
            'trial' => (bool) ($plan->has_trial ?? false),
            'status' => 'ACTIVE',
        ]));
        unset($created['modules']);

        // Los correos van después de confirmar el registro: si el servidor de correo falla, la empresa ya quedó creada.
        $this->sendRegistrationMails($validated, ...$created);

        return response()->json([
            'message' => 'Empresa, permisos y cuenta de administrador creadas con éxito.',
            'tenant_code' => $created['tenant']->code,
        ], 201);
    }

    /** Bienvenida a quien se registró y aviso a cada superadministrador activo. */
    private function sendRegistrationMails(array $validated, Tenant $tenant, User $user, SaasPlan $plan, bool $hasTrial, Carbon $endsAt): void
    {
        $annual = $validated['billing_period'] === 'ANNUAL';
        $until = $endsAt->copy()->timezone('America/Lima')->format('d/m/Y');
        $price = $annual ? $plan->annual_price : $plan->monthly_price;
        $priceText = $price !== null && (float) $price > 0
            ? 'S/ '.number_format((float) $price, 2).($annual ? ' al año' : ' al mes')
            : 'Sin costo';
        $validity = $hasTrial ? "Prueba gratis hasta el {$until}" : ($annual ? 'Anual' : 'Mensual').", vigente hasta el {$until}";
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $welcome = [
            'Empresa' => $tenant->name,
            'Plan' => $plan->name,
            'Vigencia' => $validity,
            $hasTrial ? 'Precio al terminar la prueba' : 'Precio' => $priceText,
            'Correo de acceso' => $user->email,
        ];
        $this->sendSafely($user->email, new TenantWelcomeMail($tenant->name, $welcome, $frontend.'/login'));

        $notice = array_filter([
            'Empresa' => $tenant->name,
            'RUC' => $validated['ruc'],
            'Plan' => $plan->name,
            'Vigencia' => $validity,
            'Precio' => $priceText,
            'Administrador' => $user->name,
            'Correo' => $user->email,
            'Teléfono' => $validated['telefono'] ?? null,
            'Celular' => $validated['celular'] ?? null,
            'Registrada' => now('America/Lima')->format('d/m/Y H:i'),
        ], fn ($value) => filled($value));
        $admins = User::where('is_super_admin', true)->where('status', 'ACTIVE')->pluck('email');
        foreach ($admins as $email) {
            $this->sendSafely($email, new NewSubscriptionMail($tenant->name, $notice, $frontend.'/superadmin/tenants'));
        }
    }

    private function sendSafely(string $to, Mailable $mail): void
    {
        try {
            Mail::to($to)->send($mail);
        } catch (Throwable $e) {
            report($e);
        }
    }
}