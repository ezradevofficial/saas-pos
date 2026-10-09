<?php

namespace Tests\Feature\Core\Layouts;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * LAY-03, LAY-07, RBAC-05: the form_layout kind validates sections, tabs
 * and fields (a required field without a default is never hidden),
 * resolves per role, hides per role for its reader, and places fields the
 * layout does not mention.
 */
class FormLayoutTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganisation();
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
    }

    /** @var array<string, ?int> the draft revision a refused publish left, per key and scope */
    private array $revisions = [];

    private function save(array $payload, string $key = 'item', string $scopeType = 'tenant', ?string $scopeId = null)
    {
        return $this->postJson('/api/v1/config/form_layout', [
            'key' => $key, 'scope_type' => $scopeType, 'scope_id' => $scopeId, 'payload' => $payload, 'revision' => $this->revisions["{$key}|{$scopeType}|{$scopeId}"] ?? null,
        ], $this->headersFor());
    }

    private function publish(array $payload, string $key = 'item', string $scopeType = 'tenant', ?string $scopeId = null)
    {
        $saved = $this->save($payload, $key, $scopeType, $scopeId);
        $this->assertContains($saved->status(), [200, 201], (string) $saved->getContent());

        $published = $this->postJson("/api/v1/config/form_layout/{$saved->json('data.id')}/publish", ['revision' => $saved->json('data.draft.revision')], $this->headersFor());
        $this->revisions["{$key}|{$scopeType}|{$scopeId}"] = $published->isOk() ? null : $saved->json('data.draft.revision');

        return $published;
    }

    private function twoSections(array $extra = []): array
    {
        return ['tabs' => [], 'sections' => [
            ['id' => 'basics', 'title' => 'Basics', 'columns' => 2, 'fields' => [['id' => 'code'], ['id' => 'name'], ['id' => 'type'], ...$extra]],
            ['id' => 'stock', 'title' => 'Stock', 'columns' => 1, 'fields' => [['id' => 'units'], ['id' => 'barcodes']]],
        ]];
    }

    private function resolved(string $key, ?User $as = null): array
    {
        return $this->getJson("/api/v1/config/form_layout/resolved?key={$key}", $this->headersFor($as))->assertOk()->json('data');
    }

    public function test_the_designer_lists_forms_and_their_fields_for_layout_permission_holders(): void
    {
        $keys = array_column($this->getJson('/api/v1/form-layouts', $this->headersFor())->assertOk()->json('data'), 'key');
        $this->assertContains('item', $keys);
        $this->assertContains('party', $keys);

        $item = $this->getJson('/api/v1/form-layouts?form=item', $this->headersFor())->assertOk()->json('data');
        $code = collect($item['fields'])->firstWhere('id', 'code');
        $this->assertSame(['Code', true, false], [$code['default_label'], $code['required'], $code['has_default']]);
        $this->assertSame(['details', 'units', 'barcodes', 'custom'], array_column($item['defaults']['sections'], 'id'));

        $this->getJson('/api/v1/form-layouts', $this->headersFor($this->cashier))->assertForbidden();
        $this->getJson('/api/v1/form-layouts?form=nothing', $this->headersFor())->assertUnprocessable();
    }

    public function test_a_layout_is_validated_and_a_required_field_without_a_default_cannot_be_hidden(): void
    {
        $refused = $this->publish(['tabs' => [['id' => 'main', 'title' => 'Main']], 'sections' => [
            ['id' => 'basics', 'tab' => 'other', 'columns' => 2, 'fields' => [['id' => 'code', 'hidden' => true], ['id' => 'nothing_here']]],
            ['id' => 'more', 'columns' => 2, 'fields' => [['id' => 'name', 'hidden_roles' => ['01900000-0000-7000-8000-000000000000']], ['id' => 'code']]],
        ]]);
        $refused->assertUnprocessable()->assertJsonPath('code', 'config_invalid');
        $this->assertSame(
            ['unknown_tab', 'required_hidden', 'unknown_field', 'tab_required', 'required_hidden', 'duplicate', 'unknown_role'],
            array_column($refused->json('problems'), 'code'),
        );

        // A required field with a default (the type) may be hidden; so may an optional one.
        $this->publish($this->twoSections([['id' => 'category_id', 'hidden' => true]]))->assertOk();
        $type = $this->twoSections();
        $type['sections'][0]['fields'][2]['hidden'] = true;
        $this->publish($type)->assertOk()->assertJsonPath('data.published.version', 2);
    }

    public function test_a_layout_resolves_per_role_hides_per_role_and_places_new_fields(): void
    {
        $cashierRole = $this->roles->get('cashier')->id;
        $this->publish($this->twoSections([['id' => 'category_id', 'hidden_roles' => [$cashierRole], 'label' => 'Group', 'help' => 'Where it sells']]))->assertOk();

        // LAY-07: a field the layout never named (a new custom field) appears in its group's section, else the last one.
        $this->postJson('/api/v1/custom-fields', ['entity' => 'item', 'key' => 'shelf', 'label' => 'Shelf', 'type' => 'text'], $this->headersFor())->assertCreated();

        $owner = $this->resolved('item');
        $this->assertSame(['basics', 'stock'], array_column($owner['payload']['sections'], 'id'));
        $basics = collect($owner['payload']['sections'][0]['fields'])->keyBy('id');
        $this->assertSame(['Group', 'Where it sells', 'Category'], [$basics['category_id']['label'], $basics['category_id']['help'], $basics['category_id']['default_label']]);
        $this->assertArrayNotHasKey('hidden', $basics['category_id']);
        $this->assertArrayNotHasKey('hidden_roles', $basics['category_id']);
        $stock = array_column($owner['payload']['sections'][1]['fields'], 'id');
        // company_id and tax_category_id (in the catalogue's details group, which the layout dropped) and the new field go last.
        $this->assertSame(['units', 'barcodes', 'company_id', 'tax_category_id', 'custom.shelf'], $stock);

        // The cashier holds only the listed role: the field is hidden for them.
        $cashier = collect($this->resolved('item', $this->cashier)['payload']['sections'][0]['fields'])->keyBy('id');
        $this->assertTrue($cashier['category_id']['hidden']);

        // A role layout wins for that role.
        $this->publish(['tabs' => [], 'sections' => [['id' => 'till', 'title' => 'Till', 'columns' => 1, 'fields' => [['id' => 'name'], ['id' => 'code']]]]], 'item', 'role', $cashierRole)->assertOk();
        $tillLayout = $this->resolved('item', $this->cashier);
        $this->assertSame('role', $tillLayout['source']['scope']['type']);
        $this->assertSame(['name', 'code'], array_slice(array_column($tillLayout['payload']['sections'][0]['fields'], 'id'), 0, 2));
        $this->assertSame('tenant', $this->resolved('item')['source']['scope']['type']);
    }

    public function test_unknown_form_keys_are_refused(): void
    {
        $this->save($this->twoSections(), 'invoice')->assertUnprocessable();
    }
}
