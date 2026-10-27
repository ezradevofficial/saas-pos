<?php

namespace Modules\Salon\Commissions;

use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Salon\Models\CommissionEntry;
use Modules\Salon\Models\CommissionRule;
use Modules\Salon\Models\SalonException;
use Modules\Salon\Models\Tip;
use Modules\Salon\Support\Places;

/**
 * SAL-21, SAL-22: the commission and tip ledgers, made from completed (so paid) sales and taken back by voids and
 * refunds. Everything is append-only and keyed by its source (`source_key`, unique), so a retried event adds nothing.
 *
 * - earn(): each attributed line earns its staff member the live rule's share of the line's net amount after
 *   discounts, without tax (a percentage, half up to the minor unit) or the rule's fixed amount per line.
 * - voided(): every earning and tip of the sale is reversed in full.
 * - refunded(): each refunded line reverses the refunded share of its earnings, cumulatively by quantity, so refunds in
 *   parts add up to exactly the whole.
 * A reversal after the earning was exported is just another entry: it is carried into the next batch (Batches).
 */
class Commissions
{
    public function __construct(private readonly Rules $rules, private readonly Places $places) {}

    /**
     * @param  array<string, mixed>  $sale  SaleSnapshot::sale()
     * @param  list<array{line: array<string, mixed>, staff_id: string, kind: string, service_id: ?string, appointment_id: ?string, appointment_line_id: ?string}>  $attributions
     */
    public function earn(string $companyId, array $sale, array $attributions): void
    {
        $day = CarbonImmutable::parse($sale['sold_at'])->setTimezone($this->places->zone((string) $sale['branch_id']))->toDateString();

        foreach ($attributions as $a) {
            $line = $a['line'];
            $base = (int) $line['net_minor'];
            $rule = $this->rules->pick($companyId, $a['staff_id'], $a['kind'], $a['service_id'], $day);

            if ($rule === null || $base <= 0) {
                continue;
            }

            $amount = $this->amount($rule, $base, (string) $sale['currency']);

            if ($amount === null) {
                SalonException::query()->firstOrCreate(['source_key' => "commission-currency:{$sale['id']}:{$line['id']}"], [
                    'company_id' => $companyId, 'branch_id' => $sale['branch_id'] ?? null, 'kind' => 'commission_currency', 'sale_id' => $sale['id'], 'currency' => $sale['currency'],
                    'detail' => ['rule_currency' => $rule->currency],
                ]);

                continue;
            }

            if ($amount <= 0) {
                continue;
            }

            CommissionEntry::query()->insertOrIgnore([[
                'id' => (string) Str::uuid7(), 'tenant_id' => app(TenantContext::class)->require(), 'company_id' => $companyId, 'branch_id' => $sale['branch_id'] ?? null,
                'staff_id' => $a['staff_id'], 'kind' => 'earn', 'line_kind' => $a['kind'], 'sale_id' => $sale['id'], 'sale_line_id' => $line['id'],
                'appointment_id' => $a['appointment_id'], 'appointment_line_id' => $a['appointment_line_id'], 'item_id' => $line['item_id'] ?? null,
                'qty' => (string) $line['qty'], 'base_minor' => $base, 'rule_id' => $rule->id,
                'rule_snapshot' => json_encode(['kind' => $rule->kind, 'rate' => $rule->rate, 'amount_minor' => $rule->amount_minor, 'staff_id' => $rule->staff_id, 'service_id' => $rule->service_id]),
                'currency' => $sale['currency'], 'amount_minor' => $amount, 'source_type' => 'sale', 'source_id' => $sale['id'],
                'source_key' => "sale:{$sale['id']}:{$line['id']}:{$a['staff_id']}", 'earned_at' => CarbonImmutable::parse($sale['sold_at'])->utc(),
                'created_at' => now(), 'updated_at' => now(),
            ]]);
        }
    }

