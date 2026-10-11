<?php

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Models\CrmCampaign;
use App\Modules\Crm\Models\CrmLead;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Alta de un lead como miembro de una campaña, desde la ficha de la campaña o desde el
 * formulario del lead: las reglas son las mismas en los dos caminos.
 */
class CampaignMembership
{
    /**
     * @param  string  $field  campo del formulario al que se atribuyen los errores
     */
    public static function add(CrmCampaign $campaign, CrmLead $lead, bool $consent, string $userId, string $field = 'lead_id'): void
    {
        if (! in_array($campaign->status, CrmCampaign::OPEN, true)) {
            throw ValidationException::withMessages([$field => ['La campaña está '.mb_strtolower(CrmCampaign::STATES[$campaign->status] ?? $campaign->status).': ya no admite miembros.']]);
        }
        if ($lead->status === 'DISQUALIFIED') {
            throw ValidationException::withMessages([$field => ['Un lead descartado no se agrega a una campaña.']]);
        }
        // Sin consentimiento registrado no hay comunicaciones de campaña.
        if ($campaign->consent_required && ! $consent) {
            throw ValidationException::withMessages(['consent' => ['Esta campaña exige el consentimiento registrado de cada miembro.']]);
        }

        $member = DB::table('crm_campaign_members')->where('campaign_id', $campaign->id)->where('lead_id', $lead->id)->first(['id', 'status']);
        if ($member && $member->status === 'ADDED') {
            throw ValidationException::withMessages([$field => ['El lead ya es miembro de la campaña.']]);
        }

        if ($member) {
            // Estaba excluido: vuelve a ser miembro, sin crear otra fila.
            DB::table('crm_campaign_members')->where('id', $member->id)->update([
                'status' => 'ADDED', 'consent' => $consent, 'added_by' => $userId, 'excluded_at' => null, 'excluded_by' => null,
            ]);
        } else {
            DB::table('crm_campaign_members')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $campaign->tenant_id, 'campaign_id' => $campaign->id, 'lead_id' => $lead->id,
                'status' => 'ADDED', 'consent' => $consent, 'added_by' => $userId, 'created_at' => now(),
            ]);
        }

        // La primera campaña del lead queda como su campaña de origen.
        if (! $lead->campaign_id) {
            DB::table('crm_leads')->where('id', $lead->id)->update(['campaign_id' => $campaign->id]);
            $lead->setAttribute('campaign_id', $campaign->id)->syncOriginalAttribute('campaign_id');
        }
        LeadScoring::apply($lead);

        AuditLogger::log(
            tenantId: $campaign->tenant_id,
            userId: $userId,
            action: 'crm_campaign.member.add',
            entityType: 'CrmCampaign',
            entityId: $campaign->id,
            metadata: ['lead_id' => $lead->id, 'lead_number' => $lead->lead_number, 'consent' => $consent],
        );
    }
}
