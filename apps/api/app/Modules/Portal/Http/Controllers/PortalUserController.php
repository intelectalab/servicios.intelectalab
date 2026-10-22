<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Support\PortalAccess;
use App\Modules\Portal\Support\PortalInvitations;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Usuarios del portal, del lado de la empresa (Administración). Cada cliente administra sus
 * propios usuarios desde el portal (Portal Admin); desde aquí la empresa los ve, invita en
 * nombre del cliente, cambia el rol o desactiva accesos. Las reglas son las mismas.
 */
class PortalUserController extends Controller
{
    private const CUSTOMER = "(SELECT COALESCE(o.trade_name, o.legal_name, concat_ws(' ', p.given_names, p.family_name))
        FROM customers c LEFT JOIN organizations o ON o.party_id = c.party_id LEFT JOIN persons p ON p.party_id = c.party_id
        WHERE c.id = portal_users.customer_id)";

    public const STATES = ['INVITED' => 'Invitado', 'ACTIVE' => 'Activo', 'INACTIVE' => 'Desactivado'];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $query = self::listing()
            ->when($request->filled('status'), fn ($q) => $q->where('portal_users.status', $request->query('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('portal_users.customer_id', $request->query('customer_id')))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('users.name', 'ILIKE', $like)->orWhere('users.email', 'ILIKE', $like)
                    ->orWhereRaw(self::CUSTOMER.' ILIKE ?', [$like]));
            });

        ColumnFilters::apply($query, $request, [
            'user' => ColumnFilters::text("concat_ws(' ', users.name, users.email)"),
            'customer' => ColumnFilters::text(self::CUSTOMER),
            'last_login' => ColumnFilters::date('portal_users.last_login_at'),
        ]);
        ListSort::apply($query, $request, [
            'user' => 'lower(users.name)',
            'customer' => 'lower('.self::CUSTOMER.')',
            'role' => ListSort::labels('portal_users.role', array_map(fn ($role) => $role[0], PortalAccess::ROLES)),
            'last_login' => 'portal_users.last_login_at',
            'status' => ListSort::labels('portal_users.status', self::STATES),
        ], fn ($q) => $q->orderByDesc('portal_users.created_at'), 'portal_users.id');

        $page = $query->paginate(min($request->integer('per_page', 20), 200));
        $page->setCollection($page->getCollection()->map(fn ($row) => self::present($row)));

        return response()->json($page);
    }

    /** Indicadores del prototipo: activos, invitaciones pendientes, clientes con portal, con verificación en dos pasos. */
    public function summary()
    {
        $rows = DB::table('portal_users')->get(['status', 'customer_id']);

        return response()->json([
            'active' => $rows->where('status', 'ACTIVE')->count(),
            'invited' => $rows->where('status', 'INVITED')->count(),
            'customers' => $rows->where('status', '!=', 'INACTIVE')->pluck('customer_id')->unique()->count(),
            // En el portal la verificación en dos pasos es obligatoria: la tiene toda cuenta activa.
            'mfa' => $rows->where('status', 'ACTIVE')->count(),
        ]);
    }

    /** Clientes activos a los que se puede invitar, con cuántos usuarios vigentes tienen. */
    public function options()
    {
        $customers = DB::table('customers')->where('status', 'ACTIVE')
            ->select('customers.id')->selectRaw(str_replace('portal_users.customer_id', 'customers.id', self::CUSTOMER).' AS name')
            ->selectRaw("(SELECT count(*) FROM portal_users pu WHERE pu.customer_id = customers.id AND pu.status <> 'INACTIVE') AS users")
            ->orderBy('name')->get();

        return response()->json([
            'customers' => $customers->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'users' => (int) $row->users]),
            'roles' => collect(PortalAccess::ROLES)->map(fn (array $role, string $code) => [
                'code' => $code, 'name' => $role[0], 'sections' => array_map(fn ($s) => PortalAccess::SECTION_LABELS[$s], $role[1]),
            ])->values(),
            'max_users' => PortalInvitations::MAX_USERS,
        ]);
    }

    /** Invitar en nombre del cliente. */
    public function store(Request $request)
    {
        $data = self::validated($request, true);
        $row = PortalInvitations::invite($request->attributes->get('tenant_id'), $data['customer_id'], $data, $request->user()->id, 'STAFF');

        return response()->json(self::present(self::listing()->where('portal_users.id', $row->id)->first()), 201);
    }

    /** Cambiar el rol, desactivar o reactivar. */
    public function update(string $portalUser, Request $request)
    {
        $row = $this->find($portalUser);
        $data = $request->validate([
            'role' => ['sometimes', 'in:'.implode(',', array_keys(PortalAccess::ROLES))],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $updated = PortalInvitations::update($row, $data, $request->user()->id, false);

        return response()->json(self::present(self::listing()->where('portal_users.id', $updated->id)->first()));
    }

    public function resend(string $portalUser, Request $request)
    {
        PortalInvitations::resend($this->find($portalUser), $request->user()->id);

        return response()->json(self::present(self::listing()->where('portal_users.id', $portalUser)->first()));
    }

    public static function listing()
    {
        return DB::table('portal_users')->join('users', 'users.id', '=', 'portal_users.user_id')
            ->select('portal_users.*', 'users.name', 'users.email')
            ->selectRaw(self::CUSTOMER.' AS customer_name');
    }

    public static function validated(Request $request, bool $withCustomer): array
    {
        return $request->validate([
            ...($withCustomer ? ['customer_id' => ['required', 'uuid']] : []),
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:'.implode(',', array_keys(PortalAccess::ROLES))],
        ], [
            'customer_id.required' => 'Selecciona el cliente.',
            'name.required' => 'Escribe el nombre completo.',
            'email.required' => 'Escribe el correo.',
            'email.email' => 'Escribe un correo válido.',
            'role.required' => 'Selecciona el rol.',
        ]);
    }

    public static function present(object $row): array
    {
        return [
            'id' => $row->id,
            'user_id' => $row->user_id,
            'name' => $row->name,
            'email' => $row->email,
            'customer_id' => $row->customer_id,
            'customer_name' => $row->customer_name ?? null,
            'role' => $row->role,
            'role_name' => PortalAccess::roleName($row->role),
            'status' => $row->status,
            'invited_at' => $row->invited_at ? Carbon::parse($row->invited_at)->toIso8601String() : null,
            'invite_expires_at' => $row->invite_expires_at ? Carbon::parse($row->invite_expires_at)->toIso8601String() : null,
            'activated_at' => $row->activated_at ? Carbon::parse($row->activated_at)->toIso8601String() : null,
            'last_login_at' => $row->last_login_at ? Carbon::parse($row->last_login_at)->toIso8601String() : null,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'lock_version' => (int) $row->lock_version,
        ];
    }

    private function find(string $id): object
    {
        abort_unless(Str::isUuid($id), 404);

        return DB::table('portal_users')->where('id', $id)->first() ?? abort(404);
    }
}
