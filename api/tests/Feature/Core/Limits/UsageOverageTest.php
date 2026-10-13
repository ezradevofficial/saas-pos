<?php

namespace Tests\Feature\Core\Limits;

use App\Core\Limits\Models\UsageMeter;
use App\Core\Limits\UsageOverage;
use App\Core\Limits\UsageOverageLine;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\BuildsUsageLimits;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * LIM-05: the overage billing reads, from the daily meter records, in the
 * tenant's own context (TEN-01): per unit and quantity over, the days it
 * lasted in the period.
 */
class UsageOverageTest extends TestCase
{
    use BuildsOrganisation, BuildsUsageLimits, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    /** Meter rows for $days days from $from: $unit counted $count against $allowed. */
    private function days(string $tenantId, string $from, int $days, string $unit, int $count, ?int $allowed): void
    {
        $this->asTenant($tenantId, function () use ($from, $days, $unit, $count, $allowed) {
            for ($i = 0; $i < $days; $i++) {
                $day = CarbonImmutable::parse($from)->addDays($i);
                UsageMeter::create(['day' => $day->toDateString(), 'unit' => $unit, 'count' => $count, 'allowed' => $allowed, 'metered_at' => $day->setTime(3, 0)]);
            }
        });
    }

    /** @return list<array{unit: string, quantity: int, days: int, period_days: int}> */
    private function overage(string $tenantId, string $from, string $to): array
    {
        return array_map(fn (UsageOverageLine $line) => $line->toArray(), app(UsageOverage::class)->overageFor($tenantId, CarbonImmutable::parse($from), CarbonImmutable::parse($to)));
    }

    public function test_the_overage_of_a_period_groups_the_days_by_quantity_over(): void
    {
        $tenant = $this->owner->tenant_id;
        $other = $this->otherTenant()['user']->tenant_id;

        // Devices: 1 over for 10 days, 2 over for 3 days, within for 5, then unlimited for 2.
        $this->days($tenant, '2026-10-01', 10, 'device', 3, 2);
        $this->days($tenant, '2026-10-11', 3, 'device', 4, 2);
        $this->days($tenant, '2026-10-14', 5, 'device', 2, 2);
        $this->days($tenant, '2026-10-19', 2, 'device', 9, null);
        // Outlets: 1 over for 4 days; two of them outside the period.
        $this->days($tenant, '2026-10-29', 4, 'outlet', 2, 1);
        // The other tenant's overage never shows here.
        $this->days($other, '2026-10-01', 30, 'user', 50, 1);

        $this->assertSame([
            ['unit' => 'outlet', 'quantity' => 1, 'days' => 2, 'period_days' => 30],
            ['unit' => 'device', 'quantity' => 1, 'days' => 10, 'period_days' => 30],
            ['unit' => 'device', 'quantity' => 2, 'days' => 3, 'period_days' => 30],
        ], $this->overage($tenant, '2026-10-01', '2026-10-31'));

        $this->assertSame([['unit' => 'user', 'quantity' => 49, 'days' => 30, 'period_days' => 30]], $this->overage($other, '2026-10-01', '2026-10-31'));

        // The next period has only what falls in it; an empty period has nothing.
        $this->assertSame([['unit' => 'outlet', 'quantity' => 1, 'days' => 2, 'period_days' => 30]], $this->overage($tenant, '2026-10-31', '2026-11-30'));
        $this->assertSame([], $this->overage($tenant, '2026-10-01', '2026-10-01'));
    }

    public function test_it_runs_in_the_tenants_context_and_restores_the_callers(): void
    {
        $other = $this->otherTenant()['user']->tenant_id;
        $this->days($this->owner->tenant_id, '2026-10-01', 1, 'device', 3, 2);
        $context = app(TenantContext::class);

        // From no context (a billing job before it enters a tenant).
        $this->assertCount(1, $this->overage($this->owner->tenant_id, '2026-10-01', '2026-11-01'));
        $this->assertNull($context->id());

        // From another tenant's context: still the asked tenant's rows, and the context is back.
        $this->asTenant($other, function () use ($context, $other) {
            $this->assertCount(1, $this->overage($this->owner->tenant_id, '2026-10-01', '2026-11-01'));
            $this->assertSame($other, $context->id());
        });
    }

    public function test_meter_runs_feed_the_report(): void
    {
        $this->setUpLimits();
        $this->allow(['device' => 2]);
        $this->devices(3);

        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $day) {
            $this->meter(CarbonImmutable::parse("{$day}T03:00:00Z"));
        }

        $this->assertSame([['unit' => 'device', 'quantity' => 1, 'days' => 3, 'period_days' => 31]], $this->overage($this->owner->tenant_id, '2026-10-01', '2026-11-01'));
    }
}
