<?php

namespace Modules\Ecommerce\Tests;

use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\ModuleRegistry;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Chart\ChartTemplates;
use Modules\Accounting\Models\Journal;
use Modules\Ecommerce\Contracts\CustomerOrders;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderLine;
use Modules\Ecommerce\Tests\Concerns\BuildsEcommerce;
use Modules\Inventory\Contracts\StockLevels;
use Modules\Inventory\Models\Movement;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\Programme;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// ECOM-31 to ECOM-34: Inventory, Accounting, Loyalty and CRM, each only
// while both modules are active; journals balanced; default rules of the
// KE and CD charts.
class IntegrationsTest extends TestCase
{
    use BuildsEcommerce, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEcommerce();
    }

    public function test_inventory_moves_stock_on_hand_over_and_back_on_a_failed_delivery(): void
    {
        $this->stockUp('5');
        $id = $this->dispatched('2');
        $level = fn () => $this->inTenant(fn () => app(StockLevels::class)->onHand($this->locationA->id, [$this->widget->id])[$this->widget->id]);

        $this->assertSame('3.000000', $level(), 'ECOM-18: stock leaves at hand-over');
        $this->assertSame(1, $this->inTenant(fn () => Movement::query()->where('source_type', 'ecommerce.order')->where('source_id', $id)->count()));

        $this->api('POST', "ecommerce/orders/{$id}/return", ['reason' => 'Nobody home'], $this->manager)->assertOk()->assertJsonPath('data.status', 'returned');
        $this->assertSame('5.000000', $level(), 'and comes back on return, at its cost');
        $this->assertSame('5', $this->shopCall('GET', 'duka/products/widget')->json('data.availability.left'), 'availability follows');
    }

    public function test_without_inventory_nothing_moves_and_availability_is_the_manual_flag(): void
    {
        $id = $this->dispatched('2');

        $this->assertSame(0, $this->inTenant(fn () => Movement::query()->count()));
        $this->assertNull($this->inTenant(fn () => app(StockLevels::class)->onHand($this->locationA->id, [$this->widget->id])));

        $this->inTenant(fn () => $this->widgetProduct->forceFill(['in_stock' => false])->save());
        $this->assertSame('out_of_stock', $this->shopCall('GET', 'duka/products/widget')->json('data.availability.state'));
        $this->assertNotNull($id);
    }

    public function test_accounting_posts_balanced_order_and_cash_up_journals(): void
    {
        $this->withAccounting();
        $id = $this->dispatched('1');
        $this->riderDelivers($id);

        // KES 1,000.00 + 16 % + KES 200.00 delivery, cash on delivery to the COD clearing account.
        $this->assertSame(['1270' => [136000, 0], '2200' => [0, 16000], '4100' => [0, 100000], '4200' => [0, 20000]], $this->ledger('ecommerce.order'));

        // The rider hands in KES 1,350.00: KES 10.00 short.
        $this->api('POST', 'ecommerce/cashups', ['rider_id' => $this->rider->id, 'currency' => 'KES', 'counted_minor' => '135000'], $this->manager)->assertCreated()
            ->assertJsonPath('data.expected_minor', '136000')->assertJsonPath('data.variance_minor', '-1000');
        $this->assertSame(['1110' => [135000, 0], '1270' => [0, 136000], '6900' => [1000, 0]], $this->ledger('ecommerce.cod_cashup'));

        // A second cash-up has nothing left.
        $this->api('POST', 'ecommerce/cashups', ['rider_id' => $this->rider->id, 'currency' => 'KES', 'counted_minor' => '0'], $this->manager)->assertUnprocessable()->assertJsonPath('code', 'cashup_nothing_pending');

        // A return of the delivered order posts the order rule with its sides swapped (the refund in cash).
        $this->api('POST', "ecommerce/orders/{$id}/return", ['reason' => 'Damaged', 'refund_fee' => true])->assertOk();
        $this->assertSame(['1110' => [0, 136000], '2200' => [16000, 0], '4100' => [100000, 0], '4200' => [20000, 0]], $this->ledger('ecommerce.return'));
    }

    public function test_without_accounting_nothing_posts(): void
    {
        $id = $this->dispatched('1');
        $this->riderDelivers($id);
        $this->api('POST', 'ecommerce/cashups', ['rider_id' => $this->rider->id, 'currency' => 'KES', 'counted_minor' => '136000'], $this->manager)->assertCreated();

        $this->assertSame(0, $this->inTenant(fn () => Journal::query()->count()));
    }

    public function test_the_ke_and_cd_default_rules_balance_every_source(): void
    {
        $examples = [
            'ecommerce.order' => ['tender.a' => 50000, 'cod' => 74400, 'revenue.c' => 100000, 'promotion' => 10000, 'delivery' => 20000, 'tax.v' => 14400],
            'ecommerce.cod_cashup' => ['cash' => 135000, 'shortage' => 1000, 'cod' => 136000, 'overage' => 0],
        ];

        foreach (['KE', 'CD'] as $country) {
            $rules = app(ChartTemplates::class)->get($country)['posting_rules'];

            foreach ($examples as $type => $amounts) {
                $debit = $credit = 0;

                foreach ($rules[$type] as $line) {
                    $fields = str_ends_with($line['field'], '.*')
                        ? array_filter($amounts, fn ($k) => str_starts_with($k, substr($line['field'], 0, -1)), ARRAY_FILTER_USE_KEY)
                        : [$line['field'] => $amounts[$line['field']] ?? 0];

                    foreach ($fields as $value) {
                        $line['side'] === 'debit' ? $debit += $value : $credit += $value;
                    }
                }

                $this->assertSame($debit, $credit, "{$country} {$type} balances");
            }
        }
    }

    public function test_loyalty_promotions_points_and_their_journal(): void
    {
        $this->withAccounting();
        $points = $this->withLoyalty();
        // A 10 % promotion on the widget, evaluated by Loyalty's engine on the server.
        $this->api('POST', 'loyalty/promotions', ['company_id' => $this->acme->id, 'name' => 'Ten off', 'type' => 'percent', 'percent' => '10', 'priority' => 1, 'status' => 'active',
            'targets' => ['items' => [$this->widget->id]]])->assertCreated();

        $quote = $this->shopCall('POST', 'duka/quote', $this->cart([[$this->widgetProduct, '1']]))->json('data');
        $this->assertSame(['10000', '14400', '124400'], [$quote['lines'][0]['discount_minor'], $quote['lines'][0]['tax_minor'], $quote['totals']['total_minor']]);

        // ECOM-33: points need the member's own session (a guest earns nothing).
        $member = $this->shopSignIn('0712345678');
        $id = $this->dispatched('1', $member);
        $this->api('POST', "ecommerce/orders/{$id}/deliver", [], $this->manager)->assertOk();
        $this->assertSame(['1270' => [124400, 0], '2200' => [0, 14400], '4100' => [0, 100000], '4200' => [0, 20000], '4300' => [10000, 0]], $this->ledger('ecommerce.order'));

        // ECOM-33: delivered: floor(124 400 / 10 000) = 12 points for the customer's party.
        $party = $this->inTenant(fn () => Order::query()->findOrFail($id)->party_id);
        $this->assertSame('12', $this->inTenant(fn () => (string) Account::query()->where('party_id', $party)->where('kind', 'points')->value('balance')));

        // The next order pays 10 points (KES 1.00) and the rest cash on delivery.
        $second = $this->placeOrder($this->cart([[$this->widgetProduct, '1']], ['points' => '10']), headers: $member)->assertCreated()->json('data');
        $this->assertSame(['100', '124300'], [$second['totals']['loyalty_paid_minor'], $second['totals']['due_minor']]);
        $this->assertSame('2', $this->inTenant(fn () => (string) Account::query()->where('party_id', $party)->where('kind', 'points')->value('balance')));

        // Cancelled: the points come back.
        $this->api('POST', 'ecommerce/orders/'.$this->orderId($second['number']).'/cancel', ['reason' => 'Changed mind'], $this->manager)->assertOk();
        $this->assertSame('12', $this->inTenant(fn () => (string) Account::query()->where('party_id', $party)->where('kind', 'points')->value('balance')));
        $this->assertNotNull($points);
    }

    public function test_crm_reads_a_customers_orders_through_the_contract(): void
    {
        $id = $this->orderId($this->placeOrder($this->cart([[$this->widgetProduct, '1']]), headers: $this->shopSignIn('0712345678'))->json('data.number'));
        $party = $this->inTenant(fn () => Order::query()->findOrFail($id)->party_id);
        $read = fn ($user) => $this->inTenant(fn () => app(CustomerOrders::class)->forCustomer($user, $party));

        $this->assertSame([1, 'KES', '136000'], [$read($this->owner)['count'], $read($this->owner)['totals'][0]['currency'], $read($this->owner)['totals'][0]['total_minor']]);
        $this->assertNull($read($this->riderUser), 'without ecommerce.order.view');
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('ecommerce'));
        $this->assertNull($read($this->owner), 'without the module');
    }

    public function test_delivered_orders_and_returns_are_queued_for_the_tax_authority(): void
    {
        // ECOM-35: the core fiscal queue, source `ecommerce` (the fake driver; real KRA eTIMS and DGI credentials are configuration).
        $this->inTenant(fn () => $this->vat->forceFill(['fiscal_code' => 'B'])->save());
        $this->putJson("/api/v1/companies/{$this->acme->id}/fiscal-settings", ['driver' => 'fake', 'tin' => 'P051111111A', 'enabled' => true], $this->headersFor())->assertCreated();
        $id = $this->dispatched('1');
        $this->api('POST', "ecommerce/orders/{$id}/deliver", [], $this->manager)->assertOk();
        $this->api('POST', "ecommerce/orders/{$id}/return", ['reason' => 'Damaged'])->assertOk();

        $submissions = $this->inTenant(fn () => FiscalSubmission::query()->where('source', 'ecommerce')->orderBy('invoice_no')->get());
        $this->assertSame(['sale', 'refund'], $submissions->pluck('document_type')->all());
        $this->assertSame([$id, $id], $submissions->pluck('document_id')->all());
        $this->assertSame(136000, (int) $submissions[0]->payload['totals']['total_minor'], 'the delivery fee is a line of the invoice');
        $this->assertSame(116000, (int) $submissions[1]->payload['totals']['total_minor'], 'the fee stays when it is not refunded');
    }

    public function test_the_report_sums_orders_per_currency_and_cash_outstanding(): void
    {
        $id = $this->dispatched('1');
        $this->api('POST', "ecommerce/orders/{$id}/deliver", [], $this->manager)->assertOk();
        $this->placeOrder($this->cart([[$this->serviceProduct, '1']]))->assertCreated();

        $report = $this->api('GET', 'ecommerce/reports/summary?from=2026-11-30&to=2026-11-30', [], $this->manager)->assertOk()->json('data');
        $this->assertSame([['currency' => 'KES', 'orders' => 2, 'total_minor' => '214000', 'average_minor' => '107000', 'delivery_fees_minor' => '40000']], $report['revenue']);
        $this->assertSame([['key' => 'web', 'orders' => 2]], $report['by_channel']);
        $this->assertSame(['rider' => ['id' => $this->rider->id, 'name' => 'Juma Rider'], 'currency' => 'KES', 'deliveries' => 1, 'due_minor' => '136000'], $report['cod_outstanding'][0]);
        $this->api('GET', 'ecommerce/reports/summary', [], $this->riderUser)->assertForbidden();
    }

    /** An order of $qty widgets accepted, assigned and handed to the rider; its id. */
    private function dispatched(string $qty, array $headers = []): string
    {
        $id = $this->orderId($this->placeOrder($this->cart([[$this->widgetProduct, $qty]]), headers: $headers)->assertCreated()->json('data.number'));
        $this->api('POST', "ecommerce/orders/{$id}/accept", [], $this->manager)->assertOk();
        $delivery = $this->inTenant(fn () => Delivery::query()->where('order_id', $id)->sole());
        $this->api('POST', "ecommerce/deliveries/{$delivery->id}/assign", ['rider_id' => $this->rider->id], $this->manager)->assertOk();
        $this->api('POST', "ecommerce/orders/{$id}/dispatch", [], $this->manager)->assertOk();

        return $id;
    }

    public function test_partial_returns_take_back_their_share_of_the_points(): void
    {
        // Review M6 (LOY-04): pro rata on every partial return, while Loyalty is active.
        $this->withLoyalty();
        $member = $this->shopSignIn('0712345678');
        $id = $this->dispatched('4', $member);
        $this->api('POST', "ecommerce/orders/{$id}/deliver", [], $this->manager)->assertOk();
        $party = $this->inTenant(fn () => Order::query()->findOrFail($id)->party_id);
        $balance = fn () => $this->inTenant(fn () => (string) Account::query()->where('party_id', $party)->where('kind', 'points')->value('balance'));
        // 4 × KES 1,160.00 + KES 200.00 delivery = KES 4,840.00: 48 points.
        $this->assertSame('48', $balance());
        $line = $this->inTenant(fn () => OrderLine::query()->where('order_id', $id)->sole());

        // One widget back (KES 1,160.00): floor(48 × 116,000 ÷ 484,000) = 11 points off.
        $this->api('POST', "ecommerce/orders/{$id}/returns", ['reason' => 'One', 'lines' => [['line_id' => $line->id, 'qty' => '1']]])->assertCreated();
        $this->assertSame('37', $balance());
        // The other three (KES 4,640.00 in all): 46 off in all, 35 more; the delivery fee's 2 points stay.
        $this->api('POST', "ecommerce/orders/{$id}/returns", ['reason' => 'Rest', 'lines' => [['line_id' => $line->id, 'qty' => '3']]])->assertCreated();
        $this->assertSame('2', $balance());

        // Without Loyalty active, nothing is taken.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('loyalty'));
        $other = $this->dispatched('1', $member);
        $this->api('POST', "ecommerce/orders/{$other}/deliver", [], $this->manager)->assertOk();
        $otherLine = $this->inTenant(fn () => OrderLine::query()->where('order_id', $other)->sole());
        $this->api('POST', "ecommerce/orders/{$other}/returns", ['reason' => 'x', 'lines' => [['line_id' => $otherLine->id, 'qty' => '1']]])->assertCreated();
        $this->assertSame('2', $balance());
    }

    private function withLoyalty(): PaymentMethod
    {
        return $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('loyalty');
            Programme::create(['company_id' => $this->acme->id, 'active' => true, 'currency' => 'KES', 'earn_points' => 1, 'earn_spend_minor' => '10000', 'point_value_minor' => '10', 'min_redeem_points' => '0']);
            $points = PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'points', 'name' => 'Points', 'currency' => 'KES', 'active' => true, 'position' => 4]);
            $this->shop->forceFill(['payment_methods' => [...$this->shop->payment_methods, ['kind' => 'points', 'payment_method_id' => $points->id]]])->save();

            return $points;
        });
    }
}
