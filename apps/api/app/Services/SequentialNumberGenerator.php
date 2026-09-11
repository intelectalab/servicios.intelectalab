<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SequentialNumberGenerator
{
    /**
     * Genera un número correlativo seguro ante concurrencia,
     * usando un advisory lock de PostgreSQL scoped al tenant + prefijo.
     * Debe llamarse DENTRO de una transacción (DB::transaction).
     */
    public static function next(string $tenantId, string $table, string $column, string $prefix): string
    {
        // Bloquea solo para este tenant + tabla, no toda la BD.
        $lockKey = "{$tenantId}:{$table}:{$column}";
        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$lockKey]);

        $year = date('Y');
        $likePattern = "{$prefix}-{$year}-%";

        $lastNumber = DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where($column, 'like', $likePattern)
            ->orderByDesc($column)
            ->value($column);

        $nextSequence = 1;
        if ($lastNumber) {
            $parts = explode('-', $lastNumber);
            $nextSequence = (int) end($parts) + 1;
        }

        return sprintf('%s-%s-%04d', $prefix, $year, $nextSequence);
    }
}