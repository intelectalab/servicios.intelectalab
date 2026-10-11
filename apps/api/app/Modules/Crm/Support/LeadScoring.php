<?php

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Models\CrmLead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Puntuación automática de leads: de 0 a 100 según criterios cuyos puntos fija cada empresa.
 * A ≥ 70 · B ≥ 45 · C ≥ 25 · D < 25. Se recalcula al crear o editar el lead, al registrarle
 * una actividad y al asociarlo a una campaña.
 *
 * Los criterios son los del prototipo y viven aquí; la empresa solo cambia cuántos puntos da
 * cada uno (en `crm_settings.configuration.scoring`). Requiere app.tenant_id.
 */
class LeadScoring
{
    public const MIN_POINTS = -50;

    public const MAX_POINTS = 50;

    /** id => [texto, condición legible, puntos por defecto] */
    private const RULES = [
        'source_referral' => ['Viene por referido', 'Fuente es Referido', 25],
        'source_event' => ['Contacto en evento', 'Fuente es Evento', 15],
        'source_web' => ['Formulario web', 'Fuente es Web', 10],
        'decision_maker' => ['Cargo con poder de decisión', 'Cargo contiene Gerente, Jefe o Director', 20],
        'in_core' => ['Ya es cliente o actor en CORE', 'Enlazado a Clientes y actores', 15],
        'has_email' => ['Tiene correo de contacto', 'Correo registrado', 5],
        'activities' => ['Dos o más interacciones registradas', 'Actividades registradas ≥ 2', 15],
        'campaign' => ['Asociado a una campaña', 'Campaña asignada', 5],
    ];

    /** Criterio => código de la fuente que lo cumple. */
    private const SOURCE_RULES = ['source_referral' => 'REFERIDO', 'source_event' => 'EVENTO', 'source_web' => 'WEB'];

    /**
     * Criterios con los puntos vigentes de la empresa.
     *
     * @return array<int, array{id: string, label: string, condition: string, points: int, default_points: int}>
     */
    public static function rules(string $tenantId): array
    {
        $saved = json_decode(DB::table('crm_settings')->where('tenant_id', $tenantId)->value('configuration') ?? '{}', true)['scoring'] ?? [];

        $rules = [];
        foreach (self::RULES as $id => [$label, $condition, $default]) {
            $points = isset($saved[$id]) && is_numeric($saved[$id]) ? (int) $saved[$id] : $default;
            $rules[] = [
                'id' => $id, 'label' => $label, 'condition' => $condition,
                'points' => max(self::MIN_POINTS, min(self::MAX_POINTS, $points)), 'default_points' => $default,
            ];
        }

        return $rules;
    }

