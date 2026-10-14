<?php

namespace Modules\Stores\Tests\Concerns;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\MasterData\Dimensions\Department;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Location;
use Illuminate\Testing\TestResponse;
use Modules\Stores\Models\Movement;
use Modules\Stores\Stock\Qty;
use Modules\Stores\Stock\StoreLedger;
use Tests\Concerns\BuildsOrganisation;

/**
 * A tenant with the Stores module active (BuildsOrganisation's Acme, base
 * currency KES): a main store at branch A and a store at branch B, the
 * units EA and BOX (count) and KG (weight), stock items soap (EA, boxes of
 * 12) and sugar (KG), a service, the Kitchen department (its head is a
 * colleague) and Housekeeping (no head), a cost centre, and a storekeeper
 * at the main store.
 */
trait BuildsStores
{
    use BuildsOrganisation;

    protected Location $store;

    protected Location $storeB;

    protected Uom $each;

    protected Uom $box;

    protected Uom $kg;

    protected Item $soap;

    protected Item $sugar;

    protected Item $service;

    protected Department $kitchen;

    protected Department $housekeeping;

    protected CostCentre $operations;

    protected User $head;

    protected User $storekeeper;

    protected function setUpStores(bool $inventory = false): void
    {
        $this->setUpOrganisation();
        $this->head = $this->userWith('branch_manager', Scope::branch($this->branchB->id));

        $this->inTenant(function () use ($inventory) {
            app(ModuleRegistry::class)->activate('stores');

            if ($inventory) {
                app(ModuleRegistry::class)->activate('inventory');
            }

            $this->store = $this->branchA->locations()->create(['name' => 'Main store', 'type' => 'store']);
            $this->storeB = $this->branchB->locations()->create(['name' => 'Store B', 'type' => 'warehouse']);

            $this->each = Uom::create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count']);
            $this->box = Uom::create(['code' => 'BOX', 'name' => 'Box', 'kind' => 'count']);
            $this->kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram', 'kind' => 'weight']);
            $this->soap = Item::create(['code' => 'SOAP', 'name' => 'Soap', 'type' => 'stock', 'base_uom_id' => $this->each->id]);
            ItemUom::create(['item_id' => $this->soap->id, 'uom_id' => $this->box->id, 'factor' => '12']);
            $this->sugar = Item::create(['code' => 'SUGAR', 'name' => 'Sugar', 'type' => 'stock', 'base_uom_id' => $this->kg->id]);
            $this->service = Item::create(['code' => 'DELIVERY', 'name' => 'Delivery', 'type' => 'service', 'base_uom_id' => $this->each->id]);

            $this->kitchen = Department::create(['company_id' => $this->acme->id, 'code' => 'KIT', 'name' => 'Kitchen', 'owner_user_id' => $this->head->id]);
            $this->housekeeping = Department::create(['company_id' => $this->acme->id, 'code' => 'HSK', 'name' => 'Housekeeping']);
            $this->operations = CostCentre::create(['company_id' => $this->acme->id, 'code' => 'OPS', 'name' => 'Operations']);
        });

        $this->storekeeper = $this->userWith('storekeeper', Scope::location($this->store->id));
    }

    /** Opening stock in the own ledger, straight through the API (as the owner). */
    protected function opening(Item $item, string $qty, ?Location $store = null, ?Uom $uom = null): void
    {
        $this->postJson('/api/v1/stores/stock-entries', [
            'location_id' => ($store ?? $this->store)->id, 'kind' => 'opening',
            'lines' => [['item_id' => $item->id, 'uom_id' => $uom?->id, 'qty' => $qty]],
        ], $this->headersFor())->assertCreated();
    }

    /** @param list<array<string, mixed>> $lines */
    protected function requisition(array $lines, ?Department $department = null, ?User $by = null, ?Location $store = null, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/stores/requisitions', [
            'location_id' => ($store ?? $this->store)->id,
            'department_id' => ($department ?? $this->kitchen)->id,
            'lines' => $lines,
            ...$extra,
        ], $this->headersFor($by));
    }

    protected function approve(string $requisitionId, ?User $by = null): void
    {
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $requisitionId)->sole());
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($by ?? $this->head))->assertOk();
    }

    /** An approved requisition of the Kitchen, asked by the storekeeper. @return array<string, mixed> */
    protected function approved(array $lines, ?Department $department = null): array
    {
        $requisition = $this->requisition($lines, $department, $this->storekeeper)->assertCreated()->json('data');
        $this->approve($requisition['id']);

        return $this->getJson("/api/v1/stores/requisitions/{$requisition['id']}", $this->headersFor())->assertOk()->assertJsonPath('data.status', 'approved')->json('data');
    }

    /** @param list<array{0: string, 1: string}> $lines [requisition line id, qty] */
    protected function issue(string $requisitionId, array $lines, ?User $by = null): TestResponse
    {
        return $this->postJson("/api/v1/stores/requisitions/{$requisitionId}/issues", [
            'lines' => array_map(fn (array $l) => ['requisition_line_id' => $l[0], 'qty' => $l[1]], $lines),
        ], $this->headersFor($by ?? $this->storekeeper));
    }

    protected function onHand(Item $item, ?Location $store = null): string
    {
        return $this->inTenant(fn () => Qty::show(StoreLedger::balance(($store ?? $this->store)->id, $item->id)));
    }

    protected function movements(string $sourceType): int
    {
        return $this->inTenant(fn () => Movement::query()->where('source_type', $sourceType)->count());
    }
}
