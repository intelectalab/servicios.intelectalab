<?php

namespace App\Modules\Services\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Services\Models\Contract;
use App\Modules\Services\Support\ContractSignatures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Firma electrónica desde la ficha del contrato: enviar a firma, firmar por la empresa (con código
 * al correo) y anular. Cada acción devuelve la ficha del contrato actualizada.
 */
class ContractSignatureController extends Controller
{
    public function index(string $contract)
    {
        $contract = $this->find($contract);

        return response()->json(ContractSignatures::forContract($contract->id)->map(fn ($request) => ContractSignatures::present($request))->values());
    }

    public function store(string $contract, Request $request)
    {
        $contract = $this->find($contract);
        $data = $request->validate(['portal_user_id' => ['nullable', 'uuid']]);
        ContractSignatures::send($contract->id, $request->user()->id, $data['portal_user_id'] ?? null);

        return $this->contract($contract->id);
    }

    public function code(string $contract, string $signatureRequest, Request $request)
    {
        ContractSignatures::sendCode($this->request($contract, $signatureRequest), $request->user(), 'EMPRESA');

        return response()->json(['message' => 'Te enviamos un código a tu correo.']);
    }

    public function sign(string $contract, string $signatureRequest, Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10'],
            'accept' => ['accepted'],
        ], ['code.required' => 'Escribe el código de tu correo.', 'accept.accepted' => 'Debes aceptar los términos del contrato.']);
        $signature = $this->request($contract, $signatureRequest);
        ContractSignatures::sign($signature, $request->user(), 'EMPRESA', $data + ['typed_name' => $request->user()->name], $request->ip(), $request->userAgent());

        return $this->contract($signature->contract_id);
    }

    public function cancel(string $contract, string $signatureRequest, Request $request)
    {
        $signature = $this->request($contract, $signatureRequest);
        ContractSignatures::cancel($signature, $request->user()->id);

        return $this->contract($signature->contract_id);
    }

    /** Por su modelo: un contrato fuera del alcance de la persona "no existe". */
    private function find(string $contract): Contract
    {
        abort_unless(Str::isUuid($contract), 404);

        return Contract::findOrFail($contract);
    }

    private function request(string $contract, string $signatureRequest): object
    {
        $contract = $this->find($contract);
        abort_unless(Str::isUuid($signatureRequest), 404);

        return DB::table('contract_signature_requests')->where('id', $signatureRequest)->where('contract_id', $contract->id)->first() ?? abort(404);
    }

    private function contract(string $contractId)
    {
        return app(ContractController::class)->show($contractId);
    }
}