    /** Guarda los puntos de cada criterio. Lo que no sea un criterio conocido se ignora. */
    public static function savePoints(string $tenantId, array $points): void
    {
        $current = DB::table('crm_settings')->where('tenant_id', $tenantId)->first(['id', 'configuration']);
        $configuration = json_decode($current->configuration ?? '{}', true) ?: [];
        $configuration['scoring'] = array_map('intval', array_intersect_key($points, self::RULES));

        if ($current) {
            DB::table('crm_settings')->where('id', $current->id)->update(['configuration' => json_encode($configuration)]);
        } else {
            DB::table('crm_settings')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'enabled' => true, 'configuration' => json_encode($configuration),
            ]);
        }
    }

    public static function grade(int $score): string
    {
        return $score >= 70 ? 'A' : ($score >= 45 ? 'B' : ($score >= 25 ? 'C' : 'D'));
    }

    /** Recalcula y guarda el puntaje de un lead. No cuenta como una edición del lead. */
    public static function apply(CrmLead $lead): void
    {
        $result = self::score(
            self::rules($lead->tenant_id),
            [
                'source' => $lead->source_id ? DB::table('crm_sources')->where('id', $lead->source_id)->value('code') : null,
                'position' => $lead->position,
                'party_id' => $lead->party_id,
                'email' => $lead->email,
                'campaign_id' => $lead->campaign_id,
                'activities' => DB::table('crm_activities')->where('lead_id', $lead->id)->count(),
            ],
        );

        DB::table('crm_leads')->where('id', $lead->id)->update([
            'score' => $result['score'], 'score_grade' => $result['grade'], 'score_hits' => json_encode($result['hits']),
        ]);
        $lead->setRawAttributes([...$lead->getAttributes(), 'score' => $result['score'], 'score_grade' => $result['grade'], 'score_hits' => json_encode($result['hits'])], true);
    }

    public static function applyById(?string $leadId): void
    {
        $lead = $leadId ? CrmLead::ignoringAccessScope()->find($leadId) : null;
        if ($lead) {
            self::apply($lead);
        }
    }

    /**
     * Recalcula todos los leads de la empresa, los alcance o no quien lo pide: el puntaje es
     * del lead, no de quien lo mira.
     *
     * @return array{total: int, changed: int}
     */
    public static function rescoreAll(string $tenantId): array
    {
        $rules = self::rules($tenantId);
        $sources = DB::table('crm_sources')->where('tenant_id', $tenantId)->pluck('code', 'id');
        $activities = DB::table('crm_activities')->where('tenant_id', $tenantId)->whereNotNull('lead_id')
            ->selectRaw('lead_id, count(*) AS total')->groupBy('lead_id')->pluck('total', 'lead_id');

        $total = 0;
        $changed = 0;
        DB::table('crm_leads')->where('tenant_id', $tenantId)->orderBy('id')
            ->select(['id', 'source_id', 'position', 'party_id', 'email', 'campaign_id', 'score', 'score_grade', 'score_hits'])
            ->chunk(500, function ($leads) use ($rules, $sources, $activities, &$total, &$changed) {
                foreach ($leads as $lead) {
                    $total++;
                    $result = self::score($rules, [
                        'source' => $sources[$lead->source_id] ?? null,
                        'position' => $lead->position,
                        'party_id' => $lead->party_id,
                        'email' => $lead->email,
                        'campaign_id' => $lead->campaign_id,
                        'activities' => (int) ($activities[$lead->id] ?? 0),
                    ]);
                    $hits = json_encode($result['hits']);
                    $same = $lead->score !== null && (int) $lead->score === $result['score']
                        && $lead->score_grade === $result['grade']
                        && json_decode($lead->score_hits ?? '[]', true) === $result['hits'];
                    if (! $same) {
                        DB::table('crm_leads')->where('id', $lead->id)->update(['score' => $result['score'], 'score_grade' => $result['grade'], 'score_hits' => $hits]);
                        $changed++;
                    }
                }
            });

        return ['total' => $total, 'changed' => $changed];
    }

    /**
     * @param  array{source: ?string, position: ?string, party_id: ?string, email: ?string, campaign_id: ?string, activities: int}  $facts
     * @return array{score: int, grade: string, hits: string[]}
     */
    public static function score(array $rules, array $facts): array
    {
        $met = [
            'decision_maker' => (bool) preg_match('/gerente|jefe|director/iu', (string) $facts['position']),
            'in_core' => ! empty($facts['party_id']),
            'has_email' => str_contains((string) $facts['email'], '@'),
            'activities' => (int) $facts['activities'] >= 2,
            'campaign' => ! empty($facts['campaign_id']),
        ];
        foreach (self::SOURCE_RULES as $id => $code) {
            $met[$id] = strtoupper((string) $facts['source']) === $code;
        }

        $score = 0;
        $hits = [];
        foreach ($rules as $rule) {
            if ($met[$rule['id']] ?? false) {
                $score += $rule['points'];
                $hits[] = $rule['label'].' ('.($rule['points'] >= 0 ? '+' : '−').abs($rule['points']).')';
            }
        }
        $score = max(0, min(100, $score));

        return ['score' => $score, 'grade' => self::grade($score), 'hits' => $hits];
    }
}
