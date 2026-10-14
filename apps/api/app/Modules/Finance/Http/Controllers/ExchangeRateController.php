<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Support\SunatExchangeRate;
use App\Modules\Services\Models\Quotation;
use App\Services\AuditLogger;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ExchangeRateController extends Controller
{
    private const CURRENCY = 'USD';

    public function index(Request $request)
    {
        $query = ExchangeRate::where('currency', self::CURRENCY);

        ListSort::apply($query, $request, [
            'date' => 'rate_date',
            'buy' => 'buy',
            'sell' => 'sell',
            'source' => 'source',
        ], fn ($q) => $q->orderByDesc('rate_date'));

        return response()->json($query->paginate($request->integer('per_page', 15)));
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

    /**
     * Trae el tipo de cambio que publica SUNAT y lo guarda para la fecha que SUNAT indica. Uno por
     * día: si ese día ya tenía uno (aunque fuera manual), lo corrige. Si SUNAT no responde, no se
     * guarda nada.
     */
    public function sync(Request $request)
    {
        try {
            $published = SunatExchangeRate::fetch();
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['sunat' => [$e->getMessage()]]);
        }

        $tenantId = $request->attributes->get('tenant_id');
        $existing = ExchangeRate::where('tenant_id', $tenantId)->where('currency', self::CURRENCY)->where('rate_date', $published['date'])->first();
        $unchanged = $existing && $existing->source === 'SUNAT'
            && abs((float) $existing->buy - $published['buy']) < 0.00005 && abs((float) $existing->sell - $published['sell']) < 0.00005;

        if (! $unchanged) {
            $before = $existing ? ['buy' => (float) $existing->buy, 'sell' => (float) $existing->sell, 'source' => $existing->source] : null;
            $existing = ExchangeRate::updateOrCreate(
                ['tenant_id' => $tenantId, 'currency' => self::CURRENCY, 'rate_date' => $published['date']],
                ['buy' => $published['buy'], 'sell' => $published['sell'], 'source' => 'SUNAT', 'created_by' => $request->user()->id],
            );
            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'exchange_rate.sync',
                entityType: 'ExchangeRate',
                entityId: $existing->id,
                beforeData: $before,
                afterData: ['buy' => $published['buy'], 'sell' => $published['sell'], 'source' => 'SUNAT'],
                metadata: ['date' => $published['date'], 'buy' => $published['buy'], 'sell' => $published['sell']],
            );
        }

        return response()->json(['rate' => $existing->fresh(), 'unchanged' => $unchanged]);
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
