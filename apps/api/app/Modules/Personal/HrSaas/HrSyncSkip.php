<?php

namespace App\Modules\Personal\HrSaas;

use RuntimeException;

/** Un mensaje del SaaS de RRHH que no se aplica, con el motivo que se muestra en Sincronización. */
class HrSyncSkip extends RuntimeException
{
    public function __construct(string $message, public readonly string $outcome = 'SKIPPED')
    {
        parent::__construct($message);
    }
}
