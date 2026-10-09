<?php

namespace Modules\POS\Tests;

use App\Core\Fiscal\FiscalQueue;
use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\Identity\Models\User;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\Scope;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\POS\Fiscal\PosFiscalSource;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// POS-10, concept note 7.1 and 7.2: the POS wiring into the core payment
// and fiscal services. Sales, refunds and voids are queued once for the
// tax authority from the module's own tables; the till reads the fiscal
// state of its receipts; mobile money codes recorded at the till become
// intents to verify, and mobile money refunds are paid back by B2C.
class PaymentsAndFiscalTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private User $cashier;

    private User $manager;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();

        $this->setUpPos();
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->inTenant(fn () => $this->vat->forceFill(['fiscal_code' => 'B'])->save());
        // RBAC-06: the manager may approve refunds (a test limit).
        $this->inTenant(fn () => LimitRule::create(['role_id' => $this->roles->get('branch_manager')->id, 'key' => 'max_refund_amount', 'value' => '100000']));
        $this->ranges()->assertOk();
        $this->ranges('pos.refund')->assertOk();
        $this->shift = $this->openShift();
    }

    private function transmit(): void
    {
        $this->putJson("/api/v1/companies/{$this->acme->id}/fiscal-settings", ['driver' => 'fake', 'tin' => 'P051111111A', 'enabled' => true], $this->headersFor())->assertCreated();
    }

    private function submissions(): array
    {
        return $this->inTenant(fn () => FiscalSubmission::query()->orderBy('invoice_no')->get(['document_type', 'document_id', 'status', 'invoice_no', 'original_submission_id'])->toArray());
    }

    public function test_a_sale_is_queued_once_and_its_fiscal_state_reaches_the_till(): void
    {
        $this->transmit();
        $sale = $this->saleBody($this->shift, 1, ['cashier_id' => $this->cashier->id]);

        $result = $this->upload([$sale])->assertOk()->json('results.0');
        $this->assertContains($result['fiscal'], ['pending', 'accepted']);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.fiscal', 'accepted');

        $this->assertCount(1, $this->submissions());
        $payload = $this->inTenant(fn () => FiscalSubmission::query()->sole()->payload);
        $this->assertSame('R-L01-000001', $payload['number']);
        $this->assertSame('SOAP', $payload['lines'][0]['item_code']);
        $this->assertSame('12.5000', $payload['lines'][0]['tax_rate']);
        $this->assertSame(112500, $payload['lines'][0]['total_minor']);
        $this->assertSame(['id' => $this->cashier->id, 'name' => $this->cashier->name], $payload['cashier']);
        $this->assertSame('cash', $payload['payment_type']);

        $this->getJson("/api/v1/pos/sales/{$sale['id']}/fiscal", $this->tillHeaders())->assertOk()
            ->assertJsonPath('data.sale.status', 'accepted')
            ->assertJsonPath('data.sale.invoice_number', 1)
            ->assertJsonStructure(['data' => ['sale' => ['authority' => ['receipt_signature', 'internal_data', 'qr']]]]);

        // Another location's till does not find the sale.
        [, $other] = $this->pairedTill($this->locationB, 'Till 2');
        $this->getJson("/api/v1/pos/sales/{$sale['id']}/fiscal", $this->tillHeaders($other))->assertNotFound();
    }

    public function test_nothing_is_queued_while_the_company_does_not_transmit(): void
    {
        $sale = $this->saleBody($this->shift, 1);

        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.fiscal', null);
        $this->getJson("/api/v1/pos/sales/{$sale['id']}/fiscal", $this->tillHeaders())->assertOk()->assertJsonPath('data.sale', null);
        $this->assertSame([], $this->submissions());
    }

    public function test_refunds_and_voids_are_credit_notes_naming_the_sale(): void
    {
        $this->transmit();
        $sale = $this->saleBody($this->shift, 1, ['cashier_id' => $this->cashier->id, 'lines' => [$this->line(['qty' => '3', 'tax_minor' => '18750', 'total_minor' => '168750'])]]);
        $this->upload([$sale])->assertOk();

        $refund = [
            'id' => $this->id(), 'sale_id' => $sale['id'], 'shift_id' => $this->shift, 'cashier_id' => $this->cashier->id,
            'receipt_seq' => 1, 'receipt_number' => 'RF-L01-000001', 'refunded_at' => now()->toIso8601String(),
            'reason' => 'Damaged', 'total_minor' => '56250',
            'lines' => [['id' => $this->id(), 'sale_line_id' => $sale['lines'][0]['id'], 'qty' => '1']],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => '56250', 'amount_in_sale_minor' => '56250']],
            'override' => $this->override($this->manager->id),
        ];
        $this->postJson('/api/v1/pos/refunds', ['refunds' => [$refund]], $this->tillHeaders())->assertOk()->assertJsonPath('results.0.refund_status', 'applied');

        $rows = $this->submissions();
        $this->assertSame(['sale', 'refund'], array_column($rows, 'document_type'));
        $this->assertSame(['accepted', 'accepted'], array_column($rows, 'status'));
        $credit = $this->inTenant(fn () => FiscalSubmission::query()->where('document_type', 'refund')->sole());
        $this->assertSame('RF-L01-000001', $credit->payload['number']);
        $this->assertSame('1.000000', $credit->payload['lines'][0]['qty']);
        $this->assertSame(56250, $credit->payload['lines'][0]['total_minor']);
        $this->assertEquals(['type' => 'sale', 'id' => $sale['id']], $credit->payload['original']);

        $this->getJson("/api/v1/pos/sales/{$sale['id']}/fiscal", $this->tillHeaders())->assertOk()
            ->assertJsonPath('data.refunds.0.id', $refund['id'])
            ->assertJsonPath('data.refunds.0.fiscal.status', 'accepted');

        // A void of another sale is a credit note for the whole sale.
        $second = $this->saleBody($this->shift, 2);
        $this->upload([$second])->assertOk();
        $void = $this->id();
        $this->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $void, 'sale_id' => $second['id'], 'voided_by_id' => $this->cashier->id,
            'voided_at' => now()->toIso8601String(), 'reason' => 'Wrong items', 'override' => $this->override($this->manager->id),
        ]]], $this->tillHeaders())->assertOk();
        $voided = $this->inTenant(fn () => app(FiscalQueue::class)->find(PosFiscalSource::KEY, 'void', $void));
        $this->assertSame('accepted', $voided->status);
        $this->assertSame(112500, $voided->payload['totals']['total_minor']);
    }

    public function test_a_line_whose_tax_code_has_no_fiscal_code_is_rejected_at_the_queue_not_at_the_till(): void
    {
        $this->transmit();
        $this->inTenant(fn () => $this->vat->forceFill(['fiscal_code' => null])->save());
        $sale = $this->saleBody($this->shift, 1);

        // The device wins for completed sales: stored; the authority's queue refuses it with the reason.
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->getJson("/api/v1/pos/sales/{$sale['id']}/fiscal", $this->tillHeaders())->assertOk()->assertJsonPath('data.sale.status', 'rejected');
        $this->assertSame('fiscal_code_missing', $this->inTenant(fn () => FiscalSubmission::query()->sole()->error_code));
    }

    public function test_mobile_money_codes_become_intents_and_refunds_are_paid_back(): void
    {
        config([
            'payments.mpesa.base_url' => 'https://sandbox.safaricom.co.ke',
            'payments.callback_base_url' => 'https://api.example.com',
        ]);
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/b2c/*' => Http::response(['ConversationID' => 'AG_1', 'OriginatorConversationID' => 'o-1', 'ResponseCode' => '0']),
        ]);
        $this->inTenant(fn () => $this->methods['mpesa']->fill([
            'settings' => ['shortcode' => '174379', 'initiator_name' => 'apiop', 'b2c_shortcode' => '600000'],
            'secrets' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs', 'passkey' => 'pk', 'security_credential' => 'sc'],
        ])->save());

        // Sold offline: the cashier typed the M-Pesa code.
        $sale = $this->saleBody($this->shift, 1, ['cashier_id' => $this->cashier->id, 'offline' => true]);
        $sale['payments'][0] = [...$sale['payments'][0], 'payment_method_id' => $this->methods['mpesa']->id, 'provider_reference' => 'qjk3offln1', 'status' => 'pending'];
        $this->upload([$sale])->assertOk();
        $this->upload([$sale])->assertOk();

        $intent = $this->inTenant(fn () => PaymentIntent::query()->sole());
        $this->assertSame($sale['payments'][0]['id'], $intent->id);
        $this->assertSame(['manual', 'succeeded', 'unverified', 'QJK3OFFLN1', 'pos.sale', $sale['id']],
            [$intent->mode, $intent->status, $intent->verification, $intent->provider_receipt, $intent->reference_type, $intent->reference]);

        // The phone is only known for STK pushes; give the intent one as a push would.
        $this->inTenant(fn () => $intent->fill(['phone' => '254712345678'])->save());

        $refund = [
            'id' => $this->id(), 'sale_id' => $sale['id'], 'shift_id' => $this->shift, 'cashier_id' => $this->cashier->id,
            'receipt_seq' => 1, 'receipt_number' => 'RF-L01-000001', 'refunded_at' => now()->toIso8601String(),
            'reason' => 'Damaged', 'total_minor' => '112500',
            'lines' => [['id' => $this->id(), 'sale_line_id' => $sale['lines'][0]['id'], 'qty' => '2']],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['mpesa']->id, 'currency' => 'KES', 'amount_minor' => '112500', 'amount_in_sale_minor' => '112500', 'status' => 'pending']],
            'override' => $this->override($this->manager->id),
        ];
        $response = $this->postJson('/api/v1/pos/refunds', ['refunds' => [$refund]], $this->tillHeaders());

        $response->assertOk()->assertJsonPath('results.0.refund_status', 'applied');

        $payout = $this->inTenant(fn () => PaymentIntent::query()->where('purpose', 'refund')->sole());
        $this->assertSame(['payout', 'pending', $intent->id, $refund['payments'][0]['id']], [$payout->mode, $payout->status, $payout->original_intent_id, $payout->id]);
        $b2c = Http::recorded(fn (Request $r) => str_contains($r->url(), '/mpesa/b2c/'))->first()[0]->data();
        $this->assertSame('254712345678', $b2c['PartyB']);
        $this->assertSame(1125, $b2c['Amount']);
    }
}
