<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Support\PortalAccess;
use App\Modules\Portal\Support\PortalInvitations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Portal → Usuarios: el Portal Admin invita a su equipo, cambia su rol o desactiva su acceso
 * (RN-POR-03). Solo ve y toca a los usuarios de su propio cliente.
 */
class PortalTeamController extends Controller
{
    public function index(Request $request)
    {
        $me = $request->user()->id;

        return response()->json([
            'users' => PortalUserController::listing()
                ->where('portal_users.customer_id', $request->attributes->get('portal_customer_id'))
                ->orderByRaw("CASE portal_users.status WHEN 'ACTIVE' THEN 0 WHEN 'INVITED' THEN 1 ELSE 2 END")->orderBy('users.name')
                ->get()->map(fn ($row) => [...PortalUserController::present($row), 'is_me' => $row->user_id === $me]),
            'roles' => collect(PortalAccess::ROLES)->map(fn (array $role, string $code) => [
                'code' => $code, 'name' => $role[0], 'sections' => array_map(fn ($s) => PortalAccess::SECTION_LABELS[$s], $role[1]),
            ])->values(),
            'max_users' => PortalInvitations::MAX_USERS,
        ]);
    }

    public function store(Request $request)
    {
        $data = PortalUserController::validated($request, false);
        $row = PortalInvitations::invite(
            $request->attributes->get('tenant_id'), $request->attributes->get('portal_customer_id'), $data, $request->user()->id, 'PORTAL',
        );

        return response()->json(PortalUserController::present(PortalUserController::listing()->where('portal_users.id', $row->id)->first()), 201);
    }

    public function update(string $portalUser, Request $request)
    {
        $row = $this->find($portalUser, $request);
        $data = $request->validate([
            'role' => ['sometimes', 'in:'.implode(',', array_keys(PortalAccess::ROLES))],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $updated = PortalInvitations::update($row, $data, $request->user()->id, true);

        return response()->json(PortalUserController::present(PortalUserController::listing()->where('portal_users.id', $updated->id)->first()));
    }

    public function resend(string $portalUser, Request $request)
    {
        PortalInvitations::resend($this->find($portalUser, $request), $request->user()->id);

        return response()->json(['message' => 'Invitación reenviada.']);
    }

    /** Un usuario de otro cliente "no existe" (404). */
    private function find(string $id, Request $request): object
    {
        abort_unless(Str::isUuid($id), 404);

        return DB::table('portal_users')->where('id', $id)
            ->where('customer_id', $request->attributes->get('portal_customer_id'))->first() ?? abort(404);
    }
}
