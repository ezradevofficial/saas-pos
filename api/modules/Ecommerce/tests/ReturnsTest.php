<?php

namespace Modules\Ecommerce\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditEntry;
use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Rbac\LimitRules;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Journal;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderLine;
use Modules\Ecommerce\Models\OrderReturn;
use Modules\Ecommerce\Models\Payment;
use Modules\Ecommerce\Models\ReturnLine;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Orders\Returns;
use Modules\Ecommerce\Tests\Concerns\BuildsEcommerce;
use Modules\Inventory\Contracts\StockLevels;
use Modules\Inventory\Models\Movement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// ECOM-42, ECOM-43: partial returns and exchanges of a delivered order:
// shares of the order's own lines (exact once every unit is back), refunds
// through the existing paths within the refund limit (RBAC-06), the
// difference of an exchange collected or refunded, stock through events
// (Inventory only while active), postings and fiscal documents, audit,
// permissions and other tenants.
class ReturnsTest extends TestCase
{
    use BuildsEcommerce, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEcommerce();
    }

    public function test_a_partial_return_takes_a_share_of_the_line_and_the_rest_comes_back_exactly(): void
    {
        $id = $this->delivered([[$this->widgetProduct, '3']]);
        $line = $this->inTenant(fn () => OrderLine::query()->where('order_id', $id)->sole());

        // The quote stores nothing.
        $quote = $this->api('POST', "ecommerce/orders/{$id}/returns/quote", ['lines' => [['line_id' => $line->id, 'qty' => '1']]], $this->manager)->assertOk()->json('data');
        $this->assertSame(['116000', '0', '116000', '0'], [$quote['returned_total_minor'], $quote['exchange_total_minor'], $quote['refund_minor'], $quote['collect_minor']]);
        $this->assertSame(0, $this->inTenant(fn () => OrderReturn::query()->count()));

        $order = $this->api('POST', "ecommerce/orders/{$id}/returns", ['reason' => 'Too many', 'lines' => [['line_id' => $line->id, 'qty' => '1']]], $this->manager)
            ->assertCreated()->json('data');
        $this->assertSame(['1', '2'], [$order['lines'][0]['returned_qty'], $order['lines'][0]['returnable_qty']]);
        $this->assertSame([$order['number'].'-R1', 'return', 'due', 'none', '116000'], [$order['returns'][0]['number'], $order['returns'][0]['kind'],
            $order['returns'][0]['refund_status'], $order['returns'][0]['collect_status'], $order['returns'][0]['refund_minor']]);
        $this->assertSame('delivered', $order['status'], 'a partial return leaves the order delivered');

        // More than is left is refused; the rest comes back to the minor unit.
        $this->api('POST', "ecommerce/orders/{$id}/returns", ['reason' => 'x', 'lines' => [['line_id' => $line->id, 'qty' => '3']]], $this->manager)
            ->assertUnprocessable()->assertJsonPath('code', 'return_qty_invalid');
        $this->api('POST', "ecommerce/orders/{$id}/returns", ['reason' => 'x', 'lines' => [['line_id' => $line->id, 'qty' => '1.5']]], $this->manager)
            ->assertUnprocessable()->assertJsonPath('code', 'return_qty_invalid');
        $this->api('POST', "ecommerce/orders/{$id}/returns", ['reason' => 'Rest', 'lines' => [['line_id' => $line->id, 'qty' => '2']]], $this->manager)->assertCreated();
        $back = $this->inTenant(fn () => ReturnLine::query()->where('order_line_id', $line->id)->get());
        $this->assertSame([$line->total_minor, $line->tax_minor, $line->net_minor], [(int) $back->sum('total_minor'), (int) $back->sum('tax_minor'), (int) $back->sum('net_minor')]);
        $this->assertEquals(3, (float) $back->sum('qty_base'), 'every base unit came back');

        // The whole-order return is closed once part came back; audited (AUD-01).
        $this->api('POST', "ecommerce/orders/{$id}/return", ['reason' => 'x'], $this->manager)->assertStatus(409)->assertJsonPath('code', 'partial_returns_exist');
        $this->assertSame(2, $this->inTenant(fn () => AuditEntry::query()->where('action', 'ecommerce.return.create')->count()));
    }

    public function test_the_refund_goes_back_by_hand_with_a_reference_within_the_refund_limit(): void
    {
        $id = $this->delivered([[$this->widgetProduct, '2']]);
        $line = $this->inTenant(fn () => OrderLine::query()->where('order_id', $id)->sole());
        $body = ['reason' => 'Damaged', 'lines' => [['line_id' => $line->id, 'qty' => '1']]];

        // RBAC-06 (review H1): a cashier takes goods back (order.manage) but holds no refund limit: the return waits for approval.
        $cashier = $this->userWith('cashier', Scope::branch($this->branchA->id));
        $pending = $this->api('POST', "ecommerce/orders/{$id}/returns", $body, $cashier)->assertStatus(202)->json('data.returns.0');
        $this->assertSame(['pending', 'due'], [$pending['approval_status'], $pending['refund_status']]);
        $this->api('POST', "ecommerce/returns/{$pending['id']}/refund", ['reference' => 'x'], $this->manager)->assertStatus(409)->assertJsonPath('code', 'return_awaiting_approval');
        $this->approveReturn($pending['id']);
        $return = $this->api('GET', "ecommerce/orders/{$id}", [], $this->manager)->json('data.returns.0');
        $this->assertSame('approved', $return['approval_status']);

        // Cash on delivery goes back by hand: the reference is needed. The cashier may not refund at all.
        $this->api('POST', "ecommerce/returns/{$return['id']}/refund", [], $cashier)->assertForbidden();
        $this->api('POST', "ecommerce/returns/{$return['id']}/refund", [], $this->manager)->assertUnprocessable()->assertJsonPath('code', 'refund_reference_required');
        $done = $this->api('POST', "ecommerce/returns/{$return['id']}/refund", ['reference' => 'CASH-0042'], $this->manager)->assertOk()->json('data.returns.0');
        $this->assertSame(['refunded', 'CASH-0042'], [$done['refund_status'], $done['refund_reference']]);
        $this->api('POST', "ecommerce/returns/{$return['id']}/refund", ['reference' => 'again'], $this->manager)->assertStatus(409)->assertJsonPath('code', 'refund_not_due');

        $detail = $this->inTenant(fn () => OrderReturn::query()->findOrFail($return['id'])->refund_detail);
        $this->assertSame([['cod', 116000, 'refunded']], array_map(fn ($e) => [$e['kind'], $e['amount_minor'], $e['status']], $detail));
    }

    public function test_an_mpesa_order_gets_part_of_its_payment_back_through_the_payout(): void
    {
        $placed = $this->placeOrder($this->cart([[$this->widgetProduct, '2']]), 'mpesa')->assertCreated()->json('data');
        $this->shopCall('POST', "duka/orders/{$placed['number']}/check", ['t' => $placed['tracking_token']])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $id = $this->orderId($placed['number']);
        $this->deliverPlaced($id);
        $line = $this->inTenant(fn () => OrderLine::query()->where('order_id', $id)->sole());

        foreach ([1, 2] as $n) {
            $return = $this->api('POST', "ecommerce/orders/{$id}/returns", ['reason' => "Return {$n}", 'lines' => [['line_id' => $line->id, 'qty' => '1']]], $this->manager)
                ->assertCreated()->json('data.returns.'.($n - 1));
            // No reference needed: the money goes back to the M-Pesa payment it came from.
            $this->api('POST', "ecommerce/returns/{$return['id']}/refund", [], $this->manager)->assertOk()->assertJsonPath('data.returns.'.($n - 1).'.refund_status', 'refunded');
        }

        $payouts = $this->inTenant(fn () => PaymentIntent::query()->where('reference_type', 'ecommerce.return_refund')->orderBy('created_at')->get());
        $this->assertSame([['refund', 'succeeded', 116000], ['refund', 'succeeded', 116000]], $payouts->map(fn ($p) => [$p->purpose, $p->status, (int) $p->amount_minor])->all());
        $this->assertSame('paid', $this->inTenant(fn () => Order::query()->findOrFail($id)->payment_status), 'the order keeps its payment status; the returns carry the refunds');
    }

    public function test_an_exchange_collects_the_difference_then_the_item_leaves_posts_and_is_fiscalised(): void
    {
        $this->stockUp('5');
        $this->withAccounting();
        $this->inTenant(fn () => $this->vat->forceFill(['fiscal_code' => 'B'])->save());
        $this->putJson("/api/v1/companies/{$this->acme->id}/fiscal-settings", ['driver' => 'fake', 'tin' => 'P051111111A', 'enabled' => true], $this->headersFor())->assertCreated();
        $level = fn () => (string) $this->inTenant(fn () => app(StockLevels::class)->onHand($this->locationA->id, [$this->widget->id])[$this->widget->id]);

        $id = $this->delivered([[$this->widgetProduct, '1'], [$this->serviceProduct, '1']]);
        $this->assertSame('4', rtrim(rtrim($level(), '0'), '.'));
        $service = $this->inTenant(fn () => OrderLine::query()->where('order_id', $id)->where('item_id', $this->service->id)->sole());

        // The setup service (KES 580.00) for a second widget (KES 1,160.00): KES 580.00 to collect.
        $body = ['reason' => 'Wants a widget instead', 'lines' => [['line_id' => $service->id, 'qty' => '1']], 'exchange' => [['product_id' => $this->widgetProduct->id, 'qty' => '1']]];
        $this->assertSame('58000', $this->api('POST', "ecommerce/orders/{$id}/returns/quote", $body, $this->manager)->assertOk()->json('data.collect_minor'));
        $return = $this->api('POST', "ecommerce/orders/{$id}/returns", $body, $this->manager)->assertCreated()->json('data.returns.0');
        $this->assertSame(['exchange', 'none', 'due', '58000'], [$return['kind'], $return['refund_status'], $return['collect_status'], $return['collect_minor']]);
        $this->assertSame([['in', 'Setup service'], ['out', 'Widget']], array_map(fn ($l) => [$l['direction'], $l['name']], $return['lines']));
        $this->assertSame('4', rtrim(rtrim($level(), '0'), '.'), 'the exchange item waits for the money');

        // Collected in cash: the widget leaves, the sale posts balanced, the invoice is queued.
        $this->api('POST', "ecommerce/returns/{$return['id']}/collect", ['payment_method_id' => (string) Str::uuid7()], $this->manager)->assertUnprocessable();
        $this->api('POST', "ecommerce/returns/{$return['id']}/collect", ['payment_method_id' => $this->cash->id, 'reference' => 'TILL-7'], $this->manager)->assertOk()
            ->assertJsonPath('data.returns.0.collect_status', 'collected');
        $this->api('POST', "ecommerce/returns/{$return['id']}/collect", ['payment_method_id' => $this->cash->id, 'reference' => 'TILL-8'], $this->manager)->assertStatus(409);
        $this->assertSame('3', rtrim(rtrim($level(), '0'), '.'));

        $saleId = $this->inTenant(fn () => OrderReturn::query()->findOrFail($return['id'])->exchange_sale_id);
        $this->assertSame(1, $this->inTenant(fn () => Movement::query()->where('source_type', 'ecommerce.order')->where('source_id', $saleId)->count()));
        $this->assertSame(2, $this->inTenant(fn () => Journal::query()->where('source_type', 'ecommerce.order')->count()), 'the order and the exchange');
        $this->ledger('ecommerce.order');
        // The service came back: revenue and tax reversed, the order's cash tender credited.
        $this->assertSame(['1110' => [0, 58000], '2200' => [8000, 0], '4100' => [50000, 0]], $this->ledger('ecommerce.return'));

        $submissions = $this->inTenant(fn () => FiscalSubmission::query()->where('source', 'ecommerce')->get()->keyBy('document_id'));
        $this->assertSame('refund', $submissions[$return['id']]->document_type);
        $this->assertSame(58000, (int) $submissions[$return['id']]->payload['totals']['total_minor']);
        $this->assertSame(['sale', 116000], [$submissions[$saleId]->document_type, (int) $submissions[$saleId]->payload['totals']['total_minor']]);
    }

    public function test_an_exchange_worth_less_is_refunded_and_without_inventory_nothing_moves(): void
    {
        $id = $this->delivered([[$this->widgetProduct, '1']]);
        $line = $this->inTenant(fn () => OrderLine::query()->where('order_id', $id)->sole());

        // A product that can't be sold is refused, nothing stored.
        $this->api('POST', "ecommerce/orders/{$id}/returns", ['reason' => 'x', 'lines' => [['line_id' => $line->id, 'qty' => '1']],
            'exchange' => [['product_id' => $this->gadgetProduct->id, 'qty' => '1']]], $this->manager)->assertUnprocessable()->assertJsonPath('code', 'tax_rate_missing');
        $this->assertSame(0, $this->inTenant(fn () => OrderReturn::query()->count()));

        $return = $this->api('POST', "ecommerce/orders/{$id}/returns", ['reason' => 'Swap', 'lines' => [['line_id' => $line->id, 'qty' => '1']],
            'exchange' => [['product_id' => $this->serviceProduct->id, 'qty' => '1']]], $this->manager)->assertCreated()->json('data.returns.0');

        $this->assertSame(['due', 'none', '58000'], [$return['refund_status'], $return['collect_status'], $return['refund_minor']]);
        $this->assertNotNull($this->inTenant(fn () => OrderReturn::query()->findOrFail($return['id'])->exchange_posted_at), 'nothing to collect: issued at once');
        $this->assertSame(0, $this->inTenant(fn () => Movement::query()->count()));
        $this->assertSame(0, $this->inTenant(fn () => Journal::query()->count()));

    }

    public function test_returns_need_a_delivered_order_the_permission_and_the_place(): void
    {
        $open = $this->orderId($this->placeOrder($this->cart([[$this->widgetProduct, '1']]))->assertCreated()->json('data.number'));
        $openLine = $this->inTenant(fn () => OrderLine::query()->where('order_id', $open)->sole());
        $this->api('POST', "ecommerce/orders/{$open}/returns", ['reason' => 'x', 'lines' => [['line_id' => $openLine->id, 'qty' => '1']]], $this->manager)
            ->assertStatus(409)->assertJsonPath('code', 'return_not_allowed');

        $id = $this->delivered([[$this->widgetProduct, '1']]);
        $line = $this->inTenant(fn () => OrderLine::query()->where('order_id', $id)->sole());
        $body = ['reason' => 'x', 'lines' => [['line_id' => $line->id, 'qty' => '1']]];

        // A line of another order is not this order's.
        $this->api('POST', "ecommerce/orders/{$id}/returns", ['reason' => 'x', 'lines' => [['line_id' => $openLine->id, 'qty' => '1']]], $this->manager)
            ->assertUnprocessable()->assertJsonPath('code', 'return_line_invalid');
        // Reads the order, may not take goods back: refused. Another branch: not found.
        $this->api('POST', "ecommerce/orders/{$id}/returns", $body, $this->userWith('accountant', Scope::branch($this->branchA->id)))->assertForbidden();
        $this->api('POST', "ecommerce/orders/{$id}/returns", $body, $this->userWith('branch_manager', Scope::branch($this->branchB->id)))->assertNotFound();
        $this->api('POST', "ecommerce/orders/{$id}/returns", ['lines' => $body['lines']], $this->manager)->assertUnprocessable();

        $return = $this->api('POST', "ecommerce/orders/{$id}/returns", $body, $this->manager)->assertCreated()->json('data.returns.0');
        $this->api('POST', "ecommerce/returns/{$return['id']}/refund", ['reference' => 'x'], $this->userWith('branch_manager', Scope::branch($this->branchB->id)))->assertNotFound();

        // Another tenant: nothing of this one is found.
        $other = $this->otherTenant();
        $this->inTenant(fn () => null);
        app(TenantContext::class)->run($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('ecommerce'));
        $headers = $this->bearer($this->tokenFor($other['user']));
        $this->json('POST', "/api/v1/ecommerce/orders/{$id}/returns", $body, $headers)->assertNotFound();
        $this->json('POST', "/api/v1/ecommerce/orders/{$id}/returns/quote", $body, $headers)->assertNotFound();
        $this->json('POST', "/api/v1/ecommerce/returns/{$return['id']}/refund", ['reference' => 'x'], $headers)->assertNotFound();
        $this->json('POST', "/api/v1/ecommerce/returns/{$return['id']}/collect", ['payment_method_id' => $this->cash->id], $headers)->assertNotFound();
        $this->assertSame(0, app(TenantContext::class)->run($other['user']->tenant_id, fn () => OrderReturn::query()->count()));
    }

    public function test_the_customer_sees_their_returns_on_the_tracking_page(): void
    {
        $id = $this->delivered([[$this->widgetProduct, '2']]);
        $line = $this->inTenant(fn () => OrderLine::query()->where('order_id', $id)->sole());
        $this->api('POST', "ecommerce/orders/{$id}/returns", ['reason' => 'x', 'lines' => [['line_id' => $line->id, 'qty' => '1']]], $this->manager)->assertCreated();
        $order = $this->inTenant(fn () => Order::query()->findOrFail($id));

        $returns = $this->shopCall('GET', "duka/orders/{$order->number}?t={$order->tracking_token_secret}")->assertOk()->json('data.returns');
        $this->assertSame([['number' => $order->number.'-R1', 'kind' => 'return', 'lines' => [['name' => 'Widget', 'qty' => '1', 'direction' => 'in']],
            'refund_minor' => '116000', 'collect_minor' => '0', 'refund_status' => 'due']], $returns);
    }

    public function test_splitting_a_return_never_gets_round_the_refund_limit(): void
    {
        // Review H1: the manager may refund up to 1,200 in all; three 1-unit returns of KES 1,160 each.
        $this->inTenant(fn () => $this->roles->get('branch_manager')->setLimits(['max_refund_amount' => '1200']));
        // A second Branch Manager to approve (APR-07: never the requester).
        $approver = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $id = $this->delivered([[$this->widgetProduct, '3']]);
        $line = $this->inTenant(fn () => OrderLine::query()->where('order_id', $id)->sole());
        $one = fn (string $reason) => ['reason' => $reason, 'lines' => [['line_id' => $line->id, 'qty' => '1']]];

        $first = $this->api('POST', "ecommerce/orders/{$id}/returns", $one('First'), $this->manager)->assertCreated()->json('data.returns.0');
        $this->assertSame('none', $first['approval_status']);
        // The second takes the order's refunds to 2,320: it waits; nothing of it moves.
        $second = $this->api('POST', "ecommerce/orders/{$id}/returns", $one('Second'), $this->manager)->assertStatus(202)->json('data.returns.1');
        $this->assertSame('pending', $second['approval_status']);
        $this->assertNull($this->inTenant(fn () => OrderReturn::query()->findOrFail($second['id'])->committed_at));
        $this->api('POST', "ecommerce/orders/{$id}/returns", $one('Third'), $this->manager)->assertStatus(202)->assertJsonPath('data.returns.2.approval_status', 'pending');

        // Refunding the first now counts the others due: it goes for approval too, never paid at once.
        $this->api('POST', "ecommerce/returns/{$first['id']}/refund", ['reference' => 'CASH-1'], $this->manager)->assertStatus(202)->assertJsonPath('data.returns.0.approval_status', 'pending');
        $this->assertSame(0, $this->inTenant(fn () => OrderReturn::query()->where('refund_status', 'refunded')->count()));

        // Another branch manager approves the second (APR-07: never the requester); it is committed then. The third is rejected: its unit is back to returnable.
        $this->approveReturn($second['id'], $approver);
        $this->assertNotNull($this->inTenant(fn () => OrderReturn::query()->findOrFail($second['id'])->committed_at));
        $third = $this->inTenant(fn () => OrderReturn::query()->where('order_id', $id)->where('seq', 3)->sole());
        $this->decideReturn($third->id, $approver, 'reject');
        $order = $this->api('GET', "ecommerce/orders/{$id}", [], $this->manager)->json('data');
        $this->assertSame(['rejected', 'none'], [$order['returns'][2]['approval_status'], $order['returns'][2]['refund_status']]);
        $this->assertSame('1', $order['lines'][0]['returnable_qty']);
        $this->api('POST', "ecommerce/returns/{$second['id']}/refund", ['reference' => 'CASH-2'], $this->manager)->assertOk()->assertJsonPath('data.returns.1.refund_status', 'refunded');
    }

    public function test_a_whole_return_after_a_partial_one_is_refused_under_the_orders_lock(): void
    {
        // Review H2: a partial return that lands between the checks and the lock (simulated: during the limit
        // check, which now runs under the order's lock) is seen, and the whole-order return is refused.
        $id = $this->delivered([[$this->widgetProduct, '2']]);
        $line = $this->inTenant(fn () => OrderLine::query()->where('order_id', $id)->sole());
        $limits = new class extends LimitRules
        {
            public $during;

            public function __construct() {}

            public function max(User $user, string $key, ?Scope $scope): ?string
            {
                if ($this->during !== null) {
                    $during = $this->during;
                    $this->during = null;
                    $during();
                }

                return '100000';
            }
        };
        $limits->during = fn () => app(Returns::class)->create(Order::query()->findOrFail($id), $this->manager,
            ['reason' => 'Competing', 'lines' => [['line_id' => $line->id, 'qty' => '1']]]);
        $this->app->instance(LimitRules::class, $limits);

        $this->api('POST', "ecommerce/orders/{$id}/return", ['reason' => 'All of it'], $this->manager)->assertStatus(409)->assertJsonPath('code', 'partial_returns_exist');
        $this->assertSame('delivered', $this->inTenant(fn () => Order::query()->findOrFail($id)->status));
    }

    public function test_net_plus_tax_is_the_total_on_every_return_and_the_journals_balance(): void
    {
        // Review M7: a sweep of prices, inclusive lists, odd quantities; three returns of 1, 2 and 4 of 7.
        $this->withAccounting();
        $this->inTenant(fn () => $this->list->forceFill(['tax_inclusive' => true])->save());

        // 85 × 2 returned 1 + 1 is a case where sharing net and tax apart gave 74 + 12 for a total of 85.
        foreach ([['85', '2', ['1', '1']], ['99', '2', ['1', '1']], ['1999', '7', ['1', '2', '4']], ['33333', '7', ['1', '2', '4']], ['100001', '7', ['1', '2', '4']], ['7', '7', ['1', '2', '4']]] as [$price, $ordered, $splits]) {
            $this->inTenant(fn () => ItemPrice::query()->where('item_id', $this->widget->id)->update(['amount_minor' => $price]));
            $id = $this->delivered([[$this->widgetProduct, $ordered]]);
            $line = $this->inTenant(fn () => OrderLine::query()->where('order_id', $id)->sole());

            foreach ($splits as $qty) {
                $this->api('POST', "ecommerce/orders/{$id}/returns", ['reason' => 'Sweep', 'lines' => [['line_id' => $line->id, 'qty' => $qty]]])->assertCreated();
            }

            foreach ($this->inTenant(fn () => ReturnLine::query()->where('order_id', $id)->get()) as $back) {
                $this->assertSame($back->total_minor, $back->net_minor + $back->tax_minor, "price {$price}");
                $this->assertSame($back->total_minor, $back->gross_minor - $back->discount_minor, "price {$price}");
            }
        }

        $this->ledger('ecommerce.return'); // every journal balances (asserted in ledger())
        $this->assertGreaterThan(0, $this->inTenant(fn () => Journal::query()->where('source_type', 'ecommerce.return')->count()));
    }

    public function test_only_money_the_order_holds_is_given_back(): void
    {
        // Review L4: a payment already paid back (or reversed) is no tender for a return.
        $id = $this->delivered([[$this->widgetProduct, '2']]);
        $line = $this->inTenant(fn () => OrderLine::query()->where('order_id', $id)->sole());
        $this->inTenant(fn () => Payment::query()->where('order_id', $id)->update(['status' => 'reversed']));
        $return = $this->api('POST', "ecommerce/orders/{$id}/returns", ['reason' => 'x', 'lines' => [['line_id' => $line->id, 'qty' => '1']]])->assertCreated()->json('data.returns.0');
        $this->api('POST', "ecommerce/returns/{$return['id']}/refund", ['reference' => 'CASH-3'])->assertOk();
        $detail = $this->inTenant(fn () => OrderReturn::query()->findOrFail($return['id']));
        $this->assertSame([], $detail->refund_detail, 'nothing paid back from a reversed payment');
        $this->assertContains('loyalty_share', array_column($detail->flags, 'code'));
    }

    public function test_a_riders_exchange_cash_needs_a_reference_and_joins_their_cash_up(): void
    {
        // Review L5.
        $this->withAccounting();
        $id = $this->delivered([[$this->widgetProduct, '1'], [$this->serviceProduct, '1']]);
        $service = $this->inTenant(fn () => OrderLine::query()->where('order_id', $id)->where('item_id', $this->service->id)->sole());
        $return = $this->api('POST', "ecommerce/orders/{$id}/returns", ['reason' => 'Swap', 'lines' => [['line_id' => $service->id, 'qty' => '1']],
            'exchange' => [['product_id' => $this->widgetProduct->id, 'qty' => '1']]], $this->manager)->assertCreated()->json('data.returns.0');
        $riderManager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->inTenant(fn () => Rider::create(['company_id' => $this->acme->id, 'user_id' => $riderManager->id, 'name' => 'Rider Manager', 'active' => true]));

        $this->api('POST', "ecommerce/returns/{$return['id']}/collect", ['payment_method_id' => $this->cash->id], $riderManager)->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->api('POST', "ecommerce/returns/{$return['id']}/collect", ['payment_method_id' => $this->cash->id, 'reference' => 'RCPT-9'], $riderManager)->assertOk();

        $rider = $this->inTenant(fn () => Rider::query()->where('user_id', $riderManager->id)->sole());
        $pending = collect($this->api('GET', 'ecommerce/cashups/pending', [], $this->manager)->json('data'))->firstWhere('rider.id', $rider->id)
            ?? collect($this->api('GET', 'ecommerce/cashups/pending', [], $this->manager)->json('data'))->firstWhere('rider_id', $rider->id);
        $this->assertSame('58000', $pending['expected_minor']);
        $this->api('POST', 'ecommerce/cashups', ['rider_id' => $rider->id, 'currency' => 'KES', 'counted_minor' => '58000'], $this->manager)->assertCreated();
        $this->assertNotNull($this->inTenant(fn () => OrderReturn::query()->findOrFail($return['id'])->cashup_id));
        $this->ledger('ecommerce.order');
        $this->ledger('ecommerce.cod_cashup');
    }

    private function approveReturn(string $returnId, ?User $as = null): void
    {
        $this->decideReturn($returnId, $as ?? $this->manager, 'approve');
    }

    private function decideReturn(string $returnId, User $as, string $action): void
    {
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $returnId)->where('status', 'pending')->sole()->id);
        $this->api('POST', "approvals/{$approval}/{$action}", $action === 'reject' ? ['comment' => 'Not this time'] : [], $as)->assertOk();
    }

    /** A cash on delivery order of $lines, accepted, handed to the rider and confirmed delivered by the manager. */
    private function delivered(array $lines): string
    {
        $id = $this->orderId($this->placeOrder($this->cart($lines))->assertCreated()->json('data.number'));
        $this->deliverPlaced($id);

        return $id;
    }

    private function deliverPlaced(string $id): void
    {
        $this->api('POST', "ecommerce/orders/{$id}/accept", [], $this->manager)->assertOk();
        $delivery = $this->inTenant(fn () => Delivery::query()->where('order_id', $id)->sole());
        $this->api('POST', "ecommerce/deliveries/{$delivery->id}/assign", ['rider_id' => $this->rider->id], $this->manager)->assertOk();
        $this->api('POST', "ecommerce/orders/{$id}/dispatch", [], $this->manager)->assertOk();
        $this->api('POST', "ecommerce/orders/{$id}/deliver", [], $this->manager)->assertOk();

    }
}
