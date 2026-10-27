<?php

namespace Modules\Hotel\Folios;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Hotel\HotelServiceProvider;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Reservation;
use Modules\Hotel\Models\Settings;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Support\BaseAmounts;
use Modules\Hotel\Support\HotelError;
use Modules\Hotel\Support\Properties;
use Modules\Hotel\Support\Taxing;
use Modules\Hotel\Support\Text;

/**
 * HOT-20 to HOT-26: folios and their append-only ledger.
 *
 * - A guest folio per stay, a master folio per group reservation, numbered
 *   per branch (NUM-01).
 * - post() is the one way a line enters: under the folio's row lock, refused
 *   once the folio is settled, idempotent per `(source, source_id)` (a repeat
 *   returns the line it made), numbered in order, with the CUR-04 rate for a
 *   folio in a foreign currency. Charges raise their ledger posting.
 * - Voids, transfers and the balance live here; payments in FolioPayments,
 *   settlement in Settlement.
 */
class Folios
{
    public const NUMBER_TYPE = 'hotel.folio';

    public const NUMBER = 'FO-{BRANCH}-{YYYY}-{00001}';

    /** Lines that may be moved to another folio (HOT-25). */
    private const TRANSFERABLE = [FolioLine::ROOM, FolioLine::EXTRA, FolioLine::FEE, FolioLine::POS_CHARGE, FolioLine::TRANSFER_IN];

    /** Lines that may be voided by staff (HOT-21); a POS charge is reversed by the POS void or refund only. */
    private const VOIDABLE = [FolioLine::ROOM, FolioLine::EXTRA, FolioLine::FEE];

    public function __construct(
        private readonly Numbering $numbering,
        private readonly Auditor $auditor,
        private readonly Properties $properties,
        private readonly Taxing $taxing,
        private readonly BaseAmounts $base,
        private readonly Postings $postings,
    ) {}

    public static function numberType(): DocumentNumberType
    {
        return new DocumentNumberType(self::NUMBER_TYPE, HotelServiceProvider::MODULE, self::NUMBER, NumberFormat::RESET_YEARLY, ['BRANCH'], langKey: 'hotel.numbering.folio');
    }

    /** The guest folio of a stay (made once). */
    public function guestFolio(Stay $stay): Folio
    {
        $existing = Folio::query()->where('stay_id', $stay->id)->where('kind', Folio::GUEST)->first();

        return $existing ?? $this->make($stay->reservation_id, $stay->company_id, $stay->branch_id, $stay->currency, Folio::GUEST, $stay->id, $stay->guest_id);
    }

    /** The master folio of a group reservation (made once). */
    public function masterFolio(Reservation $reservation, string $currency): Folio
    {
        $existing = Folio::query()->where('reservation_id', $reservation->id)->where('kind', Folio::MASTER)->first();

        return $existing ?? $this->make($reservation->id, $reservation->company_id, $reservation->branch_id, $currency, Folio::MASTER, null, $reservation->guest_id);
    }

