<?php

namespace App\Modules\Personal\HrSaas;

/**
 * Simulador del SaaS de RRHH (FakeHrSaasGateway del contrato, §7): responde con los ejemplos del
 * contrato y guarda lo que el ERP le envía. Sirve para las pruebas y para probar la pantalla de
 * Sincronización mientras el SaaS de RRHH no exista. Nunca se usa en producción
 * (HR_SAAS_DRIVER=fake solo funciona fuera de producción).
 */
class FakeHrSaasGateway implements HrSaasGateway
{
    /** @var array<string, array<int, array>> */
    public static array $lists = [];

    /** Eventos que el ERP envió. */
    public static array $sent = [];

    /** Respuesta del próximo envío: true confirma; false responde 500. */
    public static bool $ack = true;

    public static function reset(): void
    {
        self::$lists = self::examples();
        self::$sent = [];
        self::$ack = true;
    }

    public function fetch(object $connection, string $list, int $page = 1, array $query = []): array
    {
        $items = (self::$lists ?: self::$lists = self::examples())[$list] ?? [];
        $chunks = array_chunk($items, 50);

        return ['items' => $chunks[$page - 1] ?? [], 'next_page' => $page < count($chunks) ? $page + 1 : null];
    }

    public function send(object $connection, array $envelope): array
    {
        self::$sent[] = $envelope;

        return self::$ack
            ? ['ok' => true, 'code' => 200, 'body' => '{"ack":true}', 'error' => null]
            : ['ok' => false, 'code' => 500, 'body' => '{"error":"simulado"}', 'error' => 'El SaaS de RRHH respondió 500.'];
    }

    /** Los ejemplos del contrato (personas ficticias). */
    public static function examples(): array
    {
        $employee = fn (string $id, string $dni, string $given, string $family, string $position, string $cost, string $email) => [
            'external_id' => $id, 'external_version' => 1, 'doc_type' => 'DNI', 'doc_number' => $dni,
            'given_names' => $given, 'family_name' => $family, 'email' => $email, 'phone' => null,
            'position_external_id' => $position, 'branch_code' => null, 'hire_date' => '2026-01-05',
            'contract_type' => 'INDEFINIDO', 'weekly_hours' => 48, 'hourly_cost' => $cost, 'currency' => 'PEN',
        ];

        return [
            'positions' => [
                ['external_id' => 'AYNI-POS-01', 'external_version' => 1, 'code' => 'TEC', 'name' => 'Técnico de campo', 'department' => 'Operaciones', 'default_hourly_cost' => '22.50', 'active' => true],
                ['external_id' => 'AYNI-POS-02', 'external_version' => 1, 'code' => 'SUP', 'name' => 'Supervisor de operaciones', 'department' => 'Operaciones', 'default_hourly_cost' => '35.00', 'active' => true],
            ],
            'employees' => [
                $employee('AYNI-EMP-0001', '70000001', 'Rocío', 'Quispe Mamani', 'AYNI-POS-01', '24.80', 'rocio.quispe@ejemplo.pe'),
                $employee('AYNI-EMP-0002', '70000002', 'Julio', 'Ccori Huamán', 'AYNI-POS-01', '23.10', 'julio.ccori@ejemplo.pe'),
                $employee('AYNI-EMP-0003', '70000003', 'Elena', 'Torres Vílchez', 'AYNI-POS-02', '36.40', 'elena.torres@ejemplo.pe'),
            ],
            'leaves' => [
                ['external_id' => 'AYNI-LEA-0001', 'external_version' => 1, 'employee_external_id' => 'AYNI-EMP-0002', 'leave_type_code' => 'VAC',
                    'date_from' => now('America/Lima')->addDays(10)->toDateString(), 'date_to' => now('America/Lima')->addDays(14)->toDateString(), 'days' => 5, 'status' => 'APPROVED'],
            ],
        ];
    }
}
