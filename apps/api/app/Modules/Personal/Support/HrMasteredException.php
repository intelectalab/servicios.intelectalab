<?php

namespace App\Modules\Personal\Support;

use RuntimeException;

/** Se intentó editar en el ERP algo que mantiene el SaaS de RRHH (RN-HRM-06): 422 HR_MASTERED. */
class HrMasteredException extends RuntimeException
{
    public function render()
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'HR_MASTERED'], 422);
    }
}
