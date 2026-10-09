<?php

namespace Tests\Feature\Core\CustomFields;

use App\Core\Audit\AuditEntry;
use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CF-01, CF-02: custom field definitions. Seen with core.custom_field.view,
// changed with core.custom_field.manage at tenant scope; archived, never
// deleted; audited; another tenant's are not found. Settings must suit
// the type, the formula is parsed with the entity's other fields.
class CustomFieldApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    private function create(array $body, ?array $headers = null)
    {
        return $this->postJson('/api/v1/custom-fields', $body + ['entity' => 'item', 'type' => 'text'], $headers ?? $this->headersFor());
    }

    public function test_the_owner_adds_lists_changes_archives_and_restores_a_field(): void
    {
        $role = $this->inTenant(fn () => $this->roles->get('cashier')?->id ?? $this->roles->first()->id);

        $id = $this->create([
            'key' => 'colour', 'label' => 'Colour', 'type' => 'select', 'help' => 'As printed on the box',
            'options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']],
            'default' => 'red', 'required' => true, 'unique' => false, 'show_on_pos' => false, 'position' => 3,
            'visible_roles' => [$role],
        ])->assertCreated()
            ->assertJsonPath('data.key', 'colour')
            ->assertJsonPath('data.default', 'red')
            ->assertJsonPath('data.options.1.label', 'Blue')
            ->assertJsonPath('data.visible_roles', [$role])
            ->json('data.id');

        $this->create(['key' => 'weight', 'label' => 'Weight', 'type' => 'number', 'min' => '0', 'max' => '1000.5'])
            ->assertCreated()->assertJsonPath('data.min', '0')->assertJsonPath('data.max', '1000.5');
        $this->create(['entity' => 'party', 'key' => 'colour', 'label' => 'Favourite colour'])->assertCreated();

        $list = $this->getJson('/api/v1/custom-fields?entity=item', $this->headersFor())->assertOk();
        $this->assertSame(['weight', 'colour'], array_column($list->json('data'), 'key'));
        $this->assertSame(['colour'], array_column($this->getJson('/api/v1/custom-fields?entity=item&search=Col', $this->headersFor())->json('data'), 'key'));

        $this->patchJson("/api/v1/custom-fields/{$id}", ['label' => 'Shade', 'required' => false], $this->headersFor())
            ->assertOk()->assertJsonPath('data.label', 'Shade');
        // The entity, key and type stay as created.
        $this->patchJson("/api/v1/custom-fields/{$id}", ['key' => 'shade'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->patchJson("/api/v1/custom-fields/{$id}", ['type' => 'text'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->postJson("/api/v1/custom-fields/{$id}/archive", [], $this->headersFor())->assertOk()->assertJsonPath('data.archived_at', fn ($v) => $v !== null);
        $this->assertSame(['weight'], array_column($this->getJson('/api/v1/custom-fields?entity=item', $this->headersFor())->json('data'), 'key'));
        // A key is never reused, even once archived.
        $this->create(['key' => 'colour', 'label' => 'Colour again'])->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->postJson("/api/v1/custom-fields/{$id}/restore", [], $this->headersFor())->assertOk()->assertJsonPath('data.archived_at', null);

        $this->inTenant(function () use ($id) {
            $this->assertSame(3, CustomFieldDefinition::count());
            $actions = AuditEntry::query()->where('auditable_id', $id)->orderBy('seq')->pluck('action')->all();
            $this->assertSame(['core.custom_field.create', 'core.custom_field.update', 'core.custom_field.archive', 'core.custom_field.restore'], $actions);
        });

        $history = $this->getJson("/api/v1/history/custom_field/{$id}", $this->headersFor())->assertOk();
        $this->assertCount(4, $history->json('data'));

        $csv = $this->get('/api/v1/custom-fields?entity=item&format=csv', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('Shade', $csv);

        $meta = $this->getJson('/api/v1/custom-fields/meta', $this->headersFor())->assertOk();
        $this->assertSame(['item', 'party'], array_column($meta->json('data.entities'), 'key'));
        $this->assertContains('user', array_column($meta->json('data.lookup_targets'), 'key'));
    }

    public function test_permissions_are_checked(): void
    {
        $id = $this->create(['key' => 'colour', 'label' => 'Colour'])->assertCreated()->json('data.id');
        $viewer = $this->inTenant(function () {
            $role = $this->role('Viewer', ['core.custom_field.view']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });
        $nobody = $this->inTenant(function () {
            $role = $this->role('Nobody', ['core.company.view']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });
        // Managing at company scope is not enough: definitions are the tenant's.
        $companyManager = $this->inTenant(function () {
            $role = $this->role('Company manager', ['core.custom_field.manage']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::company($this->acme->id));

            return $user;
        });

        $this->getJson('/api/v1/custom-fields', $this->headersFor($viewer))->assertOk();
        $this->getJson("/api/v1/custom-fields/{$id}", $this->headersFor($viewer))->assertOk();
        $this->create(['key' => 'other', 'label' => 'Other'], $this->headersFor($viewer))->assertForbidden();
        $this->patchJson("/api/v1/custom-fields/{$id}", ['label' => 'X'], $this->headersFor($viewer))->assertForbidden();
        $this->postJson("/api/v1/custom-fields/{$id}/archive", [], $this->headersFor($viewer))->assertForbidden();
        $this->create(['key' => 'other', 'label' => 'Other'], $this->headersFor($companyManager))->assertForbidden();

        $this->getJson('/api/v1/custom-fields', $this->headersFor($nobody))->assertForbidden();
        $this->getJson('/api/v1/custom-fields/meta', $this->headersFor($nobody))->assertForbidden();
        $this->getJson("/api/v1/custom-fields/{$id}", $this->headersFor($nobody))->assertForbidden();
    }

    public function test_another_tenants_field_is_not_found(): void
    {
        $other = $this->otherTenant();
        $theirs = $this->postJson('/api/v1/custom-fields', ['entity' => 'item', 'type' => 'text', 'key' => 'secret', 'label' => 'Secret'], $this->headersFor($other['user']))
            ->assertCreated()->json('data.id');

        $this->getJson("/api/v1/custom-fields/{$theirs}", $this->headersFor())->assertNotFound();
        $this->patchJson("/api/v1/custom-fields/{$theirs}", ['label' => 'Mine'], $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/custom-fields/{$theirs}/archive", [], $this->headersFor())->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/custom-fields', $this->headersFor())->json('data'));
        // Their key is free here.
        $this->create(['key' => 'secret', 'label' => 'Secret'])->assertCreated();
        // A role id of the other tenant is unknown.
        $theirRole = $this->asTenant($other['user']->tenant_id, fn () => Role::query()->value('id'));
        $this->create(['key' => 'roles', 'label' => 'Roles', 'visible_roles' => [$theirRole]])->assertUnprocessable()->assertJsonValidationErrors('visible_roles.0');
    }

    public function test_settings_must_suit_the_type(): void
    {
        $this->create(['key' => 'Bad key', 'label' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->create(['key' => 'x', 'label' => 'X', 'type' => 'select'])->assertUnprocessable()->assertJsonValidationErrors('options');
        $this->create(['key' => 'x', 'label' => 'X', 'options' => [['value' => 'a', 'label' => 'A']]])->assertUnprocessable()->assertJsonValidationErrors('options');
        $this->create(['key' => 'x', 'label' => 'X', 'type' => 'boolean', 'unique' => true])->assertUnprocessable()->assertJsonValidationErrors('unique');
        $this->create(['key' => 'x', 'label' => 'X', 'type' => 'date', 'min' => '1'])->assertUnprocessable()->assertJsonValidationErrors('min');
        $this->create(['key' => 'x', 'label' => 'X', 'type' => 'number', 'min' => '5', 'max' => '1'])->assertUnprocessable()->assertJsonValidationErrors('max');
        $this->create(['key' => 'x', 'label' => 'X', 'min' => '1.5'])->assertUnprocessable()->assertJsonValidationErrors('min');
        $this->create(['key' => 'x', 'label' => 'X', 'pattern' => '(unclosed'])->assertUnprocessable()->assertJsonValidationErrors('pattern');
        $this->create(['key' => 'x', 'label' => 'X', 'type' => 'lookup'])->assertUnprocessable()->assertJsonValidationErrors('lookup_target');
        $this->create(['key' => 'x', 'label' => 'X', 'type' => 'lookup', 'lookup_target' => 'planet'])->assertUnprocessable()->assertJsonValidationErrors('lookup_target');
        $this->create(['key' => 'x', 'label' => 'X', 'type' => 'number', 'default' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('default');
        $this->create(['key' => 'x', 'label' => 'X', 'type' => 'formula'])->assertUnprocessable()->assertJsonValidationErrors('formula');
        $this->create(['key' => 'x', 'label' => 'X', 'type' => 'formula', 'formula' => 'system("ls")'])->assertUnprocessable()
            ->assertJsonPath('errors.formula.0', __('core.custom_field.formula_errors.unknown_function', ['function' => 'system']));
        // A formula reads only the entity's other active, non-formula fields.
        $this->create(['key' => 'x', 'label' => 'X', 'type' => 'formula', 'formula' => 'cost * 2'])->assertUnprocessable()
            ->assertJsonPath('errors.formula.0', __('core.custom_field.formula_errors.unknown_field', ['field' => 'cost']));
        $this->create(['key' => 'cost', 'label' => 'Cost', 'type' => 'number'])->assertCreated();
        $this->create(['key' => 'double', 'label' => 'Double', 'type' => 'formula', 'formula' => 'cost * 2'])->assertCreated()->assertJsonPath('data.formula_type', 'number');
        $this->create(['key' => 'quad', 'label' => 'Quad', 'type' => 'formula', 'formula' => 'double * 2'])->assertUnprocessable()->assertJsonValidationErrors('formula');
    }

    public function test_whole_number_bounds_keep_their_digits(): void
    {
        // Review: "100" was read as "1" before saving, so a default of 50 failed against max 100.
        $this->create(['key' => 'weight', 'label' => 'Weight', 'type' => 'number', 'min' => '0', 'max' => '100', 'default' => '50'])
            ->assertCreated()->assertJsonPath('data.max', '100')->assertJsonPath('data.default', '50');
        $this->create(['key' => 'code', 'label' => 'Code', 'type' => 'text', 'max' => '100', 'default' => 'hello'])->assertCreated();
        $this->create(['key' => 'floor', 'label' => 'Floor', 'type' => 'number', 'min' => '100', 'default' => '5'])
            ->assertUnprocessable()->assertJsonValidationErrors('default');
    }

    public function test_the_formula_result_type_is_fixed_once_created(): void
    {
        $id = $this->create(['key' => 'band', 'label' => 'Band', 'type' => 'formula', 'formula' => '"Low"', 'formula_type' => 'text'])
            ->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/custom-fields/{$id}", ['formula_type' => 'number'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('formula_type');
    }

    public function test_a_field_on_the_till_is_visible_to_everyone(): void
    {
        $role = $this->inTenant(fn () => $this->roles->first()->id);

        // RBAC-05: tills keep what they receive, so a role-limited field never goes there.
        $this->create(['key' => 'cost', 'label' => 'Cost', 'show_on_pos' => true, 'visible_roles' => [$role]])
            ->assertUnprocessable()->assertJsonValidationErrors('show_on_pos');
        $id = $this->create(['key' => 'size', 'label' => 'Size', 'show_on_pos' => true])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/custom-fields/{$id}", ['visible_roles' => [$role]], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('show_on_pos');
    }
}
