<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckPermission;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\CustomFieldDefinition;
use App\Modules\Core\Models\CustomFieldValue;
use App\Modules\Core\Models\Customer;
use App\Modules\Core\Models\Employee;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\Supplier;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\DocumentAccess;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Crm\Models\CrmOpportunity;
use App\Modules\Operations\Models\WorkOrder;
use App\Modules\Services\Models\Contract;
use App\Modules\Services\Models\Quotation;
use App\Modules\Support\Models\SupportTicket;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ImportReport;
use App\Services\ListSort;
use App\Services\SpreadsheetImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Clientes y actores": una sola identidad (persona u organización) con sus
 * roles de cliente, proveedor y empleado. Lee y escribe las mismas tablas que
 * los módulos Clientes, Proveedores y Empleados; no las reemplaza.
 */
class PartyController extends Controller
{
    // CONTACT: una persona que es el contacto de una organización. No tiene tabla
    // propia: vive en persons.metadata (contact, contact_of, position).
    private const ROLES = ['CUSTOMER', 'SUPPLIER', 'CONTACT', 'EMPLOYEE'];

    private const ROLE_LABELS = ['CUSTOMER' => 'Cliente', 'SUPPLIER' => 'Proveedor', 'CONTACT' => 'Contacto', 'EMPLOYEE' => 'Empleado'];

