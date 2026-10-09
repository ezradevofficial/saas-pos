<?php

namespace Modules\POS\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Models\NumberRange;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;
use Modules\POS\Models\SalePayment;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// POS-01, POS-07, POS-09, POS-11, NUM-02, AUTH-07: completed sales
// uploaded by a till: idempotent by id, checked against the device, device
// wins (flags, not refusals), tax at sale with "Rate needed" refused.
class SaleUploadTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPos();
        $this->ranges()->assertOk();
        $this->shift = $this->openShift();
    }

    public function test_a_sale_is_stored_with_its_lines_payments_and_base_amounts_and_raises_sale_completed_once(): void
    {
        Event::fake([SaleCompleted::class]);
        $body = $this->saleBody($this->shift);

        $first = $this->upload([$body])->assertOk()
            ->assertJsonPath('results.0.id', $body['id'])
            ->assertJsonPath('results.0.status', 'stored')
            ->assertJsonPath('results.0.receipt_number', 'R-L01-000001')
            ->assertJsonPath('results.0.flags', []);

        $this->inTenant(function () use ($body) {
            $sale = Sale::findOrFail($body['id']);
            $this->assertSame([$this->acme->id, $this->branchA->id, $this->locationA->id, $this->till->id], [$sale->company_id, $sale->branch_id, $sale->location_id, $sale->device_id]);
            $this->assertSame(['112500', '0', '12500', '112500', '112500'], [(string) $sale->subtotal_minor, (string) $sale->discount_minor, (string) $sale->tax_minor, (string) $sale->total_minor, (string) $sale->paid_minor]);
            $this->assertSame(['KES', '112500', '12500'], [$sale->base_currency, (string) $sale->base_total_minor, (string) $sale->base_tax_minor]);
            $this->assertTrue($sale->fx->isIdentity());
            $this->assertSame($this->owner->id, $sale->cashier_id);
            $this->assertSame(1, SaleLine::where('sale_id', $sale->id)->count());
            $this->assertSame(['100000', '12500', '112500'], SaleLine::where('sale_id', $sale->id)->get(['net_minor', 'tax_minor', 'total_minor'])->map(fn ($l) => [(string) $l->net_minor, (string) $l->tax_minor, (string) $l->total_minor])->first());
            $this->assertSame(1, SalePayment::where('sale_id', $sale->id)->count());
            $this->assertSame(1, AuditEntry::where('action', 'pos.sale.create')->where('auditable_id', $sale->id)->count());
            // CUR-02: the first sale locks the company's base currency.
            $this->assertNotNull($this->acme->fresh()->base_currency_locked_at);
            // NUM-02: the range knows number 1 is used.
            $this->assertSame(2, NumberRange::where('device_id', $this->till->id)->sole()->next_value);
        });

        // ADR 004: the same sale again stores nothing more and answers the same.
        $again = $this->upload([$body])->assertOk();
        $this->assertSame($first->json('results'), $again->json('results'));
        $this->inTenant(fn () => $this->assertSame(1, Sale::count()));
        Event::assertDispatchedTimes(SaleCompleted::class, 1);
    }

    public function test_one_bad_sale_does_not_block_the_batch(): void
    {
        $good = $this->saleBody($this->shift, 1);
        $bad = $this->saleBody($this->shift, 2, ['totals' => ['subtotal_minor' => '1', 'discount_minor' => '0', 'tax_minor' => '0', 'total_minor' => '1']]);
        $later = $this->saleBody($this->shift, 3);

        $response = $this->upload([$good, $bad, $later])->assertOk();

        $this->assertSame(['stored', 'rejected', 'stored'], array_column($response->json('results'), 'status'));
        $response->assertJsonPath('results.1.error.code', 'sale_totals_inconsistent')
            ->assertJsonPath('results.1.error.retryable', false);
        $this->inTenant(fn () => $this->assertSame(2, Sale::count()));
    }

    public function test_a_batch_where_nothing_is_stored_is_unprocessable(): void
    {
        $this->upload([$this->saleBody($this->shift, 1, ['shift_id' => $this->id()])])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'upload_rejected')
            ->assertJsonPath('results.0.error.code', 'shift_unknown')
            ->assertJsonPath('results.0.error.retryable', true);
    }

    public function test_the_shift_and_receipt_numbers_must_be_this_devices(): void
    {
        [, $otherToken] = $this->pairedTill($this->locationA, 'Till 2');
        $this->ranges(token: $otherToken)->assertOk();
        $otherShift = $this->openShift($otherToken);

        // Another till's shift.
        $this->upload([$this->saleBody($otherShift, 1)])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'shift_other_device');
        // The other till's range starts at 501: number 501 was never given to this till.
        $this->upload([$this->saleBody($this->shift, 501, ['receipt_number' => 'R-L01-000501'])])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'receipt_range_unknown');
        // A number not formatted as the range's pattern.
        $this->upload([$this->saleBody($this->shift, 1, ['receipt_number' => 'R-XX-000001'])])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'receipt_number_mismatch');

        // A number used twice, by two different sales.
        $this->upload([$this->saleBody($this->shift, 7)])->assertOk();
        $this->upload([$this->saleBody($this->shift, 7)])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'receipt_number_used');

        // A sale id another device already used is a conflict, not its result.
        $theirs = $this->saleBody($otherShift, 501, ['receipt_number' => 'R-L01-000501']);
        $this->upload([$theirs], $otherToken)->assertOk();
        $this->upload([[...$this->saleBody($this->shift, 8), 'id' => $theirs['id']]])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'id_conflict');
    }

    public function test_references_of_another_tenant_are_refused_and_never_stored(): void
    {
        $other = $this->otherTenant();
        $foreignUser = $other['user']->id;

        foreach ([
            ['cashier_id' => $foreignUser],
            ['customer_id' => $this->asTenant($other['user']->tenant_id, fn () => Party::create(['kind' => 'person', 'name' => 'X', 'roles' => ['customer']]))->id],
            ['price_list_id' => $this->asTenant($other['user']->tenant_id, fn () => PriceList::create(['company_id' => $other['company']->id, 'name' => 'X', 'currency' => 'KES']))->id],
            ['lines' => [$this->line(['override' => $this->override($foreignUser), 'unit_price_minor' => '50000', 'tax_minor' => '11111', 'total_minor' => '100000'])]],
        ] as $index => $overrides) {
            $this->upload([$this->saleBody($this->shift, 10 + $index, $overrides)])->assertUnprocessable();
        }

        $this->inTenant(fn () => $this->assertSame(0, Sale::count()));
    }

    public function test_an_item_whose_rate_is_needed_or_that_has_no_tax_code_is_refused(): void
    {
        $this->inTenant(fn () => TaxRate::create(['tax_code_id' => $this->vat->id, 'rate' => null, 'needs_confirmation' => true, 'effective_from' => now()->subDays(2)->toDateString()]));

        $this->upload([$this->saleBody($this->shift)])->assertUnprocessable()
            ->assertJsonPath('results.0.error.code', 'rate_needed')
            ->assertJsonPath('results.0.error.field', 'lines.0.item_id')
            ->assertJsonPath('results.0.error.message', 'Soap can\'t be sold: its tax rate is marked "Rate needed". Ask an administrator to enter the rate.');

        $this->inTenant(fn () => $this->soap->forceFill(['tax_category_id' => null])->save());
        $this->upload([$this->saleBody($this->shift)])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'tax_code_missing');
    }

    public function test_the_device_wins_on_tax_prices_and_discounts_and_the_server_flags_differences(): void
    {
        // Tax as sold differs from the server's 12.5 % (11111 for an inclusive 100000).
        $taxed = $this->line(['unit_price_minor' => '50000', 'list_price_minor' => '50000', 'tax_minor' => '10000', 'total_minor' => '100000']);
        $response = $this->upload([$this->saleBody($this->shift, 1, ['lines' => [$taxed]])])->assertOk();
        $this->assertSame([['code' => 'tax_differs', 'line' => 1, 'detail' => ['expected_tax_minor' => '11111', 'tax_code' => 'VAT_T', 'sold_tax_code_id' => $this->vat->id, 'sold_tax_rate' => '12.5000']]], $this->flagsBut($response, 'price_differs'));
        $this->inTenant(fn () => $this->assertSame('10000', (string) SaleLine::where('line_no', 1)->sole()->tax_minor));

        // A cashier without the discount permission and without a price override permission.
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $discounted = $this->line(['unit_price_minor' => '50000', 'list_price_minor' => '60000', 'discount_minor' => '10000', 'tax_minor' => '10000', 'total_minor' => '90000']);
        $response = $this->upload([$this->saleBody($this->shift, 2, ['cashier_id' => $cashier->id, 'lines' => [$discounted]])])->assertOk();
        $this->assertEqualsCanonicalizing(['discount_unauthorised', 'price_override_unauthorised'], array_column($this->flagsBut($response, 'price_differs'), 'code'));

        // With a 10 % limit the same discount is the cashier's own; a manager's override covers the price.
        $this->inTenant(function () {
            $this->roles->get('cashier')->givePermissionTo('pos.discount.give');
            LimitRule::create(['role_id' => $this->roles->get('cashier')->id, 'key' => 'max_discount_percent', 'value' => '10']);
        });
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $approved = [...$discounted, 'id' => $this->id(), 'actor_proof' => $this->actorProof($cashier->id), 'price_override' => $this->override($manager->id)];
        $response = $this->upload([$this->saleBody($this->shift, 3, ['cashier_id' => $cashier->id, 'lines' => [$approved]])])->assertOk();
        $this->assertSame([], $this->flagsBut($response, 'price_differs'));

        // AUTH-07: users switch mid-sale; a discount given by the manager on their own right (the
        // line's proof names them, 15 % here, above the cashier's 10 %) is the manager's, even though the cashier completes the sale.
        $this->inTenant(fn () => LimitRule::create(['role_id' => $this->roles->get('branch_manager')->id, 'key' => 'max_discount_percent', 'value' => '20']));
        $byManager = [...$discounted, 'id' => $this->id(), 'unit_price_minor' => '60000', 'actor_proof' => $this->actorProof($manager->id), 'discount_minor' => '18000', 'tax_minor' => '10000', 'total_minor' => '102000'];
        $response = $this->upload([$this->saleBody($this->shift, 5, ['cashier_id' => $cashier->id, 'actor_proof' => $this->actorProof($cashier->id), 'lines' => [$byManager]])])->assertOk();
        $this->assertSame([], $this->flagsBut($response, 'price_differs', 'tax_differs'));

        // H3: money in is never held: an override or a cashier that can't be proven is kept and flagged.
        $unproven = [...$discounted, 'id' => $this->id(), 'price_override' => $this->override($manager->id, proven: false)];
        $response = $this->upload([$this->saleBody($this->shift, 4, ['cashier_id' => $cashier->id, 'actor_proof' => null, 'lines' => [$unproven]])])->assertOk();
        $this->assertSame([
            ['code' => 'actor_unverified'],
            ['code' => 'actor_unverified', 'line' => 1],
            ['code' => 'override_unverified', 'line' => 1],
        ], $this->flagsBut($response, 'price_differs'));
        $this->inTenant(function () use ($approved, $manager) {
            $line = SaleLine::findOrFail($approved['id']);
            $this->assertSame([null, $manager->id], [$line->discount_override_by, $line->price_override_by]);
            $this->assertSame(2, AuditEntry::where('action', 'pos.sale.price_override')->where('on_behalf_of_user_id', $manager->id)->count());
            $this->assertSame(4, AuditEntry::where('action', 'pos.sale.discount')->count());
        });
    }

    /** The sale's flags without $code (the prices in this test differ from the server's on purpose). */
    private function flagsBut($response, string ...$codes): array
    {
        return array_values(array_filter($response->json('results.0.flags'), fn (array $flag) => ! in_array($flag['code'], $codes, true)));
    }

    public function test_line_and_sale_sums_must_add_up(): void
    {
        $this->upload([$this->saleBody($this->shift, 1, ['lines' => [$this->line(['total_minor' => '112499'])]])])
            ->assertUnprocessable()->assertJsonPath('results.0.error.code', 'line_totals_inconsistent');
        $tooMuch = $this->saleBody($this->shift, 1, ['lines' => [$this->line(['discount_minor' => '200000', 'total_minor' => '0', 'tax_minor' => '0'])]]);
        $tooMuch['payments'][0]['amount_minor'] = $tooMuch['payments'][0]['amount_in_sale_minor'] = '100';
        $this->upload([$tooMuch])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'discount_above_price');

        $underpaid = $this->saleBody($this->shift, 1);
        $underpaid['payments'][0]['amount_minor'] = '100000';
        $underpaid['payments'][0]['amount_in_sale_minor'] = '100000';
        $this->upload([$underpaid])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'sale_underpaid');
    }

    public function test_a_customer_must_be_one_of_the_companys(): void
    {
        $shared = $this->customer();
        $this->upload([$this->saleBody($this->shift, 1, ['customer_id' => $shared->id])])->assertOk();

        $supplier = $this->inTenant(fn () => Party::create(['kind' => 'organisation', 'name' => 'Supplier', 'roles' => ['supplier']]));
        $this->upload([$this->saleBody($this->shift, 2, ['customer_id' => $supplier->id])])->assertUnprocessable()->assertJsonPath('results.0.error.code', 'customer_unknown');
    }

    public function test_a_cashier_without_permission_is_flagged_and_an_unknown_user_refused(): void
    {
        $stranger = $this->inTenant(fn () => $this->colleague($this->owner));

        $this->upload([$this->saleBody($this->shift, 1, ['cashier_id' => $stranger->id])])->assertOk()
            ->assertJsonPath('results.0.flags.0.code', 'cashier_not_permitted');
        $this->upload([$this->saleBody($this->shift, 2, ['cashier_id' => $this->id()])])->assertUnprocessable()
            ->assertJsonPath('results.0.error.code', 'user_unknown');
    }

    public function test_the_shape_is_validated_for_the_whole_batch(): void
    {
        $body = $this->saleBody($this->shift);
        $body['id'] = '00000000-0000-4000-8000-000000000000';
        $body['lines'][0]['unit_price_minor'] = 562.5;
        $body['lines'][0]['qty'] = '0';

        $this->upload([$body])->assertUnprocessable()->assertJsonValidationErrors(['sales.0.id', 'sales.0.lines.0.unit_price_minor', 'sales.0.lines.0.qty']);
    }

    public function test_only_a_device_token_of_a_tenant_with_the_module_reaches_the_upload(): void
    {
        $this->postJson('/api/v1/pos/sales', ['sales' => [$this->saleBody($this->shift)]], $this->headersFor())->assertForbidden();

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('pos'));
        $this->upload([$this->saleBody($this->shift)])->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }
}
