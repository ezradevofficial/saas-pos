<?php

namespace Modules\Hotel\Folios;

use Carbon\CarbonImmutable;
use Modules\Hotel\Events\HotelPosting;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Guest;
use Modules\Hotel\Models\Payment;

/**
 * HOT-27: what a folio line means for the ledger, as fields of the posting
 * rules (docs/modules/hotel.md), in the folio's currency at the line's own
 * rate (CUR-04). Raised as HotelPosting after the line commits; Accounting
 * posts it only while it is active.
 *
 * `hotel.charge` (a room, extra or fee line; negative for its void):
 * `ledger` (the guest ledger, gross) on the debit side, `revenue.{rooms|
 * extras|fees}` (net) and `tax.{tax code}` on the credit side, so ledger =
 * Σ revenue + Σ tax exactly. `hotel.payment` (a payment; negative for a
 * refund): `tender.{payment method}` on the debit side and `ledger` on the
 * credit side. `hotel.deposit`: `tender.{payment method}` and `deposit` (the
 * guest deposits liability). `hotel.deposit_applied`: `deposit` on the debit
 * side and `ledger` on the credit side, when a deposit is applied at check-in,
 * cancellation or no-show. A POS sale charged to a room posts nothing here:
 * POS journals it (its room_charge tender debits the guest ledger).
 */
class Postings
{
    private const REVENUE = [FolioLine::ROOM => 'rooms', FolioLine::EXTRA => 'extras', FolioLine::FEE => 'fees'];

    /** A charge line (or its void, whose amounts are negative). */
    public function charge(Folio $folio, FolioLine $line, ?FolioLine $original = null): void
    {
        $kind = $original?->kind ?? $line->kind;
        $key = self::REVENUE[$kind] ?? null;

        if ($key === null) {
            return;
        }

        $amounts = ['ledger' => (string) $line->amount_minor, "revenue.{$key}" => (string) $line->net_minor];

        foreach ((array) $line->taxes as $tax) {
            $amounts['tax.'.$tax['tax_code_id']] = (string) ((int) ($amounts['tax.'.$tax['tax_code_id']] ?? 0) + (int) $tax['amount_minor']);
        }

        $this->raise($folio, $line, HotelPosting::CHARGE, $line->id, $amounts);
    }

    public function payment(Folio $folio, FolioLine $line): void
    {
        // A payment line is negative (it lowers the balance); a refund line is positive.
        $paid = -$line->amount_minor;

        if ($line->kind === FolioLine::DEPOSIT) {
            $this->raise($folio, $line, HotelPosting::DEPOSIT, $line->id, ['tender.'.$line->payment_method_id => (string) $paid, 'deposit' => (string) $paid]);

            return;
        }

        $this->raise($folio, $line, HotelPosting::PAYMENT, $line->id, ['tender.'.$line->payment_method_id => (string) $paid, 'ledger' => (string) $paid]);
    }

    public function depositApplied(Folio $folio, Payment $payment, FolioLine $deposit): void
    {
        $this->raise($folio, $deposit, HotelPosting::DEPOSIT_APPLIED, $payment->id, ['deposit' => (string) $payment->amount_minor, 'ledger' => (string) $payment->amount_minor]);
    }

    /** @param array<string, string> $amounts */
    private function raise(Folio $folio, FolioLine $line, string $type, string $postingId, array $amounts): void
    {
        HotelPosting::dispatch(
            $folio->tenant_id, $folio->company_id, $type, $postingId,
            CarbonImmutable::parse($line->business_date->toDateString().' 12:00:00', 'UTC')->toIso8601String(),
            $line->currency,
            array_filter($amounts, fn (string $minor) => $minor !== '0'),
            $line->fx === null || $line->fx->isIdentity() ? null : $line->fx->jsonSerialize(),
            array_filter(['branch_id' => $folio->branch_id, 'party_id' => $this->partyOf($folio)]),
            $folio->number,
            ['kind' => 'hotel.folio', 'id' => $folio->id],
        );
    }

    private function partyOf(Folio $folio): ?string
    {
        return $folio->guest_id === null ? null : Guest::query()->whereKey($folio->guest_id)->value('party_id');
    }
}
