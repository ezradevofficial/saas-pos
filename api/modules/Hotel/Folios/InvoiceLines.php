<?php

namespace Modules\Hotel\Folios;

use Illuminate\Support\Collection;
use Modules\Hotel\Models\FolioLine;

/**
 * HOT-27: what a folio's settlement invoice is made of: the hotel's own
 * charges (rooms, extras, fees, including those moved onto it from another
 * folio) that nobody reversed. POS sales charged to the room were receipted
 * and fiscalised at the till, so they are never on the invoice; payments and
 * deposits are how it is paid, not what it is for.
 */
class InvoiceLines
{
    /** @return Collection<int, FolioLine> */
    public function of(string $folioId): Collection
    {
        $lines = FolioLine::query()->where('folio_id', $folioId)->orderBy('line_no')->get();
        $reversed = $lines->pluck('reverses_id')->filter()->flip();

        return $lines->filter(fn (FolioLine $line) => ! $reversed->has($line->id) && (
            in_array($line->kind, FolioLine::HOTEL_CHARGES, true)
            || ($line->kind === FolioLine::TRANSFER_IN && in_array($line->reference, FolioLine::HOTEL_CHARGES, true))
        ))->values();
    }

    /** @return array{gross_minor: int, net_minor: int, tax_minor: int} */
    public function totals(Collection $lines): array
    {
        return ['gross_minor' => (int) $lines->sum('amount_minor'), 'net_minor' => (int) $lines->sum('net_minor'), 'tax_minor' => (int) $lines->sum('tax_minor')];
    }
}
