<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Mensaje de la conversación de un ticket. No se edita ni se borra (lo garantiza la base). */
class SupportTicketComment extends Model
{
    protected $table = 'support_ticket_comments';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'ticket_id', 'author_user_id', 'author_type', 'body', 'internal', 'created_at'];

    protected $casts = ['internal' => 'boolean', 'created_at' => 'datetime'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function author()
    {
        return $this->belongsTo(\App\Models\User::class, 'author_user_id');
    }
}
