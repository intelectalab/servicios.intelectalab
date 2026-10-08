<?php

namespace App\Models\Concerns;

use App\Services\AccessScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * El modelo solo devuelve lo que la persona alcanza según su rol (ver AccessScope), en
 * cualquier consulta: no depende de que cada controlador se acuerde de filtrar.
 * El modelo declara su recurso en la constante ACCESS_RESOURCE.
 *
 * Al crearse, el documento toma la sede de quien lo crea, salvo que se le indique otra
 * (un contrato hereda la de su cotización).
 */
trait ScopedByAccess
{
    public const ACCESS_SCOPE = 'access';

    public static function bootScopedByAccess(): void
    {
        static::addGlobalScope(self::ACCESS_SCOPE, fn (Builder $query) => AccessScope::apply($query, static::ACCESS_RESOURCE));

        static::creating(function ($model) {
            if (! array_key_exists('branch_id', $model->getAttributes())) {
                $model->branch_id = AccessScope::branchId();
            }
        });
    }

    /** Para comprobar existencia sin importar quién lo creó (p. ej. "ya tiene un contrato"). */
    public static function ignoringAccessScope(): Builder
    {
        return static::withoutGlobalScope(self::ACCESS_SCOPE);
    }
}
