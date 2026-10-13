<?php

namespace Tests\Feature\Core\Audit;

use App\Core\Audit\AuditEntry;
use App\Core\Audit\Auditor;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUD-03, IMP-03: a batch of entries (an import batch) locks the chain head
// once and moves it once; the chain stays intact, also when a savepoint
// inside the batch rolls back entries it had appended.
class AuditBatchTest extends TestCase
{
    use RefreshTenantDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::provision(['name' => 'Tenant A']);
        app(TenantContext::class)->set($this->tenant->id);
    }

    public function test_a_batch_links_every_entry_and_moves_the_head_once(): void
    {
        $auditor = app(Auditor::class);
        $auditor->record('core.test.before');

        $auditor->batch(function () use ($auditor) {
            foreach (range(1, 5) as $i) {
                $auditor->record('core.test.batch', null, null, ['i' => $i]);
            }
        });

        $auditor->record('core.test.after');

        $this->assertSame(7, AuditEntry::query()->count());
        $this->assertSame(7, (int) DB::table('audit_chain_heads')->where('tenant_id', $this->tenant->id)->value('seq'));
        $this->assertNull($auditor->verify($this->tenant->id));
    }

    public function test_a_savepoint_rolled_back_in_a_batch_leaves_no_gap(): void
    {
        $auditor = app(Auditor::class);

        $auditor->batch(function () use ($auditor) {
            $auditor->record('core.test.kept');

            try {
                DB::transaction(function () use ($auditor) {
                    $auditor->record('core.test.rolled_back');
                    $auditor->record('core.test.rolled_back');

                    throw new RuntimeException('row refused');
                });
            } catch (RuntimeException) {
            }

            $auditor->record('core.test.kept');
        });

        $this->assertSame(['core.test.kept', 'core.test.kept'], AuditEntry::query()->orderBy('seq')->pluck('action')->all());
        $this->assertSame([1, 2], AuditEntry::query()->orderBy('seq')->pluck('seq')->map(fn ($seq) => (int) $seq)->all());
        $this->assertNull($auditor->verify($this->tenant->id));
    }

    public function test_a_failed_batch_leaves_the_head_where_it_was(): void
    {
        $auditor = app(Auditor::class);
        $auditor->record('core.test.before');

        try {
            $auditor->batch(function () use ($auditor) {
                $auditor->record('core.test.lost');

                throw new RuntimeException('batch failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(1, AuditEntry::query()->count());
        $this->assertNull($auditor->verify($this->tenant->id));
        $auditor->record('core.test.after');
        $this->assertNull($auditor->verify($this->tenant->id));
    }

    public function test_batches_do_not_nest(): void
    {
        $this->expectException(LogicException::class);

        app(Auditor::class)->batch(fn () => app(Auditor::class)->batch(fn () => null));
    }
}
