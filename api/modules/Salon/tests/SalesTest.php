<?php

namespace Modules\Salon\Tests;

use App\Core\Audit\AuditEntry;
use Modules\Salon\Commissions\Commissions;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\CommissionEntry;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\Package;
use Modules\Salon\Models\Redemption;
use Modules\Salon\Models\SalonException;
use Modules\Salon\Models\Tip;
use Modules\Salon\Tests\Concerns\BuildsSalon;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// SAL-16, SAL-18, SAL-21, SAL-22: a visit is a normal POS sale; what it means for the appointment, commissions, tips and packages.
class SalesTest extends TestCase
{
    use BuildsSalon, RefreshTenantDatabase;

    /** @return array<string, int> staff name => net commission of the ledger */
    private function net(): array
    {
        return $this->inTenant(fn () => CommissionEntry::query()->get()->groupBy('staff_id')->map(fn ($rows) => (int) $rows->sum('amount_minor'))
            ->mapWithKeys(fn ($sum, $staffId) => [$staffId === $this->ann->id ? 'ann' : 'bob' => $sum])->all());
    }

    public function test_a_paid_visit_links_the_sale_and_earns_commissions_and_tips(): void
    {
        $this->setUpSalon();
        $made = $this->finished('10:00');
        $product = $this->line();
        $tip = $this->serviceLine($this->tipItem, 10000);
        $sale = $this->salonSale($made['id'], 1, $this->desk, [$product, $tip], null, [
            'sellers' => [['line_id' => $product['id'], 'staff_id' => $this->bob->id]],
            'tips' => [['staff_id' => $this->bob->id, 'amount_minor' => '3000']],
        ]);
        $this->sell($sale);

        $appointment = $this->appointment($made['id']);
        $this->assertSame(['paid', $sale['id']], [$appointment->status, $appointment->sale_id]);
        $line = $this->inTenant(fn () => Line::query()->where('appointment_id', $made['id'])->sole());
        // The haircut's line is the sale's first; its net is KES 1,000.00 less the 12.5 % tax included: 88,889.
        $this->assertSame([$sale['lines'][0]['id'], 88889], [$line->sale_line_id, $line->net_minor]);

        // Ann earns 20 % of the haircut's net (17,777.8 rounded half up); Bob 10 % of the soap's net (100,000). Nobody earns on the tip.
        $this->assertSame(['ann' => 17778, 'bob' => 10000], $this->net());
        $this->assertSame(['service', 'product'], $this->inTenant(fn () => CommissionEntry::query()->orderBy('line_kind', 'desc')->pluck('line_kind')->all()));
        // The tip is shared as named: Bob 3,000 and the rest to the appointment's first staff member, Ann.
        $tips = $this->inTenant(fn () => Tip::query()->get()->mapWithKeys(fn ($t) => [$t->staff_id === $this->ann->id ? 'ann' : 'bob' => $t->amount_minor])->all());
        ksort($tips);
        $this->assertSame(['ann' => 7000, 'bob' => 3000], $tips);

        // The same sale delivered again adds nothing.
        $this->upload([$sale])->assertOk();
        $this->assertSame(2, $this->inTenant(fn () => CommissionEntry::query()->count()));
        $this->assertSame(2, $this->inTenant(fn () => Tip::query()->count()));
    }

    public function test_a_void_takes_back_every_commission_and_tip_and_reopens_the_appointment(): void
    {
        $this->setUpSalon();
        $made = $this->finished('10:00');
        $tip = $this->serviceLine($this->tipItem, 10000);
        $sale = $this->salonSale($made['id'], 1, $this->desk, [$tip]);
        $this->sell($sale);
        $this->assertSame(['ann' => 17778], $this->net());

        $this->voidSale($sale);
        $this->assertSame(['ann' => 0], $this->net());
        $this->assertSame(2, $this->inTenant(fn () => CommissionEntry::query()->count()));
        $this->assertSame(0, (int) $this->inTenant(fn () => Tip::query()->sum('amount_minor')));
        $appointment = $this->appointment($made['id']);
        $this->assertSame(['completed', null], [$appointment->status, $appointment->sale_id]);

        // Taking back twice (the same void) adds nothing.
        $this->inTenant(fn () => app(Commissions::class)->voided($this->acme->id, ['id' => $sale['id']], 'again', null));
        $voidId = $this->inTenant(fn () => CommissionEntry::query()->where('kind', 'reversal')->value('source_id'));
        $this->inTenant(fn () => app(Commissions::class)->voided($this->acme->id, ['id' => $sale['id']], $voidId, null));
        $this->assertSame(2, $this->inTenant(fn () => CommissionEntry::query()->count()), 'an earning already reversed is not reversed again');

        // The visit can be paid again by a new sale; it earns again.
        $again = $this->salonSale($made['id'], 2, $this->desk);
        $this->sell($again);
        $this->assertSame(['ann' => 17778], $this->net());
    }

