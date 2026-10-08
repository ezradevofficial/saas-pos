<?php

namespace Tests\Feature\Core\Sync;

use App\Core\Currency\Models\ExchangeRate;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemBarcode;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\ItemImages;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxRates;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\SyncCursor;
use App\Core\Sync\SyncSources;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTill;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NFR-04 master data pull: cursors, tombstones, scope, field minimisation,
// "Rate needed" items (CP-02), bootstrap (TEN-05), sync status.
class SyncPullTest extends TestCase
{
    use BuildsTill, RefreshTenantDatabase;

    private array $till;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->other = $this->inTenant(fn () => $this->company('Other company'));
        $this->till = $this->pairTill($this->locationA);
    }

    public function test_bootstrap_lists_the_entities_and_where_the_device_sits(): void
    {
        $response = $this->getJson('/api/v1/sync/bootstrap', $this->deviceHeaders($this->till))->assertOk();

        $keys = array_column($response->json('entities'), 'key');
        $this->assertSame(['settings', 'currencies', 'exchange_rates', 'tax_codes', 'tax_categories', 'price_lists', 'payment_methods', 'uoms', 'item_categories', 'items', 'customers', 'staff'], $keys);
        $this->assertSame('incremental', collect($response->json('entities'))->firstWhere('key', 'items')['mode']);
        $this->assertSame('snapshot', collect($response->json('entities'))->firstWhere('key', 'staff')['mode']);
        $response->assertJsonPath('device.id', $this->till['id'])
            ->assertJsonPath('device_secret_issued', true)
            ->assertJsonPath('settings.location.id', $this->locationA->id)
            ->assertJsonPath('settings.branch.id', $this->branchA->id)
            ->assertJsonPath('settings.company.id', $this->acme->id)
            ->assertJsonPath('settings.company.base_currency', 'KES')
            ->assertJsonPath('settings.timezone', 'Africa/Nairobi')
            ->assertJsonPath('pin.max_attempts', 5);
        $this->assertEqualsWithDelta(now()->getTimestamp(), CarbonImmutable::parse($response->json('server_time'))->getTimestamp(), 5);

        $this->inTenant(fn () => $this->assertNotNull(Device::findOrFail($this->till['id'])->last_bootstrap_at));
    }

    public function test_only_device_tokens_reach_sync_and_devices_never_reach_the_back_office(): void
    {
        $this->getJson('/api/v1/sync/pull', $this->headersFor())->assertForbidden();
        $this->getJson('/api/v1/sync/bootstrap', $this->headersFor())->assertForbidden();
        $this->getJson('/api/v1/items', $this->deviceHeaders($this->till))->assertUnauthorized();
        $this->getJson('/api/v1/sync/pull')->assertUnauthorized();
    }

    public function test_items_of_the_company_and_shared_ones_arrive_but_never_another_companys(): void
    {
        $taxes = $this->taxes($this->acme);
        $shared = $this->makeItem('SHARED', null, $taxes['category']->id);
        $own = $this->makeItem('OWN', $this->acme, $taxes['category']->id);
        $foreign = $this->makeItem('FOREIGN', $this->other);

        $pulled = $this->pullAll($this->till, 'items');

        $this->assertEqualsCanonicalizing([$shared->id, $own->id], array_keys($pulled['upserts']));
        $this->assertNotContains($foreign->id, $pulled['seen']);
        $row = $pulled['upserts'][$own->id];
        $this->assertSame('OWN', $row['code']);
        $this->assertTrue($row['sellable']);
        $this->assertNull($row['reason']);
        $this->assertSame($taxes['code']->id, $row['tax_code_id']);
        $this->assertFalse($row['shared']);
        $this->assertTrue($pulled['upserts'][$shared->id]['shared']);
        $this->assertArrayNotHasKey('custom', $row);
        $this->assertArrayNotHasKey('company_id', $row);

        // Nothing changed: nothing comes back.
        $again = $this->pull($this->till, ['items'], ['items' => $pulled['cursor']])->assertOk();
        $again->assertJsonPath('entities.items.upserts', [])->assertJsonPath('entities.items.tombstones', [])->assertJsonPath('entities.items.has_more', false);
        $this->assertSame($pulled['cursor'], $again->json('entities.items.cursor'));
    }

    public function test_pages_never_skip_or_repeat_rows_written_in_one_transaction(): void
    {
        // All in the test's transaction: one transaction id, ordered by sequence.
        $ids = [];
        foreach (range(1, 7) as $n) {
            $ids[] = $this->makeItem("P{$n}")->id;
        }

        $pulled = $this->pullAll($this->till, 'items', null, 3);

        $this->assertSame(3, $pulled['pages']);
        $this->assertSame($ids, $pulled['seen']);
        $this->assertCount(7, array_unique($pulled['seen']));

        $first = $this->pull($this->till, ['items'], [], 3)->assertOk();
        $first->assertJsonPath('entities.items.has_more', true)->assertJsonCount(3, 'entities.items.upserts');
    }

    public function test_a_change_after_the_cursor_is_sent_again_and_child_rows_restamp_the_item(): void
    {
        $item = $this->makeItem('CHG');
        $other = $this->makeItem('KEEP');
        $cursor = $this->pullAll($this->till, 'items')['cursor'];

        $this->inTenant(fn () => $item->fill(['name' => 'Renamed'])->save());
        $pulled = $this->pullAll($this->till, 'items', $cursor);
        $this->assertSame([$item->id], array_keys($pulled['upserts']));
        $this->assertSame('Renamed', $pulled['upserts'][$item->id]['name']);

        // A barcode and another unit travel inside the item.
        $box = $this->uom('BOX');
        $this->inTenant(function () use ($other, $box) {
            ItemUom::create(['item_id' => $other->id, 'uom_id' => $box->id, 'factor' => '12']);
            ItemBarcode::create(['item_id' => $other->id, 'barcode' => '6161000000001']);
        });

        $pulled = $this->pullAll($this->till, 'items', $pulled['cursor']);
        $this->assertSame([$other->id], array_keys($pulled['upserts']));
        $this->assertSame([['uom_id' => $box->id, 'factor' => '12', 'is_sales_default' => false]], $pulled['upserts'][$other->id]['uoms']);
        $this->assertSame([['barcode' => '6161000000001', 'uom_id' => null]], $pulled['upserts'][$other->id]['barcodes']);

        // Removing the barcode also re-sends the item.
        $this->inTenant(fn () => ItemBarcode::query()->where('item_id', $other->id)->get()->each->delete());
        $pulled = $this->pullAll($this->till, 'items', $pulled['cursor']);
        $this->assertSame([], $pulled['upserts'][$other->id]['barcodes']);
    }

    public function test_archiving_sends_a_tombstone_and_restoring_sends_the_row_again(): void
    {
        $item = $this->makeItem('ARC');
        $cursor = $this->pullAll($this->till, 'items')['cursor'];

        $this->inTenant(fn () => $item->archive());
        $pulled = $this->pullAll($this->till, 'items', $cursor);
        $this->assertSame([$item->id], $pulled['tombstones']);
        $this->assertSame([], $pulled['upserts']);

        $this->inTenant(fn () => $item->restore());
        $pulled = $this->pullAll($this->till, 'items', $pulled['cursor']);
        $this->assertSame([$item->id], array_keys($pulled['upserts']));
    }

    public function test_an_item_moving_to_another_company_leaves_a_tombstone_and_reaches_that_companys_tills(): void
    {
        $otherBranch = $this->inTenant(fn () => $this->branch($this->other, 'O'));
        $otherLocation = $this->inTenant(fn () => $this->location($otherBranch, 'Other outlet'));
        $otherTill = $this->pairTill($otherLocation, 'Other till');

        $item = $this->makeItem('MOVE');
        $category = $this->inTenant(fn () => ItemCategory::create(['name' => 'Moving']));
        $mine = $this->pullAll($this->till, 'items')['cursor'];
        $theirs = $this->pullAll($otherTill, 'items');
        $this->assertArrayHasKey($item->id, $theirs['upserts']);
        $categories = $this->pullAll($this->till, 'item_categories')['cursor'];

        // Shared to the other company.
        $this->inTenant(function () use ($item, $category) {
            $item->fill(['company_id' => $this->other->id])->save();
            $category->fill(['company_id' => $this->other->id])->save();
        });

        $this->assertSame([$item->id], $this->pullAll($this->till, 'items', $mine)['tombstones']);
        $this->assertSame([$category->id], $this->pullAll($this->till, 'item_categories', $categories)['tombstones']);
        $theirsNow = $this->pullAll($otherTill, 'items', $theirs['cursor']);
        $this->assertSame([$item->id], array_keys($theirsNow['upserts']));
        $this->assertSame([], $theirsNow['tombstones']);

        // And back to this company: an upsert here, a tombstone there.
        $mine = $this->pullAll($this->till, 'items', $mine)['cursor'];
        $this->inTenant(fn () => $item->fill(['company_id' => $this->acme->id])->save());
        $this->assertSame([$item->id], array_keys($this->pullAll($this->till, 'items', $mine)['upserts']));
        $this->assertSame([$item->id], $this->pullAll($otherTill, 'items', $theirsNow['cursor'])['tombstones']);
    }

    public function test_customers_are_parties_with_the_customer_role_and_leave_when_they_lose_it(): void
    {
        [$customer, $supplier, $foreign] = $this->inTenant(fn () => [
            Party::create(['kind' => 'person', 'name' => 'Asha', 'roles' => ['customer', 'contact'], 'phones' => [['number' => '+254700000001', 'label' => 'mobile']],
                'emails' => [['address' => 'asha@example.com']], 'tax_id' => 'A001', 'credit_limit_minor' => 500000, 'credit_limit_currency' => 'KES']),
            Party::create(['kind' => 'organisation', 'name' => 'Supplier Ltd', 'roles' => ['supplier']]),
            Party::create(['kind' => 'person', 'name' => 'Foreign', 'roles' => ['customer'], 'company_id' => $this->other->id]),
        ]);

        $pulled = $this->pullAll($this->till, 'customers');
        $this->assertSame([$customer->id], array_keys($pulled['upserts']));
        $this->assertNotContains($supplier->id, $pulled['seen']);
        $this->assertNotContains($foreign->id, $pulled['seen']);
        $this->assertSame([
            'id' => $customer->id,
            'kind' => 'person',
            'name' => 'Asha',
            'tax_id' => 'A001',
            'phones' => [['number' => '+254700000001', 'label' => 'mobile']],
            'currency' => null,
            'payment_terms_days' => null,
            'credit_limit' => ['amount_minor' => '500000', 'currency' => 'KES'],
            'price_list_id' => null,
            'shared' => true,
        ], array_diff_key($pulled['upserts'][$customer->id], ['updated_at' => true]));

        $this->inTenant(fn () => $customer->fill(['roles' => ['contact']])->save());
        $after = $this->pullAll($this->till, 'customers', $pulled['cursor']);
        $this->assertSame([$customer->id], $after['tombstones']);
    }

    public function test_items_whose_tax_is_unknown_are_flagged_and_become_sellable_once_the_rate_is_confirmed(): void
    {
        $needed = $this->taxes($this->acme, null, 'VAT_NEW');
        $known = $this->taxes($this->acme, '16', 'VAT_STD');
        $blocked = $this->makeItem('BLOCKED', null, $needed['category']->id);
        $fine = $this->makeItem('FINE', null, $known['category']->id);
        $untaxed = $this->makeItem('NOCAT');
        $foreignCategory = $this->inTenant(fn () => TaxCategory::create(['name' => 'No code here']));
        $noCode = $this->makeItem('NOCODE', null, $foreignCategory->id);

        $pulled = $this->pullAll($this->till, 'items');
        $this->assertSame([false, 'tax_rate_needed'], [$pulled['upserts'][$blocked->id]['sellable'], $pulled['upserts'][$blocked->id]['reason']]);
        $this->assertSame([true, null], [$pulled['upserts'][$fine->id]['sellable'], $pulled['upserts'][$fine->id]['reason']]);
        $this->assertSame([false, 'tax_category_missing'], [$pulled['upserts'][$untaxed->id]['sellable'], $pulled['upserts'][$untaxed->id]['reason']]);
        $this->assertSame([false, 'tax_code_missing'], [$pulled['upserts'][$noCode->id]['sellable'], $pulled['upserts'][$noCode->id]['reason']]);

        // The tax codes entity tells the till the rate is needed too.
        $codes = collect($this->pull($this->till, ['tax_codes'])->assertOk()->json('entities.tax_codes.upserts'))->keyBy('code');
        $this->assertSame([['rate' => null, 'effective_from' => '2026-01-01', 'effective_to' => null, 'needs_confirmation' => true]], $codes['VAT_NEW']['rates']);

        // Confirming the rate re-sends exactly the items of that category.
        $this->inTenant(fn () => app(TaxRates::class)->add($needed['code'], '8', CarbonImmutable::parse('2026-01-01')));
        $after = $this->pullAll($this->till, 'items', $pulled['cursor']);
        $this->assertSame([$blocked->id], array_keys($after['upserts']));
        $this->assertTrue($after['upserts'][$blocked->id]['sellable']);
    }

    public function test_payment_methods_never_carry_settings_or_secrets(): void
    {
        $this->inTenant(fn () => PaymentMethod::create([
            'company_id' => $this->acme->id, 'type' => 'mobile_money', 'name' => 'M-Pesa', 'provider' => 'mpesa_ke', 'position' => 1, 'active' => true,
            'settings' => ['shortcode' => '174379'], 'secrets' => ['consumer_secret' => 'very-secret-value'],
        ]));

        $response = $this->pull($this->till, ['payment_methods'])->assertOk();
        $rows = $response->json('entities.payment_methods.upserts');

        $this->assertSame(['id', 'type', 'name', 'currency', 'provider', 'position'], array_keys($rows[0]));
        $this->assertStringNotContainsString('very-secret-value', $response->getContent());
        $this->assertStringNotContainsString('174379', $response->getContent());
    }

    public function test_snapshots_are_sent_whole_once_and_then_only_when_they_change(): void
    {
        $first = $this->pull($this->till, ['tax_codes', 'settings'])->assertOk();
        $first->assertJsonPath('entities.tax_codes.replace', true)->assertJsonPath('entities.tax_codes.upserts', []);
        $cursor = $first->json('entities.tax_codes.cursor');
        $settings = $first->json('entities.settings.cursor');

        $this->pull($this->till, ['tax_codes', 'settings'], ['tax_codes' => $cursor, 'settings' => $settings])->assertOk()
            ->assertJsonPath('entities.tax_codes.replace', false)
            ->assertJsonPath('entities.settings.replace', false)
            ->assertJsonPath('entities.settings.upserts', []);

        $this->taxes($this->acme, '16', 'VAT_STD');
        $this->pull($this->till, ['tax_codes'], ['tax_codes' => $cursor])->assertOk()
            ->assertJsonPath('entities.tax_codes.replace', true)
            ->assertJsonPath('entities.tax_codes.upserts.0.code', 'VAT_STD');
    }

    public function test_exchange_rates_carry_the_rate_in_force_and_later_ones(): void
    {
        $this->inTenant(function () {
            TenantCurrency::create(['code' => 'KES', 'decimals' => 2]);
            TenantCurrency::create(['code' => 'USD', 'decimals' => 2]);
            ExchangeRate::create(['company_id' => $this->acme->id, 'base' => 'USD', 'quote' => 'KES', 'kind' => 'reference', 'mid' => '129', 'effective_at' => now()->subDays(2), 'source' => 'test']);
            ExchangeRate::create(['company_id' => $this->acme->id, 'base' => 'USD', 'quote' => 'KES', 'kind' => 'shop', 'mid' => '130', 'effective_at' => now()->subDay(), 'source' => 'test']);
            ExchangeRate::create(['company_id' => $this->acme->id, 'base' => 'USD', 'quote' => 'KES', 'kind' => 'shop', 'mid' => '131.5', 'effective_at' => now()->addDay(), 'source' => 'test']);
            ExchangeRate::create(['company_id' => $this->other->id, 'base' => 'USD', 'quote' => 'KES', 'kind' => 'shop', 'mid' => '999', 'effective_at' => now()->subDay(), 'source' => 'test']);
        });

        $rows = $this->pull($this->till, ['exchange_rates'])->assertOk()->json('entities.exchange_rates.upserts');

        $this->assertSame([['130.00000000', 'shop', true], ['131.50000000', 'shop', false]], array_map(fn ($r) => [$r['mid'], $r['kind'], $r['current']], $rows));
    }

    public function test_cursors_are_checked_and_a_new_source_version_starts_over(): void
    {
        $this->pull($this->till, ['items'], ['items' => 'garbage'])->assertUnprocessable()->assertJsonPath('code', 'invalid_cursor');
        $this->pull($this->till, ['items'], ['items' => SyncCursor::snapshot(1, str_repeat('a', 64))->encode()])->assertUnprocessable();
        $this->pull($this->till, ['nonsense'])->assertUnprocessable();

        $item = $this->makeItem('VER');
        $old = SyncCursor::at(99, PHP_INT_MAX >> 1, 1)->encode();
        $response = $this->pull($this->till, ['items'], ['items' => $old])->assertOk();
        $response->assertJsonPath('entities.items.reset', true);
        $this->assertContains($item->id, array_column($response->json('entities.items.upserts'), 'id'));
    }

    public function test_entities_of_a_module_the_tenant_has_not_activated_are_not_served(): void
    {
        app(ModuleRegistry::class)->register('pos_test');
        app(SyncSources::class)->register(new class implements SnapshotSource
        {
            public function key(): string
            {
                return 'pos_test_ranges';
            }

            public function module(): string
            {
                return 'pos_test';
            }

            public function version(): int
            {
                return 1;
            }

            public function rows(DeviceScope $scope): array
            {
                return [['id' => 'range', 'from' => 1, 'to' => 100]];
            }
        });

        $keys = array_column($this->getJson('/api/v1/sync/bootstrap', $this->deviceHeaders($this->till))->json('entities'), 'key');
        $this->assertNotContains('pos_test_ranges', $keys);
        $this->pull($this->till, ['pos_test_ranges'])->assertUnprocessable();

        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('pos_test'));
        $this->pull($this->till, ['pos_test_ranges'])->assertOk()->assertJsonPath('entities.pos_test_ranges.upserts.0.to', 100);
    }

    public function test_a_pull_records_the_sync_time_shown_in_the_devices_api(): void
    {
        $this->pull($this->till, ['settings'])->assertOk();

        $device = $this->getJson("/api/v1/devices/{$this->till['id']}", $this->headersFor())->assertOk();
        $this->assertNotNull($device->json('data.last_pull_at'));
        $this->assertNull($device->json('data.last_push_at'));
        $this->assertArrayNotHasKey('secret', $device->json('data'));
    }

    public function test_item_images_reach_the_device_only_for_items_it_may_hold(): void
    {
        Storage::fake('media');
        $own = $this->makeItem('IMG');
        $foreign = $this->makeItem('IMGX', $this->other);
        [$image, $foreignImage] = $this->inTenant(fn () => [
            app(ItemImages::class)->add($own, UploadedFile::fake()->image('a.jpg', 8, 8)),
            app(ItemImages::class)->add($foreign, UploadedFile::fake()->image('b.jpg', 8, 8)),
        ]);

        $row = $this->pullAll($this->till, 'items')['upserts'][$own->id];
        $this->assertSame('/api/v1/sync/media/'.$image->id, $row['images'][0]['url']);

        $this->get($row['images'][0]['url'], $this->deviceHeaders($this->till))->assertOk();
        $this->get('/api/v1/sync/media/'.$foreignImage->id, $this->deviceHeaders($this->till))->assertNotFound();
        $this->get('/api/v1/sync/media/'.$image->id, $this->headersFor())->assertForbidden();
    }

    public function test_another_tenants_data_never_reaches_the_device(): void
    {
        $other = $this->otherTenant();
        $foreign = $this->asTenant($other['user']->tenant_id, fn () => Item::create([
            'code' => 'THEIRS', 'name' => 'Their item', 'type' => 'stock',
            'base_uom_id' => Uom::create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count'])->id,
        ]));
        $this->makeItem('MINE');

        $response = $this->pull($this->till, [])->assertOk();

        $this->assertStringNotContainsString($foreign->id, $response->getContent());
        $this->assertStringNotContainsString($other['company']->id, $response->getContent());
        $this->assertStringNotContainsString('Their item', $response->getContent());
        $this->assertSame(0, DB::table('sync_tombstones')->count());
    }
}
