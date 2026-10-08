<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Orden de un listado paginado, pedido desde la cabecera de la tabla con
 * `?sort=<columna>&dir=asc|desc`. Se ordena en la base, así el orden abarca
 * todo el listado y no solo la página que se ve.
 */
class ListSort
{
    /** Nombre del cliente de la fila (tablas con `customer_id`), para ordenar por él. */
    public static function customerName(string $table): string
    {
        return "(SELECT COALESCE(o.trade_name, o.legal_name, concat_ws(' ', p.given_names, p.family_name))
            FROM customers c
            LEFT JOIN organizations o ON o.party_id = c.party_id
            LEFT JOIN persons p ON p.party_id = c.party_id
            WHERE c.id = {$table}.customer_id)";
    }

    /**
     * Ordena una columna de códigos (ACTIVE, DRAFT…) por el texto que la pantalla
     * muestra (Vigente, Borrador…), para que el orden sea el alfabético que se ve.
     * Los textos van sin tildes y en minúsculas: solo sirven para comparar.
     *
     * @param  array<string, string>  $labels  código => texto visible
     */
    public static function labels(string $column, array $labels): string
    {
        $cases = '';
        foreach ($labels as $code => $label) {
            $cases .= " WHEN '{$code}' THEN '".strtolower(\Illuminate\Support\Str::ascii($label))."'";
        }

        return "CASE {$column}{$cases} ELSE lower({$column}) END";
    }

    /**
     * Aplica el orden pedido si la columna está en `$columns`; si no, el de siempre.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  array<string, string>  $columns  clave pedida => expresión SQL (escrita en el código, nunca por el usuario)
     * @param  callable  $default  orden habitual del listado
     * @param  string  $tiebreak  columna que desempata, para que las páginas no repitan filas
     */
    public static function apply($query, Request $request, array $columns, callable $default, string $tiebreak = 'id')
    {
        $expression = $columns[(string) $request->query('sort', '')] ?? null;
        if ($expression === null) {
            $default($query);

            return $query;
        }

        $direction = strtolower((string) $request->query('dir')) === 'desc' ? 'DESC' : 'ASC';

        // Los vacíos siempre al final, en cualquier sentido.
        return $query->orderByRaw("{$expression} {$direction} NULLS LAST")->orderBy($tiebreak);
    }
}
