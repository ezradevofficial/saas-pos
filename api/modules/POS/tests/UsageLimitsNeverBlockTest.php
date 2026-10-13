<?php

namespace Modules\POS\Tests;

use App\Core\Limits\UsageLimits;
use Carbon\CarbonImmutable;
use Modules\POS\Models\Sale;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\BuildsUsageLimits;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * LIM-04: a till keeps selling and syncing while the tenant is over its
 * plan on every unit, past the 7-day window: number ranges, shifts, sale
 * uploads (idempotent) and master data pulls all go through. Only adding
 * more is refused.
 */
class UsageLimitsNeverBlockTest extends TestCase
{
    use BuildsPos, BuildsUsageLimits, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPos();
        $this->setUpLimits();
        $this->overLimitEverywhere();
    }

    public function test_the_till_sells_and_syncs_while_every_unit_is_blocked(): void
    {
        $this->assertSame(['blocked', 'blocked', 'blocked'], $this->inTenant(fn () => array_column(array_slice(app(UsageLimits::class)->status(CarbonImmutable::now())['units'], 0, 3), 'state')));

        $this->ranges()->assertOk();
        $shift = $this->openShift();
        $sale = $this->saleBody($shift);

        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.id', $sale['id']);
        $this->inTenant(fn () => $this->assertSame(1, Sale::query()->count()));

        $this->getJson('/api/v1/sync/pull?entities[]=items', $this->tillHeaders())->assertOk();
        $this->getJson('/api/v1/devices/me', $this->tillHeaders())->assertOk();

        // Adding is what is refused.
        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 2'], $this->headersFor())->assertStatus(402);
    }
}
