<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Alcance de los permisos: qué registros alcanza un rol, además de qué puede hacer.
 *
 *   OWN     lo propio: lo que la persona creó (o tomó, en las solicitudes)
 *   TEAM    lo propio y lo de su equipo
 *   BRANCH  lo propio y lo de sus sedes (la principal y las adicionales)
 *   TENANT  toda la empresa
 *
 * Se guarda por rol y recurso en `role_scopes` y lo aplican solos los modelos que usan
 * `ScopedByAccess`: listados, fichas, conteos y relaciones. Un registro fuera de alcance
 * "no existe" (404), igual que uno de otra empresa.
 *
 * Un contrato o una orden también es "propio" de quien hizo la cotización de origen: quien
 * vendió sigue viendo en qué terminó su venta aunque otra persona haya generado el documento.
 */
class AccessScope
{
    /** Recurso (prefijo de sus permisos) => nombre visible. */
    public const RESOURCES = [
        'quotations' => 'Cotizaciones',
        'contracts' => 'Contratos',
        'orders' => 'Órdenes',
        'portal' => 'Solicitudes del portal',
        'leads' => 'Leads',
        'opportunities' => 'Oportunidades',
    ];

    /** De menor a mayor alcance. */
    public const SCOPES = ['OWN', 'TEAM', 'BRANCH', 'TENANT'];

    /** Un rol sin alcance definido alcanza solo lo propio. */
    public const DEFAULT = 'OWN';

    private const ATTRIBUTE = 'access_scope';

    /**
     * Alcance de la persona de la petición sobre un recurso. null = no se restringe: no hay
     * una petición de empresa en curso (consola, tareas) o es el portal del cliente, que ya
     * filtra por su propio cliente.
     */
    public static function current(string $resource): ?string
    {
        $context = self::context();

        return $context ? ($context['scopes'][$resource] ?? self::DEFAULT) : null;
    }

    /** Alcance por recurso de la persona de la petición, para que la web lo muestre. */
    public static function all(): array
    {
        return self::context()['scopes'] ?? [];
    }

    /** Sede de la persona de la petición: la que reciben los documentos que crea. */
    public static function branchId(): ?string
    {
        return self::context()['branch_id'] ?? null;
    }

    /** Restringe la consulta a lo que la persona alcanza de ese recurso. */
    public static function apply(Builder $query, string $resource): void
    {
        $scope = self::current($resource);
        if ($scope === null || $scope === 'TENANT') {
            return;
        }

        $context = self::context();
        $table = $query->getModel()->getTable();
        $owners = $scope === 'TEAM' ? $context['team_user_ids'] : [$context['user_id']];

        $query->where(function (Builder $visible) use ($resource, $table, $owners, $scope, $context) {
            self::owned($visible, $resource, $table, $owners);

            // Un documento sin sede no es de ninguna: no se abre a todas las sedes.
            if ($scope === 'BRANCH' && $context['branch_ids']) {
                $visible->orWhereIn("{$table}.branch_id", $context['branch_ids']);
            }
        });
    }

    /** Lo "propio" de cada recurso para un conjunto de personas. */
    private static function owned(Builder $query, string $resource, string $table, array $owners): void
    {
        $fromQuotation = fn ($sub) => $sub->select('id')->from('quotations')->whereIn('created_by', $owners);

        match ($resource) {
            'quotations' => $query->whereIn("{$table}.created_by", $owners),
            'contracts' => $query->whereIn("{$table}.created_by", $owners)
                ->orWhereIn("{$table}.quotation_id", $fromQuotation),
            'orders' => $query->whereIn("{$table}.created_by", $owners)
                ->orWhereIn("{$table}.quotation_id", $fromQuotation)
                ->orWhereIn("{$table}.contract_id", fn ($sub) => $sub->select('id')->from('contracts')->whereIn('created_by', $owners)),
            // Una solicitud que nadie tomó todavía la ve quien pueda atenderla.
            'portal' => $query->whereNull("{$table}.taken_by")->orWhereIn("{$table}.taken_by", $owners),
            // En el CRM lo propio es lo que la persona lleva como responsable.
            'leads', 'opportunities' => $query->whereIn("{$table}.owner_user_id", $owners),
        };
    }

