<?php

namespace App\Modules\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniquePartyDocument implements ValidationRule
{
    public function __construct(
        protected string $tenantId,
        protected string $partyType
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Si no hay tenant o tipo definido, fallamos por seguridad
        if (!$this->tenantId || !$this->partyType) {
            $fail('Faltan parámetros de contexto para validar el documento.');
            return;
        }

        if ($this->partyType === 'PERSON') {
            $exists = DB::table('persons')
                ->join('parties', 'persons.party_id', '=', 'parties.id')
                ->where('parties.tenant_id', $this->tenantId)
                ->where('persons.document_number', $value)
                ->exists();
        } else {
            // Es ORGANIZATION
            $exists = DB::table('organizations')
                ->join('parties', 'organizations.party_id', '=', 'parties.id')
                ->where('parties.tenant_id', $this->tenantId)
                ->where('organizations.tax_identifier', $value)
                ->exists();
        }

        if ($exists) {
            $fail('El número de documento o RUC ya se encuentra registrado en este espacio de trabajo.');
        }
    }
}