    /** A voided sale takes back every earning and tip it made. @param array<string, mixed> $sale */
    public function voided(string $companyId, array $sale, string $voidId, ?string $voidedAt): void
    {
        $at = $voidedAt === null ? CarbonImmutable::now() : CarbonImmutable::parse($voidedAt)->utc();

        foreach (CommissionEntry::query()->where('sale_id', $sale['id'])->where('kind', 'earn')->get() as $entry) {
            $this->reverse($entry, 'void', $voidId, "void:{$voidId}:{$entry->id}", (int) $entry->amount_minor, (string) $entry->qty, $at);
        }

        foreach (Tip::query()->where('sale_id', $sale['id'])->where('kind', 'tip')->get() as $tip) {
            Tip::query()->insertOrIgnore([[
                'id' => (string) Str::uuid7(), 'tenant_id' => $tip->tenant_id, 'company_id' => $tip->company_id, 'branch_id' => $tip->branch_id, 'staff_id' => $tip->staff_id,
                'sale_id' => $tip->sale_id, 'kind' => 'reversal', 'currency' => $tip->currency, 'amount_minor' => -$tip->amount_minor,
                'source_key' => "void:{$voidId}:{$tip->id}", 'earned_at' => $at, 'created_at' => now(), 'updated_at' => now(),
            ]]);
        }
    }

    /** A refund takes back the refunded share of what its lines earned. @param array<string, mixed> $refund SaleSnapshot::refund() */
    public function refunded(string $companyId, array $refund): void
    {
        $at = CarbonImmutable::parse($refund['refunded_at'])->utc();

        foreach ($refund['lines'] ?? [] as $line) {
            if (empty($line['sale_line_id'])) {
                continue;
            }

            foreach (CommissionEntry::query()->where('sale_id', $refund['sale_id'])->where('sale_line_id', $line['sale_line_id'])->where('kind', 'earn')->get() as $entry) {
                $prior = CommissionEntry::query()->where('reverses_id', $entry->id)->where('source_type', 'refund')->get();
                $soldQty = BigDecimal::of((string) $entry->qty);

                if (! $soldQty->isPositive()) {
                    continue;
                }

                $cumQty = BigDecimal::of((string) $line['qty'])->plus($prior->reduce(fn (BigDecimal $sum, CommissionEntry $e) => $sum->plus((string) $e->qty), BigDecimal::zero()));
                $cumQty = $cumQty->isGreaterThan($soldQty) ? $soldQty : $cumQty;
                $target = (int) BigDecimal::of((string) $entry->amount_minor)->multipliedBy($cumQty)->dividedBy($soldQty, 0, RoundingMode::HALF_UP)->toInt();
                $already = (int) -$prior->sum('amount_minor');
                $delta = $target - $already;

                if ($delta > 0) {
                    $this->reverse($entry, 'refund', $refund['id'], "refund:{$refund['id']}:{$line['id']}:{$entry->id}", $delta, (string) $line['qty'], $at);
                }
            }
        }
    }

    private function reverse(CommissionEntry $entry, string $type, string $sourceId, string $key, int $amount, string $qty, CarbonImmutable $at): void
    {
        // A void takes back only what a refund has not already.
        if ($type === 'void') {
            $amount -= (int) -CommissionEntry::query()->where('reverses_id', $entry->id)->sum('amount_minor');
        }

        if ($amount <= 0) {
            return;
        }

        CommissionEntry::query()->insertOrIgnore([[
            'id' => (string) Str::uuid7(), 'tenant_id' => $entry->tenant_id, 'company_id' => $entry->company_id, 'branch_id' => $entry->branch_id, 'staff_id' => $entry->staff_id,
            'kind' => 'reversal', 'line_kind' => $entry->line_kind, 'sale_id' => $entry->sale_id, 'sale_line_id' => $entry->sale_line_id,
            'appointment_id' => $entry->appointment_id, 'appointment_line_id' => $entry->appointment_line_id, 'item_id' => $entry->item_id,
            'qty' => $qty, 'base_minor' => 0, 'rule_id' => $entry->rule_id, 'currency' => $entry->currency, 'amount_minor' => -$amount, 'reverses_id' => $entry->id,
            'source_type' => $type, 'source_id' => $sourceId, 'source_key' => $key, 'earned_at' => $at, 'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    /** The commission of a rule on a base amount, in minor units; null when a fixed rule is in another currency. */
    private function amount(CommissionRule $rule, int $base, string $currency): ?int
    {
        if ($rule->kind === 'fixed') {
            return $rule->currency === $currency ? (int) $rule->amount_minor : null;
        }

        return (int) BigDecimal::of((string) $base)->multipliedBy((string) $rule->rate)->dividedBy(100, 0, RoundingMode::HALF_UP)->toInt();
    }
}
