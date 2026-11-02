<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\MessageCenter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Notificaciones (Administración): plantillas por evento, bitácora de envíos y canales de la
 * empresa. El envío lo hace MessageCenter; aquí solo se configura y se consulta.
 */
class MessagingController extends Controller
{
    public const STATES = ['QUEUED' => 'En cola', 'SENT' => 'Enviado', 'FAILED' => 'Fallido', 'SKIPPED' => 'Omitido'];

    /** Indicadores, plantillas (con el texto del sistema si la empresa no la cambió) y canales. */
    public function show(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $templates = DB::table('message_templates')->where('tenant_id', $tenantId)->where('channel', 'EMAIL')->get()->keyBy('event');

        $rows = [];
        foreach (MessageCenter::CUSTOMER as $event => $definition) {
            $rows[] = $this->template($event, $definition['label'], 'CUSTOMER', $definition['variables'], $definition['subject'], $definition['body'], $templates->get($event));
        }
        foreach (MessageCenter::INTERNAL as $event => $label) {
            $rows[] = $this->template($event, $label, 'INTERNAL', MessageCenter::INTERNAL_VARIABLES, '{titulo}', '{detalle}', $templates->get($event));
        }

        $counts = DB::table('outgoing_messages')->where('tenant_id', $tenantId)
            ->selectRaw("count(*) FILTER (WHERE status = 'SENT') AS sent")
            ->selectRaw('count(*) FILTER (WHERE opened_at IS NOT NULL) AS opened')
            ->selectRaw("count(*) FILTER (WHERE status = 'FAILED') AS failed")
            ->selectRaw("count(*) FILTER (WHERE status = 'QUEUED') AS queued")
            ->selectRaw('count(*) AS total')
            ->first();

        return response()->json([
            'kpis' => [
                'sent' => (int) $counts->sent,
                'opened' => (int) $counts->opened,
                'failed' => (int) $counts->failed,
                'queued' => (int) $counts->queued,
                'total' => (int) $counts->total,
                'active_templates' => collect($rows)->where('active', true)->count(),
            ],
            'templates' => $rows,
            'channels' => MessageCenter::channels($tenantId) + [
                'from_address' => (string) config('mail.from.address'),
                'whatsapp' => null,
            ],
            'states' => self::STATES,
            'events' => collect($rows)->mapWithKeys(fn ($row) => [$row['event'] => $row['label']]),
        ]);
    }

