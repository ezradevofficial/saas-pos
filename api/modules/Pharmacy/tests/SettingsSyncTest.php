<?php

namespace Modules\Pharmacy\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;
use Modules\Pharmacy\Models\ItemProfile;
use Modules\Pharmacy\Recalls\Recalls;
use Modules\Pharmacy\Stock\Lots;
use Modules\Pharmacy\Tests\Concerns\BuildsPharmacy;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PHA-02, PHA-03, PHA-20, PHA-21, PHA-25: a company's rules and the item
// profiles (audited, permissioned, tenant-bound) and what the till pulls to
// work offline: rules, profiles and the lots with stock at its location.
class SettingsSyncTest extends TestCase
{
    use BuildsPharmacy, RefreshTenantDatabase;

    private User $pharmacist;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPharmacy();
        $this->pharmacist = $this->userWith('pharmacist', Scope::location($this->locationA->id));
        $this->admin = $this->userWith('admin', Scope::tenant());
    }

    private function pull(string $entity): ?array
    {
        return $this->getJson("/api/v1/sync/pull?entities[]={$entity}", $this->tillHeaders())->assertOk()->json("entities.{$entity}");
    }

    public function test_a_companys_settings_default_to_neutral_values_and_are_changed_and_audited(): void
    {
        $url = "/api/v1/companies/{$this->acme->id}/pharmacy-settings";
        $this->getJson($url, $this->headersFor($this->admin))->assertOk()
            ->assertJsonPath('data.configured', false)->assertJsonPath('data.near_expiry_days', 90)->assertJsonPath('data.min_sale_days', 0)
            ->assertJsonPath('data.prescription_classes', ['prescription_only', 'controlled'])
            ->assertJsonPath('data.pharmacist_classes', ['prescription_only', 'controlled', 'poison'])
            ->assertJsonPath('data.prescription_valid_days', null)->assertJsonPath('data.quarantine_expired', true);

        $this->putJson($url, ['near_expiry_days' => 120, 'prescription_valid_days' => 30, 'prescription_classes' => ['prescription_only', 'controlled', 'poison']], $this->headersFor($this->admin))->assertOk()
            ->assertJsonPath('data.configured', true)->assertJsonPath('data.near_expiry_days', 120)->assertJsonPath('data.prescription_valid_days', 30)
            ->assertJsonPath('data.prescription_classes', ['prescription_only', 'controlled', 'poison'])->assertJsonPath('data.pharmacist_classes', ['prescription_only', 'controlled', 'poison']);
        $this->putJson($url, ['prescription_valid_days' => null], $this->headersFor($this->admin))->assertOk()->assertJsonPath('data.prescription_valid_days', null);
        $this->assertSame(['pharmacy.settings.create', 'pharmacy.settings.update'], $this->inTenant(fn () => AuditEntry::where('action', 'like', 'pharmacy.settings.%')->orderBy('seq')->pluck('action')->all()));

        $this->putJson($url, ['near_expiry_days' => 9999], $this->headersFor($this->admin))->assertUnprocessable()->assertJsonValidationErrors('near_expiry_days');
        $this->putJson($url, ['pharmacist_classes' => ['made_up']], $this->headersFor($this->admin))->assertUnprocessable()->assertJsonValidationErrors('pharmacist_classes.0');
    }

    public function test_settings_are_read_by_those_who_may_and_changed_only_by_those_who_may_edit(): void
    {
        $url = "/api/v1/companies/{$this->acme->id}/pharmacy-settings";
        $this->getJson($url, $this->headersFor($this->pharmacist))->assertNotFound();

        $companyPharmacist = $this->userWith('pharmacist', Scope::company($this->acme->id));
        $this->getJson($url, $this->headersFor($companyPharmacist))->assertOk();
        $this->putJson($url, ['near_expiry_days' => 60], $this->headersFor($companyPharmacist))->assertForbidden();
        $this->putJson($url, ['near_expiry_days' => 60], $this->headersFor($this->admin))->assertOk();

        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('pharmacy'));
        $this->getJson($url, $this->headersFor($other['user']))->assertNotFound();
        $this->putJson($url, ['near_expiry_days' => 1], $this->headersFor($other['user']))->assertNotFound();
    }

    public function test_item_profiles_class_agrochemicals_and_veterinary_drugs_and_are_audited(): void
    {
        $path = "/api/v1/pharmacy/items/{$this->plain->id}/profile";
        $this->getJson($path, $this->headersFor($this->pharmacist))->assertOk()->assertJsonPath('data.configured', false)->assertJsonPath('data.class', 'otc')->assertJsonPath('data.batch_tracked', false);

        $this->putJson($path, ['class' => 'agrochemical', 'product_kind' => 'agrochemical', 'batch_tracked' => true, 'restricted_sale' => true, 'usage_notes' => 'Dilute 1:100.', 'schedule_note' => 'Category to confirm'], $this->headersFor($this->pharmacist))->assertOk()
            ->assertJsonPath('data.configured', true)->assertJsonPath('data.class', 'agrochemical')->assertJsonPath('data.usage_notes', 'Dilute 1:100.')->assertJsonPath('data.restricted_sale', true);
        $this->putJson($path, ['product_kind' => 'veterinary', 'withdrawal_days' => 14], $this->headersFor($this->pharmacist))->assertOk()->assertJsonPath('data.withdrawal_days', 14)->assertJsonPath('data.class', 'agrochemical');
        $this->assertSame(['pharmacy.item.create', 'pharmacy.item.update'], $this->inTenant(fn () => AuditEntry::where('action', 'like', 'pharmacy.item.%')->where('auditable_id', $this->inTenant(fn () => ItemProfile::where('item_id', $this->plain->id)->value('id')))->orderBy('seq')->pluck('action')->all()));

        $this->putJson($path, ['class' => 'schedule_x'], $this->headersFor($this->pharmacist))->assertUnprocessable()->assertJsonValidationErrors('class');
        $technician = $this->userWith('pharmacy_technician', Scope::location($this->locationA->id));
        $this->putJson($path, ['class' => 'otc'], $this->headersFor($technician))->assertForbidden();
        $this->getJson($path, $this->headersFor($technician))->assertOk();
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->getJson($path, $this->headersFor($cashier))->assertForbidden();

        // A service has no stock to track.
        $service = $this->inTenant(fn () => Item::create(['code' => 'SVC', 'name' => 'Counselling', 'type' => 'service', 'base_uom_id' => $this->each->id]));
        $this->putJson("/api/v1/pharmacy/items/{$service->id}/profile", ['class' => 'otc'], $this->headersFor($this->pharmacist))->assertUnprocessable()->assertJsonPath('code', 'item_not_stock');

        $this->getJson('/api/v1/pharmacy/items?class=agrochemical', $this->headersFor($this->pharmacist))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/pharmacy/items?search=morph', $this->headersFor($this->pharmacist))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.class', 'controlled');
    }

    public function test_the_till_pulls_the_rules_the_profiles_and_the_lots_of_its_location(): void
    {
        $this->settings(['near_expiry_days' => 45, 'prescription_valid_days' => 20]);
        $here = $this->lot($this->amox, 'HERE', 100, '6', null, 20);
        $this->lot($this->amox, 'THERE', 100, '6', $this->locationB->id);
        $empty = $this->lot($this->amox, 'EMPTY', 100, '1');
        $this->inTenant(fn () => app(Lots::class)->move($empty, $this->locationA->id, 'adjustment', BigDecimal::of('-1'), 'test.adjust', (string) Str::uuid7()));

        $settings = collect($this->pull('pharmacy_settings')['upserts']);
        $rule = $settings->firstWhere('id', 'settings');
        $this->assertSame([45, 0, ['prescription_only', 'controlled'], 20], [$rule['near_expiry_days'], $rule['min_sale_days'], $rule['prescription_classes'], $rule['prescription_valid_days']]);
        $this->assertTrue($settings->firstWhere('id', 'module')['active']);

        $items = collect($this->pull('pharmacy_items')['upserts'])->keyBy('item_id');
        $this->assertSame(['prescription_only', true, null], [$items[$this->amox->id]['class'], $items[$this->amox->id]['batch_tracked'], $items[$this->amox->id]['usage_notes']]);
        $this->assertSame(['Wear gloves. Keep from water.', true, 'agrochemical'], [$items[$this->pesticide->id]['usage_notes'], $items[$this->pesticide->id]['restricted_sale'], $items[$this->pesticide->id]['product_kind']]);
        $this->assertSame(14, $items[$this->vet->id]['withdrawal_days']);
        $this->assertArrayNotHasKey($this->plain->id, $items->all());

        // Only the lots with stock at this till's place, with what it needs to pick, refuse and warn offline.
        $lots = collect($this->pull('pharmacy_lots')['upserts']);
        $this->assertSame(['HERE'], $lots->pluck('batch_number')->all());
        $row = $lots->first();
        $this->assertSame([$this->amox->id, $this->inDays(100), $this->inDays(-20), '6', null], [$row['item_id'], $row['expires_on'], $row['manufactured_on'], $row['qty'], $row['blocked']]);
        $this->assertArrayNotHasKey('supplier', $row);
    }

    public function test_a_quarantine_or_recall_reaches_the_till_on_its_next_pull_and_stock_that_runs_out_leaves_it(): void
    {
        $lot = $this->lot($this->amox, 'HOLD', 100, '6');
        $first = $this->getJson('/api/v1/sync/pull?entities[]=pharmacy_lots', $this->tillHeaders())->assertOk()->json('entities.pharmacy_lots');
        $cursor = $first['cursor'];

        $this->postJson("/api/v1/pharmacy/lots/{$lot->id}/quarantine", ['reason' => 'Damaged'], $this->headersFor($this->pharmacist))->assertOk();
        $next = $this->getJson('/api/v1/sync/pull?entities[]=pharmacy_lots&cursors[pharmacy_lots]='.$cursor, $this->tillHeaders())->assertOk()->json('entities.pharmacy_lots');
        $this->assertSame('quarantined', $next['upserts'][0]['blocked']);

        $this->postJson("/api/v1/pharmacy/lots/{$lot->id}/release", ['reason' => 'Fine'], $this->headersFor($this->pharmacist))->assertOk();
        $cursor = $next['cursor'];
        $this->inTenant(fn () => app(Recalls::class)->issue($this->amox->id, 'HOLD', 'Recall', null, $this->owner));
        $next = $this->getJson('/api/v1/sync/pull?entities[]=pharmacy_lots&cursors[pharmacy_lots]='.$cursor, $this->tillHeaders())->assertOk()->json('entities.pharmacy_lots');
        $this->assertSame('recalled', end($next['upserts'])['blocked']);

        // Stock that runs out leaves the till (a tombstone) and comes back when stock arrives.
        $cursor = $next['cursor'];
        $this->inTenant(fn () => app(Lots::class)->move($lot, $this->locationA->id, 'adjustment', BigDecimal::of('-6'), 'test.adjust', (string) Str::uuid7()));
        $gone = $this->getJson('/api/v1/sync/pull?entities[]=pharmacy_lots&cursors[pharmacy_lots]='.$cursor, $this->tillHeaders())->assertOk()->json('entities.pharmacy_lots');
        $this->assertSame([], $gone['upserts']);
        $this->assertCount(1, $gone['tombstones']);
    }

    public function test_pharmacy_data_is_not_served_to_a_tenant_without_the_module_and_staff_rows_carry_the_pharmacists_right(): void
    {
        $this->lot($this->amox, 'HERE', 100, '6');
        $staff = collect($this->getJson('/api/v1/sync/pull?entities[]=staff', $this->tillHeaders())->assertOk()->json('entities.staff.upserts'))->keyBy('id');
        $this->assertContains('pharmacy.dispense.authorise', $staff[$this->pharmacist->id]['permissions']);
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $staff = collect($this->getJson('/api/v1/sync/pull?entities[]=staff', $this->tillHeaders())->assertOk()->json('entities.staff.upserts'))->keyBy('id');
        $this->assertNotContains('pharmacy.dispense.authorise', $staff[$cashier->id]['permissions']);

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('pharmacy'));
        foreach (['pharmacy_settings', 'pharmacy_items', 'pharmacy_lots'] as $entity) {
            $this->assertNull($this->getJson("/api/v1/sync/pull?entities[]={$entity}", $this->tillHeaders())->json("entities.{$entity}"));
        }
        $this->getJson('/api/v1/pharmacy/lots', $this->headersFor($this->admin))->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }
}
