<?php

namespace Modules\Salon\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Payments\Events\PaymentIntentSettled;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\PaymentIntents;
use App\Core\Payments\ProviderResult;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Http;
use Modules\POS\Models\Sale;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Payment;
use Modules\Salon\Models\SalonException;
use Modules\Salon\Payments\Deposits;
use Modules\Salon\Tests\Concerns\BuildsSalon;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// SAL-10, SAL-11, SAL-17: deposits taken with the company's payment methods (M-Pesa included), refunded within limits, and used at the till.
class DepositTest extends TestCase
{
    use BuildsSalon, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSalon();
        $this->api('PUT', "salon/companies/{$this->acme->id}/settings", ['deposit_percent' => 50], $this->owner)->assertOk()->assertJsonPath('data.deposit_percent', '50');
    }

    /** @return list<string> the codes of the flags a stored sale carries ("tender_flagged:salon_deposit_insufficient") */
    private function flagsOf(string $saleId): array
    {
        return $this->inTenant(fn () => collect(Sale::query()->findOrFail($saleId)->flags)->map(fn (array $f) => $f['code'].(isset($f['detail']['code']) ? ':'.$f['detail']['code'] : ''))->all());
    }

    private function balance(string $id): int
    {
        return $this->inTenant(fn () => app(Deposits::class)->balance($id));
    }

    public function test_a_deposit_is_part_of_the_bill_taken_by_the_desk_and_refunded_only_by_those_who_may(): void
    {
        $made = $this->book('10:00');
        $this->assertSame('50000', $made['deposit_required_minor']);
        $cash = $this->methods['cash_kes']->id;

        $this->api('POST', "salon/appointments/{$made['id']}/deposit", ['payment_method_id' => $cash, 'amount_minor' => '50000'], $this->annUser)->assertForbidden();
        $paid = $this->api('POST', "salon/appointments/{$made['id']}/deposit", ['payment_method_id' => $cash, 'amount_minor' => '50000', 'reference' => 'RCPT-1'], $this->desk)->assertCreated()->json('data');
        $this->assertSame(['deposit', 'settled', 'cash'], [$paid['kind'], $paid['status'], $paid['method_type']]);
        $this->assertSame(50000, $this->balance($made['id']));
        // A deposit is never more than the bill.
        $this->api('POST', "salon/appointments/{$made['id']}/deposit", ['payment_method_id' => $cash, 'amount_minor' => '60000'], $this->desk)->assertStatus(422)->assertJsonPath('code', 'deposit_exceeds_total');
        $this->api('POST', "salon/appointments/{$made['id']}/deposit", ['payment_method_id' => $cash, 'amount_minor' => '0'], $this->desk)->assertStatus(422);
        // A card method of another kind (a points method) is not a way to pay a deposit.
        $this->api('POST', "salon/appointments/{$made['id']}/deposit", ['payment_method_id' => $this->creditMethodId(), 'amount_minor' => '100'], $this->desk)->assertStatus(422)->assertJsonPath('code', 'payment_method_type');

        // The desk cannot refund; the manager can, up to what is held.
        $this->api('POST', "salon/appointments/{$made['id']}/deposit/refund", ['payment_method_id' => $cash, 'amount_minor' => '10000'], $this->desk)->assertForbidden();
        $this->api('POST', "salon/appointments/{$made['id']}/deposit/refund", ['payment_method_id' => $cash, 'amount_minor' => '60000'], $this->manager)->assertStatus(422)->assertJsonPath('code', 'refund_exceeds_credit');
        $this->api('POST', "salon/appointments/{$made['id']}/deposit/refund", ['payment_method_id' => $cash, 'amount_minor' => '20000'], $this->manager)->assertCreated()->assertJsonPath('data.kind', 'refund');
        $this->assertSame(30000, $this->balance($made['id']));
        $this->assertSame(1, AuditEntry::where('action', 'salon.deposit.refund')->count());
        $this->assertSame(1, AuditEntry::where('action', 'salon.deposit.take')->count());

        // Someone who may refund but holds no limit rule refuses (RBAC-06): the limit is the role's, never the screen's.
        $nolimit = $this->inTenant(function () {
            $role = $this->role('Refunder', ['salon.deposit.refund', 'core.company.view']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::branch($this->branchA->id));

            return $user;
        });
        $this->api('POST', "salon/appointments/{$made['id']}/deposit/refund", ['payment_method_id' => $cash, 'amount_minor' => '1000'], $nolimit)->assertStatus(403)->assertJsonPath('code', 'refund_above_limit');
    }

    public function test_a_fee_still_due_is_kept_out_of_the_refund(): void
    {
        $made = $this->book('10:00');
        $cash = $this->methods['cash_kes']->id;
        $this->api('POST', "salon/appointments/{$made['id']}/deposit", ['payment_method_id' => $cash, 'amount_minor' => '50000'], $this->desk)->assertCreated();
        // The visit is inside the window: cancelling makes a fee of 10 % (10,000) which the deposit stands behind.
        $this->api('POST', "salon/appointments/{$made['id']}/cancel", ['reason' => 'Ill'], $this->desk)->assertOk()->assertJsonPath('data.fee_minor', '10000');

        $this->api('POST', "salon/appointments/{$made['id']}/deposit/refund", ['payment_method_id' => $cash, 'amount_minor' => '45000'], $this->manager)->assertStatus(422)->assertJsonPath('code', 'refund_exceeds_credit');
        $this->api('POST', "salon/appointments/{$made['id']}/deposit/refund", ['payment_method_id' => $cash, 'amount_minor' => '40000'], $this->manager)->assertCreated();
        // A cancelled booking takes no more deposits.
        $this->api('POST', "salon/appointments/{$made['id']}/deposit", ['payment_method_id' => $cash, 'amount_minor' => '1000'], $this->desk)->assertStatus(409)->assertJsonPath('code', 'appointment_not_open');
    }

    public function test_a_mobile_money_push_holds_the_deposit_once_when_the_provider_settles(): void
    {
        config(['payments.callback_base_url' => 'https://api.example.com', 'payments.mpesa.base_url' => 'https://sandbox.safaricom.co.ke', 'payments.drivers' => []]);
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/stkpush/*' => Http::response(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_salon1', 'ResponseCode' => '0', 'ResponseDescription' => 'Success', 'CustomerMessage' => 'Success']),
        ]);
        $this->inTenant(fn () => $this->methods['mpesa']->fill(['settings' => ['shortcode' => '174379'], 'secrets' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs', 'passkey' => 'pk'], 'active' => true])->save());
        $made = $this->book('10:00');

        $payment = $this->api('POST', "salon/appointments/{$made['id']}/deposit", ['payment_method_id' => $this->methods['mpesa']->id, 'amount_minor' => '50000', 'mode' => 'stk', 'phone' => '0712345678'], $this->desk)
            ->assertStatus(202)->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertSame(0, $this->balance($made['id']));
        $this->api('POST', "salon/appointments/{$made['id']}/deposit", ['payment_method_id' => $this->methods['mpesa']->id, 'amount_minor' => '50000', 'mode' => 'stk'], $this->desk)->assertStatus(422);

        $this->inTenant(function () use ($payment) {
            $intent = PaymentIntent::query()->findOrFail($payment['id']);
            $this->assertSame(['salon.payment', 'stk'], [$intent->reference_type, $intent->mode]);
            app(PaymentIntents::class)->apply($intent, ProviderResult::succeeded('QJK3SALON1', ['result_code' => '0'], 50000));
            event(new PaymentIntentSettled($intent->tenant_id, $intent->id, 'salon.payment', $intent->reference));
            event(new PaymentIntentSettled($intent->tenant_id, $intent->id, 'salon.payment', $intent->reference));
        });

        $this->assertSame(50000, $this->balance($made['id']));
        $this->assertSame(['settled', 'QJK3SALON1'], $this->inTenant(fn () => [Payment::query()->find($payment['id'])->status, Payment::query()->find($payment['id'])->reference]));
        $this->assertSame(1, $this->inTenant(fn () => Payment::query()->where('kind', 'deposit')->where('status', 'settled')->count()));
        // The push the desk polls answers the settled payment.
        $this->api('GET', "salon/payments/{$payment['id']}/check", [], $this->desk)->assertOk()->assertJsonPath('data.status', 'settled');
    }

    public function test_a_deposit_is_used_at_the_till_once_and_comes_back_when_the_sale_is_voided(): void
    {
        $made = $this->book('10:00');
        $this->api('POST', "salon/appointments/{$made['id']}/deposit", ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => '50000'], $this->desk)->assertCreated();

        foreach (['check-in', 'start', 'finish'] as $step) {
            $this->api('POST', "salon/appointments/{$made['id']}/{$step}", [], $this->annUser)->assertOk();
        }

        $credit = $this->creditPayment('50000', ['source' => 'deposit', 'appointment_id' => $made['id']]);
        $sale = $this->salonSale($made['id'], 1, $this->desk, [], [$credit, $this->cashPayment('50000')]);
        $this->sell($sale);

        $this->assertSame(0, $this->balance($made['id']));
        $this->assertSame(['paid', []], [$this->appointment($made['id'])->status, $this->inTenant(fn () => SalonException::query()->pluck('kind')->all())]);
        $this->assertSame([], $this->flagsOf($sale['id']));
        // The same sale again uses nothing more.
        $this->upload([$sale])->assertOk();
        $this->assertSame(1, $this->inTenant(fn () => Payment::query()->where('kind', 'applied')->count()));

        $this->voidSale($sale);
        $this->assertSame(50000, $this->balance($made['id']));
        $this->assertSame(['restored'], $this->inTenant(fn () => Payment::query()->where('kind', 'restored')->pluck('kind')->all()));
    }

    public function test_credit_the_appointment_does_not_hold_is_flagged_and_kept_as_an_exception(): void
    {
        $made = $this->finished('10:00');
        $credit = $this->creditPayment('50000', ['source' => 'deposit', 'appointment_id' => $made['id']]);
        $sale = $this->salonSale($made['id'], 1, $this->desk, [], [$credit, $this->cashPayment('50000')]);
        $this->sell($sale);

        $this->assertContains('tender_flagged:salon_deposit_insufficient', $this->flagsOf($sale['id']));
        $exception = $this->inTenant(fn () => SalonException::query()->sole());
        $this->assertSame(['deposit_insufficient', '50000'], [$exception->kind, (string) $exception->amount_minor]);
        $this->assertSame('paid', $this->appointment($made['id'])->status);
        $this->assertSame(0, $this->inTenant(fn () => Payment::query()->where('kind', 'applied')->count()));

        // A manager closes the exception with a note.
        $this->api('GET', 'salon/exceptions?branch_id='.$this->branchA->id, [], $this->manager)->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', 'salon/exceptions?branch_id='.$this->branchA->id, [], $this->annUser)->assertForbidden();
        $this->api('POST', "salon/exceptions/{$exception->id}/resolve", ['note' => 'Client paid the rest'], $this->manager)->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->api('GET', 'salon/exceptions?branch_id='.$this->branchA->id, [], $this->manager)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_credit_may_not_be_used_for_another_tenants_or_unknown_appointment(): void
    {
        $other = $this->otherSalonTenant();
        $made = $this->finished('10:00');
        $foreign = $this->asTenant($other['user']->tenant_id, fn () => Appointment::query()->count());
        $this->assertSame(0, $foreign);
        $credit = $this->creditPayment('1000', ['source' => 'deposit', 'appointment_id' => $this->id()]);
        $this->sell($this->salonSale($made['id'], 1, $this->desk, [], [$credit, $this->cashPayment('99000')]));
        $this->assertSame(['deposit_unknown'], $this->inTenant(fn () => SalonException::query()->pluck('kind')->all()));
    }
}
