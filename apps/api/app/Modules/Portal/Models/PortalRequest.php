<?php

namespace App\Modules\Portal\Models;

use App\Models\Concerns\ScopedByAccess;
use App\Services\AccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PortalRequest extends Model
{
    use ScopedByAccess;

    /** Recurso cuyo alcance (lo propio, equipo, sede, empresa) limita qué registros se ven. */
    public const ACCESS_RESOURCE = 'portal';

    protected $table = 'portal_requests';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'customer_id', 'request_number', 'request_type', 'subject', 'description',
        'service_id', 'status', 'resolution_note', 'crm_opportunity_id', 'quotation_id', 'created_by',
        'taken_by', 'branch_id',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Quien atiende primero la solicitud (la toma, la cotiza o la rechaza) queda como su
     * responsable y la solicitud pasa a su sede: de eso depende quién la sigue viendo.
     */
    public function claimFor(string $userId): array
    {
        return $this->taken_by ? [] : ['taken_by' => $userId, 'branch_id' => AccessScope::branchId()];
    }

    public function customer()
    {
        return $this->belongsTo(\App\Modules\Core\Models\Customer::class, 'customer_id');
    }

    public function service()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Service::class, 'service_id');
    }

    public function quotation()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Quotation::class, 'quotation_id');
    }
}
