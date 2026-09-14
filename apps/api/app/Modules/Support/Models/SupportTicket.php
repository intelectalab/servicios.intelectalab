<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    protected $table = 'support_tickets';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'customer_id', 'contract_id', 'ticket_number', 'subject', 'description',
        'priority', 'status', 'sla_id', 'assigned_user_id', 'opened_at', 'resolved_at', 'closed_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime',
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

    public function comments()
    {
        return $this->hasMany(SupportTicketComment::class, 'ticket_id')->orderBy('created_at');
    }

    public function sla()
    {
        return $this->belongsTo(SupportSla::class, 'sla_id');
    }
}