    /** @return array{user_id: string, branch_id: ?string, branch_ids: string[], team_user_ids: string[], scopes: array<string, string>}|null */
    private static function context(): ?array
    {
        $request = request();
        $tenantId = $request->attributes->get('tenant_id');
        $user = $request->user();

        if (! $tenantId || ! $user || $request->attributes->has('portal_customer_id')) {
            return null;
        }

        $cached = $request->attributes->get(self::ATTRIBUTE);
        if (is_array($cached) && $cached['user_id'] === $user->id) {
            return $cached;
        }

        $context = self::resolve((string) $tenantId, (string) $user->id);
        $request->attributes->set(self::ATTRIBUTE, $context);

        return $context;
    }

    private static function resolve(string $tenantId, string $userId): array
    {
        $roles = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.tenant_id', $tenantId)
            ->where('user_roles.user_id', $userId)
            ->get(['roles.id', 'roles.code', 'roles.is_system']);

        $member = DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $userId)->first(['team_id', 'default_branch_id']);
        $teamUserIds = $member?->team_id
            ? DB::table('tenant_users')->where('tenant_id', $tenantId)->where('team_id', $member->team_id)->pluck('user_id')->all()
            : [];

        // Las sedes adicionales amplían lo que se ve; lo que la persona crea nace en la principal.
        $extraBranchIds = DB::table('tenant_user_branches')->where('tenant_id', $tenantId)->where('user_id', $userId)->pluck('branch_id')->all();

        return [
            'user_id' => $userId,
            'branch_id' => $member?->default_branch_id,
            'branch_ids' => array_values(array_unique(array_filter([$member?->default_branch_id, ...$extraBranchIds]))),
            'team_user_ids' => array_values(array_unique([$userId, ...$teamUserIds])),
            'scopes' => self::scopesFor($roles),
        ];
    }

    /** El alcance más amplio entre los roles que dan permiso de ver cada recurso. */
    private static function scopesFor($roles): array
    {
        if ($roles->contains(fn ($role) => self::isAdmin($role))) {
            return array_fill_keys(array_keys(self::RESOURCES), 'TENANT');
        }

        $roleIds = $roles->pluck('id');
        $viewers = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->whereIn('role_permissions.role_id', $roleIds)
            ->whereIn('permissions.code', array_map(fn (string $resource) => "{$resource}.view", array_keys(self::RESOURCES)))
            ->get(['role_permissions.role_id', 'permissions.code']);
        $defined = DB::table('role_scopes')->whereIn('role_id', $roleIds)->get(['role_id', 'resource', 'scope']);

        $scopes = [];
        foreach (array_keys(self::RESOURCES) as $resource) {
            $rank = 0;
            foreach ($viewers->where('code', "{$resource}.view") as $viewer) {
                $scope = $defined->first(fn ($row) => $row->role_id === $viewer->role_id && $row->resource === $resource)?->scope ?? self::DEFAULT;
                $rank = max($rank, (int) array_search($scope, self::SCOPES, true));
            }
            $scopes[$resource] = self::SCOPES[$rank];
        }

        return $scopes;
    }

    /** El rol Administrador siempre alcanza toda la empresa: no se recorta. */
    public static function isAdmin(object $role): bool
    {
        return (bool) $role->is_system || strtoupper((string) $role->code) === 'ADMIN';
    }

    /** Olvida lo resuelto en esta petición (tras cambiar el rol, el equipo o la sede de alguien). */
    public static function forget(?Request $request = null): void
    {
        ($request ?? request())->attributes->remove(self::ATTRIBUTE);
    }
}
