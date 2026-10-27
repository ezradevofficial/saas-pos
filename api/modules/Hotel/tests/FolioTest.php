<?php

namespace Modules\Hotel\Tests;

use App\Core\Fiscal\FiscalQueue;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\Payments\Events\PaymentIntentSettled;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\PaymentIntents;
use App\Core\Payments\ProviderResult;
use App\Core\Rbac\Scope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\Hotel\Events\HotelPosting;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Payment;
use Modules\Hotel\Tests\Concerns\BuildsHotel;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HOT-14, HOT-19 to HOT-27, HOT-32: the folio ledger: nightly charges, extras, tax, deposits, payments, refunds, transfers, voids, settlement.
class FolioTest extends TestCase
{
    use BuildsHotel, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHotel();
    }

    private function folioOf(array $stay): string
    {
        return $this->inTenant(fn () => Folio::query()->where('stay_id', $stay['id'])->value('id'));
    }

    private function balance(string $folio): string
    {
        return $this->getJson("/api/v1/hotel/folios/{$folio}", $this->headersFor())->assertOk()->json('data.balance_minor');
    }

    private function audit(): void
    {
        $this->postJson('/api/v1/hotel/night-audits', $this->auditBody(), $this->headersFor())->assertCreated();
    }

    public function test_folio_maths_nights_extras_deposit_payment_and_settlement_into_an_invoice(): void
    {
        Event::fake([HotelPosting::class]);
        $stay = $this->checkedIn(2, '101');
        $folio = $this->folioOf($stay);

        // An extra: 2 × KES 250.00 plus the test tax of 12.5 % = KES 562.50.
        $extra = $this->postJson("/api/v1/hotel/folios/{$folio}/charges", ['item_id' => $this->minibar->id, 'qty' => 2, 'unit_price_minor' => 25000, 'description' => 'Minibar', 'reason' => 'Agreed with the guest'], $this->headersFor())->assertCreated()->json('data');
        $this->assertSame(['extra', '56250', '50000', '6250'], [$extra['kind'], $extra['amount_minor'], $extra['net_minor'], $extra['tax_minor']]);

        $this->audit();
        $this->audit();
        // Two nights, tax included in the plan: gross 20,000.00, tax 12.5/112.5 per night.
        $lines = $this->getJson("/api/v1/hotel/folios/{$folio}", $this->headersFor())->json('data.lines');
        $rooms = array_values(array_filter($lines, fn ($l) => $l['kind'] === 'room'));
        $this->assertSame(['1000000', '888889', '111111'], [$rooms[0]['amount_minor'], $rooms[0]['net_minor'], $rooms[0]['tax_minor']]);
        $this->assertSame([$this->day(0), $this->day(1)], array_column($rooms, 'business_date'));

        // A deposit then a payment. Balance: 2,000,000 + 56,250 - 500,000.
        $this->postJson("/api/v1/hotel/folios/{$folio}/payments", ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => 500000, 'kind' => 'deposit'], $this->headersFor())->assertCreated()->assertJsonPath('data.status', 'settled');
        $this->assertSame('1556250', $this->balance($folio));
        $this->postJson("/api/v1/hotel/stays/{$stay['id']}/check-out", [], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'folio_balance');
        $out = $this->postJson("/api/v1/hotel/stays/{$stay['id']}/check-out", ['payments' => [['payment_method_id' => $this->methods['mpesa']->id, 'amount_minor' => 1556250, 'reference' => 'QJK3XYZ']]], $this->headersFor())->assertOk()->json('data.folio');
        $this->assertSame(['settled', '0'], [$out['status'], $out['balance_minor']]);
        $this->assertStringStartsWith('HI-A-2027-', $out['invoice_number']);

        // The invoice is the hotel's own charges: two nights and the minibar; payments are not on it.
        $invoice = $this->getJson("/api/v1/hotel/folios/{$folio}/print", $this->headersFor())->json('data.invoice');
        $this->assertSame('2056250', $invoice['gross_minor']);
        $this->assertCount(3, $invoice['lines']);

        // The ledger saw three charges, a deposit applied at settlement, a deposit and two payments' worth of tender.
        $types = [];
        Event::assertDispatched(HotelPosting::class, function (HotelPosting $e) use (&$types) {
            $types[] = $e->sourceType;

            return true;
        });
        $count = array_count_values($types);
        $this->assertSame([3, 1, 1, 1], [$count['hotel.charge'], $count['hotel.deposit'], $count['hotel.deposit_applied'], $count['hotel.payment']]);
        Event::assertDispatched(HotelPosting::class, fn (HotelPosting $e) => $e->sourceType === 'hotel.charge' && ($e->amounts['revenue.rooms'] ?? null) === '888889' && ($e->amounts['ledger'] ?? null) === '1000000');
        Event::assertDispatched(HotelPosting::class, fn (HotelPosting $e) => $e->sourceType === 'hotel.deposit_applied' && $e->amounts === ['deposit' => '500000', 'ledger' => '500000']);

        // Nothing more can be added to a settled folio.
        $this->postJson("/api/v1/hotel/folios/{$folio}/charges", ['amount_minor' => 1000, 'description' => 'Late'], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'folio_settled');
    }

    public function test_the_ledger_is_append_only(): void
    {
        $stay = $this->checkedIn(1, '101');
        $folio = $this->folioOf($stay);
        $line = $this->postJson("/api/v1/hotel/folios/{$folio}/charges", ['amount_minor' => 1000, 'description' => 'Parking'], $this->headersFor())->assertCreated()->json('data.id');
        $this->expectException(QueryException::class);
        $this->inTenant(fn () => DB::table('hotel_folio_lines')->where('id', $line)->update(['amount_minor' => 1]));
    }

    public function test_a_levy_tax_code_is_added_to_room_nights_and_a_missing_rate_posts_untaxed_and_flagged(): void
    {
        $levy = $this->inTenant(fn () => TaxCode::create(['company_id' => $this->acme->id, 'code' => 'LEVY_T', 'name' => 'Levy test', 'kind' => 'excise']));
        $this->putJson("/api/v1/companies/{$this->acme->id}/hotel-settings", ['levy_tax_code_ids' => [$levy->id]], $this->headersFor())->assertOk();
        $stay = $this->checkedIn(2, '101');
        $folio = $this->folioOf($stay);
        // The levy has no confirmed rate: the night is posted untaxed and flagged, never with an invented rate.
        $this->audit();
        $line = $this->getJson("/api/v1/hotel/folios/{$folio}", $this->headersFor())->json('data.lines.0');
        $this->assertSame(['1000000', '0', ['tax_rate_needed']], [$line['amount_minor'], $line['tax_minor'], $line['flags']]);

        $this->inTenant(fn () => TaxRate::create(['tax_code_id' => $levy->id, 'rate' => '2', 'effective_from' => '2026-01-01']));
        $this->audit();
        $second = $this->getJson("/api/v1/hotel/folios/{$folio}", $this->headersFor())->json('data.lines.1');
        // Inclusive: 1,000,000 × 14.5 / 114.5 split 12.5 : 2.
        $this->assertSame('126638', $second['tax_minor']);
        $this->assertCount(2, $second['taxes']);
    }

    public function test_refunds_are_limited_to_the_credit_and_to_the_users_refund_limit(): void
    {
        $stay = $this->checkedIn(1, '101');
        $folio = $this->folioOf($stay);
        $cash = $this->methods['cash_kes']->id;
        $this->postJson("/api/v1/hotel/folios/{$folio}/payments", ['payment_method_id' => $cash, 'amount_minor' => 300000], $this->headersFor())->assertCreated();
        $this->postJson("/api/v1/hotel/folios/{$folio}/refunds", ['payment_method_id' => $cash, 'amount_minor' => 400000, 'reason' => 'Overpaid'], $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'refund_exceeds_credit');
        $this->postJson("/api/v1/hotel/folios/{$folio}/refunds", ['payment_method_id' => $cash, 'amount_minor' => 100000, 'reason' => 'Overpaid'], $this->headersFor())->assertCreated()->assertJsonPath('data.kind', 'refund');
        $this->assertSame('-200000', $this->balance($folio));

        $receptionist = $this->userWith('receptionist', Scope::branch($this->branchA->id));
        $this->postJson("/api/v1/hotel/folios/{$folio}/refunds", ['payment_method_id' => $cash, 'amount_minor' => 100000, 'reason' => 'Overpaid'], $this->headersFor($receptionist))->assertStatus(403)->assertJsonPath('code', 'refund_above_limit');
        $this->assertGreaterThan(0, $this->audited('hotel.folio.refund'));
    }

    public function test_charges_move_between_folios_in_pairs_that_net_to_zero_and_a_line_voids_once(): void
    {
        $one = $this->folioOf($this->checkedIn(2, '101'));
        $two = $this->folioOf($this->checkedIn(2, '102'));
        $charge = $this->postJson("/api/v1/hotel/folios/{$one}/charges", ['amount_minor' => 40000, 'description' => 'Laundry'], $this->headersFor())->assertCreated()->json('data.id');

        $this->postJson("/api/v1/hotel/folios/{$one}/transfers", ['to_folio_id' => $two, 'line_ids' => [$charge], 'reason' => 'Paid by the other room'], $this->headersFor())->assertCreated();
        $this->assertSame(['0', '40000'], [$this->balance($one), $this->balance($two)]);
        $this->postJson("/api/v1/hotel/folios/{$one}/transfers", ['to_folio_id' => $two, 'line_ids' => [$charge], 'reason' => 'Again'], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'transfer_line_not_movable');
        $this->postJson("/api/v1/hotel/folios/{$one}/transfers", ['to_folio_id' => $one, 'line_ids' => [$charge], 'reason' => 'Same'], $this->headersFor())->assertStatus(422);
        $this->assertGreaterThan(0, $this->audited('hotel.folio.transfer'));

        // The moved charge is still a hotel charge on the receiving folio's invoice, and can be voided there.
        $moved = collect($this->getJson("/api/v1/hotel/folios/{$two}", $this->headersFor())->json('data.lines'))->firstWhere('kind', 'transfer_in');
        $this->postJson("/api/v1/hotel/folios/{$two}/lines/{$moved['id']}/void", ['reason' => 'Mistake'], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'void_not_allowed');

        $own = $this->postJson("/api/v1/hotel/folios/{$two}/charges", ['amount_minor' => 5000, 'description' => 'Parking'], $this->headersFor())->json('data.id');
        $this->postJson("/api/v1/hotel/folios/{$two}/lines/{$own}/void", ['reason' => 'Mistake'], $this->headersFor())->assertCreated();
        $this->postJson("/api/v1/hotel/folios/{$two}/lines/{$own}/void", ['reason' => 'Again'], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'already_voided');
        $this->assertSame('40000', $this->balance($two));
        $this->assertGreaterThan(0, $this->audited('hotel.folio.void'));
    }

    public function test_a_mobile_money_push_posts_the_payment_once_when_the_provider_settles(): void
    {
        config(['payments.callback_base_url' => 'https://api.example.com', 'payments.mpesa.base_url' => 'https://sandbox.safaricom.co.ke', 'payments.drivers' => []]);
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/stkpush/*' => Http::response(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_hotel1', 'ResponseCode' => '0', 'ResponseDescription' => 'Success', 'CustomerMessage' => 'Success']),
        ]);
        $this->inTenant(fn () => $this->methods['mpesa']->fill(['settings' => ['shortcode' => '174379'], 'secrets' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs', 'passkey' => 'pk'], 'active' => true])->save());
        $folio = $this->folioOf($this->checkedIn(1, '101'));

        $payment = $this->postJson("/api/v1/hotel/folios/{$folio}/payments", ['payment_method_id' => $this->methods['mpesa']->id, 'amount_minor' => 200000, 'kind' => 'deposit', 'mode' => 'stk', 'phone' => '0712345678'], $this->headersFor())
            ->assertStatus(202)->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertSame('0', $this->balance($folio));

        $this->inTenant(function () use ($payment) {
            $intent = PaymentIntent::query()->findOrFail($payment['id']);
            $this->assertSame(['hotel.payment', 'stk'], [$intent->reference_type, $intent->mode]);
            $applied = app(PaymentIntents::class)->apply($intent, ProviderResult::succeeded('QJK3HOTEL1', ['result_code' => '0'], 200000));
            event(new PaymentIntentSettled($intent->tenant_id, $intent->id, 'hotel.payment', $intent->reference));
            event(new PaymentIntentSettled($intent->tenant_id, $intent->id, 'hotel.payment', $intent->reference));
            $this->assertSame('succeeded', $applied->status);
        });

        $this->assertSame('-200000', $this->balance($folio));
        $this->assertSame(['settled', 'QJK3HOTEL1'], $this->inTenant(fn () => [Payment::query()->find($payment['id'])->status, Payment::query()->find($payment['id'])->reference]));
        $this->assertSame(1, $this->inTenant(fn () => FolioLine::query()->where('folio_id', $folio)->where('kind', 'deposit')->count()));
    }

    public function test_settling_queues_the_invoice_on_the_fiscal_queue_once(): void
    {
        $this->inTenant(fn () => $this->vat->forceFill(['fiscal_code' => 'B'])->save());
        $this->putJson("/api/v1/companies/{$this->acme->id}/fiscal-settings", ['driver' => 'fake', 'tin' => 'P051111111A', 'enabled' => true], $this->headersFor())->assertSuccessful();
        $stay = $this->checkedIn(1, '101');
        $folio = $this->folioOf($stay);
        $this->audit();
        $this->postJson("/api/v1/hotel/stays/{$stay['id']}/check-out", ['payments' => [['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => 1000000]]], $this->headersFor())->assertOk();

        $submission = $this->inTenant(fn () => app(FiscalQueue::class)->find('hotel', 'sale', $folio));
        $this->assertNotNull($submission);
        $this->assertSame(1000000, $submission->payload['totals']['total_minor']);
        $this->assertSame(111111, $submission->payload['totals']['tax_minor']);
        $this->assertSame('cash', $submission->payload['payment_type']);
    }
}
