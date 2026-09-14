<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Support\Http\Requests\StoreTicketRequest;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketComment;
use App\Services\AuditLogger;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SupportTicketController extends Controller
{
    public function index(Request $request)
    {
        $query = SupportTicket::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->query('customer_id')));

        return response()->json($query->orderByDesc('opened_at')->paginate($request->integer('per_page', 20)));
    }

    public function show(string $ticket)
    {
        $ticket = SupportTicket::with('comments', 'sla')->findOrFail($ticket);

        return response()->json($ticket);
    }

    public function store(StoreTicketRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $ticketNumber = SequentialNumberGenerator::next($tenantId, 'support_tickets', 'ticket_number', 'TKT');

        $ticket = SupportTicket::create([
            'tenant_id' => $tenantId,
            'customer_id' => $request->customer_id,
            'contract_id' => $request->contract_id,
            'ticket_number' => $ticketNumber,
            'subject' => $request->subject,
            'description' => $request->description,
            'priority' => $request->priority ?? 'NORMAL',
            'status' => 'OPEN',
            'sla_id' => $request->sla_id,
            'opened_at' => now(),
        ]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'support_ticket.create',
            entityType: 'SupportTicket',
            entityId: $ticket->id,
            metadata: ['ticket_number' => $ticketNumber],
        );

        return response()->json($ticket, 201);
    }

    public function assign(string $ticket, Request $request)
    {
        $request->validate(['assigned_user_id' => ['required', 'uuid']]);

        $ticket = SupportTicket::findOrFail($ticket);

        $ticket->update([
            'assigned_user_id' => $request->assigned_user_id,
            'status' => 'ASSIGNED',
        ]);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'support_ticket.assign',
            entityType: 'SupportTicket',
            entityId: $ticket->id,
        );

        return response()->json($ticket);
    }

    public function addComment(string $ticket, Request $request)
    {
        $request->validate([
            'body' => ['required', 'string'],
            'internal' => ['boolean'],
        ]);

        $ticketModel = SupportTicket::findOrFail($ticket);
        $tenantId = $request->attributes->get('tenant_id');

        $comment = SupportTicketComment::create([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticketModel->id,
            'author_user_id' => $request->user()->id,
            'body' => $request->body,
            'internal' => $request->boolean('internal'),
        ]);

        // Un comentario mueve automáticamente el ticket a "en progreso"
        // si todavía estaba en un estado inicial.
        if (in_array($ticketModel->status, ['OPEN', 'TRIAGED', 'ASSIGNED'])) {
            $ticketModel->update(['status' => 'IN_PROGRESS']);
        }

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'support_ticket.comment',
            entityType: 'SupportTicket',
            entityId: $ticketModel->id,
        );

        return response()->json($comment, 201);
    }

    public function resolve(string $ticket, Request $request)
    {
        $ticket = SupportTicket::findOrFail($ticket);

        if (! in_array($ticket->status, ['IN_PROGRESS', 'ASSIGNED', 'REOPENED'])) {
            throw ValidationException::withMessages([
                'status' => ["No se puede resolver un ticket en estado {$ticket->status}."],
            ]);
        }

        $ticket->update(['status' => 'RESOLVED', 'resolved_at' => now()]);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'support_ticket.resolve',
            entityType: 'SupportTicket',
            entityId: $ticket->id,
        );

        return response()->json($ticket);
    }

    public function close(string $ticket, Request $request)
    {
        $ticket = SupportTicket::findOrFail($ticket);

        if ($ticket->status !== 'RESOLVED') {
            throw ValidationException::withMessages([
                'status' => ['Solo se puede cerrar un ticket que ya esté resuelto.'],
            ]);
        }

        $ticket->update(['status' => 'CLOSED', 'closed_at' => now()]);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'support_ticket.close',
            entityType: 'SupportTicket',
            entityId: $ticket->id,
        );

        return response()->json($ticket);
    }

    public function reopen(string $ticket, Request $request)
    {
        $ticket = SupportTicket::findOrFail($ticket);

        if (! in_array($ticket->status, ['RESOLVED', 'CLOSED'])) {
            throw ValidationException::withMessages([
                'status' => ['Solo se puede reabrir un ticket resuelto o cerrado.'],
            ]);
        }

        $ticket->update(['status' => 'REOPENED', 'resolved_at' => null, 'closed_at' => null]);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'support_ticket.reopen',
            entityType: 'SupportTicket',
            entityId: $ticket->id,
        );

        return response()->json($ticket);
    }
}