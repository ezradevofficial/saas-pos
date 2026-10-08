<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Items\DefaultUoms;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\Scope;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MD-02: units of measure (the tenant's, changed at tenant scope) and item
// categories (a tree in one sharing scope). Archived, never deleted
// (TEN-06); audited (MD-07).
class ItemCategoryAndUomApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->inTenant(fn () => app(DefaultUoms::class)->seed());
    }

    private function uom(string $code): string
    {
        return $this->inTenant(fn () => Uom::query()->active()->where('code', $code)->value('id'));
    }

    private function category(array $body, ?array $headers = null)
    {
        return $this->postJson('/api/v1/item-categories', $body, $headers ?? $this->headersFor());
    }

    public function test_units_are_listed_with_their_name_and_codes_are_unique_case_insensitively(): void
    {
        $list = $this->getJson('/api/v1/uoms?per_page=200', $this->headersFor())->assertOk();
        $this->assertSame(['BOX', 'EA', 'G', 'KG', 'L', 'M', 'ML', 'PACK'], array_column($list->json('data'), 'code'));
        $each = collect($list->json('data'))->firstWhere('code', 'EA');
        $this->assertSame(['Each', 'count'], [$each['name'], $each['kind']]);

        $crate = $this->postJson('/api/v1/uoms', ['code' => ' crate ', 'name' => 'Crate', 'kind' => 'count'], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.code', 'CRATE')->json('data.id');
        $this->postJson('/api/v1/uoms', ['code' => 'Crate', 'name' => 'Crate 2', 'kind' => 'count'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/uoms', ['code' => 'two words', 'name' => 'X', 'kind' => 'mass'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'kind']);
        $this->patchJson("/api/v1/uoms/{$crate}", ['code' => 'box'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->patchJson("/api/v1/uoms/{$crate}", ['name' => 'Cageot'], $this->headersFor())->assertOk()->assertJsonPath('data.name', 'Cageot');

        // Archived: the code is free again; restoring is refused while it is taken.
        $this->postJson("/api/v1/uoms/{$crate}/archive", [], $this->headersFor())->assertOk();
        $this->postJson('/api/v1/uoms', ['code' => 'CRATE', 'name' => 'Crate', 'kind' => 'count'], $this->headersFor())->assertCreated();
        $this->postJson("/api/v1/uoms/{$crate}/restore", [], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->getJson("/api/v1/history/uom/{$crate}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.action', 'core.uom.archive')->assertJsonCount(3, 'data');
    }

    public function test_units_are_changed_at_tenant_scope_only_and_kept_while_items_use_them(): void
    {
        $companyAdmin = $this->headersFor($this->userWith('admin', Scope::company($this->acme->id)));
        $manager = $this->headersFor($this->userWith('branch_manager', Scope::branch($this->branchA->id)));
        $hr = $this->headersFor($this->userWith('hr_officer', Scope::tenant()));
        $box = $this->uom('BOX');

        $this->getJson('/api/v1/uoms', $manager)->assertOk();
        $this->getJson("/api/v1/uoms/{$box}", $companyAdmin)->assertOk();
        // A unit is every company's: a company admin does not change it.
        $this->patchJson("/api/v1/uoms/{$box}", ['name' => 'Carton'], $companyAdmin)->assertForbidden();
        $this->postJson('/api/v1/uoms', ['code' => 'TRAY', 'name' => 'Tray', 'kind' => 'count'], $companyAdmin)->assertForbidden();
        $this->getJson('/api/v1/uoms', $hr)->assertForbidden();
        $this->getJson("/api/v1/uoms/{$box}", $hr)->assertNotFound();

        $this->postJson('/api/v1/items', ['code' => 'I1', 'name' => 'Item', 'type' => 'stock', 'base_uom_id' => $this->uom('EA'),
            'uoms' => [['uom_id' => $box, 'factor' => '10']]], $this->headersFor())->assertCreated();

        $this->postJson("/api/v1/uoms/{$box}/archive", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'uom_in_use');
        $this->postJson("/api/v1/uoms/{$this->uom('EA')}/archive", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'uom_in_use');
        $this->postJson("/api/v1/uoms/{$this->uom('KG')}/archive", [], $this->headersFor())->assertOk();
    }

    public function test_categories_form_a_tree_without_cycles(): void
    {
        $root = $this->category(['name' => 'Drinks', 'colour' => 'primary'])->assertCreated()
            ->assertJsonPath('data.shared', true)->assertJsonPath('data.colour', 'primary')->json('data.id');
        $child = $this->category(['name' => 'Boissons gazeuses', 'parent_id' => $root])->assertCreated()
            ->assertJsonPath('data.name', 'Boissons gazeuses')->json('data.id');
        $grandchild = $this->category(['name' => 'Cola', 'parent_id' => $child])->assertCreated()->json('data.id');

        $this->category(['name' => null])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->category(['name' => 'X', 'colour' => '#ff0000'])->assertUnprocessable()->assertJsonValidationErrors('colour');
        $this->category(['name' => 'X', 'company_id' => $this->acme->id])->assertUnprocessable()->assertJsonValidationErrors('company_id');

        $this->patchJson("/api/v1/item-categories/{$root}", ['parent_id' => $grandchild], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson("/api/v1/item-categories/{$root}", ['parent_id' => $root], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson("/api/v1/item-categories/{$grandchild}", ['parent_id' => $root], $this->headersFor())
            ->assertOk()->assertJsonPath('data.parent_id', $root);

        // At most six levels.
        $parent = $grandchild;
        foreach (range(3, 6) as $level) {
            $parent = $this->category(['name' => "Level {$level}", 'parent_id' => $parent])->assertCreated()->json('data.id');
        }
        $this->category(['name' => 'Level 7', 'parent_id' => $parent])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_a_category_in_use_is_kept_and_a_child_is_restored_after_its_parent(): void
    {
        $root = $this->category(['name' => 'Food'])->assertCreated()->json('data.id');
        $child = $this->category(['name' => 'Bakery', 'parent_id' => $root])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/items', ['code' => 'BRD', 'name' => 'Bread', 'type' => 'stock', 'base_uom_id' => $this->uom('EA'), 'category_id' => $child], $this->headersFor())
            ->assertCreated();

        $this->postJson("/api/v1/item-categories/{$root}/archive", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'category_in_use');
        $this->postJson("/api/v1/item-categories/{$child}/archive", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'category_in_use');

        $this->inTenant(fn () => Item::query()->sole()->archive());
        $this->postJson("/api/v1/item-categories/{$child}/archive", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/item-categories/{$root}/archive", [], $this->headersFor())->assertOk();

        $this->postJson("/api/v1/item-categories/{$child}/restore", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'parent_archived');
        $this->postJson("/api/v1/item-categories/{$root}/restore", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/item-categories/{$child}/restore", [], $this->headersFor())->assertOk()->assertJsonPath('data.archived_at', null);

        $this->getJson("/api/v1/history/item_category/{$child}", $this->headersFor())->assertOk()->assertJsonPath('data.0.action', 'core.item_category.restore');
        $this->inTenant(fn () => $this->assertSame(['core.item_category.create', 'core.item_category.archive', 'core.item_category.restore'],
            AuditEntry::where('auditable_id', $child)->orderBy('seq')->pluck('action')->all()));
    }

    public function test_category_permissions(): void
    {
        $id = $this->category(['name' => 'Tools'])->assertCreated()->json('data.id');

        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->getJson("/api/v1/item-categories/{$id}", $cashier)->assertOk();
        $this->category(['name' => 'X'], $cashier)->assertForbidden();
        $this->patchJson("/api/v1/item-categories/{$id}", ['name' => 'X'], $cashier)->assertForbidden();

        $storekeeper = $this->headersFor($this->userWith('storekeeper', Scope::location($this->locationA->id)));
        $this->patchJson("/api/v1/item-categories/{$id}", ['name' => 'Hand tools'], $storekeeper)->assertOk();
        $this->postJson("/api/v1/item-categories/{$id}/archive", [], $storekeeper)->assertForbidden();

        $hr = $this->headersFor($this->userWith('hr_officer', Scope::tenant()));
        $this->getJson("/api/v1/item-categories/{$id}", $hr)->assertNotFound();
        $this->getJson('/api/v1/item-categories', $hr)->assertForbidden();
    }

    public function test_a_parent_archived_between_validation_and_the_lock_is_refused(): void
    {
        $parent = $this->category(['name' => 'Parent'])->assertCreated()->json('data.id');
        $child = $this->category(['name' => 'Child'])->assertCreated()->json('data.id');

        // Simulates a concurrent archive right after the writer takes the items sharing lock.
        $raced = false;
        DB::listen(function (QueryExecuted $query) use ($parent, &$raced) {
            if (! $raced && str_contains($query->sql, 'pg_advisory_xact_lock_shared')) {
                $raced = true;
                ItemCategory::findOrFail($parent)->archive();
            }
        });

        $this->patchJson("/api/v1/item-categories/{$child}", ['parent_id' => $parent], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->assertTrue($raced);
        $this->inTenant(fn () => $this->assertNull(ItemCategory::findOrFail($child)->parent_id));
    }
}
