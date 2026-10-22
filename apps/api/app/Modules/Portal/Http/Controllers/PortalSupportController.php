<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketComment;
use App\Services\AuditLogger;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Portal: los tickets del cliente. Nunca muestra notas internas (S9-R-002) ni quién atiende;
 * responder un ticket que esperaba al cliente lo devuelve a atención y reanuda su reloj.
 */
class PortalSupportController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            SupportTicket::query()->where('customer_id', $request->attributes->get('portal_customer_id'))
                ->select('support_tickets.*')->selectRaw(SupportTicket::SLA_SQL.' AS sla_state')
                ->orderByDesc('created_at')->get()
                ->map(fn (SupportTicket $ticket) => $this->row($ticket)),
        );
    }

    public function show(string $ticket, Request $request)
    {
        return response()->json($this->detail($this->find($ticket, $request), $request));
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $customerId = $request->attributes->get('portal_customer_id');
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
        ], [
            'subject.required' => 'El asunto es obligatorio.',
            'description.required' => 'Cuéntanos qué pasa.',
        ]);
        if (trim($data['subject']) === '' || trim($data['description']) === '') {
            throw ValidationException::withMessages(['description' => ['Escribe el asunto y la descripción.']]);
        }

        // El contrato vigente más reciente del cliente y su primer servicio, como al registrarlo la empresa.
        $contract = DB::table('contracts')->where('customer_id', $customerId)->where('status', 'ACTIVE')
            ->orderByDesc('created_at')->first(['id', 'branch_id']);
        $serviceId = $contract
            ? DB::table('contracted_services')->where('contract_id', $contract->id)->orderBy('created_at')->value('service_id')
            : null;

        $ticket = DB::transaction(function () use ($request, $tenantId, $customerId, $data, $contract, $serviceId) {
            $user = $request->user();
            $ticket = SupportTicket::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'contract_id' => $contract?->id,
                'service_id' => $serviceId,
                'ticket_number' => SequentialNumberGenerator::next($tenantId, 'support_tickets', 'ticket_number', 'TKT'),
                'subject' => trim($data['subject']),
                'description' => trim($data['description']),
                'channel' => 'PORTAL',
                'category' => 'INCIDENT',
                'status' => 'OPEN',
                'opened_at' => now(),
                'created_by' => $user->id,
                'branch_id' => $contract?->branch_id,
            ]);
            SupportTicketComment::create([
                'tenant_id' => $tenantId, 'ticket_id' => $ticket->id, 'author_user_id' => $user->id,
                'author_type' => 'PORTAL', 'body' => $ticket->description, 'internal' => false,
            ]);
            AuditLogger::log(
                tenantId: $tenantId, userId: $user->id, action: 'ticket.create', entityType: 'SupportTicket', entityId: $ticket->id,
                metadata: ['to' => 'OPEN', 'number' => $ticket->ticket_number, 'channel' => 'PORTAL'],
            );

            return $ticket;
        });

        return response()->json($this->detail($ticket, $request), 201);
    }

    /** Respuesta del cliente. Si el ticket esperaba al cliente, vuelve a atención y el reloj sigue. */
    public function comment(string $ticket, Request $request)
    {
        $ticket = $this->find($ticket, $request);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']], ['body.required' => 'Escribe tu respuesta.']);
        if (trim($data['body']) === '') {
            throw ValidationException::withMessages(['body' => ['Escribe tu respuesta.']]);
        }
        if ($ticket->status === 'CLOSED') {
            throw ValidationException::withMessages(['status' => ['El ticket está cerrado. Si el problema sigue, crea un ticket nuevo.']]);
        }

        DB::transaction(function () use ($request, $ticket, $data) {
            $user = $request->user();
            SupportTicketComment::create([
                'tenant_id' => $ticket->tenant_id, 'ticket_id' => $ticket->id, 'author_user_id' => $user->id,
                'author_type' => 'PORTAL', 'body' => trim($data['body']), 'internal' => false,
            ]);
            AuditLogger::log(
                tenantId: $ticket->tenant_id, userId: $user->id, action: 'ticket.comment.portal', entityType: 'SupportTicket', entityId: $ticket->id,
                metadata: ['length' => mb_strlen(trim($data['body']))],
            );

            if ($ticket->status === 'WAITING_CUSTOMER') {
                $ticket->update(['status' => 'IN_PROGRESS', ...$ticket->resumeClock(now())]);
                AuditLogger::log(
                    tenantId: $ticket->tenant_id, userId: $user->id, action: 'ticket.resume', entityType: 'SupportTicket', entityId: $ticket->id,
                    metadata: ['from' => 'WAITING_CUSTOMER', 'to' => 'IN_PROGRESS', 'note' => 'Respondió el cliente desde el portal'],
                );
            }
        });

        return response()->json($this->detail($ticket->refresh(), $request), 201);
    }

    private function find(string $id, Request $request): SupportTicket
    {
        return SupportTicket::query()->whereKey($id)
            ->where('customer_id', $request->attributes->get('portal_customer_id'))
            ->select('support_tickets.*')->selectRaw(SupportTicket::SLA_SQL.' AS sla_state')
            ->firstOrFail();
    }

    /** Lo que el cliente ve de la atención: recibido, en plazo, demorado o resuelto a tiempo. */
    private function attention(?string $sla): string
    {
        return match ($sla) {
            null, 'none' => 'RECEIVED',
            'breached' => 'DELAYED',
            'met' => 'ON_TIME',
            default => 'IN_TIME',
        };
    }

    private function row(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'attention' => $this->attention($ticket->sla_state ?? null),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }

    private function detail(SupportTicket $ticket, Request $request): array
    {
        if (! isset($ticket->sla_state)) {
            $ticket = $this->find($ticket->id, $request);
        }
        $comments = DB::table('support_ticket_comments')
            ->leftJoin('users', 'users.id', '=', 'support_ticket_comments.author_user_id')
            ->where('ticket_id', $ticket->id)->orderBy('support_ticket_comments.created_at')
            ->get(['support_ticket_comments.id', 'support_ticket_comments.body', 'support_ticket_comments.internal',
                'support_ticket_comments.author_type', 'support_ticket_comments.author_user_id', 'support_ticket_comments.created_at', 'users.name']);
        $public = $comments->where('internal', false);

        return [
            ...$this->row($ticket),
            'resolution' => in_array($ticket->status, ['RESOLVED', 'CLOSED'], true) ? $ticket->resolution : null,
            'comments' => $public->map(fn ($comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'from_customer' => $comment->author_type === 'PORTAL',
                'mine' => $comment->author_user_id === $request->user()->id,
                // Del lado de la empresa el cliente ve "Equipo de soporte", no quién atiende.
                'author' => $comment->author_type === 'PORTAL' ? $comment->name : null,
                'created_at' => Carbon::parse($comment->created_at)->toIso8601String(),
            ])->values(),
        ];
    }
}
