<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\StoreContactRequest;
use App\Modules\Core\Models\Contact;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $query = Contact::query()
            ->when($request->filled('party_id'), fn ($q) => $q->where('party_id', $request->query('party_id')));

        return response()->json($query->get());
    }

    public function store(StoreContactRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $contact = DB::transaction(function () use ($request, $tenantId) {
            // Si se marca como principal, desmarcar cualquier otro del mismo tipo.
            if ($request->boolean('is_primary')) {
                Contact::where('party_id', $request->party_id)
                    ->where('contact_type', $request->contact_type)
                    ->update(['is_primary' => false]);
            }

            $contact = Contact::create([
                'tenant_id' => $tenantId,
                'party_id' => $request->party_id,
                'contact_name' => $request->contact_name,
                'contact_type' => $request->contact_type,
                'value' => $request->value,
                'country_code' => $request->country_code,
                'is_primary' => $request->boolean('is_primary'),
            ]);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'contact.create',
                entityType: 'Contact',
                entityId: $contact->id,
            );

            return $contact;
        });

        return response()->json($contact, 201);
    }

    /** Edita un contacto; si pasa a ser el principal, deja de serlo el otro de su tipo. */
    public function update(string $contact, Request $request)
    {
        $contact = Contact::findOrFail($contact);
        $data = $request->validate([
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_type' => ['required', 'in:EMAIL,PHONE,WHATSAPP,ADDRESS,OTHER'],
            'value' => ['required', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'max:5'],
            'is_primary' => ['boolean'],
        ]);

        DB::transaction(function () use ($contact, $data, $request) {
            if ($data['is_primary'] ?? false) {
                Contact::where('party_id', $contact->party_id)
                    ->where('contact_type', $data['contact_type'])
                    ->where('id', '<>', $contact->id)
                    ->update(['is_primary' => false]);
            }
            $contact->update($data);

            AuditLogger::log(
                tenantId: $request->attributes->get('tenant_id'),
                userId: $request->user()->id,
                action: 'contact.update',
                entityType: 'Contact',
                entityId: $contact->id,
            );
        });

        return response()->json($contact);
    }

    public function destroy(string $contact, Request $request)
    {
        $contact = Contact::findOrFail($contact);
        $contact->delete();

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'contact.delete',
            entityType: 'Contact',
            entityId: $contact->id,
        );

        return response()->json(['message' => 'Contacto eliminado.']);
    }
}