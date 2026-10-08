<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sedes y equipos de la empresa. Son los grupos de los que depende el alcance "Sede" y
 * "Equipo" de los roles: cada usuario pertenece a una sede y a un equipo (se asignan en
 * Usuarios). No se borran, se desactivan: los documentos conservan su sede.
 */
class OrgUnitController extends Controller
{
    /** Tipo de la ruta => tabla, columna que enlaza al usuario, textos para los mensajes y entidad de auditoría. */
    private const KINDS = [
        'branches' => ['table' => 'branches', 'member' => 'default_branch_id', 'of' => 'de la sede', 'a' => 'una sede', 'entity' => 'Branch'],
        'teams' => ['table' => 'teams', 'member' => 'team_id', 'of' => 'del equipo', 'a' => 'un equipo', 'entity' => 'Team'],
    ];

    public function index(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json([
            'branches' => $this->rows($tenantId, 'branches'),
            'teams' => $this->rows($tenantId, 'teams'),
        ]);
    }

    public function store(string $kind, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $config = $this->kind($kind);
        $name = $this->validName($request, $tenantId, $config);

        $id = (string) Str::uuid();
        $row = ['id' => $id, 'tenant_id' => $tenantId, 'name' => $name, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()];

        if ($kind === 'branches') {
            // La sede cuelga de la empresa (razón social) del tenant.
            $companyId = DB::table('companies')->where('tenant_id', $tenantId)->orderBy('created_at')->value('id');
            if (! $companyId) {
                throw ValidationException::withMessages(['name' => ['La empresa aún no tiene registrada su razón social; sin ella no se pueden crear sedes.']]);
            }
            $row += ['company_id' => $companyId, 'code' => $this->branchCode($tenantId, $name)];
        }

        DB::table($config['table'])->insert($row);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: strtolower($config['entity']).'.create',
            entityType: $config['entity'],
            entityId: $id,
            metadata: ['name' => $name],
        );

        return response()->json(['id' => $id, 'name' => $name, 'status' => 'ACTIVE', 'users' => 0], 201);
    }

    /** Cambia el nombre o el estado. Al desactivar, sus usuarios siguen asignados pero ya no se puede elegir. */
    public function update(string $kind, string $unit, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $config = $this->kind($kind);

        $current = Str::isUuid($unit)
            ? DB::table($config['table'])->where('tenant_id', $tenantId)->where('id', $unit)->first()
            : null;
        abort_if(! $current, 404);

        $status = $request->validate(['status' => ['sometimes', 'in:ACTIVE,INACTIVE']])['status'] ?? $current->status;
        $name = $request->has('name') ? $this->validName($request, $tenantId, $config, $current->id) : $current->name;

        DB::table($config['table'])->where('id', $current->id)->update(['name' => $name, 'status' => $status, 'updated_at' => now()]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: strtolower($config['entity']).'.update',
            entityType: $config['entity'],
            entityId: $current->id,
            beforeData: ['name' => $current->name, 'status' => $current->status],
            afterData: ['name' => $name, 'status' => $status],
        );

        return response()->json(['id' => $current->id, 'name' => $name, 'status' => $status]);
    }

    private function kind(string $kind): array
    {
        abort_unless(isset(self::KINDS[$kind]), 404);

        return self::KINDS[$kind];
    }

    private function rows(string $tenantId, string $kind)
    {
        $config = self::KINDS[$kind];
        $members = DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->whereNotNull($config['member'])
            ->selectRaw("{$config['member']} as unit_id, count(*) as total")
            ->groupBy($config['member'])
            ->pluck('total', 'unit_id');

        return DB::table($config['table'])
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'status'])
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'status' => $row->status, 'users' => (int) ($members[$row->id] ?? 0)]);
    }

    private function validName(Request $request, string $tenantId, array $config, ?string $exceptId = null): string
    {
        $name = trim((string) $request->validate(
            ['name' => ['required', 'string', 'max:120']],
            ['name.required' => "Indica el nombre {$config['of']}.", 'name.max' => 'El nombre es demasiado largo.'],
        )['name']);

        $repeated = DB::table($config['table'])
            ->where('tenant_id', $tenantId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
        if ($name === '' || $repeated) {
            throw ValidationException::withMessages(['name' => [$name === '' ? "Indica el nombre {$config['of']}." : "Ya existe {$config['a']} con ese nombre."]]);
        }

        return $name;
    }

    /** Código interno de la sede, único en la empresa, a partir de su nombre. */
    private function branchCode(string $tenantId, string $name): string
    {
        $base = Str::upper(Str::limit(Str::slug($name, '-'), 40, '')) ?: 'SEDE';
        $code = $base;
        for ($n = 2; DB::table('branches')->where('tenant_id', $tenantId)->where('code', $code)->exists(); $n++) {
            $code = "{$base}-{$n}";
        }

        return $code;
    }
}
