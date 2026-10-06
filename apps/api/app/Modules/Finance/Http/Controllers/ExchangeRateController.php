<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Services\Models\Quotation;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class ExchangeRateController extends Controller
{
    private const CURRENCY = 'USD';

    public function index(Request $request)
    {
        $rates = ExchangeRate::where('currency', self::CURRENCY)
            ->orderByDesc('rate_date')
            ->paginate($request->integer('per_page', 15));

        return response()->json($rates);
    }

    /** Tipo de cambio vigente hoy; `null` si aún no se registró ninguno. */
    public function current()
    {
        return response()->json(ExchangeRate::current(self::CURRENCY));
    }

    /** Indicadores de la pantalla "Monedas y tipo de cambio". */
    public function summary()
    {
        $current = ExchangeRate::current(self::CURRENCY);

        // Variación entre el primer y el último tipo de cambio de los últimos 30 registrados.
        $last = ExchangeRate::where('currency', self::CURRENCY)->orderByDesc('rate_date')->limit(30)->pluck('sell');
        $variation = $last->count() > 1 && (float) $last->last() > 0
            ? round(((float) $last->first() / (float) $last->last() - 1) * 100, 2)
            : null;

        return response()->json([
            'current' => $current,
            'variation_30' => $variation,
            'quotations_usd' => Quotation::where('currency', self::CURRENCY)->count(),
            'invoices_usd' => Invoice::where('currency', self::CURRENCY)->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'buy' => ['required', 'numeric', 'gt:0', 'lt:100'],
            'sell' => ['required', 'numeric', 'gt:0', 'lt:100'],
        ], [
            'date.before_or_equal' => 'La fecha no puede ser futura.',
            'buy.gt' => 'La compra debe ser mayor que cero.',
            'sell.gt' => 'La venta debe ser mayor que cero.',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $rate = ExchangeRate::updateOrCreate(
            ['tenant_id' => $tenantId, 'currency' => self::CURRENCY, 'rate_date' => $data['date']],
            ['buy' => $data['buy'], 'sell' => $data['sell'], 'source' => 'Manual', 'created_by' => $request->user()->id],
        );

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'exchange_rate.save',
            entityType: 'ExchangeRate',
            entityId: $rate->id,
            metadata: ['date' => $data['date'], 'buy' => (float) $data['buy'], 'sell' => (float) $data['sell']],
        );

        return response()->json($rate, $rate->wasRecentlyCreated ? 201 : 200);
    }
}
