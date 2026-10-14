<?php

namespace Modules\POS\Tests;

use App\Core\Rbac\ModuleRegistry;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Listeners\MoveSaleStock;
use Modules\Loyalty\Pos\HandlePosEvents;
use Modules\POS\Listeners\LinkMobileMoneyPayments;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NFR-04, RBAC-08, stage 7B performance review: an upload queues no job
// that would do nothing: mobile money linking only for sales paid by mobile
// money, Loyalty's handling only while Loyalty is active, Inventory's stock
// move only while Inventory is active.
class ListenerQueueingTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPos();
        $this->ranges()->assertOk();
        $this->shift = $this->openShift();
        Queue::fake();
    }

    private function listenerJobs(string $listener): int
    {
        return collect(Queue::pushedJobs()[CallQueuedListener::class] ?? [])->filter(fn (array $pushed) => $pushed['job']->class === $listener)->count();
    }

    public function test_jobs_are_queued_only_when_they_have_something_to_do(): void
    {
        // POS alone, paid in cash: nothing to link, no Loyalty, no Inventory.
        $this->upload([$this->saleBody($this->shift, 1)])->assertOk();
        $this->assertSame(0, $this->listenerJobs(LinkMobileMoneyPayments::class));
        $this->assertSame(0, $this->listenerJobs(HandlePosEvents::class));
        Queue::assertNotPushed(MoveSaleStock::class);

        // Paid by M-Pesa, with Loyalty and Inventory on: each job is queued once.
        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('loyalty');
            app(ModuleRegistry::class)->activate('inventory');
        });
        $sale = $this->saleBody($this->shift, 2);
        $sale['payments'][0] = [...$sale['payments'][0], 'payment_method_id' => $this->methods['mpesa']->id, 'provider_reference' => 'QJK3OFFLN1', 'status' => 'pending'];
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        $this->assertSame(1, $this->listenerJobs(LinkMobileMoneyPayments::class));
        $this->assertSame(1, $this->listenerJobs(HandlePosEvents::class));
        Queue::assertPushed(MoveSaleStock::class, 1);
    }
}
