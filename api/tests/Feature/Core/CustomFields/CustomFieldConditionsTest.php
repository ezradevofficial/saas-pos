<?php

namespace Tests\Feature\Core\CustomFields;

use App\Core\Currency\TenantCurrencies;
use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\CustomFields\Entities\PartyEntity;
use App\Core\MasterData\Parties\Party;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CF-03: workflow and automation conditions read custom fields of the
// document's entity. A document type naming its custom field entity gets
// `cf_<key>` fields (typed for the evaluator, labelled as typed) and values.
class CustomFieldConditionsTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->type = new class extends DocumentType
        {
            public function key(): string
            {
                return 'core.party_review';
            }

            public function label(): string
            {
                return 'workflow.list_title';
            }

            public function customFieldEntity(): string
            {
                return PartyEntity::KEY;
            }

            public function fields(): array
            {
                return [FieldDefinition::string('name', 'workflow.attributes.reason'), ...$this->customFields()];
            }

            public function fieldValues(string $documentId): array
            {
                $party = Party::query()->find($documentId);

                return $party === null ? [] : ['name' => $party->name, ...$this->customValues($party->custom)];
            }

            public function scope(string $documentId): ?DocumentScope
            {
                return null;
            }

            public function viewPermission(): string
            {
                return 'core.party.view';
            }

            public function actPermission(): string
            {
                return 'core.party.edit';
            }
        };
        app(DocumentTypeRegistry::class)->register($this->type);

        $this->inTenant(function () {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            CustomFieldDefinition::create(['entity' => 'party', 'key' => 'tier', 'label' => 'Loyalty tier', 'type' => 'select', 'options' => [['value' => 'gold', 'label' => 'Gold'], ['value' => 'silver', 'label' => 'Silver']]]);
            CustomFieldDefinition::create(['entity' => 'party', 'key' => 'spend', 'label' => 'Yearly spend', 'type' => 'money']);
            CustomFieldDefinition::create(['entity' => 'party', 'key' => 'contract', 'label' => 'Contract', 'type' => 'file']);
        });
    }

    public function test_conditions_use_custom_fields_of_the_documents_entity(): void
    {
        $party = $this->postJson('/api/v1/parties', [
            'kind' => 'organisation', 'name' => 'Juma Traders', 'roles' => ['customer'],
            'custom' => ['tier' => 'gold', 'spend' => ['amount_minor' => '50000000', 'currency' => 'KES']],
        ], $this->headersFor())->assertCreated()->json('data.id');

        // The builders' pickers list them, labelled as typed, files left out.
        $types = collect($this->getJson('/api/v1/workflow/document-types', $this->headersFor())->assertOk()->json('data'))->keyBy('key');
        $fields = collect($types['core.party_review']['fields'])->keyBy('name');
        $this->assertSame(['name', 'cf_spend', 'cf_tier'], $fields->keys()->all());
        $this->assertSame(['enum', 'Loyalty tier', ['gold', 'silver']], [$fields['cf_tier']['type'], $fields['cf_tier']['label'], $fields['cf_tier']['values']]);

        $this->inTenant(function () use ($party) {
            $evaluator = app(ConditionEvaluator::class);
            $values = $this->type->fieldValues($party);
            $byName = $this->type->fieldsByName();
            $gold = ['all' => [
                ['field' => 'cf_tier', 'op' => 'eq', 'value' => 'gold'],
                ['field' => 'cf_spend', 'op' => 'gte', 'value' => ['amount_minor' => '10000000', 'currency' => 'KES']],
            ]];

            $this->assertSame([], $evaluator->validate($gold, $byName));
            $this->assertTrue($evaluator->evaluate($gold, $values, $byName)->passed);
            $this->assertFalse($evaluator->evaluate(['field' => 'cf_tier', 'op' => 'eq', 'value' => 'silver'], $values, $byName)->passed);
            $this->assertNotSame([], $evaluator->validate(['field' => 'cf_tier', 'op' => 'eq', 'value' => 'bronze'], $byName));
            $this->assertNotSame([], $evaluator->validate(['field' => 'cf_contract', 'op' => 'empty'], $byName));
        });
    }
}
