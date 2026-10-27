<?php

namespace Modules\Hotel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Folios\Folios;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Payment;

/**
 * HOT-20: a folio with its totals; in the detail, its lines (charges
 * positive, payments negative), pending payments and the settlement invoice.
 * Amounts are minor-unit strings in the folio's currency.
 *
 * @mixin Folio
 */
class FolioResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Folio $f */
        $f = $this->resource;
        $totals = app(Folios::class)->totals($f);

        return [
            'id' => $f->id,
            'number' => $f->number,
            'kind' => $f->kind,
            'status' => $f->status,
            'currency' => $f->currency,
            'branch_id' => $f->branch_id,
            'reservation_id' => $f->reservation_id,
            'reservation_number' => $f->relationLoaded('reservation') ? $f->reservation?->number : null,
            'stay_id' => $f->stay_id,
            'room_number' => $f->relationLoaded('stay') ? $f->stay?->room?->number : null,
            'guest' => $f->relationLoaded('guest') && $f->guest !== null ? ['id' => $f->guest->id, 'name' => $f->guest->name] : ($f->guest_id === null ? null : ['id' => $f->guest_id]),
            'charges_minor' => (string) $totals['charges_minor'],
            'payments_minor' => (string) $totals['payments_minor'],
            'balance_minor' => (string) $totals['balance_minor'],
            'settled_at' => Refs::at($f->settled_at),
            'invoice_number' => $f->invoice_number,
            'balance_left_minor' => $f->balance_left_minor === null ? null : (string) $f->balance_left_minor,
            ...($this->detail ? [
                'lines' => $f->lines->map(fn (FolioLine $l) => self::line($l))->values()->all(),
                'pending_payments' => Payment::query()->where('folio_id', $f->id)->where('status', Payment::PENDING)->get()->map(fn (Payment $p) => [
                    'id' => $p->id, 'kind' => $p->kind, 'amount_minor' => (string) $p->amount_minor, 'method_type' => $p->method_type, 'created_at' => Refs::at($p->created_at),
                ])->values()->all(),
            ] : []),
        ];
    }

    /** @return array<string, mixed> */
    public static function line(FolioLine $l): array
    {
        return [
            'id' => $l->id,
            'line_no' => $l->line_no,
            'kind' => $l->kind,
            'description' => $l->description,
            'qty' => rtrim(rtrim((string) $l->qty, '0'), '.') ?: '0',
            'amount_minor' => (string) $l->amount_minor,
            'net_minor' => (string) $l->net_minor,
            'tax_minor' => (string) $l->tax_minor,
            'taxes' => array_map(fn (array $t) => [...$t, 'amount_minor' => (string) $t['amount_minor']], (array) $l->taxes),
            'currency' => $l->currency,
            'business_date' => Refs::day($l->business_date),
            'source' => $l->source,
            'reference' => $l->reference,
            'reverses_id' => $l->reverses_id,
            'flags' => array_values((array) $l->flags),
            'created_at' => Refs::at($l->created_at),
        ];
    }
}
