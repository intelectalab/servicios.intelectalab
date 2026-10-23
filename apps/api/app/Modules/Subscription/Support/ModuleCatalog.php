<?php

namespace App\Modules\Subscription\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Módulos que existen de verdad en el ERP (module_catalog) y sus reglas:
 * los obligatorios (is_core) los tiene toda empresa; el resto, solo si su
 * plan los incluye. Un módulo habilita los permisos de sus prefijos; si
 * todos sus prefijos ya los habilita otro módulo, no tiene pantallas propias.
 */
class ModuleCatalog
{
    /** @return Collection<int, array<string, mixed>> */
    public static function modules(?string $connection = null): Collection
    {
        $rows = DB::connection($connection)->table('module_catalog')
            ->where('active', true)
            ->orderBy('display_order')
            ->get(['code', 'name', 'description', 'category', 'is_core', 'permission_prefixes'])
            ->map(fn ($row) => [
                'code' => strtoupper($row->code),
                'name' => $row->name,
                'description' => $row->description,
                'category' => $row->category,
                'is_core' => (bool) $row->is_core,
                'prefixes' => json_decode($row->permission_prefixes, true) ?: [],
            ]);

        return $rows->map(function (array $module) use ($rows) {
            $others = $rows->where('code', '!=', $module['code']);
            // Quién más da cada uno de sus prefijos; si todos los da otro, no tiene pantallas propias.
            $ownPrefixes = collect($module['prefixes'])->reject(
                fn (string $prefix) => $others->contains(fn ($other) => in_array($prefix, $other['prefixes'], true) && ! $other['is_core'])
            );
            $providedBy = $others->filter(fn ($other) => array_intersect($module['prefixes'], $other['prefixes']))
                ->pluck('name')->values()->all();

            return $module + [
                'own_screens' => $module['is_core'] || $ownPrefixes->isNotEmpty(),
                'provided_by' => $module['is_core'] ? [] : $providedBy,
            ];
        })->values();
    }

    /** @return string[] */
    public static function coreCodes(): array
    {
        return self::modules()->where('is_core', true)->pluck('code')->all();
    }

    /**
     * Módulos de un plan tal como se guardan: solo códigos del catálogo, con los
     * obligatorios siempre encendidos. Un código encendido que no existe se rechaza.
     *
     * @param  array<string, mixed>|null  $modules
     * @return array<string, bool>
     */
    public static function normalizePlanModules(?array $modules): array
    {
        $catalog = self::modules()->keyBy('code');
        $enabled = collect($modules ?? [])
            ->filter(fn ($on) => filter_var($on, FILTER_VALIDATE_BOOLEAN))
            ->keys()->map(fn ($code) => strtoupper((string) $code));

        $unknown = $enabled->reject(fn (string $code) => $catalog->has($code));
        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages([
                'modules' => ['Estos módulos no existen en el ERP: '.$unknown->implode(', ').'.'],
            ]);
        }

        return $catalog->keys()
            ->filter(fn (string $code) => $catalog[$code]['is_core'] || $enabled->contains($code))
            ->mapWithKeys(fn (string $code) => [$code => true])
            ->all();
    }
}
