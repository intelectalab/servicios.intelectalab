<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Crm\Support\LeadScoring;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puntuación de leads: los criterios, cuántos puntos da cada uno en esta empresa y cómo
 * quedan repartidos los leads abiertos. Ver la pantalla pide ver leads; cambiar los puntos o
 * recalcular es una decisión de toda la empresa y lleva su propio permiso (`scoring.manage`).
 */
class CrmScoringController extends Controller
{
    public function show(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        // Distribución y prioridad, sobre los leads abiertos que la persona alcanza.
        $byGrade = CrmLead::query()->whereIn('status', CrmLead::OPEN)
            ->selectRaw("COALESCE(score_grade, 'D') AS grade, count(*) AS total")
            ->groupByRaw("COALESCE(score_grade, 'D')")
            ->toBase()->pluck('total', 'grade');

        return response()->json([
            'rules' => LeadScoring::rules($tenantId),
            'limits' => ['min' => LeadScoring::MIN_POINTS, 'max' => LeadScoring::MAX_POINTS],
            'distribution' => collect(['A', 'B', 'C', 'D'])->map(fn (string $grade) => ['grade' => $grade, 'total' => (int) ($byGrade[$grade] ?? 0)]),
            'priority' => CrmLead::query()->whereIn('status', CrmLead::OPEN)
                ->orderByRaw('score DESC NULLS LAST')->orderBy('created_at')->limit(6)
                ->get(['id', 'lead_number', 'organization_name', 'person_name', 'score', 'score_grade'])
                ->map(fn (CrmLead $lead) => [
                    'id' => $lead->id, 'lead_number' => $lead->lead_number,
                    'name' => $lead->organization_name ?: $lead->person_name,
                    'score' => (int) $lead->score, 'score_grade' => $lead->score_grade ?? 'D',
                ]),
        ]);
    }

    /** Cambia los puntos de los criterios y recalcula todos los leads con las reglas nuevas. */
    public function updateRules(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $min = LeadScoring::MIN_POINTS;
        $max = LeadScoring::MAX_POINTS;

        $data = $request->validate([
            'points' => ['required', 'array'],
            'points.*' => ['required', 'integer', "between:{$min},{$max}"],
        ], [
            'points.required' => 'Indica los puntos de cada criterio.',
            'points.*.required' => 'Indica los puntos de cada criterio.',
            'points.*.integer' => 'Los puntos son números enteros.',
            'points.*.between' => 'Los puntos van de −50 a 50.',
        ]);

        $before = collect(LeadScoring::rules($tenantId))->pluck('points', 'id')->all();
        if (array_diff_key($data['points'], $before)) {
            throw ValidationException::withMessages(['points' => ['Hay un criterio que no existe.']]);
        }

        $result = DB::transaction(function () use ($tenantId, $data, $before, $request) {
            LeadScoring::savePoints($tenantId, [...$before, ...$data['points']]);
            $after = collect(LeadScoring::rules($tenantId))->pluck('points', 'id')->all();
            $result = LeadScoring::rescoreAll($tenantId);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'crm_scoring.update',
                entityType: 'CrmScoring',
                beforeData: $before,
                afterData: $after,
                metadata: ['leads' => $result['total'], 'changed' => $result['changed']],
            );

            return $result;
        });

        return response()->json([...$result, 'rules' => LeadScoring::rules($tenantId)]);
    }

    /** Recalcula el puntaje de todos los leads de la empresa. */
    public function rescore(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $result = DB::transaction(function () use ($tenantId, $request) {
            $result = LeadScoring::rescoreAll($tenantId);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'crm_scoring.rescore',
                entityType: 'CrmScoring',
                metadata: ['leads' => $result['total'], 'changed' => $result['changed']],
            );

            return $result;
        });

        return response()->json($result);
    }
}
