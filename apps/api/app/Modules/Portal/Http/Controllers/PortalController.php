<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Customer;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Portal\Models\PortalRequest;
use App\Modules\Services\Models\Contract;
use App\Modules\Services\Models\Quotation;
use App\Modules\Support\Models\SupportTicket;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class PortalController extends Controller
{
    /**
     * Panel principal del cliente: su propia info, sin nada de otro cliente.
     * customer_id SIEMPRE sale de portal_customer_id (resuelto por el
     * middleware), nunca de algo que el cliente pueda manipular.
     */
    public function dashboard(Request $request)
    {
        $customerId = $request->attributes->get('portal_customer_id');

        $customer = Customer::with('party.person', 'party.organization')->findOrFail($customerId);

        $activeContracts = Contract::where('customer_id', $customerId)->where('status', 'ACTIVE')->get();
        $pendingInvoices = Invoice::where('customer_id', $customerId)
            ->whereIn('status', ['ISSUED', 'PARTIALLY_PAID', 'OVERDUE'])
            ->get(['id', 'invoice_number', 'total', 'balance_due', 'due_date']);
        $openTickets = SupportTicket::where('customer_id', $customerId)
            ->whereNotIn('status', ['CLOSED'])
            ->count();

        return response()->json([
            'customer' => $customer,
            'active_contracts' => $activeContracts,
            'pending_invoices' => $pendingInvoices,
            'open_tickets' => $openTickets,
        ]);
    }

    public function requests(Request $request)
    {
        $customerId = $request->attributes->get('portal_customer_id');

        return response()->json(
            PortalRequest::where('customer_id', $customerId)->orderByDesc('created_at')->get()
        );
    }

    public function storeRequest(Request $request)
    {
        $request->validate([
            'request_type' => ['required', 'in:NEW_SERVICE,SUPPORT,RENEWAL,OTHER'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $customerId = $request->attributes->get('portal_customer_id');

        $portalRequest = PortalRequest::create([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'request_type' => $request->request_type,
            'subject' => $request->subject,
            'description' => $request->description,
            'status' => 'OPEN',
            'created_by' => $request->user()->id,
        ]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'portal_request.create',
            entityType: 'PortalRequest',
            entityId: $portalRequest->id,
        );

        // Nota: si el CRM está activo, aquí debería generarse una
        // crm_opportunity automáticamente para solicitudes de tipo
        // NEW_SERVICE. Queda pendiente de definir el criterio exacto
        // con el equipo (no lo invento).

        return response()->json($portalRequest, 201);
    }
}