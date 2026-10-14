<?php

namespace App\Modules\Accounting\Support;

use Illuminate\Support\Facades\DB;

/**
 * Plan de cuentas de la empresa. Nace con el subconjunto del PCGE 2019 para empresas de servicios
 * (el del prototipo, más las cuentas que usan las reglas automáticas) la primera vez que se usa;
 * después cada empresa agrega las suyas.
 */
class Chart
{
    public const TYPES = ['ASSET' => 'Activo', 'LIABILITY' => 'Pasivo', 'EQUITY' => 'Patrimonio', 'INCOME' => 'Ingreso', 'EXPENSE' => 'Gasto'];

    /** Código => [nombre, tipo, imputable]. La naturaleza sale del tipo: activo y gasto, deudora. */
    public const PCGE = [
        '10' => ['Efectivo y equivalentes de efectivo', 'ASSET', false],
        '1011' => ['Caja', 'ASSET', true],
        '1041' => ['Cuentas corrientes operativas – moneda nacional', 'ASSET', true],
        '10412' => ['Cuentas corrientes operativas – moneda extranjera', 'ASSET', true],
        '1042' => ['Cuentas corrientes para fines específicos (detracciones)', 'ASSET', true],
        '12' => ['Cuentas por cobrar comerciales – terceros', 'ASSET', false],
        '1212' => ['Facturas, boletas y otros comprobantes por cobrar – emitidas en cartera', 'ASSET', true],
        '122' => ['Anticipos de clientes', 'LIABILITY', true],
        '16' => ['Cuentas por cobrar diversas – terceros', 'ASSET', false],
        '1629' => ['Otras cuentas por cobrar diversas (detracciones y retenciones por recibir)', 'ASSET', true],
        '40' => ['Tributos por pagar', 'LIABILITY', false],
        '40111' => ['IGV – Cuenta propia', 'LIABILITY', true],
        '40113' => ['IGV – Régimen de retenciones', 'LIABILITY', true],
        '40172' => ['Renta de cuarta categoría – retenciones', 'LIABILITY', true],
        '42' => ['Cuentas por pagar comerciales – terceros', 'LIABILITY', false],
        '4212' => ['Facturas, boletas y otros comprobantes por pagar – emitidas', 'LIABILITY', true],
        '50' => ['Capital', 'EQUITY', false],
        '5011' => ['Acciones', 'EQUITY', true],
        '59' => ['Resultados acumulados', 'EQUITY', false],
        '5911' => ['Utilidades acumuladas', 'EQUITY', true],
        // Para el cierre de un ejercicio con pérdida.
        '5921' => ['Pérdidas acumuladas', 'EQUITY', true],
        '62' => ['Gastos de personal', 'EXPENSE', false],
        '6211' => ['Sueldos y salarios', 'EXPENSE', true],
        '63' => ['Gastos de servicios prestados por terceros', 'EXPENSE', false],
        '6311' => ['Transporte de carga y movilidad', 'EXPENSE', true],
        '6343' => ['Mantenimiento y reparaciones', 'EXPENSE', true],
        '6352' => ['Alquiler de edificaciones', 'EXPENSE', true],
        '6361' => ['Energía eléctrica', 'EXPENSE', true],
        '6364' => ['Telefonía e internet', 'EXPENSE', true],
        '6391' => ['Gastos bancarios', 'EXPENSE', true],
        '6392' => ['Servicios de terceros subcontratados', 'EXPENSE', true],
        '6394' => ['Licencias y servicios cloud', 'EXPENSE', true],
        '65' => ['Otros gastos de gestión', 'EXPENSE', false],
        '6563' => ['Suministros y repuestos', 'EXPENSE', true],
        '67' => ['Gastos financieros', 'EXPENSE', false],
        '6761' => ['Pérdida por diferencia de cambio', 'EXPENSE', true],
        '70' => ['Ventas', 'INCOME', false],
        '7041' => ['Prestación de servicios – terceros', 'INCOME', true],
        '77' => ['Ingresos financieros', 'INCOME', false],
        '7761' => ['Ganancia por diferencia de cambio', 'INCOME', true],
    ];

    /** Nombres por código, por empresa, para no consultarlos fila por fila. */
    private static array $names = [];

    public static function nature(string $type): string
    {
        return in_array($type, ['ASSET', 'EXPENSE'], true) ? 'D' : 'C';
    }

    /** Siembra el PCGE si la empresa aún no tiene plan de cuentas. No toca lo que ya tiene. */
    public static function ensure(string $tenantId): void
    {
        if (! DB::table('acc_accounts')->where('tenant_id', $tenantId)->exists()) {
            $rows = [];
            foreach (self::PCGE as $code => [$name, $type, $postable]) {
                $code = (string) $code;
                $rows[] = [
                    'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'account_type' => $type, 'nature' => self::nature($type),
                    'postable' => $postable, 'parent_code' => self::parentOf($code), 'active' => true, 'is_system' => true,
                ];
            }
            DB::table('acc_accounts')->insertOrIgnore($rows);
        }
    }

    /** La cuenta título de la que cuelga: el código más largo del PCGE que sea prefijo del suyo. */
    public static function parentOf(string $code, ?array $codes = null): ?string
    {
        $codes ??= array_map('strval', array_keys(self::PCGE));
        $parent = null;
        foreach ($codes as $candidate) {
            if ($candidate !== $code && str_starts_with($code, $candidate) && strlen($candidate) > strlen((string) $parent)) {
                $parent = $candidate;
            }
        }

        return $parent;
    }

    /** Nombre de una cuenta de la empresa (o el código si no existe). */
    public static function name(string $tenantId, ?string $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }
        if (! isset(self::$names[$tenantId])) {
            self::ensure($tenantId);
            self::$names[$tenantId] = DB::table('acc_accounts')->where('tenant_id', $tenantId)->pluck('name', 'code')->all();
        }

        return self::$names[$tenantId][$code] ?? $code;
    }

    /** "6392 · Servicios de terceros subcontratados". */
    public static function label(string $tenantId, ?string $code): string
    {
        return $code === null || $code === '' ? '' : "{$code} · ".self::name($tenantId, $code);
    }

    /** Cuenta imputable y activa de la empresa, opcionalmente de ciertos tipos. */
    public static function postable(string $tenantId, ?string $code, array $types = []): bool
    {
        if ($code === null || $code === '') {
            return false;
        }
        self::ensure($tenantId);

        return DB::table('acc_accounts')->where('tenant_id', $tenantId)->where('code', $code)->where('postable', true)->where('active', true)
            ->when($types, fn ($q) => $q->whereIn('account_type', $types))->exists();
    }

    /** Cuentas imputables y activas, para los formularios. */
    public static function options(string $tenantId, array $types = []): array
    {
        self::ensure($tenantId);

        return DB::table('acc_accounts')->where('tenant_id', $tenantId)->where('postable', true)->where('active', true)
            ->when($types, fn ($q) => $q->whereIn('account_type', $types))
            ->orderBy('code')->get(['code', 'name', 'account_type'])
            ->map(fn ($account) => ['code' => $account->code, 'name' => $account->name, 'type' => $account->account_type])->all();
    }

    /** Se olvida lo leído (al crear o renombrar una cuenta, y entre pruebas). */
    public static function forget(): void
    {
        self::$names = [];
    }
}
