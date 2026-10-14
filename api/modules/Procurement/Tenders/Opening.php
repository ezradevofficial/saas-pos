<?php

namespace Modules\Procurement\Tenders;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Procurement\Events\TenderOpened;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Models\TenderLine;
use Modules\Procurement\Models\TenderMember;
use Modules\Procurement\Models\TenderOpening;
use Modules\Procurement\Support\Pricing;
use Modules\Procurement\Support\ProcurementError;
use Modules\Procurement\Support\Tx;

/**
 * PRC-10: the timed opening by a committee. An opener (a committee member
 * with the `opener` role) confirms the opening at or after the opening
 * time (`opening_not_due` before); each confirmation is audited. The
 * second different opener's confirmation opens every submitted bid in the
 * same transaction: its ciphertext is checked against its fingerprint and
 * decrypted (Seal), amounts are computed per tender line (round half up),
 * converted to base at the company's rate at the opening (CUR-04), and the
 * plain columns filled; a bid that fails the check is `tampered` and stays
 * sealed. The tender is `opened`; the opening is audited with both
 * openers, the bid count and every fingerprint, then TenderOpened.
 */
class Opening
{
    public const REQUIRED = 2;

    public function __construct(
        private readonly Pricing $pricing,
        private readonly Auditor $auditor,
    ) {}

    public function confirm(Tender $tender, User $by): Tender
    {
        $event = null;

        $opened = Tx::run(function () use ($tender, $by, &$event) {
            $tender = Tender::query()->whereKey($tender->id)->lockForUpdate()->firstOrFail();

            if ($tender->status !== Tender::PUBLISHED) {
                throw ProcurementError::make('tender_not_published', status: 409);
            }

            $now = CarbonImmutable::now();

            if ($now->lessThan($tender->opening_at)) {
                throw ProcurementError::make('opening_not_due', ['time' => $tender->opening_at->toIso8601String()], status: 409);
            }

            if (! TenderMember::query()->where('tender_id', $tender->id)->where('user_id', $by->id)->where('role', TenderMember::OPENER)->exists()) {
                throw ProcurementError::make('opener_not_member', status: 403);
            }

            if (TenderOpening::query()->where('tender_id', $tender->id)->where('user_id', $by->id)->exists()) {
                throw ProcurementError::make('already_confirmed', status: 409);
            }

            TenderOpening::query()->create(['tender_id' => $tender->id, 'user_id' => $by->id, 'confirmed_at' => $now]);
            $openers = TenderOpening::query()->where('tender_id', $tender->id)->orderBy('confirmed_at')->pluck('user_id')->all();

            if (count(array_unique($openers)) < self::REQUIRED) {
                return $tender->refresh();
            }

            $company = Company::query()->findOrFail($tender->company_id);
            $quantities = TenderLine::query()->where('tender_id', $tender->id)->pluck('qty', 'id');
            $fingerprints = [];
            $count = 0;

            foreach (Bid::query()->where('tender_id', $tender->id)->where('status', Bid::SUBMITTED)->orderBy('submitted_at')->lockForUpdate()->get() as $bid) {
                $fingerprints[$bid->id] = $bid->fingerprint;
                $payload = Seal::open($tender, $bid->sealed_payload, $bid->fingerprint);

                if ($payload === null) {
                    $bid->fill(['status' => Bid::TAMPERED])->save();

                    continue;
                }

                $count++;
                $currency = (string) $payload['currency'];
                $lines = [];
                $total = BigDecimal::zero();

                foreach ($payload['lines'] as $line) {
                    $qty = $quantities[$line['tender_line_id']] ?? null;

                    if ($qty === null) {
                        continue;
                    }

                    $amount = BigDecimal::of((string) $qty)->multipliedBy((string) $line['unit_price_minor'])->toScale(0, RoundingMode::HalfUp);
                    $total = $total->plus($amount);
                    $lines[] = ['tender_line_id' => $line['tender_line_id'], 'unit_price_minor' => (string) $line['unit_price_minor'], 'amount_minor' => (string) $amount];
                }

                $fx = $this->pricing->fx($company, $currency, $now);
                $bid->fill([
                    'status' => Bid::OPENED,
                    'opened_at' => $now,
                    'currency' => $currency,
                    'fx' => $fx,
                    'total_minor' => (string) $total,
                    'base_total_minor' => Pricing::convert($fx, (string) $total, $currency),
                    'lines' => $lines,
                    'technical_response' => $payload['technical_response'] ?? null,
                    'complete' => count($lines) === $quantities->count(),
                ])->save();
            }

            $tender->fill(['status' => Tender::OPENED, 'opened_at' => $now])->save();
            $this->auditor->record('procurement.tender.opened', $tender, null, [
                'openers' => $openers, 'bids' => $count, 'fingerprints' => $fingerprints,
            ]);
            $event = new TenderOpened((string) $tender->tenant_id, $tender->id, $tender->company_id, $count, $openers);

            return $tender->refresh();
        });

        if ($event !== null) {
            event($event);
        }

        return $opened;
    }
}
