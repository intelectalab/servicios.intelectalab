<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Support\PortalAccess;
use App\Modules\Portal\Support\PortalCustomer;
use App\Modules\Portal\Support\PortalInvitations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use App\Services\LocalTime;

/**
 * Portal del cliente: quién entra, su inicio y la activación de la invitación.
 *
 * Todo lo que entrega el portal sale del cliente de la cuenta (`portal_customer_id`, que pone
 * el middleware `portal.user`), nunca de un dato que envíe el cliente. No muestra borradores,
 * notas internas, credenciales ni auditoría (RN-POR-04).
 */
class PortalController extends Controller
{
    /** La cuenta, su cliente, su rol, la empresa y las secciones que abre. */
    public function me(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $portalUser = $request->attributes->get('portal_user');
        $user = $request->user();
        $company = DB::table('companies')->where('tenant_id', $tenantId)->orderBy('created_at')->first(['legal_name', 'trade_name', 'tax_identifier']);
        $tenant = DB::table('tenants')->where('id', $tenantId)->first(['name', 'code', 'legal_name']);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $portalUser->role,
            'role_name' => PortalAccess::roleName($portalUser->role),
            'customer' => [...PortalCustomer::of($portalUser->customer_id), 'name' => PortalCustomer::name($portalUser->customer_id)],
            'company_name' => PortalCustomer::companyName($tenantId),
            // Misma forma que /me/access, para la cabecera de los PDF.
            'tenant' => $tenant,
            'company' => $company ? ['legal_name' => $company->legal_name, 'tax_identifier' => $company->tax_identifier] : null,
            'enabled' => $request->attributes->get('portal_enabled'),
            'sections' => $request->attributes->get('portal_sections'),
            'roles' => collect(PortalAccess::ROLES)->map(fn (array $role, string $code) => [
                'code' => $code, 'name' => $role[0],
                'sections' => array_map(fn ($section) => PortalAccess::SECTION_LABELS[$section], $role[1]),
            ])->values(),
        ]);
    }

    /** Inicio: pendientes, saldo, tickets, renovaciones, avisos y trabajos en curso. */
    public function home(Request $request)
    {
        $customerId = $request->attributes->get('portal_customer_id');
        $can = fn (string $section) => PortalAccess::can($request, $section);
        $today = LocalTime::today()->toDateString();

        $open = $can('invoices')
            ? DB::table('receivables')->where('customer_id', $customerId)->whereIn('status', ['OPEN', 'PARTIAL'])
                ->get(['balance_amount', 'currency', 'due_date'])
            : collect();

        return response()->json([
            'quotations_pending' => $can('quotes')
                ? DB::table('quotations')->where('customer_id', $customerId)->where('status', 'SUBMITTED')->count()
                : null,
            'balance' => $can('invoices')
                ? $open->groupBy('currency')->map(fn ($rows, $currency) => ['currency' => $currency, 'amount' => round($rows->sum('balance_amount'), 2)])->values()
                : null,
            'overdue' => $can('invoices') && $open->contains(fn ($row) => $row->due_date && $row->due_date < $today),
            'open_tickets' => $can('support')
                ? DB::table('support_tickets')->where('customer_id', $customerId)->whereNotIn('status', ['RESOLVED', 'CLOSED'])->count()
                : null,
            // Servicios recurrentes cuya vigencia termina en los próximos 60 días.
            'renewals' => $can('services')
                ? DB::table('recurring_service_instances')->where('customer_id', $customerId)->where('status', 'ACTIVE')
                    ->whereBetween('end_date', [$today, now()->addDays(60)->toDateString()])->count()
                : null,
            'signatures_pending' => $can('sign')
                ? DB::table('contract_signature_requests')->where('customer_id', $customerId)->where('status', 'PENDING')->where('expires_at', '>', now())->count()
                : null,
            'notices' => self::notices($request->attributes->get('tenant_id'), $customerId)->take(3)->values(),
            'work_orders' => $can('services')
                ? DB::table('work_orders')->where('customer_id', $customerId)->whereIn('status', ['ASSIGNED', 'IN_PROGRESS'])
                    ->orderBy('scheduled_start')->limit(10)
                    ->get(['id', 'code', 'title', 'status', 'scheduled_start'])
                : [],
        ]);
    }

    /** Avisos vigentes para el cliente: los suyos y los publicados para todos. */
    public static function notices(string $tenantId, string $customerId)
    {
        return DB::table('portal_notices')->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->whereNull('customer_id')->orWhere('customer_id', $customerId))
            ->whereNull('withdrawn_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('created_at')
            ->get(['id', 'body', 'link', 'created_at', 'expires_at']);
    }

    /** Activar la invitación (sin sesión): código del correo + contraseña nueva. */
    public function activate(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ], [
            'code.required' => 'Escribe el código de tu invitación.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        PortalInvitations::activate($data['email'], $data['code'], $data['password']);

        return response()->json(['message' => 'Tu acceso al portal está activo. Ya puedes iniciar sesión.']);
    }
}
