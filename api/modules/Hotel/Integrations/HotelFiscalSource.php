<?php

namespace Modules\Hotel\Integrations;

use App\Core\Fiscal\Contracts\FiscalDocumentSource;
use App\Core\Fiscal\FiscalDocument;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use Modules\Hotel\Folios\InvoiceLines;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Guest;
use Modules\Hotel\Models\Payment;
use Modules\Hotel\Models\Stay;

/**
 * HOT-27: a settled folio's invoice for the core fiscal queue
 * (FiscalDocumentSource, key `hotel`), built from the module's own tables in
 * the document's tenant. The document is a `sale` named by the folio's id:
 * the hotel's own charges (InvoiceLines), each with the tax code and the
 * rate it was posted with, nothing recomputed. A line with a levy on top of
 * VAT carries its first code and the sum of its taxes (how an authority
 * bands a levy is **to confirm**, docs/modules/hotel.md). The buyer is the
 * guest's customer party (TIN and name).
 */
final class HotelFiscalSource implements FiscalDocumentSource
{
    public const KEY = 'hotel';

    public function key(): string
    {
        return self::KEY;
    }

    public function document(string $documentType, string $documentId): FiscalDocument
    {
        $folio = Folio::query()->findOrFail($documentId);
        $lines = app(InvoiceLines::class)->of($folio->id);
        $codes = Item::query()->whereKey($lines->pluck('item_id')->filter()->unique()->all())->pluck('code', 'id');
        $guest = $folio->guest_id === null ? null : Guest::query()->find($folio->guest_id);
        $party = $guest === null ? null : Party::query()->find($guest->party_id);
        $rows = $lines->values()->map(function (FolioLine $line, int $index) use ($codes) {
            $first = $line->taxes[0] ?? null;

            return [
                'line_no' => $index + 1,
                'item_id' => $line->item_id,
                'item_code' => $line->item_id === null ? null : ($codes[$line->item_id] ?? null),
                'item_name' => $line->description,
                'qty' => (string) $line->qty,
                'unit_price_minor' => (int) ($line->unit_price_minor ?? $line->amount_minor),
                'discount_minor' => 0,
                'tax_code_id' => $first['tax_code_id'] ?? null,
                'tax_rate' => $first['rate'] ?? null,
                'tax_minor' => (int) $line->tax_minor,
                'total_minor' => (int) $line->amount_minor,
            ];
        })->all();
        $stay = $folio->stay_id === null ? null : Stay::query()->find($folio->stay_id);
        $paid = Payment::query()->where('folio_id', $folio->id)->where('status', Payment::SETTLED)->whereIn('kind', [Payment::PAYMENT, Payment::DEPOSIT])->orderByDesc('amount_minor')->first();

        return FiscalDocument::fromArray(array_filter([
            'type' => 'sale',
            'id' => $folio->id,
            'number' => $folio->invoice_number,
            'issued_at' => ($folio->settled_at ?? now())->toIso8601String(),
            'company_id' => $folio->company_id,
            'branch_id' => $folio->branch_id,
            'currency' => $folio->currency,
            'customer' => $party === null ? null : array_filter(['tin' => $party->tax_id, 'name' => $party->name]),
            'payment_type' => match ($paid?->method_type) {
                'mobile_money' => 'mobile_money',
                'card' => 'card',
                'cash' => 'cash',
                default => 'other',
            },
            'lines' => $rows,
            'totals' => ['tax_minor' => (int) array_sum(array_column($rows, 'tax_minor')), 'total_minor' => (int) array_sum(array_column($rows, 'total_minor'))],
            'stay' => $stay === null ? null : ['arrival_on' => $stay->arrival_on->toDateString(), 'departure_on' => $stay->departure_on->toDateString()],
        ], fn ($value) => $value !== null));
    }
}
