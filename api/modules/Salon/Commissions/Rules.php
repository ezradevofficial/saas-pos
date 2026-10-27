<?php

namespace Modules\Salon\Commissions;

use Illuminate\Support\Collection;
use Modules\Salon\Models\CommissionRule;

/**
 * SAL-20: which commission rule applies. Rules are effective-dated rows: for a staff member or everyone, for a service
 * or every service, or for product sales. The most specific live rule on the sale's day wins: a staff member and a
 * service, then the staff member, then the service, then the default; a tie goes to the later start, then the id. A
 * staff member without a live rule earns nothing.
 */
class Rules
{
    /** @var array<string, Collection<int, CommissionRule>> */
    private array $loaded = [];

    /**
     * @param  string  $appliesTo  `service` or `product`
     * @param  string  $day  Y-m-d, the sale's day in the branch's time zone
     */
    public function pick(string $companyId, string $staffId, string $appliesTo, ?string $serviceId, string $day): ?CommissionRule
    {
        $rules = $this->loaded[$companyId.'|'.$day] ??= CommissionRule::query()->where('company_id', $companyId)->whereNull('archived_at')
            ->whereDate('effective_from', '<=', $day)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))->get();

        return $rules
            ->filter(fn (CommissionRule $rule) => $rule->applies_to === $appliesTo
                && ($rule->staff_id === null || $rule->staff_id === $staffId)
                && ($rule->service_id === null || $rule->service_id === $serviceId))
            ->sort(fn (CommissionRule $a, CommissionRule $b) => [self::score($b, $staffId), $b->effective_from->toDateString(), $b->id] <=> [self::score($a, $staffId), $a->effective_from->toDateString(), $a->id])
            ->first();
    }

    private static function score(CommissionRule $rule, string $staffId): int
    {
        return ($rule->staff_id === $staffId ? 2 : 0) + ($rule->service_id !== null ? 1 : 0);
    }
}