    public function test_a_refund_takes_back_the_refunded_share_and_never_more_than_was_earned(): void
    {
        $this->setUpSalon();
        $made = $this->finished('10:00');
        $product = $this->line();
        $sale = $this->salonSale($made['id'], 1, $this->desk, [$product], null, ['sellers' => [['line_id' => $product['id'], 'staff_id' => $this->bob->id]]]);
        $this->sell($sale);
        $this->assertSame(['ann' => 17778, 'bob' => 10000], $this->net());

        // One of the two soaps back: half of Bob's 10,000.
        $this->refundLine($sale, 1, '1', '56250', 1);
        $this->assertSame(['ann' => 17778, 'bob' => 5000], $this->net());
        // The other: the rest, exactly.
        $this->refundLine($sale, 1, '1', '56250', 2);
        $this->assertSame(['ann' => 17778, 'bob' => 0], $this->net());
    }

    public function test_a_sale_that_does_not_match_its_appointment_is_kept_with_an_exception(): void
    {
        $this->setUpSalon();
        $made = $this->finished('10:00');
        $wrong = $this->salonSale($made['id'], 1, $this->desk, [], null, [], ['lines' => [$this->serviceLine($this->colourItem, 300000)]]);
        $this->sell($wrong);
        $kinds = $this->inTenant(fn () => SalonException::query()->pluck('kind')->all());
        $this->assertSame(['sale_mismatch'], $kinds);
        $this->assertSame('paid', $this->appointment($made['id'])->status);

        // A sale naming an appointment that does not exist is stored too.
        $unknown = $this->salonSale($made['id'], 2, $this->desk, [], null, ['appointment_id' => $this->id()], ['lines' => [$this->serviceLine($this->haircutItem, 100000)]]);
        $this->sell($unknown);
        $this->assertContains('appointment_unknown', $this->inTenant(fn () => SalonException::query()->pluck('kind')->all()));
    }

    public function test_a_fee_is_paid_at_the_till_and_comes_back_when_the_sale_is_voided(): void
    {
        $this->setUpSalon();
        $made = $this->book('10:00');
        $this->api('POST', "salon/appointments/{$made['id']}/cancel", ['reason' => 'Late'], $this->desk)->assertOk()->assertJsonPath('data.fee_minor', '10000');

        $checkout = $this->api('GET', "salon/appointments/{$made['id']}/checkout", [], $this->desk)->assertOk()->json('data');
        $this->assertSame([$this->feeItem->id, '10000'], [$checkout['fee']['item_id'], $checkout['fee']['amount_minor']]);

        $sale = $this->salonSale($made['id'], 1, $this->desk, [], null, ['fee' => true], ['lines' => [$this->serviceLine($this->feeItem, 10000)]]);
        $this->sell($sale);
        $this->assertSame('paid', $this->appointment($made['id'])->fee_status);
        $this->assertSame([], $this->inTenant(fn () => CommissionEntry::query()->get()->all()), 'a fee earns no commission');

        $this->voidSale($sale);
        $this->assertSame('due', $this->appointment($made['id'])->fee_status);
    }

    public function test_selling_a_package_makes_it_and_a_session_is_redeemed_by_a_credit_payment(): void
    {
        $this->setUpSalon();
        $this->sell($this->packageSale(1, $this->client->id));

        /** @var Package $package */
        $package = $this->inTenant(fn () => Package::query()->sole());
        $this->assertSame([5, 0, 450000, 450000, '2027-08-30', 'active'], [$package->sessions_total, $package->sessions_used, $package->price_minor, $package->remaining_minor, $package->expires_on->toDateString(), $package->status]);
        $this->assertSame(90000, $package->sessionValue());

        // A haircut paid with a session (its value, 90,000) and the rest in cash.
        $made = $this->finished('11:00');
        $credit = $this->creditPayment('90000', ['source' => 'package', 'package_id' => $package->id, 'service_id' => $this->haircut->id]);
        $sale = $this->salonSale($made['id'], 2, $this->desk, [], [$credit, $this->cashPayment('10000')]);
        $this->sell($sale);

        $package = $package->refresh();
        $this->assertSame([1, 360000, 'active'], [$package->sessions_used, $package->remaining_minor, $package->status]);
        $redemption = $this->inTenant(fn () => Redemption::query()->sole());
        $this->assertSame([$sale['id'], 90000, 'redeemed'], [$redemption->sale_id, $redemption->value_minor, $redemption->status]);
        $this->assertSame([], $this->inTenant(fn () => SalonException::query()->pluck('kind')->all()));
        // Delivered again: once.
        $this->upload([$sale])->assertOk();
        $this->assertSame(1, $this->inTenant(fn () => Redemption::query()->count()));

        // A void gives the session back.
        $this->voidSale($sale);
        $package = $package->refresh();
        $this->assertSame([0, 450000], [$package->sessions_used, $package->remaining_minor]);
        $this->assertSame('reversed', $this->inTenant(fn () => Redemption::query()->sole()->status));
    }

