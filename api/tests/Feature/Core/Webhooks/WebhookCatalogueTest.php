<?php

namespace Tests\Feature\Core\Webhooks;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Rbac\Scope;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Delivery\WebhookDispatcher;
use App\Core\Webhooks\Delivery\WebhookSignature;
use App\Core\Webhooks\Jobs\FanOutWebhookEvent;
use App\Core\Webhooks\Models\WebhookSubscription;
use App\Core\Webhooks\Principals\WebhookPrincipal;
use App\Core\Webhooks\WebhookSubscriptions;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Queue;
use Modules\Accounting\Events\DocumentPosted;
use Modules\Ecommerce\Events\OrderEvent;
use Modules\Inventory\Events\StockLow;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Payroll\Events\PayrollApproved;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Webhooks\PosWebhookEvents;
use Tests\Concerns\BuildsWebhooks;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * HOOK-01: the public catalogue the modules register. Every type is
 * versioned, belongs to a registered module, names a catalogue permission
 * and maps from an after-commit domain event; captures carry only the
 * documented fields; a module switched off queues nothing; field rules
 * strip what they hide (payroll totals).
 */
class WebhookCatalogueTest extends TestCase
{
    use BuildsWebhooks, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebhooks();
    }

    public function test_every_registered_type_is_versioned_owned_by_a_module_and_raised_after_commit(): void
    {
        $catalogue = app(WebhookCatalogue::class);
        $keys = array_map(fn (WebhookEventType $type) => $type->key, $catalogue->all());

        foreach (['sale.completed.v1', 'sale.voided.v1', 'sale.refunded.v1', 'stock.low.v1', 'invoice.posted.v1', 'credit_note.posted.v1',
            'supplier_invoice.approved.v1', 'purchase_order.approved.v1', 'payroll.approved.v1', 'order.placed.v1', 'order.paid.v1',
            'order.status_changed.v1', 'order.completed.v1', 'document.stage_changed.v1', 'webhook.test.v1'] as $key) {
            $this->assertContains($key, $keys);
        }

        foreach ($catalogue->all() as $type) {
            $this->assertMatchesRegularExpression(WebhookEventType::KEY_PATTERN, $type->key);
            $this->assertContains($type->module, app(ModuleRegistry::class)->registered(), $type->key);
            $this->assertNotEmpty($type->fields, "{$type->key} documents its fields");

            if ($type->permission !== null) {
                $this->assertTrue(app(PermissionRegistry::class)->has($type->permission), "{$type->key}: {$type->permission} is in the catalogue");
                $this->assertSame($type->module, PermissionRegistry::moduleOf($type->permission), "{$type->key} is received with its own module's permission");
            }

            if ($type->event !== null) {
                $this->assertTrue(is_subclass_of($type->event, ShouldDispatchAfterCommit::class), "{$type->key}: only committed events are sent");
            }
        }
    }

    public function test_a_completed_sale_carries_its_documented_fields_only(): void
    {
        $type = app(WebhookCatalogue::class)->find('sale.completed.v1');
        $occurrence = ($type->capture)(new SaleCompleted('tenant', 'sale-1', 'company-1', 'location-1', [
            'id' => 'sale-1', 'company_id' => 'company-1', 'branch_id' => 'branch-1', 'location_id' => 'location-1', 'device_id' => 'device-1',
            'shift_id' => 'shift-1', 'cashier_id' => 'cashier-1', 'customer_id' => null, 'receipt_number' => 'R-1', 'status' => 'completed',
            'sold_at' => '2026-10-16T09:00:00Z', 'offline' => false, 'currency' => 'KES',
            'totals' => ['subtotal_minor' => '1000', 'discount_minor' => '0', 'tax_minor' => '160', 'total_minor' => '1160', 'paid_minor' => '1200', 'rounding_minor' => '0'],
            'payments' => [['method' => 'mpesa', 'reference' => 'QWERTY']],
            'lines' => [['id' => 'line-1', 'line_no' => 1, 'item_id' => 'item-1', 'uom_id' => 'uom-1', 'qty' => '1', 'unit_price_minor' => '1000',
                'discount_minor' => '0', 'tax_minor' => '160', 'total_minor' => '1160', 'adjustments' => ['x' => 1]]],
            'extensions' => ['loyalty' => ['phone' => '+254700000000']],
        ]));

        $this->assertSame(['company-1', 'branch-1', 'location-1'], [$occurrence->companyId, $occurrence->branchId, $occurrence->locationId]);
        // Review finding 9: queued with the id, the place and the sale's own time only; values are read in the job.
        $this->assertSame(['sale_id' => 'sale-1'], $occurrence->data);
        $this->assertSame('2026-10-16T09:00:00Z', $occurrence->occurredAt);

        $data = PosWebhookEvents::sale([
            'id' => 'sale-1', 'company_id' => 'company-1', 'branch_id' => 'branch-1', 'location_id' => 'location-1', 'device_id' => 'device-1',
            'shift_id' => 'shift-1', 'cashier_id' => 'cashier-1', 'customer_id' => null, 'receipt_number' => 'R-1', 'status' => 'completed',
            'sold_at' => '2026-10-16T09:00:00Z', 'offline' => false, 'currency' => 'KES',
            'totals' => ['subtotal_minor' => '1000', 'discount_minor' => '0', 'tax_minor' => '160', 'total_minor' => '1160', 'paid_minor' => '1200', 'rounding_minor' => '0'],
            'payments' => [['method' => 'mpesa', 'reference' => 'QWERTY']],
            'lines' => [['id' => 'line-1', 'line_no' => 1, 'item_id' => 'item-1', 'uom_id' => 'uom-1', 'qty' => '1', 'unit_price_minor' => '1000',
                'discount_minor' => '0', 'tax_minor' => '160', 'total_minor' => '1160', 'adjustments' => ['x' => 1]]],
            'extensions' => ['loyalty' => ['phone' => '+254700000000']],
        ]);
        $this->assertSame('R-1', $data['receipt_number']);
        $this->assertSame('1160', $data['totals']['total_minor']);
        $flat = json_encode($data);
        foreach (['cashier-1', 'shift-1', 'QWERTY', '+254700000000', 'adjustments', 'rounding_minor'] as $secretish) {
            $this->assertStringNotContainsString($secretish, $flat, "not documented: {$secretish}");
        }
        $this->assertSame(['line_no', 'item_id', 'uom_id', 'qty', 'unit_price_minor', 'discount_minor', 'tax_minor', 'total_minor'], array_keys($data['lines'][0]));
    }

    public function test_one_internal_event_maps_to_the_type_of_its_kind_only(): void
    {
        $catalogue = app(WebhookCatalogue::class);
        $paid = new OrderEvent('tenant', OrderEvent::PAID, 'order-1', 'company-1', 'W-1', 'KES', '5000');

        $captured = collect($catalogue->forEvent(OrderEvent::class))->filter(fn (WebhookEventType $type) => ($type->capture)($paid) !== null)->map->key->values()->all();
        $this->assertSame(['order.paid.v1'], $captured);

        // A supplier document posted in Accounting is no customer invoice event.
        $supplier = new DocumentPosted('tenant', 'doc-1', 'company-1', 'supplier_invoice');
        $this->assertSame([], collect($catalogue->forEvent(DocumentPosted::class))->filter(fn ($type) => ($type->capture)($supplier) !== null)->all());
        $invoice = new DocumentPosted('tenant', 'doc-1', 'company-1', 'customer_invoice');
        $this->assertSame(['invoice.posted.v1'], collect($catalogue->forEvent(DocumentPosted::class))->filter(fn ($type) => ($type->capture)($invoice) !== null)->map->key->values()->all());
    }

    public function test_payroll_totals_are_left_out_for_a_principal_whose_rules_hide_them(): void
    {
        $type = app(WebhookCatalogue::class)->find('payroll.approved.v1');
        $captured = ($type->capture)(new PayrollApproved('tenant', 'run-1', 'PR-1', 'company-1', 'regular', '2026-10-01', '2026-10-31', '2026-10-28', ['KES' => ['gross' => 100000, 'net' => 80000]]));
        // Review finding 9: the totals never sit in the queue; the job reads them from the run.
        $this->assertSame(['payroll_run_id' => 'run-1'], $captured->data);
        $occurrence = $captured->with(['payroll_run_id' => 'run-1', 'number' => 'PR-1', 'totals' => ['KES' => ['gross' => '100000', 'net' => '80000']]]);

        $hiding = new class implements WebhookPrincipal
        {
            public function type(): string
            {
                return 'user';
            }

            public function id(): string
            {
                return 'u';
            }

            public function name(): string
            {
                return 'U';
            }

            public function isActive(): bool
            {
                return true;
            }

            public function can(string $permission, Scope $scope): bool
            {
                return true;
            }

            public function hiddenFields(string $resource): array
            {
                return $resource === 'payroll' ? ['totals'] : [];
            }

            public function actingUser(): ?User
            {
                return null;
            }

            public function notifiableUserId(): ?string
            {
                return null;
            }
        };

        $visible = $type->visibleData($hiding, $occurrence->data);
        $this->assertArrayNotHasKey('totals', $visible);
        $this->assertSame('PR-1', $visible['number']);
    }

    public function test_a_module_switched_off_queues_no_event_for_its_subscribers(): void
    {
        Queue::fake();
        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate(InventoryServiceProvider::MODULE);
            WebhookSubscription::create([
                'principal_type' => 'user', 'principal_id' => $this->owner->id, 'url' => 'https://hooks.example.com/stock', 'url_display' => 'https://hooks.example.com/stock',
                'events' => ['stock.low.v1'], 'secret' => WebhookSignature::newSecret(), 'created_by' => $this->owner->id,
            ]);
        });
        $event = new StockLow($this->owner->tenant_id, $this->acme->id, 'item-1', $this->locationA->id, '2', '5', '10');

        $this->inTenant(fn () => app(WebhookDispatcher::class)->handle($event));
        Queue::assertPushed(FanOutWebhookEvent::class, fn (FanOutWebhookEvent $job) => $job->type === 'stock.low.v1'
            && $job->occurrence['data'] == ['item_id' => 'item-1', 'company_id' => $this->acme->id, 'location_id' => $this->locationA->id, 'qty' => '2', 'reorder_level' => '5', 'reorder_qty' => '10']);

        Queue::fake();
        $this->inTenant(function () use ($event) {
            app(ModuleRegistry::class)->deactivate(InventoryServiceProvider::MODULE);
            app(WebhookSubscriptions::class)->flush();
            app(WebhookDispatcher::class)->handle($event);
        });
        Queue::assertNothingPushed();
    }
}
