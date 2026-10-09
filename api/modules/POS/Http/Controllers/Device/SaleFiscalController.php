<?php

namespace Modules\POS\Http\Controllers\Device;

use App\Core\Fiscal\FiscalQueue;
use Illuminate\Http\JsonResponse;
use Modules\POS\Fiscal\PosFiscalSource;
use Modules\POS\Http\Requests\Device\ShowSaleFiscalRequest;
use Modules\POS\Models\Refund;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleVoid;

/**
 * POS-10: what the tax authority says about a sale, its refunds and its
 * void, for the receipt (the till shows "pending" until accepted). Each is
 * `null` when the company does not transmit, else `{status: pending |
 * accepted | rejected, invoice_number, accepted_at, authority}` with the
 * authority's references (receipt signature, internal data, QR content).
 */
class SaleFiscalController
{
    public function __construct(private readonly FiscalQueue $queue) {}

    public function __invoke(ShowSaleFiscalRequest $request, Sale $posSale): JsonResponse
    {
        $transmits = $this->queue->transmits($posSale->company_id);
        $status = fn (string $type, string $id) => $this->queue->statusFor(PosFiscalSource::KEY, $type, $id)
            ?? ($transmits ? ['status' => 'pending', 'invoice_number' => null, 'accepted_at' => null, 'authority' => []] : null);

        return response()->json(['data' => [
            'sale_id' => $posSale->id,
            'sale' => $status('sale', $posSale->id),
            'refunds' => Refund::query()->where('sale_id', $posSale->id)->where('status', 'applied')->orderBy('refunded_at')->pluck('id')
                ->map(fn (string $id) => ['id' => $id, 'fiscal' => $status('refund', $id)])->values(),
            'void' => ($void = SaleVoid::query()->where('sale_id', $posSale->id)->where('status', 'applied')->value('id')) === null
                ? null : ['id' => $void, 'fiscal' => $status('void', $void)],
        ]]);
    }
}
