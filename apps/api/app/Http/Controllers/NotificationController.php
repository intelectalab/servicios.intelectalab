<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Centro de notificaciones y campana de la cabecera: siempre las propias, en la empresa de la
 * sesión. La de otra persona (o de otra empresa) "no existe" (404).
 */
class NotificationController extends Controller
{
    public const PER_PAGE_MAX = 200;

    /** Lista paginada: todas o solo las no leídas, con buscador. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:all,unread,read'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::PER_PAGE_MAX],
        ]);

        $query = $this->mine($request)
            ->when(($data['status'] ?? 'all') === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when(($data['status'] ?? 'all') === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->when(trim((string) ($data['q'] ?? '')) !== '', function ($q) use ($data) {
                $like = '%'.addcslashes(trim($data['q']), '%_\\').'%';
                $q->where(fn ($w) => $w->where('subject', 'ILIKE', $like)->orWhere('body', 'ILIKE', $like));
            })
            ->orderByDesc('created_at')->orderByDesc('id');

        $page = $query->paginate($data['per_page'] ?? 20, self::COLUMNS);
        $page->getCollection()->transform(fn ($row) => $this->row($row));

        return response()->json([...$page->toArray(), 'unread' => $this->mine($request)->whereNull('read_at')->count()]);
    }

    /** Para la campana: cuántas sin leer y las más recientes. */
    public function summary(Request $request)
    {
        return response()->json([
            'unread' => $this->mine($request)->whereNull('read_at')->count(),
            'latest' => $this->mine($request)->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get(self::COLUMNS)
                ->map(fn ($row) => $this->row($row)),
        ]);
    }

    public function read(string $notification, Request $request)
    {
        abort_unless(Str::isUuid($notification), 404);
        $row = $this->mine($request)->where('id', $notification)->first(self::COLUMNS);
        abort_if(! $row, 404);

        if (! $row->read_at) {
            DB::table('notifications')->where('id', $row->id)->whereNull('read_at')->update(['read_at' => now()]);
            $row->read_at = now();
        }

        return response()->json($this->row($row));
    }

    public function readAll(Request $request)
    {
        $marked = $this->mine($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['marked' => $marked]);
    }

    private const COLUMNS = ['id', 'event', 'subject', 'body', 'link', 'read_at', 'created_at'];

    private function mine(Request $request)
    {
        return DB::table('notifications')
            ->where('tenant_id', $request->attributes->get('tenant_id'))
            ->where('user_id', $request->user()->id)
            ->where('channel', 'IN_APP');
    }

    private function row(object $row): array
    {
        return [
            'id' => $row->id,
            'event' => $row->event,
            'title' => $row->subject,
            'body' => $row->body,
            'link' => $row->link,
            'read' => $row->read_at !== null,
            'read_at' => $row->read_at ? \Illuminate\Support\Carbon::parse($row->read_at)->toIso8601String() : null,
            'created_at' => \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }
}
