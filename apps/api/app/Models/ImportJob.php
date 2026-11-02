<?php

namespace App\Models;

use App\Models\Concerns\ScopedByAccess;
use Illuminate\Database\Eloquent\Model;

/** Una importación desde Excel o CSV (historial de Importación masiva). Solo se agrega: no se edita. */
class ImportJob extends Model
{
    use ScopedByAccess;

    public const ACCESS_RESOURCE = 'imports';

    public const STATES = ['COMPLETED' => 'Completada', 'PARTIAL' => 'Con omisiones', 'FAILED' => 'Fallida'];

    protected $table = 'import_jobs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];
}
