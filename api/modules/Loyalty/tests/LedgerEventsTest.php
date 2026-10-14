<?php

namespace Modules\Loyalty\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\Loyalty\Events\CreditCharged;
use Modules\Loyalty\Events\CreditPaymentReceived;
use Modules\Loyalty\Events\GiftCardExpired;
use Modules\Loyalty\Events\GiftCardRedeemed;
use Modules\Loyalty\Events\GiftCardSold;
use Modules\Loyalty\Events\PointsEarned;
use Modules\Loyalty\Events\PointsRedeemed;
use Modules\Loyalty\GiftCards\GiftCards;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Models\GiftCardEntry;
use Modules\Loyalty\Tests\Concerns\BuildsLoyalty;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Stage 7B: every Loyalty ledger entry raises its event (one per entry,
// signed in the event's direction, with the points' money value, the
// sale's branch and channel), and expired gift cards are written off once.
class LedgerEventsTest extends TestCase
{
    use BuildsLoyalty, RefreshTenantDatabase;

    private const EVENTS = [GiftCardSold::class, GiftCardRedeemed::class, GiftCardExpired::class, PointsEarned::class, PointsRedeemed::class, CreditCharged::class, CreditPaymentReceived::class];

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLoyalty();
        $this->ranges()->assertOk();
        $this->shift = $this->openShift();
    }

    public function test_a_sale_and_its_void_raise_one_event_per_entry_with_signed_amounts(): void
    {
        [, $code] = $this->giftCard('50000');
        $account = $this->creditAccount();
        Event::fake(self::EVENTS);

        // KES 1,125.00: KES 500.00 by gift card, 100 points (KES 100.00), KES 525.00 on account.
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id, [
            $this->pay('voucher', '50000', ['code_hash' => GiftCards::proof($code)]),
            $this->pay('points', '10000', ['points' => '100']),
            $this->pay('credit', '52500'),
        ]);
        $this->upload([$sale])->assertOk();

        Event::assertDispatched(GiftCardRedeemed::class, fn (GiftCardRedeemed $e) => $e->amountMinor === '50000' && $e->channel === 'pos' && $e->saleId === $sale['id'] && $e->branchId === $this->branchA->id && $e->fx['rate'] === '1.00000000');
        Event::assertDispatched(PointsRedeemed::class, fn (PointsRedeemed $e) => $e->points === '100' && $e->amountMinor === '10000' && $e->currency === 'KES');
        Event::assertDispatched(CreditCharged::class, fn (CreditCharged $e) => $e->amountMinor === '52500' && $e->partyId === $this->amina->id && $e->recordId === $account->id);
        // Earned on KES 1,025.00 (the total less what points paid): 10 points worth KES 10.00.
        Event::assertDispatched(PointsEarned::class, fn (PointsEarned $e) => $e->points === '10' && $e->amountMinor === '1000');

        $this->voidSale($sale['id'])->assertOk();

        Event::assertDispatched(GiftCardRedeemed::class, fn (GiftCardRedeemed $e) => $e->amountMinor === '-50000' && $e->entryKind === 'reverse_redeem');
        Event::assertDispatched(PointsRedeemed::class, fn (PointsRedeemed $e) => $e->points === '-100' && $e->amountMinor === '-10000');
        Event::assertDispatched(PointsEarned::class, fn (PointsEarned $e) => $e->points === '-10' && $e->amountMinor === '-1000');
        Event::assertDispatched(CreditCharged::class, fn (CreditCharged $e) => $e->amountMinor === '-52500');
        // Each entry raises its own event, once.
        Event::assertDispatchedTimes(GiftCardRedeemed::class, 2);
    }

    public function test_back_office_sales_payments_and_issued_cards_name_their_channel(): void
    {
        $account = $this->creditAccount();
        Event::fake(self::EVENTS);

        $this->giftCard('20000');
        Event::assertDispatched(GiftCardSold::class, fn (GiftCardSold $e) => $e->amountMinor === '20000' && $e->channel === 'issued' && $e->fx === null);

        $this->postJson("/api/v1/loyalty/accounts/{$account->id}/payments", ['amount_minor' => '40000', 'reference' => 'MPESA-1'], $this->headersFor())->assertCreated();
        Event::assertDispatched(CreditPaymentReceived::class, fn (CreditPaymentReceived $e) => $e->amountMinor === '40000' && $e->channel === 'back_office' && $e->reference === 'MPESA-1');
    }

    public function test_expired_cards_are_written_off_once(): void
    {
        [$card] = $this->giftCard('30000');
        $this->inTenant(fn () => $card->forceFill(['expires_on' => '2026-01-31'])->save());
        Event::fake(self::EVENTS);
        CarbonImmutable::setTestNow('2026-02-01T03:00:00Z');

        $this->artisan('loyalty:expire-gift-cards')->assertSuccessful();
        $this->artisan('loyalty:expire-gift-cards')->assertSuccessful();

        $this->inTenant(function () use ($card) {
            $this->assertSame('0', (string) GiftCard::query()->findOrFail($card->id)->balance_minor);
            $this->assertSame(1, GiftCardEntry::query()->where('gift_card_id', $card->id)->where('kind', 'expire')->count());
        });
        Event::assertDispatchedTimes(GiftCardExpired::class, 1);
        Event::assertDispatched(GiftCardExpired::class, fn (GiftCardExpired $e) => $e->amountMinor === '30000' && $e->channel === 'system');
    }

    /** Review #7: a points entry keeps the point value it was written with; a later programme change never revalues it, live or replayed. */
    public function test_points_events_use_the_value_stored_with_the_entry(): void
    {
        $sale = $this->loyaltySale($this->shift, 1, $this->amina->id);
        $this->upload([$sale])->assertOk();
        $this->inTenant(fn () => $this->programme->forceFill(['point_value_minor' => '500'])->save());

        $replayed = $this->inTenant(function () {
            $events = [];
            app(\Modules\Loyalty\Ledgers\LedgerEvents::class)->replay(now()->subDay()->toImmutable(), now()->addDay()->toImmutable(), function (object $e) use (&$events) {
                $events[] = $e;
            });

            return $events;
        });
        $earned = collect($replayed)->first(fn ($e) => $e instanceof PointsEarned);

        // 11 points earned at KES 1.00 each: KES 11.00, whatever a point is worth today.
        $this->assertSame(['11', '1100'], [$earned->points, $earned->amountMinor]);
        $this->assertSame('100', $this->inTenant(fn () => (string) \Modules\Loyalty\Models\PointsEntry::query()->where('kind', 'earn')->value('point_value_minor')));
    }

    /** Review #10: a card whose expiry was extended, and that has value again, expires again on its new date. */
    public function test_a_card_expires_again_after_its_expiry_is_extended(): void
    {
        [$card] = $this->giftCard('30000');
        $this->inTenant(fn () => $card->forceFill(['expires_on' => '2026-01-31'])->save());
        CarbonImmutable::setTestNow('2026-02-01T03:00:00Z');
        $this->artisan('loyalty:expire-gift-cards')->assertSuccessful();

        // Value comes back (a voided sale's redemption put back) and the card is given until March.
        $this->inTenant(function () use ($card) {
            $card = app(\Modules\Loyalty\Ledgers\Ledgers::class)->lockCard($card->id);
            app(\Modules\Loyalty\Ledgers\Ledgers::class)->giftCard($card, 'reverse_redeem', '10000', ['source_type' => 'manual']);
            $card->forceFill(['expires_on' => '2026-03-31'])->save();
        });
        CarbonImmutable::setTestNow('2026-04-01T03:00:00Z');
        $this->artisan('loyalty:expire-gift-cards')->assertSuccessful();

        $this->inTenant(function () use ($card) {
            $this->assertSame('0', (string) GiftCard::query()->findOrFail($card->id)->balance_minor);
            $this->assertSame(['-30000', '-10000'], GiftCardEntry::query()->where('gift_card_id', $card->id)->where('kind', 'expire')->orderBy('occurred_at')->pluck('amount_minor')->map(fn ($a) => (string) $a)->all());
        });
    }
}
