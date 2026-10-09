<?php

namespace Modules\POS\Fiscal;

use App\Core\DocumentTemplates\Codes;
use App\Core\Fiscal\FiscalQueue;
use Modules\POS\Models\Refund;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleVoid;

/**
 * POS-10: what the tax authority says about a sale, its applied refunds
 * and its void. Each is `null` when the company does not transmit, else
 * `{status: pending | accepted | rejected, invoice_number, accepted_at,
 * authority}` with the authority's references (receipt signature,
 * internal data, QR content). Read by the till (for its receipt) and the
 * back office (the sale's detail). An accepted document's QR content
 * also comes drawn (`qr_svg`, a data URI): the till prints it in the
 * receipt template's fiscal block (TPL-03) without a QR library.
 */
class SaleFiscalStatus
{
    public function __construct(private readonly FiscalQueue $queue, private readonly Codes $codes) {}

    /** @return array<string, mixed> */
    public function of(Sale $sale): array
    {
        $transmits = $this->queue->transmits($sale->company_id);
        $status = fn (string $type, string $id) => $this->withQr($this->queue->statusFor(PosFiscalSource::KEY, $type, $id))
            ?? ($transmits ? ['status' => 'pending', 'invoice_number' => null, 'accepted_at' => null, 'authority' => []] : null);

        return [
            'sale_id' => $sale->id,
            'transmits' => $transmits,
            'sale' => $status('sale', $sale->id),
            'refunds' => Refund::query()->where('sale_id', $sale->id)->where('status', 'applied')->orderBy('refunded_at')->get(['id', 'receipt_number'])
                ->map(fn (Refund $refund) => ['id' => $refund->id, 'receipt_number' => $refund->receipt_number, 'fiscal' => $status('refund', $refund->id)])->values()->all(),
            'void' => ($void = SaleVoid::query()->where('sale_id', $sale->id)->where('status', 'applied')->value('id')) === null
                ? null : ['id' => $void, 'fiscal' => $status('void', $void)],
        ];
    }

    private function withQr(?array $status): ?array
    {
        $qr = $status['authority']['qr'] ?? null;

        return is_string($qr) && $qr !== '' ? [...$status, 'qr_svg' => $this->codes->qrDataUri($qr)] : $status;
    }
}