    /** Nombres de las organizaciones ya consultadas en esta petición ("Contacto de …"). */
    private array $organizationNames = [];

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
            ->when($role === 'CONTACT', fn ($q) => $q->whereHas('person', fn ($p) => $p->where('metadata->contact', true)))
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
            });

        $this->applyColumnFilters($query, $request);

        ListSort::apply($query, $request, $this->sortColumns($request), fn ($q) => $q
            ->orderByDesc('parties.created_at')
            ->orderByDesc('parties.id'), 'parties.id');

        $page = $query->paginate($request->integer('per_page', 20));

        // Valores de las columnas personalizadas de toda la página, en una sola consulta.
        $custom = DB::table('custom_field_values')
            ->where('entity_type', 'Party')
            ->whereIn('entity_id', $page->getCollection()->pluck('id'))
            ->get(['entity_id', 'field_id', 'value'])
            ->groupBy('entity_id');

        return response()->json($page->through(fn (Party $party) => $this->summary($party) + [
            'custom_values' => (object) ($custom->get($party->id)?->pluck('value', 'field_id')->all() ?? []),
        ]));
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

        $party = $this->createParty($data, $tenantId, $request);

        return response()->json($this->detail($party), 201);
    }

    /** Crea la identidad con sus roles y contactos principales, a partir de datos ya validados. */
    private function createParty(array $data, string $tenantId, Request $request): Party
    {
        return DB::transaction(function () use ($data, $tenantId, $request) {
            $party = Party::create(['tenant_id' => $tenantId, 'party_type' => $data['kind'], 'status' => 'ACTIVE']);

            if ($data['kind'] === 'PERSON') {
                Person::create([
                    'party_id' => $party->id,
                    'given_names' => $data['given_names'],
                    'family_name' => $data['family_name'],
                    'document_type' => $data['document_type'] ?? 'DNI',
                    'document_number' => $data['document_number'] ?? null,
                    'metadata' => array_filter([
                        'contact' => in_array('CONTACT', $data['roles'], true),
                        'contact_of' => $data['contact_of'] ?? null,
                        'position' => $data['position'] ?? null,
                    ]),
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
    }

    /**
     * Para otros módulos que dan de alta una identidad (el CRM, al convertir una oportunidad):
     * misma validación y misma búsqueda de duplicados que el alta de esta pantalla.
     *
     * @return array{party: ?Party, duplicates: array}
     */
    public function createFromModule(Request $request, array $input): array
    {
        $data = $this->validated($request, true, null, $input);

        $duplicates = $this->duplicatesOf($request, $data);
        if ($duplicates) {
            return ['party' => null, 'duplicates' => $duplicates];
        }

        return ['party' => $this->createParty($data, $request->attributes->get('tenant_id'), $request), 'duplicates' => []];
    }

    /** El cliente de una identidad; si aún no lo es, le agrega el rol (queda en su historial). */
    public function customerOf(Party $party, Request $request, bool $create = true): ?Customer
    {
        $customer = Customer::where('party_id', $party->id)->first();
        if ($customer || ! $create) {
            return $customer;
        }

        $this->grantRole($party, 'CUSTOMER', $request->attributes->get('tenant_id'));
        $this->audit($request, $party, 'party.role.add', ['note' => 'Rol agregado: '.self::ROLE_LABELS['CUSTOMER']]);

        return Customer::where('party_id', $party->id)->firstOrFail();
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
                $metadata = $party->person->metadata ?? [];
                foreach (['contact_of', 'position'] as $key) {
                    if (array_key_exists($key, $data)) {
                        $metadata[$key] = $data[$key];
                    }
                }
                $party->person->update([
                    ...array_intersect_key($data, array_flip(['given_names', 'family_name', 'document_type', 'document_number'])),
                    'metadata' => array_filter($metadata),
                ]);
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

    /**
     * Datos propios del rol Proveedor: su cuenta de detracciones en el Banco de la Nación, adonde
     * se deposita lo detraído al pagarle.
     */
    public function updateSupplier(string $party, Request $request)
    {
        $party = $this->find($party, $request);
        abort_unless($party->supplier, 404);
        $data = $request->validate([
            'detraction_account' => ['present', 'nullable', 'regex:/^\d{2}-\d{3}-\d{6}$/'],
        ], ['detraction_account.regex' => 'La cuenta de detracciones tiene el formato 00-000-000000.']);

        $before = $party->supplier->detraction_account;
        DB::transaction(function () use ($party, $data, $request, $before) {
            $party->supplier->update(['detraction_account' => $data['detraction_account'] ?: null]);
            if ($before !== ($data['detraction_account'] ?: null)) {
                $this->audit($request, $party, 'party.supplier.update', ['note' => 'Cuenta de detracciones: '.($data['detraction_account'] ?: 'sin registrar')]);
            }
        });

        return response()->json($this->detail($party->fresh()));
    }

    /** Agrega un rol a una identidad que ya existe, sin duplicarla. */
    public function addRole(string $party, Request $request)
    {
        $party = $this->find($party, $request);
        $role = $request->validate(['role' => ['required', 'in:'.implode(',', self::ROLES)]])['role'];

        if (in_array($role, ['EMPLOYEE', 'CONTACT'], true) && $party->party_type !== 'PERSON') {
            throw ValidationException::withMessages(['role' => ['Solo una persona puede ser empleado o contacto.']]);
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

    /** Columnas por las que se puede ordenar el listado: las fijas y las personalizadas (por su id). */
    private function sortColumns(Request $request): array
    {
        $organization = fn (string $column) => "(SELECT {$column} FROM organizations o WHERE o.party_id = parties.id)";
        $person = fn (string $column) => "(SELECT {$column} FROM persons p WHERE p.party_id = parties.id)";
        $has = fn (string $table, string $key, string $label) => "CASE WHEN EXISTS (SELECT 1 FROM {$table} r WHERE r.{$key} = parties.id) THEN '{$label}' END";

        $columns = [
            'name' => 'lower(COALESCE('.$organization('COALESCE(o.trade_name, o.legal_name)').', '.$person("concat_ws(' ', p.given_names, p.family_name)").'))',
            'document' => 'COALESCE('.$organization('o.tax_identifier').', '.$person('p.document_number').')',
            'roles' => "NULLIF(concat_ws(', ', ".$has('customers', 'party_id', 'Cliente').', '.$has('suppliers', 'party_id', 'Proveedor').', '
                .$person("CASE WHEN p.metadata->>'contact' = 'true' THEN 'Contacto' END").', '.$has('employees', 'person_party_id', 'Empleado')."), '')",
            'relation' => 'lower(COALESCE('.$organization("NULLIF(o.metadata->>'sector', '')")
                .', (SELECT NULLIF(e.position, \'\') FROM employees e WHERE e.person_party_id = parties.id LIMIT 1), '
                .$person("NULLIF(p.metadata->>'position', '')").'))',
            'status' => '('.self::STATUS_SQL.')',
        ];

        // Una columna personalizada se pide por su id; las numéricas se ordenan como número.
        $sort = (string) $request->query('sort', '');
        if (Str::isUuid($sort)) {
            $field = CustomFieldDefinition::where('entity_type', 'Party')->find($sort);
            if ($field) {
                $value = "(SELECT NULLIF(v.value, '') FROM custom_field_values v WHERE v.field_id = '{$field->id}' AND v.entity_id = parties.id)";
                $columns[$sort] = $field->field_type === 'number'
                    ? "CASE WHEN {$value} ~ '^-?[0-9]+([.][0-9]+)?$' THEN {$value}::numeric END"
                    : "lower({$value})";
            }
        }

        return $columns;
    }

    /**
     * Filtros por columna (los de "Filtrar por esta columna"), aplicados en la base
     * para que abarquen todo el listado. Llegan en `filters` como JSON:
     * [{ id, type, operator, value }], con los mismos operadores que usa la pantalla.
     */
    private function applyColumnFilters($query, Request $request): void
    {
        $fixed = $this->sortColumns($request);
        $columns = [
            'name' => ['text' => $fixed['name']],
            'document' => ['text' => "lower(COALESCE((SELECT 'RUC ' || o.tax_identifier FROM organizations o WHERE o.party_id = parties.id),"
                ." (SELECT COALESCE(p.document_type, 'DNI') || ' ' || p.document_number FROM persons p WHERE p.party_id = parties.id)))"],
            'roles' => ['text' => 'lower('.$fixed['roles'].')'],
            'relation' => ['text' => $fixed['relation']],
            'status' => ['text' => 'lower('.$fixed['status'].')'],
        ];

        ColumnFilters::apply($query, $request, function (string $id) use ($columns) {
            if (isset($columns[$id])) {
                return $columns[$id];
            }
            // Columna personalizada, por su id. Su valor es texto: como número solo cuenta si lo parece.
            if (! Str::isUuid($id) || ! ($field = CustomFieldDefinition::where('entity_type', 'Party')->find($id))) {
                return null;
            }
            $raw = "(SELECT NULLIF(v.value, '') FROM custom_field_values v WHERE v.field_id = '{$field->id}' AND v.entity_id = parties.id)";

            return [
                'text' => "lower({$raw})",
                'number' => "(CASE WHEN {$raw} ~ '^-?[0-9]+([.][0-9]+)?$' THEN ({$raw})::numeric END)",
                'date' => "left({$raw}, 10)",
                // Una casilla Sí / No sin marcar cuenta como "No".
                'empty' => $field->field_type === 'boolean' ? 'false' : '',
            ];
        });
    }

    private function baseQuery(Request $request)
    {
        return Party::query()
            ->where('parties.tenant_id', $request->attributes->get('tenant_id'))
            ->with(
                'person',
                'organization',
                'customer:id,party_id,status,credit_limit,payment_terms_days,preferred_currency,retention_agent',
                'supplier:id,party_id,status,detraction_account',
                'employee:id,person_party_id,status,position,employee_code,user_id',
                'employee.user:id,name',
            )
            ->select('parties.*')
            ->selectRaw('('.self::STATUS_SQL.') AS effective_status')
            ->selectSub($this->primaryContact('EMAIL'), 'email')
            ->selectSub($this->primaryContact('PHONE'), 'phone')
            ->selectSub($this->primaryContact('PHONE', 'country_code'), 'phone_country_code');
    }

    private function primaryContact(string $type, string $column = 'value')
    {
        return DB::table('contacts')
            ->select($column)
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
            ($party->person?->metadata['contact'] ?? false) ? 'CONTACT' : null,
            $party->employee ? 'EMPLOYEE' : null,
        ]));
    }

    /** La organización de la que una persona es contacto. */
    private function contactOf(Party $party): ?array
    {
        $id = $party->person?->metadata['contact_of'] ?? null;
        if (! $id || ! Str::isUuid($id)) {
            return null;
        }
        if (! array_key_exists($id, $this->organizationNames)) {
            $organization = Organization::where('party_id', $id)->first(['legal_name', 'trade_name']);
            $this->organizationNames[$id] = $organization ? ($organization->trade_name ?: $organization->legal_name) : null;
        }

        return $this->organizationNames[$id] ? ['id' => $id, 'name' => $this->organizationNames[$id]] : null;
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
            'phone_country_code' => $party->phone_country_code,
            'position' => $party->employee?->position ?: ($party->person?->metadata['position'] ?? null),
            'contact_of' => $this->contactOf($party),
            'employee_code' => $party->employee?->employee_code,
            'user_name' => $party->employee?->user?->name,
            'created_at' => $party->created_at?->toIso8601String(),
            'lock_version' => (int) $party->lock_version,
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
            ->selectSub($this->primaryContact('PHONE', 'country_code'), 'phone_country_code')
            ->firstOrFail();

        $customerId = $party->customer?->id;
        // Cada parte de la vista del cliente pide el permiso de ver de su módulo (y que la empresa
        // tenga el módulo): ver clientes no da acceso a sus comprobantes, tickets u oportunidades.
        $can = fn (string $permission) => CheckPermission::granted(request(), $permission);

        // Cotizaciones y contratos: solo con su permiso de ver y dentro del alcance del rol
        // (lo propio, equipo, sede o empresa), igual que en sus listados.
        $ofCustomerInScope = fn (string $model, string $permission) => $model::query()
            ->where('customer_id', $customerId ?? '00000000-0000-0000-0000-000000000000')
            ->when(! $can($permission), fn ($query) => $query->whereRaw('false'))
            ->toBase();

        // Saldo del cliente: por su modelo, las cuentas por cobrar que el rol alcanza.
        $receivables = fn () => $ofCustomerInScope(Receivable::class, 'receivables.view')->whereIn('status', Receivable::OPEN);

        return [
            ...$this->summary($party),
            'address' => DB::table('contacts')->where('party_id', $party->id)->where('contact_type', 'ADDRESS')
                ->orderByDesc('is_primary')->orderBy('created_at')->value('value'),
            'customer' => $party->customer?->only(['id', 'status', 'credit_limit', 'payment_terms_days', 'preferred_currency', 'retention_agent']),
            'supplier' => $party->supplier?->only(['id', 'status', 'detraction_account']),
            'contacts' => Contact::where('party_id', $party->id)->orderByDesc('is_primary')->orderBy('created_at')
                ->get(['id', 'contact_name', 'contact_type', 'value', 'country_code', 'is_primary']),
            // Personas con rol Contacto enlazadas a esta organización.
            'contact_people' => $party->party_type === 'ORGANIZATION'
                ? Party::query()
                    ->where('parties.tenant_id', $party->tenant_id)
                    ->whereHas('person', fn ($p) => $p->where('metadata->contact_of', $party->id))
                    ->with('person', 'employee:id,person_party_id,position')
                    ->select('parties.*')
                    ->selectSub($this->primaryContact('EMAIL'), 'email')
                    ->selectSub($this->primaryContact('PHONE'), 'phone')
                    ->selectSub($this->primaryContact('PHONE', 'country_code'), 'phone_country_code')
                    ->orderBy('parties.created_at')
                    ->get()
                    ->map(fn (Party $person) => [
                        'id' => $person->id,
                        'name' => $person->display_name,
                        'position' => $person->employee?->position ?: ($person->person?->metadata['position'] ?? null),
                        'email' => $person->email,
                        'phone' => $person->phone,
                        'phone_country_code' => $person->phone_country_code,
                        'status' => $person->status,
                    ])
                    ->all()
                : [],
            'finance' => $customerId && $can('receivables.view') ? [
                'outstanding' => (float) $receivables()->sum('balance_amount'),
                'overdue' => (float) $receivables()->where('due_date', '<', now()->toDateString())->sum('balance_amount'),
                'open_documents' => $receivables()->count(),
                // Crédito usado y disponible: la deuda de todo el cliente frente a su límite (null sin límite).
                'credit' => $party->customer ? \App\Modules\Finance\Support\CreditLimit::status($party->customer) : null,
            ] : null,
            'quotations' => $ofCustomerInScope(Quotation::class, 'quotations.view')->orderByDesc('created_at')->limit(100)
                ->get(['id', 'quotation_number', 'created_at', 'total', 'currency', 'status']),
            'contracts' => $ofCustomerInScope(Contract::class, 'contracts.view')->orderByDesc('created_at')->limit(100)
                ->get(['id', 'contract_number', 'start_date', 'end_date', 'total_value', 'currency', 'status']),
            // Por su modelo, para respetar el alcance del rol.
            'work_orders' => $ofCustomerInScope(WorkOrder::class, 'work_orders.view')
                ->leftJoin('users', 'users.id', '=', 'work_orders.assigned_user_id')
                ->orderByDesc('work_orders.created_at')->limit(100)
                ->get(['work_orders.id', 'work_orders.code', 'work_orders.title as description', 'work_orders.scheduled_start', 'work_orders.status', 'users.name as technician']),
            // Por su modelo, para respetar el alcance del rol. Un borrador descartado no es un comprobante del cliente.
            'invoices' => $ofCustomerInScope(Invoice::class, 'billing.view')->where('status', '<>', 'CANCELLED')->orderByDesc('created_at')->limit(100)
                ->get(['id', 'invoice_number', 'issue_date', 'total', 'currency', 'status']),
            // Por su modelo, para respetar el alcance del rol.
            'tickets' => $ofCustomerInScope(SupportTicket::class, 'tickets.view')->orderByDesc('opened_at')->limit(100)
                ->get(['id', 'ticket_number', 'subject', 'priority', 'status']),
            // Los suyos y los de sus contratos, proyectos y órdenes que el rol alcanza.
            'documents' => $can('documents.view')
                ? DocumentAccess::forRecord('Party', $party->id)->with('latestVersion')->orderByDesc('documents.updated_at')->limit(100)->get()
                    ->map(fn (Document $document) => [
                        'id' => $document->id, 'title' => $document->title, 'category' => $document->document_type,
                        'version' => (int) ($document->latestVersion?->version ?? 0), 'status' => $document->status, 'visibility' => $document->visibility,
                    ])
                : [],
            // Leads y oportunidades: por sus modelos, para respetar el alcance del rol.
            'leads' => CrmLead::query()->where('party_id', $party->id)
                ->when(! $can('leads.view'), fn ($query) => $query->whereRaw('false'))->orderByDesc('created_at')->limit(100)
                ->toBase()->get(['id', 'lead_number', 'person_name', 'organization_name', 'status']),
            'opportunities' => $ofCustomerInScope(CrmOpportunity::class, 'opportunities.view')->orderByDesc('created_at')->limit(100)
                ->get(['id', 'opportunity_number', 'name', 'status']),
            'history' => AuditHistory::for('Party', $party->id),
        ];
    }

    /** Valida los datos del formulario o, con `$input`, los de una fila importada. */
    private function validated(Request $request, bool $creating, ?Party $party = null, ?array $input = null): array
    {
        $input ??= $request->all();
        $kind = $creating ? ($input['kind'] ?? null) : $party->party_type;
        $need = $creating ? 'required' : 'sometimes';

        $rules = [
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'phone_country_code' => ['sometimes', 'nullable', 'string', 'max:5'],
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
                'contact_of' => ['sometimes', 'nullable', 'uuid'],
            ];
        }

        $data = Validator::make($input, $rules, [
            'roles.required' => 'Selecciona al menos un rol.',
            'legal_name.required' => 'Indica la razón social.',
            'tax_identifier.required' => 'Indica el RUC.',
            'tax_identifier.regex' => 'RUC inválido (11 dígitos).',
            'given_names.required' => 'Indica los nombres.',
            'family_name.required' => 'Indica los apellidos.',
            'email.email' => 'Correo inválido.',
            'sla_level.in' => 'nivel_sla debe ser ESTANDAR o PREMIUM.',
            'document_type.in' => 'tipo_documento debe ser DNI, CE o PASAPORTE.',
        ])->validate();

        if ($creating && $kind === 'ORGANIZATION' && array_intersect(['EMPLOYEE', 'CONTACT'], $data['roles'])) {
            throw ValidationException::withMessages(['roles' => ['Solo una persona puede ser empleado o contacto.']]);
        }
        // "Contacto de": tiene que ser una organización de esta empresa.
        if (! empty($data['contact_of'])) {
            $isOrganization = Party::where('tenant_id', $request->attributes->get('tenant_id'))
                ->where('id', $data['contact_of'])->where('party_type', 'ORGANIZATION')->exists();
            if (! $isOrganization) {
                throw ValidationException::withMessages(['contact_of' => ['La organización indicada no existe.']]);
            }
        }

        return $data;
    }

    // --- Importación ---------------------------------------------------------------

    private const IMPORT_HEADERS = [
        'tipo', 'razon_social', 'nombre_comercial', 'nombres', 'apellidos', 'tipo_documento', 'numero_documento', 'roles',
        'sector', 'nivel_sla', 'correo', 'prefijo', 'telefono', 'direccion', 'cargo', 'contacto_de',
        'dias_credito', 'limite_credito', 'moneda',
    ];

    private const IMPORT_ALIASES = [
        'tipo_persona' => 'tipo', 'tipo_actor' => 'tipo', 'tipo_cliente' => 'tipo',
        'nombre_o_razon_social' => 'razon_social', 'razon_social_o_nombre' => 'razon_social', 'empresa' => 'razon_social',
        'trade_name' => 'nombre_comercial', 'nombre' => 'nombres', 'apellido' => 'apellidos',
        'tipo_doc' => 'tipo_documento', 'documento_tipo' => 'tipo_documento',
        'numero_doc' => 'numero_documento', 'documento_numero' => 'numero_documento', 'documento' => 'numero_documento',
        'ruc' => 'numero_documento', 'dni' => 'numero_documento',
        'rol' => 'roles', 'roles_en_core' => 'roles', 'sla' => 'nivel_sla', 'nivel_de_sla' => 'nivel_sla',
        'email' => 'correo', 'correo_electronico' => 'correo', 'phone' => 'telefono', 'celular' => 'telefono',
        'prefijo_pais' => 'prefijo', 'codigo_pais' => 'prefijo', 'direccion_fiscal' => 'direccion', 'puesto' => 'cargo',
        'contacto_de_organizacion' => 'contacto_de', 'organizacion' => 'contacto_de',
        'dias_de_credito' => 'dias_credito', 'plazo_credito' => 'dias_credito',
        'limite_de_credito' => 'limite_credito', 'preferred_currency' => 'moneda', 'currency' => 'moneda',
    ];

    private const IMPORT_ROLES = [
        'cliente' => 'CUSTOMER', 'customer' => 'CUSTOMER', 'proveedor' => 'SUPPLIER', 'supplier' => 'SUPPLIER',
        'contacto' => 'CONTACT', 'contact' => 'CONTACT', 'empleado' => 'EMPLOYEE', 'employee' => 'EMPLOYEE',
    ];

    /** Plantilla de importación, con las columnas personalizadas de la tabla al final. */
    public function downloadTemplate(Request $request)
    {
        $custom = CustomFieldDefinition::where('tenant_id', $request->attributes->get('tenant_id'))
            ->where('entity_type', 'Party')->orderBy('created_at')->get(['label', 'field_type']);
        $sample = fn (string $text) => $custom->map(fn ($field) => match ($field->field_type) {
            'number' => '100', 'date' => '2026-09-16', 'boolean' => 'Sí', default => $text,
        })->all();

        return SpreadsheetImport::template(
            'plantilla_importacion_clientes_y_actores.xlsx',
            'Plantilla de importación de clientes y actores',
            [...self::IMPORT_HEADERS, ...$custom->pluck('label')->all()],
            [
                ['ORGANIZACION', 'Corporación Ejemplo S.A.C.', 'Ejemplo', '', '', 'RUC', '20123456789', 'Cliente, Proveedor',
                    'Tecnología', 'PREMIUM', 'contacto@ejemplo.pe', '+51', '999888777', 'Av. Principal 123, Lima', '', '',
                    '45', '5000', 'USD', ...$sample('Norte')],
                ['PERSONA', '', '', 'Juan Carlos', 'Pérez Gómez', 'DNI', '12345678', 'Contacto',
                    '', '', 'juan.perez@ejemplo.pe', '+51', '987654321', '', 'Jefe de compras', '20123456789',
                    '', '', '', ...$sample('Sur')],
                ['PERSONA', '', '', 'Ana', 'Torres Silva', 'DNI', '87654321', 'Empleado',
                    '', '', '', '', '', '', 'Técnica', '', '', '', '', ...$sample('Norte')],
            ],
            [
                'tipo: ORGANIZACION o PERSONA. Una organización lleva razon_social y su RUC (11 dígitos) en numero_documento; una persona, nombres y apellidos.',
                'roles: Cliente, Proveedor, Contacto, Empleado; varios separados por comas. Sin rol, una organización entra como Cliente y una persona como Contacto. Contacto y Empleado son solo para personas.',
                'tipo_documento (personas): DNI, CE o PASAPORTE. nivel_sla: ESTANDAR o PREMIUM.',
                'contacto_de (rol Contacto): el RUC o la razón social de la organización, que debe existir o venir antes en este archivo. cargo: con Empleado o Contacto.',
                'dias_credito, limite_credito y moneda (PEN o USD): solo para el rol Cliente.',
                'Si el documento ya existe no se duplica: se le agregan los roles que le falten.',
                'Puedes agregar columnas al final: se crean como columnas personalizadas de la tabla.',
                'Las columnas tipo, tipo_documento, roles, nivel_sla, dias_credito y moneda tienen lista desplegable.',
            ],
            [
                'tipo' => ['ORGANIZACION', 'PERSONA'],
                'tipo_documento' => ['RUC', 'DNI', 'CE', 'PASAPORTE'],
                'roles' => ['Cliente', 'Proveedor', 'Cliente, Proveedor', 'Contacto', 'Empleado', 'Cliente, Contacto', 'Proveedor, Contacto', 'Cliente, Empleado'],
                'nivel_sla' => ['ESTANDAR', 'PREMIUM'],
                'dias_credito' => ['15', '30', '45', '60'],
                'moneda' => ['PEN', 'USD'],
                ...$custom->where('field_type', 'boolean')->mapWithKeys(fn ($field) => [$field->label => ['Sí', 'No']])->all(),
            ],
            // Aceptan otro valor: más combinaciones de roles u otro plazo de crédito.
            ['roles', 'dias_credito'],
        );
    }

    /**
     * Importa organizaciones y personas con sus roles. Cada fila se guarda por
     * separado: las que fallan se devuelven como avisos y no detienen a las demás.
     */
    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);
        $tenantId = $request->attributes->get('tenant_id');

        [$headers, $rows] = SpreadsheetImport::read($request->file('file'), self::IMPORT_ALIASES, [
            'tipo', 'razon_social', 'nombres', 'apellidos', 'numero_documento', 'roles', 'tipo_documento',
        ]);
        $customColumns = array_values(array_diff($headers, self::IMPORT_HEADERS));

        return ImportReport::run($request, 'parties', $rows, ['tipo', 'numero_documento', 'razon_social', 'nombres', 'roles'],
            function (array $row, int $number, ImportReport $report) use ($request, $tenantId, $customColumns) {
                $data = $this->importData($row, $tenantId);

                $existing = $this->duplicatesOf($request, [...$data, 'legal_name' => null])[0] ?? null;
                if ($existing) {
                    // Ya existe: no se duplica, solo recibe los roles que le falten.
                    $party = $this->find($existing['id'], $request);
                    $missing = array_values(array_diff($data['roles'], $existing['roles']));
                    foreach ($missing as $role) {
                        $this->grantRole($party, $role, $tenantId, $data['position'] ?? null);
                    }
                    if ($missing) {
                        $this->audit($request, $party, 'party.role.add', ['note' => 'Importación: '.implode(', ', array_map(fn ($role) => self::ROLE_LABELS[$role], $missing))]);
                        $report->updated("«{$existing['name']}» ya existía; se le agregó ".implode(', ', array_map(fn ($role) => self::ROLE_LABELS[$role], $missing)).'.');
                    } else {
                        $report->skipped("«{$existing['name']}» ya existía; no se cambió.");
                    }

                    return;
                }

                $party = $this->createParty($data, $tenantId, $request);
                $this->importTerms($party, $row);
                $this->importCustomFields($party, $row, $customColumns, $tenantId);
            });
    }

    /** Una fila del archivo, convertida a los mismos datos que valida el formulario. */
    private function importData(array $row, string $tenantId): array
    {
        $get = fn (string $key) => trim((string) ($row[$key] ?? ''));

        $type = SpreadsheetImport::normalize($get('tipo'));
        $kind = match (true) {
            in_array($type, ['organizacion', 'organization', 'empresa', 'juridica'], true) => 'ORGANIZATION',
            in_array($type, ['persona', 'person', 'natural', 'persona_natural'], true) => 'PERSON',
            // Sin tipo: con nombres o apellidos es una persona; si no, una organización.
            default => $get('nombres') !== '' || $get('apellidos') !== '' ? 'PERSON' : 'ORGANIZATION',
        };

        $roles = [];
        foreach (preg_split('/[,;\/|]+/', $get('roles')) ?: [] as $name) {
            $name = SpreadsheetImport::normalize($name);
            if ($name === '') {
                continue;
            }
            if (! isset(self::IMPORT_ROLES[$name])) {
                throw ValidationException::withMessages(['roles' => ["Rol desconocido: «{$name}»."]]);
            }
            $roles[] = self::IMPORT_ROLES[$name];
        }
        $roles = array_values(array_unique($roles)) ?: [$kind === 'ORGANIZATION' ? 'CUSTOMER' : 'CONTACT'];

        $input = array_filter([
            'kind' => $kind,
            'roles' => $roles,
            'email' => $get('correo'),
            'phone' => $get('telefono'),
            'phone_country_code' => $get('telefono') !== '' ? $get('prefijo') : '',
            'address' => $get('direccion'),
        ], fn ($value) => $value !== '');

        if ($kind === 'ORGANIZATION') {
            $input += array_filter([
                'legal_name' => $get('razon_social'),
                'trade_name' => $get('nombre_comercial'),
                'tax_identifier' => $get('numero_documento'),
                'sector' => $get('sector'),
                'sla_level' => strtoupper(SpreadsheetImport::normalize($get('nivel_sla'))),
            ], fn ($value) => $value !== '');
        } else {
            $documentType = strtoupper(SpreadsheetImport::normalize($get('tipo_documento')));
            $input += array_filter([
                // Quien use una sola columna de nombre para todo (como en Clientes) también entra.
                'given_names' => $get('nombres') !== '' ? $get('nombres') : $get('razon_social'),
                'family_name' => $get('apellidos'),
                'document_type' => in_array($documentType, ['PASAPORTE', 'PASSPORT'], true) ? 'PASSPORT' : $documentType,
                'document_number' => $get('numero_documento'),
                'position' => array_intersect(['EMPLOYEE', 'CONTACT'], $roles) ? $get('cargo') : '',
                'contact_of' => in_array('CONTACT', $roles, true) ? $this->organizationFor($get('contacto_de'), $tenantId) : '',
            ], fn ($value) => $value !== '' && $value !== null);
        }

        return $this->validated(request(), true, null, $input);
    }

    /** La organización de "contacto_de", por RUC o por nombre. */
    private function organizationFor(string $value, string $tenantId): ?string
    {
        if ($value === '') {
            return null;
        }

        $organization = Organization::whereHas('party', fn ($p) => $p->where('tenant_id', $tenantId))
            ->where(fn ($q) => $q->where('tax_identifier', $value)
                ->orWhereRaw('lower(legal_name) = lower(?)', [$value])
                ->orWhereRaw('lower(trade_name) = lower(?)', [$value]))
            ->first();
        if (! $organization) {
            throw ValidationException::withMessages(['contact_of' => ["contacto_de: no existe la organización «{$value}»."]]);
        }

        return $organization->party_id;
    }

    /** Días de crédito, límite y moneda del rol Cliente. */
    private function importTerms(Party $party, array $row): void
    {
        $customer = Customer::where('party_id', $party->id)->first();
        if (! $customer) {
            return;
        }

        $days = trim((string) ($row['dias_credito'] ?? ''));
        $limit = trim((string) ($row['limite_credito'] ?? ''));
        $currency = strtoupper(trim((string) ($row['moneda'] ?? '')));
        if (($days !== '' && ! is_numeric($days)) || ($limit !== '' && (! is_numeric($limit) || (float) $limit < 0))) {
            throw ValidationException::withMessages(['terms' => ['dias_credito y limite_credito deben ser números.']]);
        }

        $customer->update(array_filter([
            'payment_terms_days' => $days === '' ? null : (int) $days,
            'credit_limit' => $limit === '' ? null : (float) $limit,
            'preferred_currency' => in_array($currency, ['PEN', 'USD'], true) ? $currency : null,
        ], fn ($value) => $value !== null));
    }

    /** Las columnas que no son de la plantilla son columnas personalizadas: se crean si no existen. */
    private function importCustomFields(Party $party, array $row, array $columns, string $tenantId): void
    {
        $definitions = CustomFieldDefinition::where('tenant_id', $tenantId)->where('entity_type', 'Party')->get();

        foreach ($columns as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $definition = $definitions->first(fn ($candidate) => SpreadsheetImport::normalize($candidate->label) === $key
                || SpreadsheetImport::normalize($candidate->field_key) === $key);
            if (! $definition) {
                $definition = CustomFieldDefinition::create([
                    'tenant_id' => $tenantId, 'entity_type' => 'Party', 'field_key' => $key,
                    'label' => ucfirst(str_replace('_', ' ', $key)), 'field_type' => 'text',
                ]);
                $definitions->push($definition);
            }

            CustomFieldValue::updateOrCreate(
                ['field_id' => $definition->id, 'entity_id' => $party->id],
                ['tenant_id' => $tenantId, 'entity_type' => 'Party', 'value' => match ($definition->field_type) {
                    'date' => SpreadsheetImport::date($value),
                    'boolean' => SpreadsheetImport::truthy($value) ? 'true' : 'false',
                    default => $value,
                }],
            );
        }
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
        if ($role === 'CONTACT') {
            $person = Person::where('party_id', $party->id)->first();
            $person?->update(['metadata' => [...($person->metadata ?? []), 'contact' => true]]);
        } elseif ($role === 'CUSTOMER') {
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
            // El teléfono lleva aparte el prefijo del país, si se indicó.
            $extra = $field === 'phone' && array_key_exists('phone_country_code', $data)
                ? ['country_code' => $data['phone_country_code'] ?: null]
                : [];

            if ($value === '') {
                $current?->delete();
            } elseif ($current) {
                $current->update(['value' => $value, ...$extra]);
            } else {
                Contact::create([
                    'tenant_id' => $tenantId, 'party_id' => $party->id,
                    'contact_type' => $type, 'value' => $value, 'is_primary' => true, ...$extra,
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
