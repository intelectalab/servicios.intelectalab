<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckPermission;
use App\Modules\Core\Models\Party;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\DocumentAccess;
use App\Modules\Documents\Support\DocumentStore;
use App\Modules\Operations\Models\Project;
use App\Modules\Operations\Models\WorkOrder;
use App\Modules\Services\Models\Contract;
use App\Services\AuditCatalog;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Documentos (Control → Documentos): subir archivos vinculados a un cliente, contrato, proyecto u
 * orden de trabajo, con versiones que no se destruyen, antivirus, huella SHA-256 y descarga solo por
 * enlace temporal registrado. Marcar uno "Visible en el portal" lo muestra al cliente en su portal.
 */
class DocumentController extends Controller
{
    /** Minutos que vale un enlace de descarga. */
    private const URL_MINUTES = 5;

    private const OPTIONS_LIMIT = 200;

    /** Último archivo de cada documento, para listar y ordenar. */
    private const LATEST = '(SELECT f.%s FROM document_versions v JOIN file_objects f ON f.id = v.file_object_id
        WHERE v.document_id = documents.id ORDER BY v.version DESC LIMIT 1)';

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $category = (string) $request->query('category', '');
        $status = (string) $request->query('status', '');
        $visibility = (string) $request->query('visibility', '');

        // En la ficha de un registro: todos sus documentos, si la persona ve el registro.
        $entityType = (string) $request->query('entity_type', '');
        $query = $entityType !== ''
            ? DocumentAccess::forRecord($entityType, (string) $request->query('entity_id', ''))
            : DocumentAccess::listing();

        $query->select('documents.*')
            ->when(in_array($category, DocumentStore::CATEGORIES, true), fn ($q) => $q->where('documents.document_type', $category))
            ->when(isset(Document::STATES[$status]), fn ($q) => $q->where('documents.status', $status))
            ->when(in_array($visibility, DocumentStore::VISIBILITIES, true), fn ($q) => $q->where('documents.visibility', $visibility))
            ->when($search !== '', function ($q) use ($search) {
                // Por título o nombre de cualquiera de sus archivos.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('documents.title', 'ILIKE', $like)
                    ->orWhereExists(fn ($files) => $files->select(DB::raw(1))->from('document_versions as sv')
                        ->join('file_objects as sf', 'sf.id', '=', 'sv.file_object_id')
                        ->whereColumn('sv.document_id', 'documents.id')->where('sf.original_name', 'ILIKE', $like)));
            });

        ColumnFilters::apply($query, $request, [
            'document' => ColumnFilters::text("concat_ws(' ', documents.title, ".sprintf(self::LATEST, 'original_name').')'),
            'category' => ColumnFilters::text('documents.document_type'),
            'version' => ColumnFilters::number('(SELECT max(v.version) FROM document_versions v WHERE v.document_id = documents.id)'),
            'updated' => ColumnFilters::date('documents.updated_at'),
        ]);

        ListSort::apply($query, $request, [
            'document' => 'documents.title',
            'category' => 'documents.document_type',
            'version' => '(SELECT max(v.version) FROM document_versions v WHERE v.document_id = documents.id)',
            'visibility' => 'documents.visibility',
            'status' => ListSort::labels('documents.status', Document::STATES),
            'updated' => 'documents.updated_at',
        ], fn ($q) => $q->orderByDesc('documents.updated_at'), 'documents.id');

        $page = $query->with('latestVersion.fileObject')->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $links = DocumentAccess::links($page->getCollection()->pluck('id')->all());
        $users = DB::table('users')->whereIn('id', $page->getCollection()->pluck('created_by')->filter()->unique()->all())->pluck('name', 'id');
        $page->getCollection()->transform(fn (Document $document) => $this->row($document, $links[$document->id] ?? [], $users[$document->created_by] ?? null));

        return response()->json($page);
    }

    /** Categorías y registros a los que se puede vincular (los que la persona alcanza). */
    public function options(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $like = '%'.addcslashes($search, '%_\\').'%';
        $can = fn (string $permission) => CheckPermission::granted($request, $permission);
        $customerName = "(SELECT COALESCE(o.trade_name, o.legal_name, concat_ws(' ', p.given_names, p.family_name)) FROM customers c
            LEFT JOIN organizations o ON o.party_id = c.party_id LEFT JOIN persons p ON p.party_id = c.party_id WHERE c.id = %s.customer_id)";
        $partyName = "COALESCE(o.trade_name, o.legal_name, concat_ws(' ', pe.given_names, pe.family_name))";

        $targets = collect();
        if ($can('core.view')) {
            $targets = $targets->concat(Party::query()
                ->leftJoin('organizations as o', 'o.party_id', '=', 'parties.id')->leftJoin('persons as pe', 'pe.party_id', '=', 'parties.id')
                ->where('parties.status', '<>', 'INACTIVE')
                ->when($search !== '', fn ($q) => $q->whereRaw("{$partyName} ILIKE ?", [$like]))
                ->orderByRaw($partyName)->limit(self::OPTIONS_LIMIT)
                ->get(['parties.id', DB::raw("{$partyName} AS name"), DB::raw('EXISTS (SELECT 1 FROM customers c WHERE c.party_id = parties.id) AS is_customer')])
                ->map(fn ($party) => ['type' => 'Party', 'id' => $party->id, 'label' => $party->name, 'customer' => (bool) $party->is_customer]));
        }
        if ($can('contracts.view')) {
            $targets = $targets->concat(Contract::query()
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('contract_number', 'ILIKE', $like)->orWhereRaw(sprintf($customerName, 'contracts').' ILIKE ?', [$like])))
                ->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)
                ->get(['id', 'contract_number', DB::raw(sprintf($customerName, 'contracts').' AS customer')])
                ->map(fn ($row) => ['type' => 'Contract', 'id' => $row->id, 'label' => "Contrato {$row->contract_number}".($row->customer ? " · {$row->customer}" : ''), 'customer' => true]));
        }
        if ($can('projects.view')) {
            $targets = $targets->concat(Project::query()
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('code', 'ILIKE', $like)->orWhere('name', 'ILIKE', $like)))
                ->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)
                ->get(['id', 'code', 'name', 'customer_id'])
                ->map(fn ($row) => ['type' => 'Project', 'id' => $row->id, 'label' => "Proyecto {$row->code} · {$row->name}", 'customer' => (bool) $row->customer_id]));
        }
        if ($can('work_orders.view')) {
            $targets = $targets->concat(WorkOrder::query()
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('code', 'ILIKE', $like)->orWhere('title', 'ILIKE', $like)))
                ->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)
                ->get(['id', 'code', 'title', 'customer_id'])
                ->map(fn ($row) => ['type' => 'WorkOrder', 'id' => $row->id, 'label' => "{$row->code} · {$row->title}", 'customer' => (bool) $row->customer_id]));
        }

        return response()->json([
            'categories' => DocumentStore::CATEGORIES,
            'link_types' => DocumentAccess::LINKABLE,
            'targets' => $targets->values(),
        ]);
    }

    public function show(string $document)
    {
        return response()->json($this->detail(DocumentAccess::find($document)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(DocumentStore::CATEGORIES)],
            'visibility' => ['required', Rule::in(DocumentStore::VISIBILITIES)],
            'link_type' => ['nullable', Rule::in(array_keys(DocumentAccess::LINKABLE))],
            'link_id' => ['nullable', 'required_with:link_type', 'uuid'],
            'sha256' => ['nullable', 'regex:/^[0-9a-fA-F]{64}$/'],
            'file' => DocumentStore::fileRules(),
        ], [
            'title.required' => 'El título es obligatorio.',
            'category.required' => 'Elige la categoría.',
            'link_id.required_with' => 'Elige el registro al que se vincula.',
            ...DocumentStore::fileMessages(),
        ]);
        $linkType = $data['link_type'] ?? null;
        $linkId = $data['link_id'] ?? null;

        if ($linkType && ! DocumentAccess::recordVisible($linkType, $linkId)) {
            throw ValidationException::withMessages(['link_id' => ['No encontramos ese registro.']]);
        }
        if ($data['visibility'] === 'PORTAL') {
            $this->assertCanPublish($request, $linkType ? [[$linkType, $linkId]] : []);
        }

        $version = DocumentStore::store(
            $request->attributes->get('tenant_id'), $request->user()->id, $request->file('file'),
            ['title' => trim($data['title']), 'category' => $data['category'], 'visibility' => $data['visibility']],
            null, $data['sha256'] ?? null, quarantine: true,
        );
        $document = $version->document;

        DB::transaction(function () use ($request, $document, $version, $linkType, $linkId) {
            if ($linkType) {
                DocumentStore::link($document, $linkType, $linkId);
            }
            $this->audit($request, $document, 'document.create', [
                'version' => 1, 'note' => $version->fileObject->original_name, 'antivirus' => $version->fileObject->antivirus_status,
            ]);
        });

        return response()->json($this->detail($document->fresh()), 201);
    }

    /** Versión nueva: el mismo documento con otro archivo; las anteriores no se destruyen. */
    public function storeVersion(string $document, Request $request)
    {
        $document = DocumentAccess::find($document);
        $data = $request->validate([
            'sha256' => ['nullable', 'regex:/^[0-9a-fA-F]{64}$/'],
            'file' => DocumentStore::fileRules(),
        ], DocumentStore::fileMessages());

        $version = DocumentStore::store(
            $document->tenant_id, $request->user()->id, $request->file('file'),
            ['title' => $document->title, 'category' => $document->document_type, 'visibility' => $document->visibility],
            $document, $data['sha256'] ?? null, quarantine: true,
        );

        $this->audit($request, $document, 'document.version', [
            'version' => $version->version, 'note' => $version->fileObject->original_name, 'antivirus' => $version->fileObject->antivirus_status,
        ]);

        return response()->json($this->detail($document->fresh()), 201);
    }

    /** Título, categoría y visibilidad en el portal. */
    public function update(string $document, Request $request)
    {
        $document = DocumentAccess::find($document);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(DocumentStore::CATEGORIES)],
            'visibility' => ['required', Rule::in(DocumentStore::VISIBILITIES)],
        ], ['title.required' => 'El título es obligatorio.', 'category.required' => 'Elige la categoría.']);

        if ($document->status === 'ARCHIVED') {
            throw ValidationException::withMessages(['status' => ['Un documento archivado no se modifica.']]);
        }
        if ($data['visibility'] === 'PORTAL' && $document->visibility !== 'PORTAL') {
            if ($document->status === 'QUARANTINED') {
                throw ValidationException::withMessages(['visibility' => ['Un documento en cuarentena no se publica en el portal.']]);
            }
            $this->assertCanPublish($request, DB::table('document_links')->where('document_id', $document->id)
                ->get(['entity_type', 'entity_id'])->map(fn ($link) => [$link->entity_type, $link->entity_id])->all());
        }

        $before = ['title' => $document->title, 'category' => $document->document_type, 'visibility' => $document->visibility];
        $after = ['title' => trim($data['title']), 'category' => $data['category'], 'visibility' => $data['visibility']];
        if ($before === $after) {
            return response()->json($this->detail($document));
        }

        DB::transaction(function () use ($request, $document, $before, $after) {
            $document->update(['title' => $after['title'], 'document_type' => $after['category'], 'visibility' => $after['visibility']]);
            AuditLogger::log(
                tenantId: $document->tenant_id, userId: $request->user()->id, action: 'document.update', entityType: 'Document', entityId: $document->id,
                beforeData: $before, afterData: $after,
                metadata: $before['visibility'] !== $after['visibility']
                    ? ['note' => $after['visibility'] === 'PORTAL' ? 'Visible en el portal' : 'Ya no se ve en el portal'] : [],
            );
        });

        return response()->json($this->detail($document->fresh()));
    }

    /** Archivar: deja de verse en el portal y no se modifica más. Sus versiones se conservan. */
    public function archive(string $document, Request $request)
    {
        $document = DocumentAccess::find($document);
        if ($document->status === 'ARCHIVED') {
            throw ValidationException::withMessages(['status' => ['El documento ya está archivado.']]);
        }

        DB::transaction(function () use ($request, $document) {
            $from = $document->status;
            $document->update(['status' => 'ARCHIVED', 'archived_at' => now(), 'archived_by' => $request->user()->id]);
            $this->audit($request, $document, 'document.archive', ['from' => $from, 'to' => 'ARCHIVED']);
        });

        return response()->json($this->detail($document->fresh()));
    }

    /** Enlace temporal (5 minutos) para descargar la última versión o la indicada; queda registrado. */
    public function downloadUrl(string $document, Request $request)
    {
        $document = DocumentAccess::find($document);
        $version = $request->filled('version')
            ? $document->versions()->with('fileObject')->where('version', $request->integer('version'))->first()
            : $document->latestVersion()->with('fileObject')->first();
        abort_unless($version, 404);

        if ($version->fileObject->antivirus_status === 'INFECTED') {
            throw ValidationException::withMessages(['document' => ['Archivo en cuarentena por el antivirus: no se puede descargar.']]);
        }
        $url = DocumentStore::downloadUrl($document, self::URL_MINUTES, $version);
        abort_unless($url, 404);
        $expires = now()->addMinutes(self::URL_MINUTES);

        $this->audit($request, $document, 'document.download_url', [
            'version' => $version->version, 'note' => $version->fileObject->original_name, 'expires_at' => $expires->toIso8601String(),
        ]);

        return response()->json([
            'url' => $url,
            'expires_at' => $expires->toIso8601String(),
            'name' => $version->fileObject->original_name,
            'size' => (int) $version->fileObject->size_bytes,
            'version' => $version->version,
        ]);
    }

    /**
     * Mostrar un documento al cliente es gestionar documentos, y solo tiene sentido si está
     * vinculado a algo de un cliente: el portal no tendría a quién mostrárselo.
     */
    private function assertCanPublish(Request $request, array $links): void
    {
        if (! CheckPermission::granted($request, 'documents.manage')) {
            abort(response()->json([
                'message' => 'No tienes el permiso requerido: documents.manage',
                'code' => 'PERMISSION_DENIED',
            ], 403));
        }
        if (! collect($links)->contains(fn (array $link) => DocumentAccess::belongsToCustomer($link[0], $link[1]))) {
            throw ValidationException::withMessages(['visibility' => ['Para mostrarlo en el portal, vincúlalo a un cliente o a su contrato, proyecto u orden de trabajo.']]);
        }
    }

    private function row(Document $document, array $links, ?string $creator): array
    {
        $version = $document->latestVersion;
        $file = $version?->fileObject;

        return [
            'id' => $document->id,
            'title' => $document->title,
            'category' => $document->document_type,
            'status' => $document->status,
            'visibility' => $document->visibility,
            'version' => (int) ($version?->version ?? 0),
            'file' => $file ? [
                'name' => $file->original_name, 'size' => (int) $file->size_bytes, 'mime' => $file->mime_type, 'sha256' => trim((string) $file->sha256),
                'antivirus' => $file->antivirus_status, 'engine' => $file->antivirus_engine,
            ] : null,
            'links' => $links,
            'creator' => $creator,
            'created_at' => Carbon::parse($document->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($document->updated_at)->toIso8601String(),
        ];
    }

    private function detail(Document $document): array
    {
        $document->load('latestVersion.fileObject');
        $users = DB::table('users')->whereIn('id', array_filter([$document->created_by, $document->archived_by]))->pluck('name', 'id');

        $versions = DB::table('document_versions as v')
            ->join('file_objects as f', 'f.id', '=', 'v.file_object_id')
            ->leftJoin('users as u', 'u.id', '=', 'v.created_by')
            ->where('v.document_id', $document->id)->orderByDesc('v.version')
            ->get(['v.version', 'v.created_at', 'u.name as author', 'f.original_name', 'f.size_bytes', 'f.mime_type', 'f.sha256', 'f.antivirus_status', 'f.antivirus_engine'])
            ->map(fn ($row) => [
                'version' => (int) $row->version,
                'name' => $row->original_name,
                'size' => (int) $row->size_bytes,
                'sha256' => trim((string) $row->sha256),
                'antivirus' => $row->antivirus_status,
                'engine' => $row->antivirus_engine,
                'author' => $row->author,
                'at' => Carbon::parse($row->created_at)->toIso8601String(),
            ]);

        // Registro de accesos: lo que quedó en auditoría del documento, también desde el entregable,
        // la evidencia de la OT o el portal.
        $accesses = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where(fn ($q) => $q->where(fn ($own) => $own->where('audit_logs.entity_type', 'Document')->where('audit_logs.entity_id', $document->id))
                ->orWhereRaw("audit_logs.metadata->>'document_id' = ?", [$document->id]))
            ->orderByDesc('audit_logs.created_at')->limit(100)
            ->get(['audit_logs.action', 'users.name as user', 'audit_logs.created_at as at', 'audit_logs.metadata'])
            ->map(fn ($row) => [
                'action' => $row->action,
                'label' => AuditCatalog::actionLabel($row->action),
                'user' => $row->user,
                'at' => Carbon::parse($row->at)->toIso8601String(),
                'metadata' => json_decode($row->metadata ?? '{}', true) ?: [],
            ]);

        return [
            ...$this->row($document, DocumentAccess::links([$document->id])[$document->id] ?? [], $users[$document->created_by] ?? null),
            'branch' => $document->branch_id ? DB::table('branches')->where('id', $document->branch_id)->value('name') : null,
            'archived_at' => $document->archived_at?->toIso8601String(),
            'archived_by' => $document->archived_by ? ($users[$document->archived_by] ?? null) : null,
            'lock_version' => (int) $document->lock_version,
            'versions' => $versions,
            'accesses' => $accesses,
        ];
    }

    private function audit(Request $request, Document $document, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $document->tenant_id,
            userId: $request->user()->id,
            action: $action,
            entityType: 'Document',
            entityId: $document->id,
            metadata: $metadata,
        );
    }
}
