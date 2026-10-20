<?php

namespace Modules\Loyalty\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Webhooks\Delivery\WebhookDispatcher;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Inventory\Listeners\MoveSaleStock;
use Modules\Inventory\Listeners\MoveStockForSale;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockLedger;
use Modules\Loyalty\Pos\HandlePosEvents;
use Modules\Loyalty\Pos\PosSales;
use Modules\Loyalty\Tests\Concerns\BuildsLoyalty;
use Modules\Manufacturing\Backflush\BackflushSales;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
use RuntimeException;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;
use Throwable;

// LOY-26, INV-14, NFR-04: one module's failure while handling a POS event
// never fails the till's upload nor stops another module's handling. Every
// listener on the sale events is queued (or only queues a job).
class PosEventChainTest extends TestCase
{
    use BuildsLoyalty, RefreshTenantDatabase;

    /** Listeners that run in the request but only queue a job (they carry ids, never the snapshot). */
    /** Listeners that only dispatch a queued job (ids only) and cannot fail the upload. */
    // HOOK-01: WebhookDispatcher queues one fan-out job when the tenant subscribes, and reports instead of throwing.
    private const DISPATCH_ONLY = [MoveStockForSale::class, BackflushSales::class, WebhookDispatcher::class];

    public function test_every_listener_on_the_sale_events_is_queued_or_only_queues(): void
    {
        foreach ([SaleCompleted::class, SaleVoided::class, SaleRefunded::class] as $event) {
            $listeners = Event::getRawListeners()[$event] ?? [];
            $this->assertNotEmpty($listeners, $event);

            foreach ($listeners as $listener) {
                $class = is_array($listener) ? $listener[0] : (is_string($listener) ? explode('@', $listener)[0] : null);
                $this->assertIsString($class, "{$event}: closures are not allowed (they run in the upload request)");
                $this->assertTrue(
                    is_subclass_of($class, ShouldQueue::class) || in_array($class, self::DISPATCH_ONLY, true),
                    "{$event}: {$class} runs in the upload request",
                );
            }
        }

        $this->assertTrue(is_subclass_of(HandlePosEvents::class, ShouldQueue::class));
        $this->assertTrue((new \ReflectionClass(HandlePosEvents::class))->getProperty('afterCommit')->getDefaultValue());
    }

    public function test_a_loyalty_exception_still_moves_stock_and_the_upload_returns_ok(): void
    {
        $this->setUpLoyalty();
        $this->ranges()->assertOk();
        $shift = $this->openShift();
        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('inventory');
            app(StockLedger::class)->post(new Posting($this->acme->id, 'test.source', (string) Str::uuid7(), CarbonImmutable::now(), null, [
                new PostingLine($this->soap->id, $this->locationA->id, Movement::RECEIPT, Qty::of('10'), cost: BigInteger::of(1000), setsLastCost: true),
            ]));
        });
        $this->app->instance(PosSales::class, new class extends PosSales
        {
            public function __construct() {}

            public function completed(array $sale): void
            {
                throw new RuntimeException('loyalty unavailable');
            }
        });

        Queue::fake();
        $sale = $this->loyaltySale($shift, 1, $this->amina->id);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        // A worker runs each queued job on its own: Loyalty's fails, Inventory's still moves the stock.
        $failed = [];
        $worker = new SyncQueue;
        $worker->setContainer($this->app);
        $worker->setConnectionName('sync');

        foreach (Queue::pushedJobs() as $jobs) {
            foreach ($jobs as $pushed) {
                try {
                    $worker->push($pushed['job']);
                } catch (Throwable $e) {
                    $failed[] = $e->getMessage();
                }
            }
        }

        // Inventory's job carries ids only, never the sale snapshot (the receipt number is in the snapshot).
        Queue::assertPushed(MoveSaleStock::class, fn (MoveSaleStock $job) => $job->id === $sale['id'] && ! str_contains(serialize($job), $sale['receipt_number']));
        $this->assertSame(['loyalty unavailable'], $failed);
        $this->assertSame('8', $this->inTenant(fn () => Qty::show((string) Balance::query()->where('item_id', $this->soap->id)->where('location_id', $this->locationA->id)->value('qty'))));
    }
}
