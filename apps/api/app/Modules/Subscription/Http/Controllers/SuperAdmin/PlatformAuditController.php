<?php

namespace App\Modules\Subscription\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Auditoría de plataforma y Suscripciones, leídas con el usuario de la consola. */
class PlatformAuditController extends Controller
{
    private const CONSOLE = PlatformDashboardController::CONNECTION;

    private const SORTS = ['created_at', 'action', 'entity_type', 'user_name'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'action' => ['nullable', 'string', 'max:100'],
            'user' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'in:'.implode(',', self::SORTS)],
            'dir' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = DB::connection(self::CONSOLE)->table('platform_audit_view')
            ->when($data['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($data['user'] ?? null, fn ($q, $user) => $q->where('user_id', $user))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<', date('Y-m-d', strtotime($to.' +1 day'))))
            ->when(trim((string) ($data['q'] ?? '')), function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($w) => $w->where('action', 'ilike', $like)->orWhere('entity_type', 'ilike', $like)
                    ->orWhere('user_name', 'ilike', $like)->orWhere('user_email', 'ilike', $like)
                    ->orWhereRaw('entity_id::text ilike ?', [$like])
                    ->orWhereRaw('after_data::text ilike ?', [$like])
                    ->orWhereRaw('metadata::text ilike ?', [$like]));
            })
            ->orderBy($data['sort'] ?? 'created_at', $data['dir'] ?? 'desc')
            ->orderByDesc('id');

        $page = $query->paginate((int) ($data['per_page'] ?? 25));
        $page->getCollection()->transform(function ($row) {
            foreach (['before_data', 'after_data', 'metadata'] as $field) {
                $row->{$field} = $row->{$field} === null ? null : json_decode($row->{$field}, true);
            }

            return $row;
        });

        return response()->json($page);
    }

    public function options(): JsonResponse
    {
        $console = DB::connection(self::CONSOLE);

        return response()->json([
            'actions' => $console->table('platform_audit_view')->distinct()->orderBy('action')->pluck('action'),
            'users' => $console->table('platform_audit_view')->whereNotNull('user_id')
                ->select('user_id as id', 'user_name as name', 'user_email as email')->distinct()->orderBy('user_name')->get(),
        ]);
    }

    public function subscriptions(): JsonResponse
    {
        $row = DB::connection(self::CONSOLE)->selectOne('SELECT platform_subscriptions() AS data');

        return response()->json(['data' => json_decode($row->data, true)]);
    }
}
