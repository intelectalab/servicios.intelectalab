<?php

namespace App\Modules\Personal\HrSaas;

/**
 * El otro lado del contrato con el SaaS de RRHH (docs/14-INTEGRACION-RRHH.md del kit, §4 y §6).
 * HttpHrSaasGateway llama de verdad; FakeHrSaasGateway responde con los ejemplos del contrato
 * (pruebas y desarrollo, mientras el SaaS de RRHH no exista).
 */
interface HrSaasGateway
{
    /**
     * Una página de una lista del SaaS de RRHH (`employees`, `positions` o `leaves`).
     *
     * @return array{items: array<int, array>, next_page: ?int}
     */
    public function fetch(object $connection, string $list, int $page = 1, array $query = []): array;

    /**
     * Envía un evento firmado (§4). Responde si el SaaS de RRHH lo confirmó (`{"ack": true}`).
     *
     * @return array{ok: bool, code: ?int, body: ?string, error: ?string}
     */
    public function send(object $connection, array $envelope): array;
}
