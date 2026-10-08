<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Filtros por columna de un listado ("Filtrar por esta columna"), aplicados en
 * la base para que abarquen todo el listado y no solo la página visible.
 *
 * Llegan en `?filters=` como JSON: [{ id, type, operator, value }], con los
 * operadores de la pantalla: contains, equals, greater, less y, en fechas,
 * today, yesterday, this_week, last_week, last_month, year_start y range ("desde|hasta").
 *
 * Cada listado declara qué columnas acepta y con qué expresión SQL (escrita en
 * el código, nunca por el usuario); los valores viajan siempre como parámetros.
 */
class ColumnFilters
{
    /** Columna de texto. */
    public static function text(string $expression): array
    {
        return ['text' => "lower(({$expression})::text)"];
    }

    /** Columna numérica: además de "contiene", admite igual, mayor y menor como número. */
    public static function number(string $expression): array
    {
        return ['text' => "({$expression})::text", 'number' => "({$expression})"];
    }

    /** Columna de fecha (date o timestamp). */
    public static function date(string $expression): array
    {
        $day = "to_char({$expression}, 'YYYY-MM-DD')";

        return ['text' => $day, 'date' => $day];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  array<string, array>|callable(string): ?array  $columns  columnas aceptadas, o quien las resuelve por id
     */
    public static function apply($query, Request $request, array|callable $columns): void
    {
        $filters = json_decode((string) $request->query('filters', ''), true);
        if (! is_array($filters)) {
            return;
        }

        foreach (array_slice($filters, 0, 20) as $filter) {
            if (! is_array($filter)) {
                continue;
            }
            $id = (string) ($filter['id'] ?? '');
            $column = is_callable($columns) ? $columns($id) : ($columns[$id] ?? null);
            if (! $column) {
                continue;
            }

            $operator = (string) ($filter['operator'] ?? '');
            $value = trim((string) ($filter['value'] ?? ''));

            if (($filter['type'] ?? '') === 'date') {
                self::applyDate($query, $column['date'] ?? $column['text'], $operator, $value);

                continue;
            }
            if ($value === '') {
                continue;
            }

            $numeric = isset($column['number']) && is_numeric($value);
            $needle = mb_strtolower($value);

            match (true) {
                $operator === 'contains' => $query->whereRaw("{$column['text']} LIKE ?", ['%'.addcslashes($needle, '%_\\').'%']),
                $operator === 'equals' && $numeric => $query->whereRaw("{$column['number']} = ?", [(float) $value]),
                $operator === 'equals' => $query->whereRaw("COALESCE({$column['text']}, ?) = ?", [$column['empty'] ?? '', $needle]),
                $operator === 'greater' && $numeric => $query->whereRaw("{$column['number']} > ?", [(float) $value]),
                $operator === 'less' && $numeric => $query->whereRaw("{$column['number']} < ?", [(float) $value]),
                default => null,
            };
        }
    }

    /** `$day` es una expresión que da la fecha como texto "YYYY-MM-DD". */
    private static function applyDate($query, string $day, string $operator, string $value): void
    {
        $today = now();
        // La semana empieza el domingo, como en el calendario de la pantalla.
        $weekStart = $today->copy()->subDays($today->dayOfWeek);

        [$from, $to] = match ($operator) {
            'today' => [$today, $today],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'this_week' => [$weekStart, $today],
            'last_week' => [$weekStart->copy()->subDays(7), $weekStart->copy()->subDay()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'year_start' => [$today->copy()->startOfYear(), $today],
            'range' => array_pad(explode('|', $value, 2), 2, ''),
            default => [null, null],
        };

        foreach ([['>=', $from], ['<=', $to]] as [$comparison, $limit]) {
            $limit = $limit instanceof \DateTimeInterface ? $limit->format('Y-m-d') : $limit;
            if ($limit) {
                $query->whereRaw("{$day} {$comparison} ?", [$limit]);
            }
        }
    }
}