    /** Asunto, texto y si se envía. Con control de ediciones simultáneas (If-Match: lock_version). */
    public function updateTemplate(Request $request, string $event)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $definition = $this->definition($event);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'active' => ['required', 'boolean'],
        ], ['subject.required' => 'Escribe el asunto.', 'body.required' => 'Escribe el texto del mensaje.']);
        $this->assertVariables($data['subject'].' '.$data['body'], $definition['variables']);

        DB::transaction(function () use ($request, $tenantId, $event, $data) {
            $current = DB::table('message_templates')->where('tenant_id', $tenantId)->where('event', $event)->where('channel', 'EMAIL')->lockForUpdate()->first();
            $expected = $request->header('If-Match');
            if ($current && $expected !== null && (int) $expected !== (int) $current->lock_version) {
                abort(response()->json(['code' => 'STALE_RECORD', 'message' => 'Otra persona cambió esta plantilla mientras la editabas. Vuelve a abrirla.'], 409));
            }

            $values = ['subject' => trim($data['subject']), 'body' => trim($data['body']), 'active' => $data['active'], 'updated_by' => $request->user()->id, 'updated_at' => now()];
            if ($current) {
                DB::table('message_templates')->where('id', $current->id)->update($values + ['lock_version' => $current->lock_version + 1]);
                $id = $current->id;
            } else {
                $id = (string) Str::uuid();
                DB::table('message_templates')->insert($values + ['id' => $id, 'tenant_id' => $tenantId, 'event' => $event, 'channel' => 'EMAIL', 'created_at' => now()]);
            }
            AuditLogger::log($tenantId, $request->user()->id, 'message_template.update', 'MessageTemplate', $id,
                $current ? ['subject' => $current->subject, 'active' => (bool) $current->active] : null,
                ['subject' => $values['subject'], 'active' => $values['active']], ['note' => $event]);
        });

        return $this->show($request);
    }

    /** Vuelve al texto del sistema; conserva si está activa. */
    public function resetTemplate(Request $request, string $event)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $definition = $this->definition($event);
        DB::transaction(function () use ($request, $tenantId, $event, $definition) {
            $current = DB::table('message_templates')->where('tenant_id', $tenantId)->where('event', $event)->where('channel', 'EMAIL')->lockForUpdate()->first();
            if (! $current) {
                return;
            }
            DB::table('message_templates')->where('id', $current->id)->update([
                'subject' => $definition['subject'], 'body' => $definition['body'],
                'lock_version' => $current->lock_version + 1, 'updated_by' => $request->user()->id, 'updated_at' => now(),
            ]);
            AuditLogger::log($tenantId, $request->user()->id, 'message_template.reset', 'MessageTemplate', $current->id, metadata: ['note' => $event]);
        });

        return $this->show($request);
    }

    public function updateChannels(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $data = $request->validate([
            'email' => ['required', 'boolean'],
            'sender_name' => ['nullable', 'string', 'max:80'],
            'reply_to' => ['nullable', 'email', 'max:255'],
        ], ['reply_to.email' => 'El correo para respuestas no es válido.']);

        DB::transaction(function () use ($request, $tenantId, $data) {
            $row = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first(['configuration']);
            $configuration = json_decode($row->configuration ?: '{}', true) ?: [];
            $before = MessageCenter::channels($tenantId);
            $configuration['messaging'] = [
                'email' => (bool) $data['email'],
                // Sin saltos de línea: va en la cabecera del correo.
                'sender_name' => trim(preg_replace('/[\r\n"<>]+/', ' ', (string) ($data['sender_name'] ?? ''))),
                'reply_to' => trim((string) ($data['reply_to'] ?? '')),
            ];
            DB::table('tenants')->where('id', $tenantId)->update(['configuration' => json_encode($configuration), 'updated_at' => now()]);
            AuditLogger::log($tenantId, $request->user()->id, 'messaging.channels', 'MessagingChannels', $tenantId, $before, $configuration['messaging']);
        });

        return $this->show($request);
    }

    public function messages(Request $request)
    {
        $query = DB::table('outgoing_messages as m')
            ->where('m.tenant_id', (string) $request->attributes->get('tenant_id'))
            ->when(array_key_exists((string) $request->query('status'), self::STATES), fn ($q) => $q->where('m.status', $request->query('status')))
            ->when(in_array($request->query('audience'), ['INTERNAL', 'CUSTOMER'], true), fn ($q) => $q->where('m.audience', $request->query('audience')))
            ->when($request->query('opened') === '1', fn ($q) => $q->whereNotNull('m.opened_at'))
            ->when(is_string($request->query('event')) && $request->query('event') !== '', fn ($q) => $q->where('m.event', $request->query('event')))
            ->select(['m.id', 'm.channel', 'm.event', 'm.audience', 'm.to_address', 'm.to_name', 'm.subject', 'm.status', 'm.error', 'm.attempts', 'm.opened_at', 'm.sent_at', 'm.created_at']);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('m.to_address', 'ilike', "%{$search}%")->orWhere('m.to_name', 'ilike', "%{$search}%")
                ->orWhere('m.subject', 'ilike', "%{$search}%")->orWhere('m.body', 'ilike', "%{$search}%"));
        }
        ColumnFilters::apply($query, $request, [
            'to' => ColumnFilters::text("concat_ws(' ', m.to_name, m.to_address)"),
            'subject' => ColumnFilters::text('m.subject'),
            'date' => ColumnFilters::date("(m.created_at AT TIME ZONE 'America/Lima')"),
        ]);
        ListSort::apply($query, $request, [
            'to' => 'lower(m.to_address)', 'subject' => 'lower(m.subject)', 'status' => 'm.status', 'date' => 'm.created_at', 'opened' => 'm.opened_at',
        ], fn ($q) => $q->orderByDesc('m.created_at'), 'm.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn ($row) => $this->messageRow($row));

        return response()->json($page);
    }

    public function message(Request $request, string $message)
    {
        abort_unless(Str::isUuid($message), 404);
        $row = DB::table('outgoing_messages')->where('tenant_id', (string) $request->attributes->get('tenant_id'))->where('id', $message)->first();
        abort_unless($row, 404);

        return response()->json($this->messageRow($row) + ['body' => $row->body, 'link' => $row->link]);
    }

    /** Reintentar un envío fallido, ahora mismo. */
    public function retry(Request $request, string $message)
    {
        abort_unless(Str::isUuid($message), 404);
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = DB::table('outgoing_messages')->where('tenant_id', $tenantId)->where('id', $message)->first();
        abort_unless($row, 404);
        if ($row->status !== 'FAILED') {
            throw ValidationException::withMessages(['status' => ['Solo se reintenta un envío fallido.']]);
        }

        $status = MessageCenter::retry($tenantId, $row->id);
        AuditLogger::log($tenantId, $request->user()->id, 'message.retry', 'OutgoingMessage', $row->id, metadata: ['note' => "{$row->subject} → {$row->to_address}: ".(self::STATES[$status] ?? $status)]);

        return $this->message($request, $row->id);
    }

    /** Imagen de 1×1 del correo: marca la apertura. Pública y sin sesión; no revela nada. */
    public function opened(string $token)
    {
        if (preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            DB::select('SELECT message_opened(?)', [$token]);
        }

        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function template(string $event, string $label, string $audience, array $variables, string $subject, string $body, ?object $row): array
    {
        return [
            'event' => $event,
            'label' => $label,
            'audience' => $audience,
            'channel' => 'EMAIL',
            'variables' => $variables,
            'subject' => $row->subject ?? $subject,
            'body' => $row->body ?? $body,
            'default_subject' => $subject,
            'default_body' => $body,
            'customized' => $row !== null && ($row->subject !== $subject || $row->body !== $body),
            'active' => $row ? (bool) $row->active : true,
            'lock_version' => $row ? (int) $row->lock_version : 0,
            'updated_at' => $row ? Carbon::parse($row->updated_at)->toIso8601String() : null,
        ];
    }

    /** @return array{subject: string, body: string, variables: string[]} */
    private function definition(string $event): array
    {
        if (isset(MessageCenter::CUSTOMER[$event])) {
            return MessageCenter::CUSTOMER[$event];
        }
        abort_unless(isset(MessageCenter::INTERNAL[$event]), 404);

        return ['subject' => '{titulo}', 'body' => '{detalle}', 'variables' => MessageCenter::INTERNAL_VARIABLES];
    }

    private function assertVariables(string $text, array $allowed): void
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $matches);
        $unknown = array_values(array_diff(array_unique($matches[1]), $allowed));
        if ($unknown) {
            throw ValidationException::withMessages(['body' => ['Variable desconocida: {'.implode('}, {', $unknown).'}. Usa: {'.implode('}, {', $allowed).'}.']]);
        }
    }

    private function messageRow(object $row): array
    {
        return [
            'id' => $row->id,
            'channel' => $row->channel,
            'event' => $row->event,
            'event_label' => MessageCenter::INTERNAL[$row->event] ?? (MessageCenter::CUSTOMER[$row->event]['label'] ?? $row->event),
            'audience' => $row->audience,
            'to' => $row->to_address,
            'to_name' => $row->to_name,
            'subject' => $row->subject,
            'status' => $row->status,
            'error' => $row->error,
            'attempts' => (int) $row->attempts,
            'opened_at' => $row->opened_at ? Carbon::parse($row->opened_at)->toIso8601String() : null,
            'sent_at' => $row->sent_at ? Carbon::parse($row->sent_at)->toIso8601String() : null,
            'at' => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }
}
