<?php

namespace Tests\Feature\Core\CustomFields;

use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\CustomFields\CustomFieldEntities;
use App\Core\CustomFields\Jobs\RestampCustomFieldRecords;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use App\Core\Sync\StaffDirectory;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTill;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CF-03, NFR-04: items and customers carry the values of their custom
// fields shown on the POS to the till; the `custom_fields` entity tells the
// till their labels; staff rows' field rules hide fields a cashier's roles
// may not see (StaffDirectory).
// Turning `show_on_pos` on or off re-stamps the records.
class CustomFieldSyncTest extends TestCase
{
    use BuildsTill, RefreshTenantDatabase;

    private array $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->till = $this->pairTill($this->locationA);
    }

    public function test_pos_fields_travel_with_items_and_customers(): void
    {
        $admin = $this->inTenant(fn () => $this->roles->get('admin'));
        $fields = $this->inTenant(fn () => [
            'size' => CustomFieldDefinition::create(['entity' => 'item', 'key' => 'size', 'label' => 'Size', 'type' => 'text', 'show_on_pos' => true]),
            'margin' => CustomFieldDefinition::create(['entity' => 'item', 'key' => 'margin', 'label' => 'Margin', 'type' => 'number', 'show_on_pos' => true, 'visible_roles' => [$admin->id]]),
            'supplier_note' => CustomFieldDefinition::create(['entity' => 'item', 'key' => 'supplier_note', 'label' => 'Supplier note', 'type' => 'text']),
            'tier' => CustomFieldDefinition::create(['entity' => 'party', 'key' => 'tier', 'label' => 'Tier', 'type' => 'text', 'show_on_pos' => true]),
        ]);
        $item = $this->makeItem('TEA');
        $customer = $this->inTenant(function () use ($item) {
            $item->forceFill(['custom' => ['size' => 'Large', 'margin' => '30', 'supplier_note' => 'Back office only']])->save();

            $party = new Party(['kind' => 'person', 'name' => 'Asha', 'roles' => ['customer']]);
            $party->forceFill(['custom' => ['tier' => 'Gold']])->save();

            return $party;
        });

        $items = $this->pullAll($this->till, 'items');
        $this->assertSame(['margin' => '30', 'size' => 'Large'], $items['upserts'][$item->id]['custom']);
        $customers = $this->pullAll($this->till, 'customers');
        $this->assertSame(['tier' => 'Gold'], $customers['upserts'][$customer->id]['custom']);

        $labels = $this->pull($this->till, ['custom_fields'])->assertOk()->json('entities.custom_fields.upserts');
        $this->assertSame([['items', 'margin', 'Margin'], ['items', 'size', 'Size'], ['customers', 'tier', 'Tier']], array_map(fn ($r) => [$r['entity'], $r['key'], $r['label']], $labels));

        // A cashier without the admin role has `margin` hidden on the till.
        $cashier = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->roles->get('cashier'), Scope::location($this->locationA->id));

            return $user;
        });
        $rules = $this->inTenant(fn () => app(StaffDirectory::class)->fieldRules([$cashier->id, $this->owner->id]));
        $this->assertContains('custom.margin', $rules[$cashier->id]['item']['hidden']);
        $this->assertNotContains('custom.margin', $rules[$this->owner->id]['item']['hidden']);

        // Turning a field on for the POS re-stamps the items, after commit.
        Queue::fake();
        $this->inTenant(fn () => $fields['supplier_note']->update(['show_on_pos' => true]));
        Queue::assertPushed(RestampCustomFieldRecords::class, fn ($job) => $job->entity === 'item');
        (new RestampCustomFieldRecords($this->owner->tenant_id, 'item'))->handle(app(CustomFieldEntities::class));

        $again = $this->pull($this->till, ['items'], ['items' => $items['cursor']])->assertOk();
        $this->assertSame('Back office only', $again->json('entities.items.upserts.0.custom.supplier_note'));
        $this->inTenant(fn () => $this->assertNotNull(Item::findOrFail($item->id)->custom));
    }
}
