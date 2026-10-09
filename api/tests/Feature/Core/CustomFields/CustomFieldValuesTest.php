<?php

namespace Tests\Feature\Core\CustomFields;

use App\Core\Currency\TenantCurrencies;
use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\CustomFields\CustomFieldFile;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\DefaultUoms;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CF-01, CF-02, CF-03, CF-06, RBAC-05: custom values on items and parties:
// validated per type and setting, unique per tenant and entity, formulas
// computed on save, shown in resources, lists, filters, sorts and exports
// only where the user may see them, and refused on write where the user may
// not change them. Lookups stay inside the tenant and the user's reach.
class CustomFieldValuesTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private string $eachUom;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        $this->setUpOrganisation();
        $this->eachUom = $this->inTenant(function () {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            app(DefaultUoms::class)->seed();

            return Uom::query()->where('code', 'ea')->value('id');
        });
    }

    private function field(array $attributes): CustomFieldDefinition
    {
        return $this->inTenant(fn () => CustomFieldDefinition::create($attributes + ['entity' => 'item', 'label' => ucfirst($attributes['key'])]));
    }

    private function item(array $custom, string $code = 'SODA', ?array $headers = null)
    {
        return $this->postJson('/api/v1/items', [
            'code' => $code, 'name' => "Item {$code}", 'type' => 'stock', 'base_uom_id' => $this->eachUom, 'custom' => $custom,
        ], $headers ?? $this->headersFor());
    }

    /** A user whose only role has item and party permissions, plus $extra. */
    private function clerk(array $extra = []): array
    {
        return $this->inTenant(function () use ($extra) {
            $role = $this->role('Clerk '.count($extra).random_int(1, 99999), [
                'core.item.view', 'core.item.create', 'core.item.edit', 'core.party.view', 'core.party.create', 'core.party.edit', ...$extra,
            ]);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return [$user, $role];
        });
    }

    public function test_each_type_is_validated_and_stored_in_its_shape(): void
    {
        $this->field(['key' => 'note', 'type' => 'text', 'min_value' => 2, 'max_value' => 10, 'pattern' => '[A-Z][a-z]+']);
        $this->field(['key' => 'story', 'type' => 'long_text']);
        $this->field(['key' => 'weight', 'type' => 'number', 'min_value' => 0, 'max_value' => 100]);
        $this->field(['key' => 'cost', 'type' => 'money']);
        $this->field(['key' => 'expires', 'type' => 'date']);
        $this->field(['key' => 'checked_at', 'type' => 'datetime']);
        $this->field(['key' => 'fragile', 'type' => 'boolean']);
        $this->field(['key' => 'colour', 'type' => 'select', 'options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']]]);
        $this->field(['key' => 'tags', 'type' => 'multi_select', 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]]);

        $bad = $this->item([
            'note' => 'lower', 'weight' => 101, 'cost' => ['amount_minor' => '12.5', 'currency' => 'KES'], 'expires' => '2026-02-30',
            'checked_at' => 'yesterday', 'fragile' => 'yes', 'colour' => 'green', 'tags' => ['c'], 'ghost' => 'x',
        ])->assertUnprocessable();
        foreach (['note', 'weight', 'cost', 'expires', 'checked_at', 'fragile', 'colour', 'tags', 'ghost'] as $key) {
            $bad->assertJsonValidationErrors("custom.{$key}");
        }
        $this->item(['weight' => 1.5])->assertUnprocessable()->assertJsonValidationErrors('custom.weight');
        $this->item(['cost' => ['amount_minor' => '100', 'currency' => 'EUR']])->assertUnprocessable()
            ->assertJsonPath('errors', fn (array $errors) => $errors['custom.cost'][0] === __('core.custom_field.errors.currency', ['field' => 'Cost']));

        $id = $this->item([
            'note' => 'Hello', 'story' => "Line one\nLine two", 'weight' => '12.50', 'cost' => ['amount_minor' => 125000, 'currency' => 'KES'],
            'expires' => '2027-01-31', 'checked_at' => '2026-10-09T10:15:00+03:00', 'fragile' => true, 'colour' => 'blue', 'tags' => ['b', 'a'],
        ])->assertCreated()
            ->assertJsonPath('data.custom.weight', '12.5')
            ->assertJsonPath('data.custom.cost', ['amount_minor' => '125000', 'currency' => 'KES'])
            ->assertJsonPath('data.custom.checked_at', '2026-10-09T07:15:00Z')
            ->assertJsonPath('data.custom.tags', ['a', 'b'])
            ->json('data.id');

        // Only the keys given change; null clears.
        $this->patchJson("/api/v1/items/{$id}", ['custom' => ['note' => null, 'weight' => '3']], $this->headersFor())->assertOk()
            ->assertJsonPath('data.custom.weight', '3')
            ->assertJsonPath('data.custom.colour', 'blue')
            ->assertJsonMissingPath('data.custom.note');

        $this->inTenant(fn () => $this->assertSame('3', Item::findOrFail($id)->custom['weight']));
    }

    public function test_required_defaults_and_unique(): void
    {
        $this->field(['key' => 'serial', 'type' => 'text', 'is_unique' => true]);
        $this->field(['key' => 'grade', 'type' => 'select', 'required' => true, 'options' => [['value' => 'a', 'label' => 'A']]]);
        $this->field(['key' => 'origin', 'type' => 'text', 'required' => true, 'default_value' => 'Kenya']);

        $this->item([])->assertUnprocessable()->assertJsonValidationErrors('custom.grade')->assertJsonMissingValidationErrors('custom.origin');
        $first = $this->item(['grade' => 'a', 'serial' => 'SN-1'])->assertCreated()->assertJsonPath('data.custom.origin', 'Kenya')->json('data.id');

        $this->item(['grade' => 'a', 'serial' => 'SN-1'], 'COLA')->assertUnprocessable()
            ->assertJsonPath('errors', fn (array $errors) => $errors['custom.serial'][0] === __('core.custom_field.errors.unique', ['field' => 'Serial']));
        $second = $this->item(['grade' => 'a', 'serial' => 'SN-2'], 'COLA')->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/items/{$second}", ['custom' => ['serial' => 'SN-1']], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('custom.serial');
        // Saving a record with its own value again is fine; a required field can't be cleared.
        $this->patchJson("/api/v1/items/{$first}", ['custom' => ['serial' => 'SN-1']], $this->headersFor())->assertOk();
        $this->patchJson("/api/v1/items/{$first}", ['custom' => ['grade' => null]], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('custom.grade');

        // Archived records free their values; restoring one is refused while another holds it.
        $this->postJson("/api/v1/items/{$first}/archive", [], $this->headersFor())->assertOk();
        $this->patchJson("/api/v1/items/{$second}", ['custom' => ['serial' => 'SN-1']], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/items/{$first}/restore", [], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('custom.serial');
    }

    public function test_formulas_are_computed_on_save_and_never_written(): void
    {
        $this->field(['key' => 'cost', 'type' => 'number']);
        $this->field(['key' => 'markup', 'type' => 'number']);
        $this->field(['key' => 'price', 'type' => 'formula', 'formula' => 'round(cost * (1 + markup / 100), 2)', 'formula_type' => 'number']);
        $this->field(['key' => 'band', 'type' => 'formula', 'formula' => 'if(cost > 100, "High", "Low")', 'formula_type' => 'text']);

        $id = $this->item(['cost' => '80', 'markup' => '12.5'])->assertCreated()
            ->assertJsonPath('data.custom.price', '90')
            ->assertJsonPath('data.custom.band', 'Low')
            ->json('data.id');

        $this->patchJson("/api/v1/items/{$id}", ['custom' => ['cost' => '200']], $this->headersFor())->assertOk()
            ->assertJsonPath('data.custom.price', '225')
            ->assertJsonPath('data.custom.band', 'High');

        $this->patchJson("/api/v1/items/{$id}", ['custom' => ['price' => '1']], $this->headersFor())->assertUnprocessable()
            ->assertJsonPath('code', 'field_readonly')->assertJsonValidationErrors('custom.price');
        // A missing input gives no value rather than a failed save.
        $this->patchJson("/api/v1/items/{$id}", ['custom' => ['markup' => null]], $this->headersFor())->assertOk()->assertJsonMissingPath('data.custom.price');
    }

    public function test_hidden_fields_are_left_out_of_resources_lists_and_exports(): void
    {
        [$clerk, $clerkRole] = $this->clerk();
        [$other, $otherRole] = $this->clerk();
        $manager = $this->inTenant(fn () => $this->roles->get('admin'));
        $this->field(['key' => 'margin', 'type' => 'number', 'visible_roles' => [$manager->id, $otherRole->id]]);
        $this->field(['key' => 'supplier_code', 'type' => 'text']);
        // RBAC-05 field rules name custom fields `custom.<key>`.
        $this->inTenant(fn () => FieldRule::create(['role_id' => $otherRole->id, 'resource' => 'item', 'field' => 'custom.supplier_code', 'mode' => 'hidden']));

        $id = $this->item(['margin' => '42', 'supplier_code' => 'SUP-77'])->assertCreated()->json('data.id');

        foreach ([[$clerk, 'margin'], [$other, 'supplier_code']] as [$user, $hidden]) {
            $headers = $this->headersFor($user);
            $shown = $hidden === 'margin' ? 'supplier_code' : 'margin';

            $this->getJson("/api/v1/items/{$id}", $headers)->assertOk()
                ->assertJsonMissingPath("data.custom.{$hidden}")->assertJsonPath("data.custom.{$shown}", $hidden === 'margin' ? 'SUP-77' : '42');
            $list = $this->getJson('/api/v1/items', $headers)->assertOk();
            $this->assertArrayNotHasKey($hidden, $list->json('data.0.custom'));

            $csv = $this->get('/api/v1/items?format=csv', $headers)->assertOk()->streamedContent();
            $this->assertStringNotContainsString($hidden === 'margin' ? '42' : 'SUP-77', $csv);
            $this->assertStringContainsString($hidden === 'margin' ? 'SUP-77' : '42', $csv);
            // Asking for the hidden column alone, sorting or filtering by it is refused.
            $this->get("/api/v1/items?format=csv&columns[]=cf_{$hidden}", [...$headers, 'Accept' => 'application/json'])->assertUnprocessable();
            $this->getJson("/api/v1/items?sort=cf_{$hidden}", $headers)->assertUnprocessable();
            $this->getJson("/api/v1/items?custom[{$hidden}]=1", $headers)->assertUnprocessable();
            // History leaves the hidden value out.
            $history = json_encode($this->getJson("/api/v1/history/item/{$id}", $headers)->assertOk()->json('data'));
            $this->assertStringNotContainsString($hidden === 'margin' ? '"margin"' : 'SUP-77', $history);

            $schema = $this->getJson('/api/v1/custom-fields/schema?entity=item', $headers)->assertOk();
            $this->assertSame([$shown], array_column($schema->json('data'), 'key'));
        }

        // The owner sees both, in the export too.
        $csv = $this->get('/api/v1/items?format=csv', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('SUP-77', $csv);
        $this->assertStringContainsString('Margin', $csv);
    }

    public function test_writing_a_field_the_user_may_not_change_is_refused(): void
    {
        [$clerk] = $this->clerk();
        $admin = $this->inTenant(fn () => $this->roles->get('admin'));
        $this->field(['key' => 'margin', 'type' => 'number', 'editable_roles' => [$admin->id]]);
        $this->field(['key' => 'secret', 'type' => 'text', 'visible_roles' => [$admin->id]]);
        $id = $this->item(['margin' => '5'])->assertCreated()->json('data.id');
        $headers = $this->headersFor($clerk);

        foreach (['margin' => '6', 'secret' => 'x'] as $key => $value) {
            $this->patchJson("/api/v1/items/{$id}", ['name' => 'Renamed', 'custom' => [$key => $value]], $headers)->assertUnprocessable()
                ->assertJsonPath('code', 'field_readonly')
                ->assertJsonPath('errors', fn (array $errors) => $errors["custom.{$key}"][0] === __('rbac.errors.field_readonly', ['field' => "custom.{$key}"]));
        }

        // Nothing was saved; other fields still save, and the clerk sees the read-only field as such.
        $this->patchJson("/api/v1/items/{$id}", ['name' => 'Renamed'], $headers)->assertOk()->assertJsonPath('data.custom.margin', '5');
        $schema = collect($this->getJson('/api/v1/custom-fields/schema?entity=item', $headers)->json('data'))->keyBy('key');
        $this->assertTrue($schema['margin']['readonly']);
        $this->assertFalse($schema->has('secret'));
        // A field rule `custom` locks every custom field.
        [$locked, $lockedRole] = $this->clerk();
        $this->inTenant(fn () => FieldRule::create(['role_id' => $lockedRole->id, 'resource' => 'item', 'field' => 'custom', 'mode' => 'readonly']));
        $this->patchJson("/api/v1/items/{$id}", ['custom' => ['margin' => '1']], $this->headersFor($locked))->assertUnprocessable()->assertJsonPath('code', 'field_readonly');
    }

    public function test_lists_filter_and_sort_by_custom_fields(): void
    {
        $this->field(['key' => 'colour', 'type' => 'select', 'options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']]]);
        $this->field(['key' => 'weight', 'type' => 'number']);
        $this->field(['key' => 'fragile', 'type' => 'boolean']);
        $this->field(['key' => 'note', 'type' => 'text']);
        $red = $this->item(['colour' => 'red', 'weight' => '2.5', 'fragile' => true, 'note' => 'Keep cold'], 'A1')->json('data.id');
        $blue = $this->item(['colour' => 'blue', 'weight' => '10', 'fragile' => false, 'note' => 'Dry place'], 'A2')->json('data.id');
        $none = $this->item([], 'A3')->assertCreated()->json('data.id');
        $ids = fn (string $query) => array_column($this->getJson("/api/v1/items?{$query}", $this->headersFor())->assertOk()->json('data'), 'id');

        $this->assertSame([$red], $ids('custom[colour]=red'));
        $this->assertSame([$blue], $ids('custom[fragile]=false'));
        $this->assertSame([$red], $ids('custom[note]=cold'));
        $this->assertSame([$blue], $ids('custom[weight][min]=3'));
        $this->assertSame([$red], $ids('custom[weight][max]=3&custom[colour]=red'));
        $this->assertSame([$red, $blue, $none], $ids('sort=cf_weight'));
        $this->assertSame([$blue, $red, $none], $ids('sort=-cf_weight'));

        $this->getJson('/api/v1/items?custom[weight]=heavy', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('custom');
        $this->getJson('/api/v1/items?custom[ghost]=1', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('custom');

        $csv = $this->get('/api/v1/items?format=csv&columns[]=code&columns[]=cf_colour&columns[]=cf_fragile', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('A1,Red,Yes', $csv);
        $this->assertStringContainsString('Colour', $csv);
    }

    public function test_parties_carry_custom_fields_too(): void
    {
        $this->inTenant(fn () => CustomFieldDefinition::create(['entity' => 'party', 'key' => 'loyalty_no', 'label' => 'Loyalty number', 'type' => 'text', 'is_unique' => true]));

        $id = $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Asha', 'roles' => ['customer'], 'custom' => ['loyalty_no' => 'L-1']], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.custom.loyalty_no', 'L-1')->json('data.id');
        $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Baraka', 'roles' => ['customer'], 'custom' => ['loyalty_no' => 'L-1']], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('custom.loyalty_no');
        $this->assertSame([$id], array_column($this->getJson('/api/v1/parties?custom[loyalty_no]=L-1', $this->headersFor())->json('data'), 'id'));
        $this->inTenant(fn () => $this->assertSame(['loyalty_no' => 'L-1'], Party::findOrFail($id)->custom));
    }

    public function test_lookups_stay_inside_the_tenant_and_the_users_reach(): void
    {
        $this->field(['key' => 'supplier', 'type' => 'lookup', 'lookup_target' => 'party']);
        $this->field(['key' => 'buyer', 'type' => 'lookup', 'lookup_target' => 'user']);
        $party = $this->postJson('/api/v1/parties', ['kind' => 'organisation', 'name' => 'Juma Traders', 'roles' => ['supplier']], $this->headersFor())->json('data.id');
        $other = $this->otherTenant();
        $theirParty = $this->postJson('/api/v1/parties', ['kind' => 'organisation', 'name' => 'Their supplier', 'roles' => ['supplier']], $this->headersFor($other['user']))->json('data.id');

        $this->item(['supplier' => $theirParty])->assertUnprocessable()
            ->assertJsonPath('errors', fn (array $errors) => $errors['custom.supplier'][0] === __('core.custom_field.errors.lookup_unknown', ['field' => 'Supplier']));
        $this->item(['buyer' => $other['user']->id])->assertUnprocessable()->assertJsonValidationErrors('custom.buyer');
        $this->item(['supplier' => $party, 'buyer' => $this->owner->id])->assertCreated()
            ->assertJsonPath('data.custom.supplier', ['id' => $party, 'label' => 'Juma Traders'])
            ->assertJsonPath('data.custom.buyer.label', $this->owner->name);

        // Candidates: only what the user may see.
        $found = $this->getJson('/api/v1/custom-fields/lookup?target=party&search=supplier', $this->headersFor())->assertOk()->json('data');
        $this->assertSame([], $found);
        $this->assertSame([['id' => $party, 'label' => 'Juma Traders']], $this->getJson('/api/v1/custom-fields/lookup?target=party&search=juma', $this->headersFor())->json('data'));
        $this->assertSame([], $this->getJson("/api/v1/custom-fields/lookup?target=party&id={$theirParty}", $this->headersFor())->json('data'));

        // A user without party permissions can't pick a party.
        [$itemsOnly] = $this->inTenant(function () {
            $role = $this->role('Items only', ['core.item.view', 'core.item.create']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return [$user];
        });
        $this->item(['supplier' => $party], 'OTHER', $this->headersFor($itemsOnly))->assertUnprocessable()->assertJsonValidationErrors('custom.supplier');
        $this->assertSame([], $this->getJson('/api/v1/custom-fields/lookup?target=party', $this->headersFor($itemsOnly))->json('data'));
    }

    public function test_file_fields_take_an_uploaded_file(): void
    {
        $this->field(['key' => 'manual', 'type' => 'file']);
        [$clerk] = $this->clerk();

        $file = $this->post('/api/v1/custom-field-files', ['entity' => 'item', 'field' => 'manual', 'file' => UploadedFile::fake()->create('manual.pdf', 20, 'application/pdf')], [...$this->headersFor(), 'Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.name', 'manual.pdf')->json('data');

        // Someone else's pending upload can't be used.
        $this->item(['manual' => $file['id']], 'X1', $this->headersFor($clerk))->assertUnprocessable()->assertJsonValidationErrors('custom.manual');
        $id = $this->item(['manual' => $file['id']])->assertCreated()->assertJsonPath('data.custom.manual.name', 'manual.pdf')->json('data.id');
        $this->inTenant(fn () => $this->assertSame($id, CustomFieldFile::findOrFail($file['id'])->record_id));

        $url = $this->getJson("/api/v1/items/{$id}", $this->headersFor())->json('data.custom.manual.url');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // The URL is bound to the user it was signed for, who must still see the item.
        $this->get(str_replace('user='.$this->owner->id, 'user='.$clerk->id, $url))->assertForbidden();

        $this->post('/api/v1/custom-field-files', ['entity' => 'item', 'field' => 'manual', 'file' => UploadedFile::fake()->create('run.sh', 1, 'application/x-sh')], [...$this->headersFor(), 'Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->post('/api/v1/custom-field-files', ['entity' => 'item', 'field' => 'ghost', 'file' => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')], [...$this->headersFor(), 'Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('field');
    }

    public function test_a_user_who_cannot_view_the_entity_gets_no_schema(): void
    {
        $user = $this->inTenant(function (): User {
            $role = $this->role('Parties only', ['core.party.view']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });

        $this->getJson('/api/v1/custom-fields/schema?entity=item', $this->headersFor($user))->assertForbidden();
        $this->getJson('/api/v1/custom-fields/schema?entity=party', $this->headersFor($user))->assertOk();
        $this->getJson('/api/v1/custom-fields/schema?entity=planet', $this->headersFor($user))->assertUnprocessable();
    }
}
