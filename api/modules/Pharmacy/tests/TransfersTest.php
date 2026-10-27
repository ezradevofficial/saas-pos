<?php

namespace Modules\Pharmacy\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Testing\TestResponse;
use Modules\Pharmacy\Stock\Lots;
use Modules\Pharmacy\Tests\Concerns\BuildsPharmacy;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PHA-13, PHA-07: lots move between two locations of a company, named or
// first-expiry-first-out; expired and blocked lots are refused.
class TransfersTest extends TestCase
{
    use BuildsPharmacy, RefreshTenantDatabase;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPharmacy();
        $this->technician = $this->userWith('pharmacy_technician', Scope::company($this->acme->id));
    }

    private function move(array $lines, ?User $by = null, ?string $to = null): TestResponse
    {
        return $this->postJson('/api/v1/pharmacy/transfers', ['from_location_id' => $this->locationA->id, 'to_location_id' => $to ?? $this->locationB->id, 'note' => 'Restock', 'lines' => $lines], $this->headersFor($by ?? $this->technician));
    }

    public function test_a_transfer_takes_the_first_expiry_first_and_credits_the_destination(): void
    {
        $late = $this->lot($this->amox, 'LATE', 200, '10');
        $soon = $this->lot($this->amox, 'SOON', 60, '4');
        $this->lot($this->amox, 'GONE', -1, '9');

        $response = $this->move([['item_id' => $this->amox->id, 'qty' => '6']])->assertCreated();
        $this->assertSame([['lot_id' => $soon->id, 'qty' => '4'], ['lot_id' => $late->id, 'qty' => '2']], array_map(fn (array $l) => ['lot_id' => $l['lot_id'], 'qty' => $l['qty']], $response->json('data.lines')));

        $this->assertSame(['0', '8'], [$this->lotQty($soon), $this->lotQty($late)]);
        $this->assertSame(['4', '2'], [$this->lotQty($soon, $this->locationB->id), $this->lotQty($late, $this->locationB->id)]);
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::where('action', 'pharmacy.transfer.create')->count()));
    }

    public function test_named_lots_are_checked_and_must_add_up(): void
    {
        $late = $this->lot($this->amox, 'LATE', 200, '10');
        $soon = $this->lot($this->amox, 'SOON', 60, '4');
        $old = $this->lot($this->amox, 'GONE', -1, '9');
        $held = $this->lot($this->amox, 'HELD', 90, '3');
        $this->inTenant(fn () => app(Lots::class)->quarantine($held, 'Damaged', null));

        $line = fn (array $lots, string $qty) => [['item_id' => $this->amox->id, 'qty' => $qty, 'lots' => $lots]];
        $this->move($line([['lot_id' => $late->id, 'qty' => '3'], ['lot_id' => $soon->id, 'qty' => '1']], '4'))->assertCreated();
        $this->assertSame(['7', '3'], [$this->lotQty($late), $this->lotQty($soon)]);

        $this->move($line([['lot_id' => $late->id, 'qty' => '3']], '4'))->assertUnprocessable()->assertJsonPath('code', 'lots_do_not_add_up');
        $this->move($line([['lot_id' => $old->id, 'qty' => '1']], '1'))->assertUnprocessable()->assertJsonPath('code', 'lot_expired');
        $this->move($line([['lot_id' => $held->id, 'qty' => '1']], '1'))->assertUnprocessable()->assertJsonPath('code', 'lot_quarantined');
        $this->move($line([['lot_id' => $soon->id, 'qty' => '5']], '5'))->assertUnprocessable()->assertJsonPath('code', 'insufficient_stock');
        $this->move([['item_id' => $this->amox->id, 'qty' => '99']])->assertUnprocessable()->assertJsonPath('code', 'insufficient_stock');
        $this->move([['item_id' => $this->plain->id, 'qty' => '1']])->assertUnprocessable()->assertJsonPath('code', 'item_not_tracked');
        // Nothing moved by the refused ones.
        $this->assertSame(['7', '3'], [$this->lotQty($late), $this->lotQty($soon)]);
    }

    public function test_the_two_places_must_differ_belong_to_one_company_and_be_ones_the_user_works_at(): void
    {
        $this->lot($this->amox, 'LATE', 200, '10');
        $this->move([['item_id' => $this->amox->id, 'qty' => '1']], null, $this->locationA->id)->assertUnprocessable()->assertJsonPath('code', 'transfer_same_location');

        $second = $this->inTenant(fn () => $this->location($this->branch($this->company('Beta'), 'B1'), 'Beta outlet'));
        $this->move([['item_id' => $this->amox->id, 'qty' => '1']], $this->owner, $second->id)->assertUnprocessable()->assertJsonPath('code', 'transfer_cross_company');

        $atA = $this->userWith('pharmacy_technician', Scope::location($this->locationA->id));
        $this->move([['item_id' => $this->amox->id, 'qty' => '1']], $atA)->assertForbidden();
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->move([['item_id' => $this->amox->id, 'qty' => '1']], $cashier)->assertForbidden();

        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('pharmacy'));
        $this->move([['item_id' => $this->amox->id, 'qty' => '1']], $other['user'])->assertUnprocessable();
    }
}
