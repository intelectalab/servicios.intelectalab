<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\AuditCatalog;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Auditoría de la empresa (Control): quién hizo qué, sobre qué registro y cuándo, con el antes
 * y el después. Es de solo lectura: la aplicación no puede modificar ni borrar `audit_logs`.
 * Quien tiene `audit.view` ve los movimientos de toda la empresa, no solo los propios.
 */
class AuditController extends Controller
{
    /** Claves cuyo valor nunca se entrega, aunque algún registro antiguo lo hubiera guardado. */
    private const SECRET_KEYS = '/password|secret|token|hash|otp/i';

    public function index(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $search = trim((string) $request->query('search', ''));
        $entity = (string) $request->query('entity', '');
        $userId = Str::isUuid((string) $request->query('user_id')) ? $request->query('user_id') : null;

        $query = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.tenant_id', $tenantId)
            ->when($entity !== '', fn ($q) => $q->where('audit_logs.entity_type', $entity))
            ->when($userId, fn ($q, $id) => $q->where('audit_logs.user_id', $id))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(function ($w) use ($like, $search) {
                    $w->where('audit_logs.action', 'ILIKE', $like)
                        ->orWhere('audit_logs.entity_type', 'ILIKE', $like)
                        ->orWhere('users.name', 'ILIKE', $like)
                        ->orWhere('users.email', 'ILIKE', $like)
                        ->orWhereRaw('audit_logs.entity_id::text ILIKE ?', [$like])
                        ->orWhereRaw('audit_logs.correlation_id::text ILIKE ?', [$like])
                        ->orWhereRaw('host(audit_logs.ip_address) ILIKE ?', [$like]);
                    AuditCatalog::orWhereLabelMatches($w, $search);
                });
            });

        $actionLabel = AuditCatalog::actionLabelSql();

        ColumnFilters::apply($query, $request, [
            'at' => ColumnFilters::date('audit_logs.created_at'),
            'action' => ColumnFilters::text("concat_ws(' ', {$actionLabel}, audit_logs.action)"),
            'ip' => ColumnFilters::text('host(audit_logs.ip_address)'),
            'correlation' => ColumnFilters::text('audit_logs.correlation_id'),
        ]);

        ListSort::apply($query, $request, [
            'at' => 'audit_logs.created_at',
            'actor' => 'lower(users.name)',
            'action' => "lower({$actionLabel})",
            'entity' => ListSort::labels('audit_logs.entity_type', AuditCatalog::ENTITIES),
            'ip' => 'audit_logs.ip_address',
            'correlation' => 'audit_logs.correlation_id::text',
        ], fn ($q) => $q->orderByDesc('audit_logs.created_at')->orderByDesc('audit_logs.id'), 'audit_logs.id');

        $page = $query
            ->select([
                'audit_logs.id', 'audit_logs.created_at', 'audit_logs.action', 'audit_logs.entity_type', 'audit_logs.entity_id',
                'audit_logs.user_id', 'users.name as user_name', 'users.email as user_email',
                'audit_logs.correlation_id', 'audit_logs.user_agent', 'audit_logs.before_data', 'audit_logs.after_data', 'audit_logs.metadata',
            ])
            ->selectRaw('host(audit_logs.ip_address) as ip')
            ->paginate(min(max($request->integer('per_page', 20), 1), 200));

        $page->getCollection()->transform(fn ($row) => [
            'id' => $row->id,
            'at' => Carbon::parse($row->created_at)->toIso8601String(),
            'action' => $row->action,
            'action_label' => AuditCatalog::actionLabel($row->action),
            'entity_type' => $row->entity_type,
            'entity_label' => AuditCatalog::entityLabel($row->entity_type),
            'entity_id' => $row->entity_id,
            'user' => $row->user_id ? ['id' => $row->user_id, 'name' => $row->user_name, 'email' => $row->user_email] : null,
            'ip_address' => $row->ip,
            'user_agent' => $row->user_agent,
            'correlation_id' => $row->correlation_id,
            'before' => self::data($row->before_data),
            'after' => self::data($row->after_data),
            'metadata' => self::data($row->metadata),
        ]);

        return response()->json($page);
    }

    /** Listas de los filtros: las entidades que tienen movimientos y las personas de la empresa. */
    public function options(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json([
            'entities' => DB::table('audit_logs')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('entity_type')
                ->distinct()
                ->pluck('entity_type')
                ->map(fn (string $type) => ['code' => $type, 'label' => AuditCatalog::entityLabel($type)])
                ->sortBy(fn (array $entity) => Str::ascii(mb_strtolower($entity['label'])))
                ->values(),
            'users' => DB::table('tenant_users')
                ->join('users', 'users.id', '=', 'tenant_users.user_id')
                ->where('tenant_users.tenant_id', $tenantId)
                ->orderBy('users.name')
                ->get(['users.id', 'users.name']),
        ]);
    }

    /** JSON guardado → dato para la pantalla; vacío es null y los secretos no salen. */
    private static function data(?string $json): ?array
    {
        $data = json_decode($json ?? 'null', true);

        return is_array($data) && $data !== [] ? self::redact($data) : null;
    }

    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEYS, $key)) {
                $data[$key] = '[oculto]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