    private function make(string $reservationId, string $companyId, string $branchId, string $currency, string $kind, ?string $stayId, ?string $guestId): Folio
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($reservationId, $companyId, $branchId, $currency, $kind, $stayId, $guestId) {
            $number = $this->numbering->next(self::NUMBER_TYPE, new NumberContext(Company::query()->findOrFail($companyId), Branch::query()->findOrFail($branchId)))->number;
            $folio = Folio::create([
                'company_id' => $companyId, 'branch_id' => $branchId, 'reservation_id' => $reservationId, 'stay_id' => $stayId, 'guest_id' => $guestId,
                'kind' => $kind, 'number' => $number, 'currency' => $currency, 'status' => Folio::OPEN,
            ]);
            $this->auditor->record('hotel.folio.open', $folio, null, ['number' => $number, 'kind' => $kind]);

            return $folio;
        });
    }

    /** The folio a stay's charges go to: the master folio when it bills to master, else its own. */
    public function billingFolio(Stay $stay): Folio
    {
        if ($stay->bill_to_master) {
            $master = Folio::query()->where('reservation_id', $stay->reservation_id)->where('kind', Folio::MASTER)->first();

            if ($master !== null && $master->isOpen()) {
                return $master;
            }
        }

        return $this->guestFolio($stay);
    }

    /**
     * Add a line to a folio (see the class doc). `$line` keys: kind, description, amount_minor (signed, gross), and optional item_id, qty,
     * unit_price_minor, net_minor, tax_minor, taxes, flags, business_date, source, source_id, reverses_id, pair_id, payment_method_id,
     * payment_id, reference, reason, created_by. A repeat of a `(source, source_id)` returns the existing line (`wasRecentlyCreated` false).
     *
     * @param  array<string, mixed>  $line
     */
    public function post(Folio|string $folio, array $line): FolioLine
    {
        $folioId = $folio instanceof Folio ? $folio->id : $folio;

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($folioId, $line) {
            $folio = Folio::query()->whereKey($folioId)->lockForUpdate()->firstOrFail();
            $sourceId = $line['source_id'] ?? null;

            if ($sourceId !== null && ($existing = FolioLine::query()->where('folio_id', $folio->id)->where('source', $line['source'])->where('source_id', (string) $sourceId)->first()) !== null) {
                return $existing;
            }

            if (! $folio->isOpen()) {
                throw HotelError::make(409, 'folio_settled');
            }

            $date = (string) ($line['business_date'] ?? $this->properties->businessDate($folio->branch_id));
            $amount = (int) $line['amount_minor'];
            $base = $this->base->for($folio->company_id, $folio->currency, $amount, CarbonImmutable::parse($date.' 12:00:00', 'UTC'));
            $created = FolioLine::create([
                'folio_id' => $folio->id,
                'line_no' => (int) FolioLine::query()->where('folio_id', $folio->id)->max('line_no') + 1,
                'kind' => $line['kind'],
                'description' => mb_substr((string) $line['description'], 0, 240),
                'item_id' => $line['item_id'] ?? null,
                'qty' => $line['qty'] ?? '1',
                'unit_price_minor' => $line['unit_price_minor'] ?? null,
                'amount_minor' => $amount,
                'net_minor' => $line['net_minor'] ?? $amount,
                'tax_minor' => $line['tax_minor'] ?? 0,
                'taxes' => $line['taxes'] ?? [],
                'currency' => $folio->currency,
                'fx' => $base['fx'],
                'base_amount_minor' => $base['base_amount_minor'],
                'business_date' => $date,
                'source' => $line['source'],
                'source_id' => $sourceId === null ? null : (string) $sourceId,
                'reverses_id' => $line['reverses_id'] ?? null,
                'pair_id' => $line['pair_id'] ?? null,
                'payment_method_id' => $line['payment_method_id'] ?? null,
                'payment_id' => $line['payment_id'] ?? null,
                'reference' => $line['reference'] ?? null,
                'reason' => $line['reason'] ?? null,
                'flags' => $line['flags'] ?? [],
                'created_by' => $line['created_by'] ?? null,
            ]);

            match ($created->kind) {
                FolioLine::ROOM, FolioLine::EXTRA, FolioLine::FEE => $this->postings->charge($folio, $created),
                FolioLine::DEPOSIT, FolioLine::PAYMENT, FolioLine::REFUND => $this->postings->payment($folio, $created),
                FolioLine::VOID => $this->postings->charge($folio, $created, FolioLine::query()->find($created->reverses_id)),
                default => null,
            };

            return $created;
        });
    }

    /**
     * HOT-21: a charge for an item (an extra, a fee line from an item, a night's room) with its tax.
     *
     * @param  array{kind?: string, item_id?: ?string, description: string, qty?: string, unit_price_minor: int, tax_inclusive?: bool, business_date?: string, source: string, source_id?: ?string, levies?: bool, flags?: list<string>, reference?: ?string, reason?: ?string}  $in
     */
    public function charge(Folio|string $folio, array $in, ?User $by = null, bool $audit = true): FolioLine
    {
        $folio = $folio instanceof Folio ? $folio : Folio::query()->findOrFail($folio);
        $date = (string) ($in['business_date'] ?? $this->properties->businessDate($folio->branch_id));
        $qty = (string) ($in['qty'] ?? '1');
        $gross = (int) (string) BigDecimal::of($qty)->multipliedBy($in['unit_price_minor'])->toScale(0, RoundingMode::HalfUp);
        $levies = ($in['levies'] ?? false) ? (array) Settings::for($folio->company_id)->levy_tax_code_ids : [];
        $split = $this->taxing->split($folio->company_id, $in['item_id'] ?? null, $levies, $gross, $folio->currency, (bool) ($in['tax_inclusive'] ?? false), $date);

        if ($split['gross'] === 0) {
            throw HotelError::make(422, 'amount_zero', field: 'unit_price_minor');
        }

        $line = $this->post($folio, [
            'kind' => $in['kind'] ?? FolioLine::EXTRA,
            'description' => $in['description'],
            'item_id' => $in['item_id'] ?? null,
            'qty' => $qty,
            'unit_price_minor' => $in['unit_price_minor'],
            'amount_minor' => $split['gross'],
            'net_minor' => $split['net'],
            'tax_minor' => $split['tax'],
            'taxes' => $split['taxes'],
            'flags' => array_values(array_unique([...($in['flags'] ?? []), ...$split['flags']])),
            'business_date' => $date,
            'source' => $in['source'],
            'source_id' => $in['source_id'] ?? null,
            'reference' => $in['reference'] ?? null,
            'reason' => $in['reason'] ?? null,
            'created_by' => $by?->id,
        ]);

        if ($audit && $line->wasRecentlyCreated) {
            $this->auditor->record('hotel.folio.charge', $folio, null, ['line_id' => $line->id, 'kind' => $line->kind, 'amount_minor' => $line->amount_minor, 'description' => $line->description]);
        }

        return $line;
    }

    /** HOT-21: a staff void of a room, extra or fee line (a reversing line; audited). */
    public function void(FolioLine $line, string $reason, User $by): FolioLine
    {
        $folio = Folio::query()->findOrFail($line->folio_id);

        if ($line->kind === FolioLine::POS_CHARGE) {
            throw HotelError::make(409, 'void_pos_charge');
        }

        if (! in_array($line->kind, self::VOIDABLE, true)) {
            throw HotelError::make(409, 'void_not_allowed');
        }

        if (FolioLine::query()->where('reverses_id', $line->id)->exists()) {
            throw HotelError::make(409, 'already_voided');
        }

        $void = $this->post($folio, $this->reversal($line, FolioLine::VOID, 'void', $reason, $by));
        $this->auditor->record('hotel.folio.void', $folio, ['line_id' => $line->id, 'amount_minor' => $line->amount_minor], ['void_line_id' => $void->id, 'reason' => $reason]);

        return $void;
    }

    /** The reversing line of $line (every amount negated), for a void or a transfer out. @return array<string, mixed> */
    public function reversal(FolioLine $line, string $kind, string $source, ?string $reason, ?User $by, ?string $pairId = null): array
    {
        return [
            'kind' => $kind,
            'description' => Text::line($kind === FolioLine::VOID ? 'hotel.lines.void' : 'hotel.lines.transfer_out', ['description' => $line->description]),
            'item_id' => $line->item_id,
            'qty' => $line->qty,
            'unit_price_minor' => $line->unit_price_minor,
            'amount_minor' => -$line->amount_minor,
            'net_minor' => -$line->net_minor,
            'tax_minor' => -$line->tax_minor,
            'taxes' => array_map(fn (array $tax) => [...$tax, 'amount_minor' => -$tax['amount_minor']], (array) $line->taxes),
            'business_date' => $line->business_date->toDateString(),
            'source' => $source,
            'source_id' => $line->id,
            'reverses_id' => $line->id,
            'pair_id' => $pairId,
            'reference' => $line->kind === FolioLine::TRANSFER_IN ? $line->reference : $line->kind,
            'reason' => $reason,
            'created_by' => $by?->id,
        ];
    }

    /**
     * HOT-25: move charges from one folio to another of the same company and
     * currency as pairs of lines that net to zero, in one transaction.
     *
     * @param  list<string>  $lineIds
     * @return list<FolioLine> the lines made on the receiving folio
     */
    public function transfer(Folio $from, Folio $to, array $lineIds, string $reason, User $by): array
    {
        if ($from->id === $to->id) {
            throw HotelError::make(422, 'transfer_same_folio', field: 'to_folio_id');
        }

        if ($from->company_id !== $to->company_id || $from->currency !== $to->currency) {
            throw HotelError::make(422, 'transfer_mismatch', field: 'to_folio_id');
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($from, $to, $lineIds, $reason, $by) {
            // Both folios locked in id order, so two opposite transfers cannot deadlock.
            Folio::query()->whereKey([$from->id, $to->id])->orderBy('id')->lockForUpdate()->get();
            $lines = FolioLine::query()->where('folio_id', $from->id)->whereKey($lineIds)->orderBy('line_no')->get();

            if ($lines->count() !== count(array_unique($lineIds))) {
                throw HotelError::make(422, 'transfer_line_unknown', field: 'line_ids');
            }

            $made = [];

            foreach ($lines as $line) {
                if (! in_array($line->kind, self::TRANSFERABLE, true) || FolioLine::query()->where('reverses_id', $line->id)->exists()) {
                    throw HotelError::make(409, 'transfer_line_not_movable', ['line' => $line->line_no], 'line_ids');
                }

                $pair = (string) Str::uuid7();
                $this->post($from, $this->reversal($line, FolioLine::TRANSFER_OUT, 'transfer', $reason, $by, $pair));
                $made[] = $this->post($to, [
                    'kind' => FolioLine::TRANSFER_IN,
                    'description' => Text::line('hotel.lines.transfer_in', ['description' => $line->description, 'folio' => $from->number]),
                    'item_id' => $line->item_id,
                    'qty' => $line->qty,
                    'unit_price_minor' => $line->unit_price_minor,
                    'amount_minor' => $line->amount_minor,
                    'net_minor' => $line->net_minor,
                    'tax_minor' => $line->tax_minor,
                    'taxes' => $line->taxes,
                    'business_date' => $line->business_date->toDateString(),
                    'source' => 'transfer',
                    'source_id' => $line->id,
                    'pair_id' => $pair,
                    // The root kind of the charge (a moved room night stays a hotel charge on the invoice; a POS charge stays receipted at the till).
                    'reference' => $line->kind === FolioLine::TRANSFER_IN ? $line->reference : $line->kind,
                    'reason' => $reason,
                    'created_by' => $by->id,
                ]);
            }

            $this->auditor->record('hotel.folio.transfer', $from, null, ['to_folio_id' => $to->id, 'lines' => $lines->pluck('id')->all(), 'reason' => $reason]);

            return $made;
        });
    }

    /** The folio's balance: charges less payments, in minor units (positive: the guest owes). */
    public function balance(Folio|string $folio): int
    {
        return (int) FolioLine::query()->where('folio_id', $folio instanceof Folio ? $folio->id : $folio)->sum('amount_minor');
    }

    /** @return array{charges_minor: int, payments_minor: int, balance_minor: int} */
    public function totals(Folio $folio): array
    {
        $rows = FolioLine::query()->where('folio_id', $folio->id)->selectRaw('kind, sum(amount_minor) as total')->groupBy('kind')->pluck('total', 'kind');
        $payments = (int) (($rows[FolioLine::PAYMENT] ?? 0) + ($rows[FolioLine::DEPOSIT] ?? 0));
        $balance = (int) $rows->sum();

        return ['charges_minor' => $balance - $payments, 'payments_minor' => -$payments, 'balance_minor' => $balance];
    }
}
