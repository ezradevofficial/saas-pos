<?php

namespace Modules\Stores\Tests;

use App\Core\Rbac\Scope;
use Modules\Stores\Tests\Concerns\BuildsStores;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// STR-07, STR-17 (stage 7B closing review, stores.md decision 12): in the
// store's own ledger a storekeeper may add stock, but taking stock out by
// an adjustment needs stores.stock.approve at the store (a manager), as
// Inventory's decreases need an approval.
class StockApprovalTest extends TestCase
{
    use BuildsStores, RefreshTenantDatabase;

    private function adjust(string $qty, $user)
    {
        return $this->postJson('/api/v1/stores/stock-entries', ['location_id' => $this->store->id, 'kind' => 'adjustment', 'reason' => 'damage',
            'lines' => [['item_id' => $this->soap->id, 'qty' => $qty]]], $this->headersFor($user));
    }

    public function test_a_decrease_needs_the_approve_permission_and_an_increase_does_not(): void
    {
        $this->setUpStores();
        $this->opening($this->soap, '10');

        $this->adjust('2', $this->storekeeper)->assertCreated();
        $this->adjust('-1', $this->storekeeper)->assertForbidden()->assertJsonPath('code', 'stock_decrease_needs_approval');
        $this->assertSame('12', $this->onHand($this->soap));

        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->adjust('-1', $manager)->assertCreated();
        $this->assertSame('11', $this->onHand($this->soap));
    }
}
