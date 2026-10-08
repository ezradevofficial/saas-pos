<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\MasterData\Items\DefaultUoms;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemBarcode;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// TEN-08 and review focus 3 and 4 for items (MD-02): items and their
// categories split per company and share again without orphans or leaks;
// codes and barcodes are unique per company when kept per company, and a
// switch to shared that would make two active items share one is refused
// with `duplicate_codes`.
class ItemSharingTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private Company $globex;

    private Location $globexLocation;

    private string $ea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->ea = $this->inTenant(function () {
            $this->globex = $this->company('Globex');
            $this->globexLocation = $this->location($this->branch($this->globex, 'G'), 'Globex outlet');
            app(DefaultUoms::class)->seed();

            return Uom::query()->where('code', 'EA')->value('id');
        });
    }

    private function settings(array $body)
    {
        return $this->putJson('/api/v1/master-data/settings', ['data_type' => 'items', ...$body], $this->headersFor());
    }

    private function create(array $body, ?array $headers = null)
    {
        return $this->postJson('/api/v1/items', ['name' => 'Item', 'type' => 'stock', 'base_uom_id' => $this->ea, ...$body], $headers ?? $this->headersFor());
    }

    public function test_splitting_assigns_items_categories_and_barcodes_to_the_chosen_company(): void
    {
        $category = $this->postJson('/api/v1/item-categories', ['name' => 'Drinks'], $this->headersFor())->assertCreated()->json('data.id');
        $item = $this->create(['code' => 'S1', 'category_id' => $category, 'barcodes' => [['barcode' => '111']]])->assertCreated()->json('data.id');
        $archived = $this->create(['code' => 'S2'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/items/{$archived}/archive", [], $this->headersFor())->assertOk();

        $this->settings(['mode' => 'per_company'])->assertUnprocessable()->assertJsonPath('code', 'records_need_company');
        // Two items (one archived) and one category.
        $this->settings(['mode' => 'per_company', 'assign_to_company_id' => $this->acme->id])->assertOk();

        $this->inTenant(function () use ($item, $archived, $category) {
            $this->assertSame($this->acme->id, Item::findOrFail($item)->company_id);
            $this->assertSame($this->acme->id, Item::findOrFail($archived)->company_id);
            $this->assertSame($this->acme->id, ItemCategory::findOrFail($category)->company_id);
            $this->assertSame($this->acme->id, ItemBarcode::query()->sole()->company_id);
        });

        // New items and categories now need a company.
        $this->create(['code' => 'S3'])->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->postJson('/api/v1/item-categories', ['name' => 'Food'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->create(['code' => 'S3', 'company_id' => $this->globex->id, 'category_id' => $category])
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->create(['code' => 'S3', 'company_id' => $this->acme->id, 'category_id' => $category])->assertCreated();
    }

    public function test_per_company_items_are_unique_per_company_and_invisible_to_other_companies(): void
    {
        $this->settings(['mode' => 'per_company'])->assertOk();

        $acmeItem = $this->create(['code' => 'abc', 'company_id' => $this->acme->id, 'name' => 'Acme pen', 'barcodes' => [['barcode' => '777']]])->assertCreated()->json('data.id');
        $this->create(['code' => 'ABC', 'company_id' => $this->acme->id])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->create(['code' => 'X', 'company_id' => $this->acme->id, 'barcodes' => [['barcode' => '7-7-7']]])->assertUnprocessable()->assertJsonValidationErrors('barcodes.0.barcode');

        // Another company may use the same code and barcode; the owner is warned of the barcode match.
        $globexItem = $this->create(['code' => 'ABC', 'company_id' => $this->globex->id, 'name' => 'Globex marker', 'barcodes' => [['barcode' => '777']]])
            ->assertCreated()
            ->assertJsonPath('meta.possible_duplicates', [['id' => $acmeItem, 'code' => 'abc', 'name' => 'Acme pen', 'reason' => 'barcode']])
            ->json('data.id');

        // A Globex cashier sees Globex's items only, and is warned of nothing from Acme.
        $globexCashier = $this->headersFor($this->userWith('cashier', Scope::location($this->globexLocation->id)));
        $this->assertSame([$globexItem], array_column($this->getJson('/api/v1/items', $globexCashier)->assertOk()->json('data'), 'id'));
        $this->assertSame([$globexItem], array_column($this->getJson('/api/v1/items?barcode=777', $globexCashier)->assertOk()->json('data'), 'id'));
        $this->getJson("/api/v1/items/{$acmeItem}", $globexCashier)->assertNotFound();
        $this->getJson("/api/v1/history/item/{$acmeItem}", $globexCashier)->assertNotFound();

        $globexKeeper = $this->headersFor($this->userWith('storekeeper', Scope::location($this->globexLocation->id)));
        $this->create(['code' => 'Z', 'company_id' => $this->acme->id], $globexKeeper)->assertNotFound();
        $this->create(['code' => 'Z', 'company_id' => $this->globex->id, 'name' => 'Acme pen'], $globexKeeper)->assertCreated()
            ->assertJsonPath('meta.possible_duplicates', []);

        // A branch manager reads and adds the company's items but changes them only with a covering scope.
        $manager = $this->headersFor($this->userWith('branch_manager', Scope::branch($this->branchA->id)));
        $this->getJson("/api/v1/items/{$acmeItem}", $manager)->assertOk();
        $this->create(['code' => 'M1', 'company_id' => $this->acme->id], $manager)->assertCreated();
        $this->patchJson("/api/v1/items/{$acmeItem}", ['name' => 'X'], $manager)->assertForbidden();

        // Moving an item to another company needs edit there; codes are checked in the new company.
        $acmeAdmin = $this->headersFor($this->userWith('admin', Scope::company($this->acme->id)));
        $this->patchJson("/api/v1/items/{$acmeItem}", ['company_id' => $this->globex->id], $acmeAdmin)->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->patchJson("/api/v1/items/{$acmeItem}", ['company_id' => $this->globex->id], $this->headersFor())->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'barcodes.0.barcode']);
    }

    public function test_sharing_again_is_refused_while_codes_or_barcodes_would_collide(): void
    {
        $this->settings(['mode' => 'per_company'])->assertOk();

        $acme = $this->create(['code' => 'dup-1', 'company_id' => $this->acme->id, 'barcodes' => [['barcode' => '0042']]])->assertCreated()->json('data.id');
        $globex = $this->create(['code' => 'DUP-1', 'company_id' => $this->globex->id, 'barcodes' => [['barcode' => '0042']]])->assertCreated()->json('data.id');
        $this->create(['code' => 'ONLY', 'company_id' => $this->globex->id, 'barcodes' => [['barcode' => '42']]])->assertCreated();

        $refused = $this->settings(['mode' => 'shared', 'confirm' => true])->assertUnprocessable()->assertJsonPath('code', 'duplicate_codes');
        $this->assertSame(['dup-1'], array_map('strtolower', $refused->json('codes')));
        $this->assertSame(['0042'], $refused->json('barcodes'));
        $this->assertSame('per_company', collect($this->getJson('/api/v1/master-data/settings', $this->headersFor())->json('data'))->firstWhere('data_type', 'items')['mode']);

        // Archived items never block: archiving one of each pair frees the switch.
        $this->postJson("/api/v1/items/{$globex}/archive", [], $this->headersFor())->assertOk();
        $this->settings(['mode' => 'shared', 'confirm' => true])->assertOk();

        $this->inTenant(function () use ($acme, $globex) {
            $this->assertNull(Item::findOrFail($acme)->company_id);
            $this->assertNull(Item::findOrFail($globex)->company_id);
            $this->assertSame(0, ItemBarcode::query()->whereNotNull('company_id')->count());
        });

        // The archived twin cannot come back while its code and barcode are taken.
        $this->postJson("/api/v1/items/{$globex}/restore", [], $this->headersFor())->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'barcodes.0.barcode']);
    }
}
