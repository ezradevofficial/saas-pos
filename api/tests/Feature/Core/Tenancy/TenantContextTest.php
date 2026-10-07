<?php

namespace Tests\Feature\Core\Tenancy;

use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantContextMissing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// TEN-01..TEN-06: tenant context, isolation and the tenant hierarchy.
class TenantContextTest extends TestCase
{
    use RefreshTenantDatabase;

    private TenantContext $context;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->a = Tenant::provision(['name' => 'Tenant A']);
        $this->b = Tenant::provision(['name' => 'Tenant B']);

        $this->context->run($this->a->id, fn () => Company::create($this->companyAttributes('A Ltd')));
        $this->context->run($this->b->id, fn () => Company::create($this->companyAttributes('B Ltd')));
    }

    private function companyAttributes(string $name): array
    {
        return [
            'name' => $name,
            'legal_name' => $name,
            'country' => 'KE',
            'base_currency' => 'KES',
            'fiscal_year_start_month' => 1,
            'address' => ['city' => 'Nairobi'],
            'timezone' => 'Africa/Nairobi',
        ];
    }

    public function test_context_a_sees_only_tenant_a_rows(): void
    {
        $this->context->set($this->a->id);

        $this->assertSame(1, Company::count());
        $this->assertSame($this->a->id, Company::first()->tenant_id);
        $this->assertSame([$this->a->id], Tenant::pluck('id')->all());
    }

    public function test_no_context_sees_no_rows(): void
    {
        $this->context->set(null);

        $this->assertNull($this->context->id());
        $this->assertSame(0, Company::count());
        $this->assertSame(0, Tenant::count());
    }

    public function test_inserting_a_row_for_another_tenant_is_rejected(): void
    {
        $this->context->set($this->a->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('violates row-level security policy');
        // Savepoint keeps the surrounding test transaction usable.
        DB::transaction(fn () => Company::create($this->companyAttributes('Sneaky') + ['tenant_id' => $this->b->id]));
    }

    public function test_run_restores_the_previous_context_after_an_exception(): void
    {
        $this->context->set($this->a->id);

        try {
            $this->context->run($this->b->id, function () {
                $this->assertSame($this->b->id, $this->context->id());
                throw new RuntimeException('boom');
            });
            $this->fail('Exception was swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($this->a->id, $this->context->id());
        $this->assertSame($this->a->id, DB::selectOne("select current_setting('app.tenant_id', true) as id")->id);
    }

    public function test_run_inside_a_failing_transaction_keeps_php_and_database_in_step(): void
    {
        $this->context->set($this->a->id);

        try {
            DB::transaction(fn () => $this->context->run($this->b->id, fn () => DB::select('select 1/0')));
            $this->fail('Division by zero was swallowed');
        } catch (QueryException $e) {
            // The original SQL error surfaces, not the failed restore (25P02).
            $this->assertStringContainsString('division by zero', $e->getMessage());
        }

        $this->assertSame($this->a->id, $this->context->id());
        $this->assertSame($this->a->id, $this->databaseTenant());
        $this->assertSame([$this->a->id], Company::pluck('tenant_id')->all());
    }

    public function test_set_inside_a_rolled_back_transaction_keeps_php_and_database_in_step(): void
    {
        $this->context->set($this->a->id);

        try {
            DB::transaction(function () {
                $this->context->set($this->b->id);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame($this->context->id(), $this->databaseTenant());
    }

    private function databaseTenant(): ?string
    {
        return DB::selectOne("select current_setting('app.tenant_id', true) as id")->id;
    }

    public function test_require_throws_without_context(): void
    {
        $this->context->set(null);

        $this->expectException(TenantContextMissing::class);
        $this->context->require();
    }

    public function test_creating_a_tenant_row_without_context_throws(): void
    {
        $this->context->set(null);

        $this->expectException(TenantContextMissing::class);
        Company::create($this->companyAttributes('Orphan'));
    }

    public function test_listeners_are_told_about_context_changes(): void
    {
        $seen = [];
        $this->context->onChange(function (?string $id) use (&$seen) {
            $seen[] = $id;
        });

        $this->context->set($this->a->id);
        $this->context->set(null);

        $this->assertSame([$this->a->id, null], $seen);
    }

    public function test_hierarchy_and_archiving(): void
    {
        $this->context->set($this->a->id);
        $company = Company::first();

        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Westlands', 'code' => 'WL', 'address' => []]);
        $location = Location::create(['branch_id' => $branch->id, 'name' => 'Front shop', 'type' => 'outlet']);
        $device = Device::create(['location_id' => $location->id, 'name' => 'Till 1']);

        $this->assertSame($this->a->id, $device->fresh()->tenant_id);
        $this->assertSame('pending', $device->fresh()->status);
        $this->assertTrue($location->is($device->location));
        $this->assertTrue($branch->is($location->branch));
        $this->assertTrue($company->is($branch->company));
        $this->assertNotNull($this->a->id);
        $this->assertSame(8, $this->a->setting('password_min_length'));
        $this->assertSame(60, $this->a->setting('session_timeout_minutes'));

        // TEN-06: archive and restore instead of deleting.
        $branch->archive();
        $this->assertTrue($branch->fresh()->isArchived());
        $this->assertSame(0, Branch::active()->count());
        $branch->restore();
        $this->assertFalse($branch->fresh()->isArchived());
        $this->assertSame(1, Branch::active()->count());

        $this->context->set($this->b->id);
        $this->assertSame(0, Branch::count());
        $this->assertSame(0, Device::count());
    }

    public function test_reset_middleware_clears_context_and_require_tenant_rejects(): void
    {
        Route::middleware('tenant')->get('/_test/tenant', fn () => response()->json(['id' => app(TenantContext::class)->id()]));

        $this->context->set($this->a->id);

        $this->getJson('/_test/tenant')->assertUnauthorized();
        $this->assertNull($this->context->id());
    }

    public function test_tenant_aware_job_middleware_runs_inside_the_job_tenant(): void
    {
        $job = new class
        {
            public string $tenantId;
        };
        $job->tenantId = $this->b->id;

        $this->context->set(null);
        $count = (new TenantAware)->handle($job, fn () => Company::count());

        $this->assertSame(1, $count);
        $this->assertNull($this->context->id());
    }
}
