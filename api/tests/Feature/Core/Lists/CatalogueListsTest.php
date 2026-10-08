<?php

namespace Tests\Feature\Core\Lists;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Items\DefaultUoms;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\ReadsListExports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Lists and pickers plan, task 3: search, sort and export (EXP-01) on units
// and item categories (MD-02), without fields hidden by field rules
// (RBAC-05), audited (AUD-01), never another tenant's rows (TEN-01).
class CatalogueListsTest extends TestCase
{
    use BuildsOrganisation, ReadsListExports, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->inTenant(fn () => app(DefaultUoms::class)->seed());
    }

    private function category(string $name, ?string $parent = null): string
    {
        return $this->postJson('/api/v1/item-categories', ['name' => $name, 'parent_id' => $parent], $this->headersFor())->assertCreated()->json('data.id');
    }

    public function test_units_search_sort_and_export(): void
    {
        $codes = fn (string $query) => array_column($this->getJson("/api/v1/uoms{$query}", $this->headersFor())->assertOk()->json('data'), 'code');

        $this->assertSame(['BOX', 'EA', 'G', 'KG', 'L', 'M', 'ML', 'PACK'], $codes(''));
        $this->assertSame(['PACK', 'ML', 'M', 'L', 'KG', 'G', 'EA', 'BOX'], $codes('?sort=-code'));
        $this->assertSame(['BOX', 'EA', 'G', 'KG'], array_slice($codes('?sort=name'), 0, 4));
        $this->assertSame(['KG'], $codes('?search=kilo'));
        $this->assertSame(['EA'], $codes('?search=ea&sort=code&per_page=1'));
        $this->getJson('/api/v1/uoms?sort=factor', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');

        $rows = $this->csvRows($this->get('/api/v1/uoms?format=csv&search=kilo', $this->headersFor())->assertOk());
        $this->assertSame(['Code', 'Name', 'Kind', 'Status', 'Created', 'Updated'], $rows[0]);
        $this->assertSame(['KG', 'Kilogram', 'Weight', 'Active'], array_slice($rows[1], 0, 4));
        $this->assertCount(2, $rows);

        $fr = $this->csvRows($this->get('/api/v1/uoms?format=csv&search=kilo&columns[]=kind&columns[]=status', [...$this->headersFor(), 'Accept-Language' => 'fr'])->assertOk());
        $this->assertSame([['Nature', 'Statut'], ['Poids', 'Actif']], $fr);

        $this->inTenant(fn () => $this->assertSame(2, AuditEntry::where('action', 'core.uom.export')->count()));
    }

    public function test_item_categories_search_sort_and_export(): void
    {
        $drinks = $this->category('Drinks');
        $soda = $this->category('Soda', $drinks);
        $bakery = $this->category('Bakery');

        $this->assertSame([$bakery, $drinks, $soda], $this->listIds('/api/v1/item-categories', $this->headersFor()));
        $this->assertSame([$soda, $drinks, $bakery], $this->listIds('/api/v1/item-categories?sort=-name', $this->headersFor()));
        // By the parent's name; top-level categories first.
        // Ties (both top level) by id.
        $this->assertSame([$drinks, $bakery, $soda], $this->listIds('/api/v1/item-categories?sort=parent', $this->headersFor()));
        $this->assertSame([$drinks], $this->listIds('/api/v1/item-categories?search=ink', $this->headersFor()));
        $this->assertSame([$soda], $this->listIds('/api/v1/item-categories?search=sod', $this->headersFor()));
        $this->getJson('/api/v1/item-categories?sort=colour', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');

        $rows = $this->csvRows($this->get('/api/v1/item-categories?format=csv', $this->headersFor())->assertOk());
        $this->assertSame(['Name', 'Parent', 'Used by', 'Colour', 'Status', 'Created', 'Updated'], $rows[0]);
        $this->assertSame(['Soda', 'Drinks'], array_slice($rows[3], 0, 2));
        $this->assertSame('Active', $rows[3][4]);

        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.item_category.export')->count()));
    }

    public function test_a_hidden_category_name_is_not_sorted_searched_or_exported(): void
    {
        // Records first, through the API: the role below is still built on the right guard.
        $drinks = $this->category('Drinks');
        $this->category('Soda', $drinks);
        $clerk = $this->inTenant(function () {
            $role = $this->role('Clerk', ['core.item_category.view']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'item_category', 'field' => 'name', 'mode' => 'hidden']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });
        $headers = $this->headersFor($clerk);

        $this->getJson('/api/v1/item-categories?sort=name', $headers)->assertUnprocessable()->assertJsonPath('errors.sort.0', 'You can’t sort by a field you can’t see. Choose another column.');
        $this->getJson('/api/v1/item-categories?sort=parent', $headers)->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->assertSame([], $this->listIds('/api/v1/item-categories?search=Drinks', $headers));

        $rows = $this->csvRows($this->get('/api/v1/item-categories?format=csv', $headers)->assertOk());
        $this->assertNotContains('Name', $rows[0]);
        $this->assertNotContains('Parent', $rows[0]);
        $this->assertStringNotContainsString('Drinks', implode("\n", array_merge(...$rows)));
        $html = $this->capturePdfHtml(fn () => $this->get('/api/v1/item-categories?format=pdf', $headers)->assertOk()->streamedContent());
        $this->assertStringNotContainsString('Drinks', $html);
        $this->assertStringNotContainsString('Soda', $html);
    }

    public function test_an_export_needs_the_lists_view_permission_and_never_shows_another_tenant(): void
    {
        $this->category('Drinks');
        $hr = $this->headersFor($this->userWith('hr_officer', Scope::company($this->acme->id)));

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->refusedExport("/api/v1/uoms?format={$format}", $hr)->assertForbidden();
            $this->refusedExport("/api/v1/item-categories?format={$format}", $hr)->assertForbidden();
        }

        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, function () {
            Uom::create(['code' => 'THEIRS', 'name' => 'Their unit', 'kind' => 'count']);
            ItemCategory::create(['name' => 'Their category']);
        });

        foreach (['uoms' => 'Each', 'item-categories' => 'Drinks'] as $list => $ours) {
            $text = implode("\n", array_merge(...$this->xlsxRows($this->get("/api/v1/{$list}?format=xlsx&status=all", $this->headersFor())->assertOk())));
            $this->assertStringContainsString($ours, $text);
            $this->assertStringNotContainsString('THEIRS', $text);
            $this->assertStringNotContainsString('Their', $text);
        }

        $this->inTenant(fn () => $this->assertSame(0, AuditEntry::where('action', 'core.uom.export')->where('user_id', '!=', $this->owner->id)->count()));
    }
}
