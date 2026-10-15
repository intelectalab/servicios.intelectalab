<?php

namespace App\Modules\Support\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Reclamo de garantía de un servicio contratado, con su validación de cobertura. */
class WarrantyClaim extends Model
{
    use ScopedByAccess;

    public const ACCESS_RESOURCE = 'warranty';

    /** Estado => texto visible (los del prototipo). */
    public const STATES = [
        'SUBMITTED' => 'Recibido', 'UNDER_REVIEW' => 'En revisión', 'APPROVED' => 'Cubierto',
        'REJECTED' => 'No cubierto', 'RESOLVED' => 'Resuelto',
    ];

    protected $table = 'warranty_claims';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'customer_id', 'contract_id', 'contracted_service_id', 'service_id', 'ticket_id', 'claim_number',
        'status', 'description', 'opened_at', 'resolved_at', 'created_by', 'branch_id',
        'coverage_checked_at', 'coverage_valid', 'coverage_base_date', 'coverage_until', 'coverage_policy', 'coverage_base',
        'reject_reason', 'resolution', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime', 'resolved_at' => 'datetime', 'coverage_checked_at' => 'datetime', 'reviewed_at' => 'datetime',
        'coverage_valid' => 'boolean', 'coverage_base_date' => 'date', 'coverage_until' => 'date',
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

    public function customer()
    {
        return $this->belongsTo(\App\Modules\Core\Models\Customer::class, 'customer_id');
    }

    /** Por su modelo: un servicio contratado fuera del alcance de la persona no se entrega. */
    public function contractedService()
    {
        return $this->belongsTo(\App\Modules\Operations\Models\ContractedService::class, 'contracted_service_id');
    }

    public function contract()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Contract::class, 'contract_id');
    }

    public function service()
    {
        return $this->belongsTo(\App\Modules\Services\Models\Service::class, 'service_id');
    }

    /** Por su modelo: un ticket fuera del alcance de la persona no se entrega. */
    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(\App\Models\User::class, 'reviewed_by');
    }
}
