<?php

namespace Tests\Feature\Core\Tenancy;

use App\Core\Audit\AuditContext;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Jobs\RecordsContextJob;
use Tests\TestCase;

// Review focus 3, TEN-01, AUD-02: a queue worker clears the tenant and the
// audit context between jobs.
class QueueContextResetTest extends TestCase
{
    use RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RecordsContextJob::$seen = [];
    }

    public function test_a_worker_starts_every_job_without_the_previous_jobs_tenant_or_audit_context(): void
    {
        $tenantA = (string) Str::uuid7();

        Queue::connection('database')->push(new RecordsContextJob('first', $tenantA));
        Queue::connection('database')->push(new RecordsContextJob('second'));

        // A worker stops after a job once the process passes its memory limit
        // (128 MB by default); late in the full suite this process is past
        // it, so the second job never ran. The limit is about the worker, not
        // what this test checks.
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096]);

        $this->assertSame(['first', 'second'], array_column(RecordsContextJob::$seen, 'name'));
        [, $second] = RecordsContextJob::$seen;
        $this->assertNull($second['tenant']);
        $this->assertSame('', $second['db_tenant']);
        $this->assertNull($second['audit_user']);
        $this->assertNull($second['device']);

        // And the worker leaves no tenant behind once the queue is empty.
        $this->assertNull(app(TenantContext::class)->id());
        $this->assertNull(app(AuditContext::class)->deviceId());
    }

    public function test_a_sync_job_keeps_the_callers_context(): void
    {
        $tenantA = (string) Str::uuid7();
        app(TenantContext::class)->set($tenantA);

        RecordsContextJob::dispatchSync('inline');

        $this->assertSame($tenantA, RecordsContextJob::$seen[0]['tenant']);
        $this->assertSame($tenantA, app(TenantContext::class)->id());
    }
}
