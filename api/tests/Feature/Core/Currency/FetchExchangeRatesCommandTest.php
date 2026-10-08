<?php

namespace Tests\Feature\Core\Currency;

use App\Core\Currency\Jobs\FetchReferenceRates;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\WithoutOwnerConnection;
use Tests\TestCase;

/**
 * CUR-03: `exchange-rates:fetch` queues one job per active company with a
 * feed, reading each tenant's companies under row-level security. Active
 * tenants come from a security-definer function on the runtime
 * connection, so the test runs with the owner connection unusable (ADR 002).
 */
class FetchExchangeRatesCommandTest extends TestCase
{
    use BuildsRbac, RefreshTenantDatabase, WithoutOwnerConnection;

    public function test_the_daily_command_queues_a_job_per_company_with_a_feed_and_is_scheduled(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();

        [$withFeed, $archived] = $this->asTenant($first->tenant_id, function () {
            $this->company('No feed');
            $withFeed = $this->company('BCC');
            $withFeed->forceFill(['rate_feed' => 'bcc'])->save();
            $archived = $this->company('Closed');
            $archived->forceFill(['rate_feed' => 'cbk', 'archived_at' => now()])->save();

            return [$withFeed, $archived];
        });
        $other = $this->asTenant($second->tenant_id, function () {
            $company = $this->company('CBK');
            $company->forceFill(['rate_feed' => 'cbk'])->save();

            return $company;
        });
        // A suspended tenant is not fetched for.
        $suspended = $this->createUser();
        $this->asTenant($suspended->tenant_id, function () use ($suspended) {
            $this->company('Suspended')->forceFill(['rate_feed' => 'cbk'])->save();
            Tenant::findOrFail($suspended->tenant_id)->forceFill(['status' => 'suspended'])->save();
        });

        $this->withoutOwnerConnection();
        Bus::fake();

        $this->artisan('exchange-rates:fetch', ['--date' => '2026-10-08'])->assertSuccessful();

        Bus::assertDispatchedTimes(FetchReferenceRates::class, 2);
        foreach ([[$first->tenant_id, $withFeed->id], [$second->tenant_id, $other->id]] as [$tenantId, $companyId]) {
            Bus::assertDispatched(FetchReferenceRates::class, fn (FetchReferenceRates $job) => $job->tenantId === $tenantId
                && $job->companyId === $companyId && $job->date === '2026-10-08');
        }
        Bus::assertNotDispatched(FetchReferenceRates::class, fn (FetchReferenceRates $job) => $job->companyId === $archived->id);
        Bus::assertNotDispatched(FetchReferenceRates::class, fn (FetchReferenceRates $job) => $job->tenantId === $suspended->tenant_id);

        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'exchange-rates:fetch'));
        $this->assertCount(1, $events);
        $this->assertSame('30 6 * * *', $events->first()->expression);
    }
}
