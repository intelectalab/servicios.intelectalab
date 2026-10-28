<?php

namespace App\Modules\Finance\Support;

use App\Http\Middleware\CheckPermission;
use App\Modules\Core\Http\Controllers\TenantSettingsController;
use App\Modules\Core\Models\Customer;
use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Finance\Models\Receivable;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Límite de crédito del cliente. La deuda es la suma de sus cuentas por cobrar pendientes
 * (toda la empresa, no solo lo que alcanza quien consulta), en la moneda del cliente: lo que
 * está en otra moneda se convierte al tipo de cambio vigente (venta).
 *
 * Se controla al comprometer trabajo (aprobar la cotización, activar el contrato), según la
 * regla de la empresa: "Solo avisar" pide un motivo; "Bloquear" no deja seguir salvo a quien
 * tenga `credit.approve`, que también deja motivo. Emitir un comprobante solo avisa.
 */
class CreditLimit
{
    public const WARN = 'WARN';
    public const BLOCK = 'BLOCK';

    /**
     * Crédito del cliente si además se le suma $amount (en $currency). Null si no tiene límite.
     *
     * @return array{limit: float, currency: string, debt: float, amount: float, available: float, exceeds: bool, excess: float, rate_missing: bool}|null
     */
    public static function status(Customer $customer, float $amount = 0, ?string $currency = null): ?array
    {
        if ($customer->credit_limit === null) {
            return null;
        }

        $limitCurrency = $customer->preferred_currency === 'USD' ? 'USD' : 'PEN';
        $rate = null;
        $rateMissing = false;
        // Lleva un monto a la moneda del cliente; sin tipo de cambio registrado no se puede sumar.
        $convert = function (float $value, ?string $from) use ($limitCurrency, &$rate, &$rateMissing): float {
            $from = $from === 'USD' ? 'USD' : 'PEN';
            if ($from === $limitCurrency || $value == 0.0) {
                return $value;
            }
            $rate ??= (float) (ExchangeRate::current('USD')?->sell ?? 0);
            if ($rate <= 0) {
                $rateMissing = true;

                return 0;
            }

            return $from === 'USD' ? $value * $rate : $value / $rate;
        };

        $debt = 0.0;
        $open = Receivable::ignoringAccessScope()->where('customer_id', $customer->id)
            ->whereIn('status', Receivable::OPEN)->get(['balance_amount', 'currency']);
        foreach ($open as $receivable) {
            $debt += $convert((float) $receivable->balance_amount, $receivable->currency);
        }
        $new = $convert($amount, $currency ?? $limitCurrency);
        $limit = (float) $customer->credit_limit;

        return [
            'limit' => round($limit, 2),
            'currency' => $limitCurrency,
            'debt' => round($debt, 2),
            'amount' => round($new, 2),
            'available' => round($limit - $debt, 2),
            'exceeds' => round($debt + $new, 2) > round($limit, 2),
            'excess' => round(max(0, $debt + $new - $limit), 2),
            'rate_missing' => $rateMissing,
        ];
    }

    public static function mode(string $tenantId): string
    {
        return TenantSettingsController::settings($tenantId)['credit_limit_mode'];
    }

    /**
     * Aviso para una ficha (cotización, contrato, comprobante): null si no supera el límite.
     * Los montos de la deuda solo los ve quien puede ver las cuentas por cobrar.
     */
    public static function warning(Request $request, ?Customer $customer, float $amount, ?string $currency): ?array
    {
        $status = $customer ? self::status($customer, $amount, $currency) : null;
        if (! $status || ! $status['exceeds']) {
            return null;
        }

        $tenantId = (string) $request->attributes->get('tenant_id');
        $blocked = self::mode($tenantId) === self::BLOCK && ! CheckPermission::granted($request, 'credit.approve');
        $amounts = CheckPermission::granted($request, 'receivables.view');

        return [
            'exceeds' => true,
            'mode' => self::mode($tenantId),
            'blocked' => $blocked,
            'message' => self::message($status, $amounts),
            ...($amounts ? ['limit' => $status['limit'], 'debt' => $status['debt'], 'excess' => $status['excess'], 'currency' => $status['currency']] : []),
        ];
    }

    /**
     * Al comprometer trabajo: si supera el límite, bloquea (regla "Bloquear" sin `credit.approve`)
     * o exige `credit_reason`. Devuelve el texto para el historial, o null si no lo supera.
     */
    public static function guard(Request $request, ?Customer $customer, float $amount, ?string $currency): ?string
    {
        $status = $customer ? self::status($customer, $amount, $currency) : null;
        if (! $status || ! $status['exceeds']) {
            return null;
        }

        $tenantId = (string) $request->attributes->get('tenant_id');
        $amounts = CheckPermission::granted($request, 'receivables.view');
        if (self::mode($tenantId) === self::BLOCK && ! CheckPermission::granted($request, 'credit.approve')) {
            throw ValidationException::withMessages([
                'credit_limit' => [self::message($status, $amounts).' La empresa bloquea seguir sobre el límite: debe autorizarlo quien tenga el permiso "Aprobar sobre el límite de crédito".'],
            ]);
        }

        $reason = trim((string) $request->input('credit_reason', ''));
        if ($reason === '') {
            throw ValidationException::withMessages([
                'credit_reason' => [self::message($status, $amounts).' Indica el motivo para continuar.'],
            ]);
        }
        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['credit_reason' => ['El motivo admite hasta 500 caracteres.']]);
        }

        return 'Sobre el límite de crédito ('.Notifier::money($status['excess'], $status['currency'])." de exceso): {$reason}";
    }

    private static function message(array $status, bool $amounts): string
    {
        if (! $amounts) {
            return 'Con esta operación el cliente supera su límite de crédito.';
        }

        return 'Con esta operación el cliente supera su límite de crédito ('.Notifier::money($status['limit'], $status['currency'])
            .') por '.Notifier::money($status['excess'], $status['currency']).'; hoy debe '.Notifier::money($status['debt'], $status['currency'])
            .($status['rate_missing'] ? ' sin contar lo que está en otra moneda (no hay tipo de cambio registrado)' : '').'.';
    }
}
