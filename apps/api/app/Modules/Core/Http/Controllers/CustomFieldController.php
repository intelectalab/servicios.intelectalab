<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\CustomFieldDefinition;
use App\Modules\Core\Models\CustomFieldValue;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomFieldController extends Controller
{
    /**
     * Lista las definiciones de campos personalizados para un tipo de entidad
     * (ej. "customer"), para que el frontend sepa qué campos renderizar.
     */
    public function definitions(Request $request)
    {
        $request->validate(['entity_type' => ['required', 'string']]);

        return response()->json(
            CustomFieldDefinition::where('entity_type', $request->query('entity_type'))->get()
        );
    }

    /**
     * El administrador crea un campo nuevo (ej. "Instagram", tipo texto)
     * para un tipo de entidad. A partir de aquí, aparece disponible
     * para todos los registros de ese tipo.
     */
    public function storeDefinition(Request $request)
    {
        $request->validate([
            'entity_type' => ['required', 'string', 'max:50'],
            'field_key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'label' => ['required', 'string', 'max:150'],
            'field_type' => ['required', 'in:text,number,date,boolean'],
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $definition = CustomFieldDefinition::create([
            'tenant_id' => $tenantId,
            'entity_type' => $request->entity_type,
            'field_key' => $request->field_key,
            'label' => $request->label,
            'field_type' => $request->field_type,
        ]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'custom_field_definition.create',
            entityType: 'CustomFieldDefinition',
            entityId: $definition->id,
        );

        return response()->json($definition, 201);
    }

    /**
     * Obtiene los valores de campos personalizados de un registro específico
     * (ej. un cliente puntual), ya combinados con su definición (label, tipo).
     */
    public function values(Request $request)
    {
        $request->validate([
            'entity_type' => ['required', 'string'],
            'entity_id' => ['required', 'uuid'],
        ]);

        $values = CustomFieldValue::with('definition')
            ->where('entity_type', $request->query('entity_type'))
            ->where('entity_id', $request->query('entity_id'))
            ->get();

        return response()->json($values);
    }

    /**
     * Guarda (crea o actualiza) el valor de un campo personalizado
     * para un registro específico.
     */
    public function storeValue(Request $request)
    {
        $request->validate([
            'field_id' => ['required', 'uuid'],
            'entity_type' => ['required', 'string'],
            'entity_id' => ['required', 'uuid'],
            'value' => ['nullable', 'string'],
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $value = CustomFieldValue::updateOrCreate(
            [
                'field_id' => $request->field_id,
                'entity_id' => $request->entity_id,
            ],
            [
                'tenant_id' => $tenantId,
                'entity_type' => $request->entity_type,
                'value' => $request->value,
            ]
        );

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'custom_field_value.set',
            entityType: 'CustomFieldValue',
            entityId: $value->id,
        );

        return response()->json($value->load('definition'));
    }

    /**
     * Elimina una definición de campo personalizado y sus valores asociados.
     */
    public function destroyDefinition(Request $request, string $id)
    {
        $tenantId = $request->attributes->get('tenant_id');
        
        $definition = CustomFieldDefinition::where('tenant_id', $tenantId)->findOrFail($id);

        DB::transaction(function () use ($definition, $tenantId) {
            // Eliminar los valores asociados a esta definición
            CustomFieldValue::where('field_id', $definition->id)->delete();
            
            // Eliminar la definición
            $definition->delete();
        });

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'custom_field_definition.delete',
            entityType: 'CustomFieldDefinition',
            entityId: $id,
        );

        return response()->json([
            'message' => 'Campo personalizado eliminado correctamente.'
        ]);
    }
}