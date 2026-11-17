<?php

namespace App\Modules\Personal\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas compartidas de Personal: tipos de ausencia por defecto, quién manda sobre el personal
 * (el ERP o el SaaS de RRHH), costo por hora en un solo lugar y el bloqueo de la agenda por
 * una ausencia aprobada.
 */
class Personal
{
    public const CONTRACT_TYPES = [
        'INDEFINIDO' => 'Indefinido',
        'PLAZO_FIJO' => 'Plazo fijo',
        'TIEMPO_PARCIAL' => 'Tiempo parcial',
        'PRACTICAS' => 'Prácticas',
    ];

    public const EMPLOYEE_STATES = ['ACTIVE' => 'Activo', 'INACTIVE' => 'Inactivo', 'TERMINATED' => 'Cesado'];

    public const LEAVE_STATES = ['REQUESTED' => 'Solicitada', 'APPROVED' => 'Aprobada', 'REJECTED' => 'Rechazada', 'CANCELLED' => 'Anulada'];

    /** Tipos con los que nace cada empresa (la empresa los edita y agrega los suyos). */
    public const DEFAULT_LEAVE_TYPES = [
        ['VAC', 'Vacaciones', true, true, 30],
        ['DM', 'Descanso médico', true, true, null],
        ['LIC', 'Licencia con goce', true, true, null],
        ['LSG', 'Licencia sin goce', false, true, null],
        ['PER', 'Permiso personal', true, true, null],
    ];

    /** Siembra los tipos de ausencia por defecto la primera vez que la empresa los necesita. */
    public static function ensureLeaveTypes(string $tenantId): void
    {
        if (DB::table('hr_leave_types')->where('tenant_id', $tenantId)->exists()) {
            return;
        }
        foreach (self::DEFAULT_LEAVE_TYPES as [$code, $name, $paid, $approval, $days]) {
            DB::table('hr_leave_types')->insertOrIgnore([
                'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'paid' => $paid,
                'requires_approval' => $approval, 'days_per_year' => $days, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** La conexión con el SaaS de RRHH activa (modo RRHH maestro), o null (el ERP manda). */
    public static function activeConnection(string $tenantId): ?object
    {
        return DB::table('hr_connections')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->first();
    }

    /** 422 HR_MASTERED: el registro lo mantiene el SaaS de RRHH (RN-HRM-06). */
    public static function mastered(string $what = 'Este trabajador'): never
    {
        throw new HrMasteredException("{$what} se edita en el SaaS de RRHH: aquí solo se puede cambiar el usuario vinculado y la sede operativa.");
    }

    /**
     * Costo por hora de la persona para valorizar sus horas: el de su ficha de trabajador y, si no
     * es trabajador (o no lo tiene), el de su usuario como hasta ahora.
     */
    public static function hourlyCost(string $tenantId, string $userId): float
    {
        $employee = DB::table('employees')->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->whereNotNull('hourly_cost')->value('hourly_cost');

        return $employee !== null
            ? (float) $employee
            : (float) DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $userId)->value('hourly_cost');
    }

    /** El costo por hora vive en un solo lugar: lo que cambia en el trabajador se copia a su usuario. */
    public static function copyCostToUser(string $tenantId, ?string $userId, $hourlyCost): void
    {
        if ($userId && $hourlyCost !== null) {
            DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->update(['hourly_cost' => $hourlyCost, 'updated_at' => now()]);
        }
    }

    /** …y lo que cambia en Usuarios, a su trabajador (si lo mantiene el ERP). */
    public static function copyCostToEmployee(string $tenantId, string $userId, $hourlyCost): void
    {
        DB::table('employees')->where('tenant_id', $tenantId)->where('user_id', $userId)->where('source', 'LOCAL')
            ->update(['hourly_cost' => $hourlyCost > 0 ? round((float) $hourlyCost, 2) : null, 'updated_at' => now()]);
    }

    /** ¿El usuario tiene un trabajador mantenido por el SaaS de RRHH? (su costo no se edita en Usuarios). */
    public static function userMastered(string $tenantId, string $userId): bool
    {
        return DB::table('employees')->where('tenant_id', $tenantId)->where('user_id', $userId)->where('source', 'HR_SAAS')->exists();
    }

    /**
     * Ausencia aprobada de la persona que toca ese rango (fechas de Lima), o null.
     *
     * @return object{date_from: string, date_to: string, type: string, name: string}|null
     */
    public static function leaveOverlapping(string $tenantId, ?string $userId, CarbonInterface|string|null $from, CarbonInterface|string|null $to = null): ?object
    {
        if (! $userId || ! $from) {
            return null;
        }
        $start = self::limaDate($from);
        $end = $to ? self::limaDate($to) : $start;
        if ($end < $start) {
            $end = $start;
        }
        if (! DB::table('tenant_modules')->where('tenant_id', $tenantId)->whereRaw("upper(module_code) = 'PERSONAL'")->where('enabled', true)->exists()) {
            return null;
        }

        return DB::table('hr_leave_requests as r')
            ->join('hr_leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->join('employees as e', 'e.id', '=', 'r.employee_id')
            ->join('persons as p', 'p.party_id', '=', 'e.person_party_id')
            ->where('r.tenant_id', $tenantId)->where('e.user_id', $userId)->where('r.status', 'APPROVED')
            ->where('r.date_from', '<=', $end)->where('r.date_to', '>=', $start)
            ->orderBy('r.date_from')
            ->first(['r.date_from', 'r.date_to', 't.name as type', DB::raw("btrim(p.given_names || ' ' || p.family_name) as name")]);
    }

    /** 422 si la persona está ausente en ese rango: no se le asigna trabajo (RN-HRM-03). */
    public static function assertAvailable(string $tenantId, ?string $userId, CarbonInterface|string|null $from, CarbonInterface|string|null $to = null, string $field = 'assigned_user_id'): void
    {
        $leave = self::leaveOverlapping($tenantId, $userId, $from, $to);
        if ($leave) {
            throw ValidationException::withMessages([$field => [self::absenceMessage($leave)]]);
        }
    }

    public static function absenceMessage(object $leave): string
    {
        $from = Carbon::parse($leave->date_from)->format('d/m/Y');
        $to = Carbon::parse($leave->date_to)->format('d/m/Y');
        $range = $from === $to ? "el {$from}" : "del {$from} al {$to}";

        return "{$leave->name} está ausente {$range} ({$leave->type}). Elige otra persona u otra fecha.";
    }

    /** Días de calendario entre dos fechas, ambos inclusive. */
    public static function days(string $from, string $to): int
    {
        return Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
    }

    private static function limaDate(CarbonInterface|string $value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return Carbon::parse($value)->setTimezone('America/Lima')->toDateString();
    }
}