    public function test_a_session_the_package_cannot_give_is_kept_as_an_exception_and_the_sale_stands(): void
    {
        $this->setUpSalon();
        $this->sell($this->packageSale(1, $this->client->id));
        $package = $this->inTenant(fn () => Package::query()->sole());
        $tender = ['source' => 'package', 'package_id' => $package->id, 'service_id' => $this->haircut->id];
        $stranger = $this->customer($this->acme->id);

        // Another customer's package.
        $made = $this->finished('10:00');
        $sale = $this->salonSale($made['id'], 2, $this->desk, [], [$this->creditPayment('90000', $tender), $this->cashPayment('10000')], [], ['customer_id' => $stranger->id]);
        $this->sell($sale);
        $this->assertSame(0, $package->refresh()->sessions_used);
        $this->assertContains('package_customer', $this->inTenant(fn () => SalonException::query()->pluck('kind')->all()));

        // A package with no sessions left.
        $this->inTenant(fn () => $package->forceFill(['sessions_used' => 5, 'remaining_minor' => 0, 'status' => 'exhausted'])->save());
        $made = $this->finished('11:00');
        $this->sell($this->salonSale($made['id'], 3, $this->desk, [], [$this->creditPayment('90000', $tender), $this->cashPayment('10000')]));
        $this->assertContains('package_exhausted', $this->inTenant(fn () => SalonException::query()->pluck('kind')->all()));

        // An expired package.
        $this->inTenant(fn () => $package->forceFill(['sessions_used' => 0, 'remaining_minor' => 450000, 'status' => 'active', 'expires_on' => '2027-03-02'])->save());
        $made = $this->finished('15:00');
        $this->sell($this->salonSale($made['id'], 4, $this->desk, [], [$this->creditPayment('90000', $tender), $this->cashPayment('10000')]));
        $this->assertContains('package_expired', $this->inTenant(fn () => SalonException::query()->pluck('kind')->all()));
        $this->assertSame(0, $this->inTenant(fn () => Redemption::query()->count()));
        $this->assertSame(['paid', 'paid', 'paid'], $this->inTenant(fn () => Appointment::query()->whereNotNull('sale_id')->orderBy('starts_at')->pluck('status')->all()));
    }

    public function test_the_last_session_takes_the_rest_of_the_value(): void
    {
        $this->setUpSalon();
        $this->inTenant(function () {
            $package = Package::create([
                'company_id' => $this->acme->id, 'type_id' => $this->fiveCuts->id, 'customer_id' => $this->client->id, 'name' => 'Three cuts', 'sale_id' => $this->id(), 'sale_line_id' => $this->id(),
                'sessions_total' => 3, 'price_minor' => 100000, 'remaining_minor' => 100000, 'currency' => 'KES', 'service_ids' => [],
            ]);
            $values = [];

            while ($package->sessionsLeft() > 0) {
                $values[] = $package->sessionValue();
                $package->forceFill(['sessions_used' => $package->sessions_used + 1, 'remaining_minor' => $package->remaining_minor - $package->sessionValue()])->save();
            }

            $this->assertSame([33333, 33333, 33334], $values);
            $this->assertSame(0, $package->remaining_minor);
        });
    }

    public function test_a_package_item_sold_without_a_customer_is_kept_with_an_exception(): void
    {
        $this->setUpSalon();
        $this->sell($this->packageSale(1, null));
        $this->assertSame(0, $this->inTenant(fn () => Package::query()->count()));
        $this->assertSame(['package_no_customer'], $this->inTenant(fn () => SalonException::query()->pluck('kind')->all()));
        $this->assertSame(1, AuditEntry::where('action', 'salon.exception.create')->count());
    }
}
