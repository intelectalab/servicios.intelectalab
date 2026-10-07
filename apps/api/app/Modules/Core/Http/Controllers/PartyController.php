<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\Customer;
use App\Modules\Core\Models\Employee;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\Supplier;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Clientes y actores": una sola identidad (persona u organización) con sus
 * roles de cliente, proveedor y empleado. Lee y escribe las mismas tablas que
 * los módulos Clientes, Proveedores y Empleados; no las reemplaza.
 */
class PartyController extends Controller
{
    private const ROLES = ['CUSTOMER', 'SUPPLIER', 'EMPLOYEE'];

    private const ROLE_LABELS = ['CUSTOMER' => 'Cliente', 'SUPPLIER' => 'Proveedor', 'EMPLOYEE' => 'Empleado'];

    // Una identidad está inactiva si se dio de baja o si ninguno de sus roles sigue activo.
    private const STATUS_SQL = "CASE WHEN parties.status <> 'ACTIVE' OR (
            (EXISTS (SELECT 1 FROM customers c WHERE c.party_id = parties.id)
              OR EXISTS (SELECT 1 FROM suppliers s WHERE s.party_id = parties.id)
              OR EXISTS (SELECT 1 FROM employees e WHERE e.person_party_id = parties.id))
            AND NOT EXISTS (SELECT 1 FROM customers c WHERE c.party_id = parties.id AND c.status = 'ACTIVE')
            AND NOT EXISTS (SELECT 1 FROM suppliers s WHERE s.party_id = parties.id AND s.status = 'ACTIVE')
            AND NOT EXISTS (SELECT 1 FROM employees e WHERE e.person_party_id = parties.id AND e.status = 'ACTIVE')
        ) THEN 'INACTIVE' ELSE 'ACTIVE' END";

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $role = $request->query('role');

        $query = $this->baseQuery($request)
            ->when($request->filled('kind'), fn ($q) => $q->where('parties.party_type', $request->query('kind')))
            ->when($request->filled('status'), fn ($q) => $q->whereRaw('('.self::STATUS_SQL.') = ?', [$request->query('status')]))
            ->when($role === 'CUSTOMER', fn ($q) => $q->whereHas('customer'))
            ->when($role === 'SUPPLIER', fn ($q) => $q->whereHas('supplier'))
            ->when($role === 'EMPLOYEE', fn ($q) => $q->whereHas('employee'))
            ->when($search !== '', function ($q) use ($search) {
                // Por nombre, RUC, documento o correo.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(function ($w) use ($like) {
                    $w->whereHas('organization', fn ($o) => $o
                        ->where('legal_name', 'ILIKE', $like)
                        ->orWhere('trade_name', 'ILIKE', $like)
                        ->orWhere('tax_identifier', 'ILIKE', $like))
                        ->orWhereHas('person', fn ($p) => $p
                            ->whereRaw("concat_ws(' ', given_names, family_name) ILIKE ?", [$like])
                            ->orWhere('document_number', 'ILIKE', $like))
                        ->orWhereExists(fn ($c) => $c->selectRaw('1')->from('contacts')
                            ->whereColumn('contacts.party_id', 'parties.id')
                            ->where('contacts.contact_type', 'EMAIL')
                            ->where('contacts.value', 'ILIKE', $like));
                });
            })
            ->orderByDesc('parties.created_at')
            ->orderByDesc('parties.id');

        return response()->json(
            $query->paginate($request->integer('per_page', 20))->through(fn (Party $party) => $this->summary($party))
        );
    }

    public function show(string $party, Request $request)
    {
        return response()->json($this->detail($this->find($party, $request)));
    }

    /**
     * Alta de una organización o persona con sus roles. Si ya existe una
     * identidad con ese documento (o esa razón social) no se duplica: se
     * devuelven las coincidencias para abrirla o agregarle el rol.
     */
    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $this->validated($request, true);

        $duplicates = $this->duplicatesOf($request, $data);
        if ($duplicates) {
            return response()->json([
                'message' => 'Ya existe en el registro. Para no duplicar identidades, reutiliza la existente o agrégale el rol.',
                'duplicates' => $duplicates,
            ], 409);
        }

        $party = DB::transaction(function () use ($data, $tenantId, $request) {
            $party = Party::create(['tenant_id' => $tenantId, 'party_type' => $data['kind'], 'status' => 'ACTIVE']);

            if ($data['kind'] === 'PERSON') {
                Person::create([
                    'party_id' => $party->id,
                    'given_names' => $data['given_names'],
                    'family_name' => $data['family_name'],
                    'document_type' => $data['document_type'] ?? 'DNI',
                    'document_number' => $data['document_number'] ?? null,
                    'metadata' => [],
                ]);
            } else {
                Organization::create([
                    'party_id' => $party->id,
                    'legal_name' => $data['legal_name'],
                    'trade_name' => $data['trade_name'] ?? null,
                    'tax_identifier' => $data['tax_identifier'],
                    'metadata' => array_filter(['sector' => $data['sector'] ?? null, 'sla_level' => $data['sla_level'] ?? 'ESTANDAR']),
                ]);
            }

            foreach ($data['roles'] as $role) {
                $this->grantRole($party, $role, $tenantId, $data['position'] ?? null);
            }
            $this->saveContacts($party, $tenantId, $data);

            $this->audit($request, $party, 'party.create', ['to' => 'ACTIVE', 'note' => 'Roles: '.implode(', ', array_map(fn ($role) => self::ROLE_LABELS[$role], $data['roles']))]);

            return $party;
        });

        return response()->json($this->detail($party), 201);
    }

    /** Datos de la identidad. Solo cambia lo que llega: sirve a la ficha y a la edición en celda. */
    public function update(string $party, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $party = $this->find($party, $request);
        $data = $this->validated($request, false, $party);

        $duplicates = $this->duplicatesOf($request, [...$data, 'kind' => $party->party_type, 'legal_name' => null], $party->id);
        if ($duplicates) {
            throw ValidationException::withMessages([
                $party->party_type === 'PERSON' ? 'document_number' : 'tax_identifier' => ['Ya existe otra identidad con ese documento.'],
            ]);
        }

        DB::transaction(function () use ($party, $data, $tenantId, $request) {
            if ($party->party_type === 'PERSON' && $party->person) {
                $party->person->update(array_intersect_key($data, array_flip(['given_names', 'family_name', 'document_type', 'document_number'])));
            }
            if ($party->party_type === 'ORGANIZATION' && $party->organization) {
                $organization = $party->organization;
                $metadata = $organization->metadata ?? [];
                foreach (['sector', 'sla_level'] as $key) {
                    if (array_key_exists($key, $data)) {
                        $metadata[$key] = $data[$key];
                    }
                }
                $organization->update([
                    ...array_intersect_key($data, array_flip(['legal_name', 'trade_name', 'tax_identifier'])),
                    'metadata' => array_filter($metadata),
                ]);
            }
            if (array_key_exists('position', $data) && $party->employee) {
                $party->employee->update(['position' => $data['position']]);
            }
            $this->saveContacts($party, $tenantId, $data);

            $this->audit($request, $party, 'party.update');
        });

        return response()->json($this->detail($party->fresh()));
    }

    /** Agrega un rol a una identidad que ya existe, sin duplicarla. */
    public function addRole(string $party, Request $request)
    {
        $party = $this->find($party, $request);
        $role = $request->validate(['role' => ['required', 'in:'.implode(',', self::ROLES)]])['role'];

        if ($role === 'EMPLOYEE' && $party->party_type !== 'PERSON') {
            throw ValidationException::withMessages(['role' => ['Solo una persona puede ser empleado.']]);
        }
        if (in_array($role, $this->rolesOf($party), true)) {
            throw ValidationException::withMessages(['role' => ['La identidad ya tiene ese rol.']]);
        }

        DB::transaction(function () use ($party, $role, $request) {
            $this->grantRole($party, $role, $request->attributes->get('tenant_id'));
            $this->audit($request, $party, 'party.role.add', ['note' => 'Rol agregado: '.self::ROLE_LABELS[$role]]);
        });

        return response()->json($this->detail($party->fresh()));
    }

    public function deactivate(string $party, Request $request)
    {
        return response()->json($this->detail($this->setStatus($this->find($party, $request), 'INACTIVE', $request, true)));
    }

    public function activate(string $party, Request $request)
    {
        return response()->json($this->detail($this->setStatus($this->find($party, $request), 'ACTIVE', $request, true)));
    }

    public function bulkDeactivate(Request $request)
    {
        return $this->bulk($request, 'INACTIVE');
    }

    public function bulkActivate(Request $request)
    {
        return $this->bulk($request, 'ACTIVE');
    }

    private function bulk(Request $request, string $status): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid']])['ids'];

        $changed = DB::transaction(function () use ($ids, $status, $request) {
            $changed = 0;
            foreach ($this->baseQuery($request)->whereIn('parties.id', $ids)->get() as $party) {
                if ($party->effective_status !== $status) {
                    $this->setStatus($party, $status, $request);
                    $changed++;
                }
            }

            return $changed;
        });

        return response()->json(['changed' => $changed]);
    }

    /** Baja o alta lógica: la identidad y todos sus roles cambian juntos. */
    private function setStatus(Party $party, string $status, Request $request, bool $strict = false): Party
    {
        if ($strict && $party->effective_status === $status) {
            throw ValidationException::withMessages([
                'status' => [$status === 'ACTIVE' ? 'El registro ya está activo.' : 'El registro ya está inactivo.'],
            ]);
        }

        $previous = $party->effective_status;
        DB::transaction(function () use ($party, $status, $request, $previous) {
            $party->update(['status' => $status]);
            Customer::where('party_id', $party->id)->update(['status' => $status]);
            Supplier::where('party_id', $party->id)->update(['status' => $status]);
            Employee::where('person_party_id', $party->id)->update(['status' => $status]);
            $this->audit($request, $party, $status === 'ACTIVE' ? 'party.activate' : 'party.deactivate', ['from' => $previous, 'to' => $status]);
        });

        return $party->fresh();
    }

    private function baseQuery(Request $request)
    {
        return Party::query()
            ->where('parties.tenant_id', $request->attributes->get('tenant_id'))
            ->with(
                'person',
                'organization',
                'customer:id,party_id,status,credit_limit,payment_terms_days,preferred_currency',
                'supplier:id,party_id,status',
                'employee:id,person_party_id,status,position,employee_code,user_id',
                'employee.user:id,name',
            )
            ->select('parties.*')
            ->selectRaw('('.self::STATUS_SQL.') AS effective_status')
            ->selectSub($this->primaryContact('EMAIL'), 'email')
            ->selectSub($this->primaryContact('PHONE'), 'phone');
    }

    private function primaryContact(string $type)
    {
        return DB::table('contacts')
            ->select('value')
            ->whereColumn('contacts.party_id', 'parties.id')
            ->where('contact_type', $type)
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->limit(1);
    }

    private function find(string $party, Request $request): Party
    {
        abort_unless(Str::isUuid($party), 404);

        return $this->baseQuery($request)->where('parties.id', $party)->firstOrFail();
    }

    private function rolesOf(Party $party): array
    {
        return array_values(array_filter([
            $party->customer ? 'CUSTOMER' : null,
            $party->supplier ? 'SUPPLIER' : null,
            $party->employee ? 'EMPLOYEE' : null,
        ]));
    }

    /** Fila del listado: la identidad con sus roles y lo que la tabla muestra. */
    private function summary(Party $party): array
    {
        $metadata = $party->organization?->metadata ?? [];

        return [
            'id' => $party->id,
            'kind' => $party->party_type,
            'status' => $party->effective_status ?? $party->status,
            'name' => $party->display_name,
            'person' => $party->person?->only(['given_names', 'family_name', 'document_type', 'document_number']),
            'organization' => $party->organization?->only(['legal_name', 'trade_name', 'tax_identifier']),
            'roles' => $this->rolesOf($party),
            // Los roles dados de baja por separado en Clientes, Proveedores o Empleados.
            'inactive_roles' => array_values(array_filter([
                $party->customer && $party->customer->status !== 'ACTIVE' ? 'CUSTOMER' : null,
                $party->supplier && $party->supplier->status !== 'ACTIVE' ? 'SUPPLIER' : null,
                $party->employee && $party->employee->status !== 'ACTIVE' ? 'EMPLOYEE' : null,
            ])),
            'customer_id' => $party->customer?->id,
            'supplier_id' => $party->supplier?->id,
            'employee_id' => $party->employee?->id,
            'email' => $party->email,
            'phone' => $party->phone,
            'sector' => $metadata['sector'] ?? null,
            'sla_level' => $party->party_type === 'ORGANIZATION' ? ($metadata['sla_level'] ?? 'ESTANDAR') : null,
            'position' => $party->employee?->position,
            'employee_code' => $party->employee?->employee_code,
            'user_name' => $party->employee?->user?->name,
            'created_at' => $party->created_at?->toIso8601String(),
        ];
    }

    /** Vista 360: la identidad y lo que cada área tiene de ella. */
    private function detail(Party $party): array
    {
        $party = Party::query()->where('parties.id', $party->id)
            ->with('person', 'organization', 'customer', 'supplier', 'employee', 'employee.user:id,name')
            ->select('parties.*')
            ->selectRaw('('.self::STATUS_SQL.') AS effective_status')
            ->selectSub($this->primaryContact('EMAIL'), 'email')
            ->selectSub($this->primaryContact('PHONE'), 'phone')
            ->firstOrFail();

        $customerId = $party->customer?->id;
        $ofCustomer = fn (string $table) => DB::table($table)->where("{$table}.customer_id", $customerId ?? '00000000-0000-0000-0000-000000000000');

        $receivables = $ofCustomer('receivables')->where('balance_amount', '>', 0)->where('status', '<>', 'PAID');

        return [
            ...$this->summary($party),
            'address' => DB::table('contacts')->where('party_id', $party->id)->where('contact_type', 'ADDRESS')
                ->orderByDesc('is_primary')->orderBy('created_at')->value('value'),
            'customer' => $party->customer?->only(['id', 'status', 'credit_limit', 'payment_terms_days', 'preferred_currency']),
            'contacts' => Contact::where('party_id', $party->id)->orderByDesc('is_primary')->orderBy('created_at')
                ->get(['id', 'contact_name', 'contact_type', 'value', 'country_code', 'is_primary']),
            'finance' => $customerId ? [
                'outstanding' => (float) (clone $receivables)->sum('balance_amount'),
                'overdue' => (float) (clone $receivables)->where('due_date', '<', now()->toDateString())->sum('balance_amount'),
                'open_documents' => (clone $receivables)->count(),
            ] : null,
            'quotations' => $ofCustomer('quotations')->orderByDesc('created_at')->limit(100)
                ->get(['id', 'quotation_number', 'created_at', 'total', 'currency', 'status']),
            'contracts' => $ofCustomer('contracts')->orderByDesc('created_at')->limit(100)
                ->get(['id', 'contract_number', 'start_date', 'end_date', 'total_value', 'currency', 'status']),
            'work_orders' => $ofCustomer('work_orders')
                ->leftJoin('users', 'users.id', '=', 'work_orders.assigned_user_id')
                ->orderByDesc('work_orders.created_at')->limit(100)
                ->get(['work_orders.id', 'work_orders.code', 'work_orders.description', 'work_orders.scheduled_start', 'work_orders.status', 'users.name as technician']),
            'invoices' => $ofCustomer('invoices')->orderByDesc('created_at')->limit(100)
                ->get(['id', 'invoice_number', 'issue_date', 'total', 'currency', 'status']),
            'tickets' => $ofCustomer('support_tickets')->orderByDesc('created_at')->limit(100)
                ->get(['id', 'ticket_number', 'subject', 'priority', 'status']),
            // Los documentos aún no se enlazan a una identidad.
            'documents' => [],
            'leads' => DB::table('crm_leads')->where('party_id', $party->id)->orderByDesc('created_at')->limit(100)
                ->get(['id', 'person_name', 'organization_name', 'status']),
            'opportunities' => $ofCustomer('crm_opportunities')->orderByDesc('created_at')->limit(100)
                ->get(['id', 'name', 'status']),
            'history' => AuditHistory::for('Party', $party->id),
        ];
    }

    private function validated(Request $request, bool $creating, ?Party $party = null): array
    {
        $kind = $creating ? $request->input('kind') : $party->party_type;
        $need = $creating ? 'required' : 'sometimes';

        $rules = [
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
        if ($creating) {
            $rules['kind'] = ['required', 'in:PERSON,ORGANIZATION'];
            $rules['roles'] = ['required', 'array', 'min:1'];
            $rules['roles.*'] = ['in:'.implode(',', self::ROLES)];
        }

        if ($kind === 'ORGANIZATION') {
            $rules += [
                'legal_name' => [$need, 'string', 'max:255'],
                'trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'tax_identifier' => [$need, 'regex:/^(10|20)\d{9}$/'],
                'sector' => ['sometimes', 'nullable', 'string', 'max:150'],
                'sla_level' => ['sometimes', 'nullable', 'in:ESTANDAR,PREMIUM'],
            ];
        } else {
            $rules += [
                'given_names' => [$need, 'string', 'max:255'],
                'family_name' => [$need, 'string', 'max:255'],
                'document_type' => ['sometimes', 'nullable', 'in:DNI,CE,PASSPORT'],
                'document_number' => ['sometimes', 'nullable', 'string', 'max:20'],
                'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            ];
        }

        $data = $request->validate($rules, [
            'roles.required' => 'Selecciona al menos un rol.',
            'legal_name.required' => 'Indica la razón social.',
            'tax_identifier.required' => 'Indica el RUC.',
            'tax_identifier.regex' => 'RUC inválido (11 dígitos).',
            'given_names.required' => 'Indica los nombres.',
            'family_name.required' => 'Indica los apellidos.',
            'email.email' => 'Correo inválido.',
        ]);

        if ($creating && $kind === 'ORGANIZATION' && in_array('EMPLOYEE', $data['roles'], true)) {
            throw ValidationException::withMessages(['roles' => ['Solo una persona puede ser empleado.']]);
        }

        return $data;
    }

    /** Identidades que ya usan ese documento (o, al crear una organización, esa razón social). */
    private function duplicatesOf(Request $request, array $data, ?string $exceptId = null): array
    {
        $document = $data['kind'] === 'PERSON' ? ($data['document_number'] ?? null) : ($data['tax_identifier'] ?? null);
        $legalName = $data['kind'] === 'ORGANIZATION' ? ($data['legal_name'] ?? null) : null;
        if (! $document && ! $legalName) {
            return [];
        }

        return $this->baseQuery($request)
            ->where('parties.party_type', $data['kind'])
            ->when($exceptId, fn ($q) => $q->where('parties.id', '<>', $exceptId))
            ->where(function ($w) use ($data, $document, $legalName) {
                if ($data['kind'] === 'PERSON') {
                    $w->whereHas('person', fn ($p) => $p->where('document_number', $document));
                } else {
                    $w->whereHas('organization', fn ($o) => $o
                        ->when($document, fn ($q) => $q->where('tax_identifier', $document))
                        ->when($legalName, fn ($q) => $document
                            ? $q->orWhereRaw('lower(legal_name) = lower(?)', [$legalName])
                            : $q->whereRaw('lower(legal_name) = lower(?)', [$legalName])));
                }
            })
            ->limit(5)
            ->get()
            ->map(fn (Party $party) => [
                'id' => $party->id,
                'name' => $party->display_name,
                'kind' => $party->party_type,
                'roles' => $this->rolesOf($party),
            ])
            ->all();
    }

    private function grantRole(Party $party, string $role, string $tenantId, ?string $position = null): void
    {
        if ($role === 'CUSTOMER') {
            Customer::firstOrCreate(
                ['tenant_id' => $tenantId, 'party_id' => $party->id],
                ['status' => 'ACTIVE', 'payment_terms_days' => 30, 'preferred_currency' => 'PEN'],
            );
        } elseif ($role === 'SUPPLIER') {
            Supplier::firstOrCreate(['tenant_id' => $tenantId, 'party_id' => $party->id], ['status' => 'ACTIVE']);
        } elseif (! Employee::where('person_party_id', $party->id)->exists()) {
            // Mismo correlativo que el módulo Empleados.
            $count = Employee::where('tenant_id', $tenantId)->count();
            do {
                $count++;
                $code = 'EMP-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            } while (Employee::where('tenant_id', $tenantId)->where('employee_code', $code)->exists());

            Employee::create([
                'tenant_id' => $tenantId,
                'person_party_id' => $party->id,
                'employee_code' => $code,
                'position' => $position,
                'status' => 'ACTIVE',
            ]);
        }
    }

    /** Correo, teléfono y dirección principales: se guardan como contactos de la identidad. */
    private function saveContacts(Party $party, string $tenantId, array $data): void
    {
        foreach (['email' => 'EMAIL', 'phone' => 'PHONE', 'address' => 'ADDRESS'] as $field => $type) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $current = Contact::where('party_id', $party->id)->where('contact_type', $type)
                ->orderByDesc('is_primary')->orderBy('created_at')->first();
            $value = trim((string) $data[$field]);

            if ($value === '') {
                $current?->delete();
            } elseif ($current) {
                $current->update(['value' => $value]);
            } else {
                Contact::create([
                    'tenant_id' => $tenantId, 'party_id' => $party->id,
                    'contact_type' => $type, 'value' => $value, 'is_primary' => true,
                ]);
            }
        }
    }

    private function audit(Request $request, Party $party, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'Party',
            entityId: $party->id,
            metadata: $metadata,
        );
    }
}
