<?php

namespace Modules\Pharmacy\Tests;

use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Modules\Pharmacy\Models\ExpiryNotice;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Stock\Lots;
use Modules\Pharmacy\Tests\Concerns\BuildsPharmacy;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PHA-10, PHA-11, PHA-02: the nightly sweep tells the pharmacy team once per
// lot and stage about stock near expiry and expired, quarantines expired
// stock, and follows the company's own near-expiry window.
class ExpiryAlertsTest extends TestCase
{
    use BuildsPharmacy, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPharmacy();
    }

    private function notified(string $type): array
    {
        return $this->inTenant(fn () => InAppNotification::query()->where('event_type', $type)->get(['user_id', 'subject', 'body'])->all());
    }

    public function test_the_sweep_quarantines_expired_stock_and_tells_the_team_once(): void
    {
        $pharmacist = $this->userWith('pharmacist', Scope::location($this->locationA->id));
        $elsewhere = $this->userWith('pharmacist', Scope::location($this->locationB->id));
        $gone = $this->lot($this->para, 'GONE', -3, '4');
        $near = $this->lot($this->amox, 'NEAR', 30, '2');
        $far = $this->lot($this->amox, 'FAR', 300, '9');
        $empty = $this->lot($this->vet, 'EMPTY', -3, '1');
        $this->inTenant(fn () => app(Lots::class)->move($empty, $this->locationA->id, 'adjustment', BigDecimal::of('-1'), 'test.adjust', (string) Str::uuid7()));

        Artisan::call('pharmacy:expiry-alerts');

        $this->assertSame(['quarantined', 'active', 'active', 'active'], $this->inTenant(fn () => [Lot::find($gone->id)->status, Lot::find($near->id)->status, Lot::find($far->id)->status, Lot::find($empty->id)->status]));
        $this->assertSame('expired', $this->inTenant(fn () => Lot::find($gone->id)->quarantine_reason));
        $this->assertSame([['expired', 'GONE'], ['near', 'NEAR']], $this->inTenant(fn () => ExpiryNotice::query()->join('pharmacy_lots', 'pharmacy_lots.id', '=', 'pharmacy_expiry_notices.lot_id')->orderBy('stage')->get(['stage', 'batch_number'])->map(fn ($n) => [$n->stage, $n->batch_number])->all()));

        $expired = $this->notified('pharmacy.stock.expired');
        $this->assertContains($pharmacist->id, array_column($expired, 'user_id'));
        $this->assertContains($this->owner->id, array_column($expired, 'user_id'));
        $this->assertNotContains($elsewhere->id, array_column($expired, 'user_id'));
        $mine = collect($expired)->firstWhere('user_id', $pharmacist->id);
        $this->assertStringContainsString('1 lots expired at Outlet A', $mine->subject);
        $this->assertStringContainsString('Paracetamol 500 mg GONE (4)', $mine->body);
        $this->assertContains($pharmacist->id, array_column($this->notified('pharmacy.stock.near_expiry'), 'user_id'));

        // The same lots are not announced again; a lot entering the window later is.
        $before = count($this->notified('pharmacy.stock.expired')) + count($this->notified('pharmacy.stock.near_expiry'));
        Artisan::call('pharmacy:expiry-alerts');
        $this->assertSame($before, count($this->notified('pharmacy.stock.expired')) + count($this->notified('pharmacy.stock.near_expiry')));

        $this->settings(['near_expiry_days' => 400]);
        Artisan::call('pharmacy:expiry-alerts');
        $this->assertSame(3, $this->inTenant(fn () => ExpiryNotice::count()));
        $this->assertSame('near', $this->inTenant(fn () => ExpiryNotice::query()->where('lot_id', $far->id)->value('stage')));
    }

    public function test_a_company_may_keep_expired_stock_out_of_quarantine_and_other_tenants_are_not_swept_together(): void
    {
        $this->settings(['quarantine_expired' => false]);
        $gone = $this->lot($this->para, 'GONE', -3, '4');

        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('pharmacy'));

        Artisan::call('pharmacy:expiry-alerts');

        $this->assertSame('active', $this->inTenant(fn () => Lot::find($gone->id)->status));
        $this->assertSame(1, $this->inTenant(fn () => ExpiryNotice::count()));
        $this->assertSame([], $this->asTenant($other['user']->tenant_id, fn () => InAppNotification::query()->where('event_type', 'like', 'pharmacy.%')->pluck('id')->all()));
    }

    public function test_a_tenant_without_the_module_is_left_alone(): void
    {
        $gone = $this->lot($this->para, 'GONE', -3, '4');
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('pharmacy'));

        Artisan::call('pharmacy:expiry-alerts');

        $this->assertSame('active', $this->inTenant(fn () => Lot::find($gone->id)->status));
        $this->assertSame(0, $this->inTenant(fn () => ExpiryNotice::count()));
    }
}
