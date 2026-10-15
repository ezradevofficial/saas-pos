<?php

namespace Modules\CRM\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\CustomFields\OpportunityEntity;
use Modules\CRM\Http\Resources\OpportunityResource;

/** CRM-16, CRM-32: the opportunities list and its export (field rules `crm_opportunity`, custom fields). */
class OpportunityList extends CrmList
{
    public function name(): string
    {
        return 'crm-opportunities';
    }

    public function auditAction(): string
    {
        return 'crm.opportunity.export';
    }

    public function title(array $filters): string
    {
        return __('crm.lists.opportunities');
    }

    public function fieldRules(): ?string
    {
        return OpportunityEntity::KEY;
    }

    public function fieldSources(): array
    {
        return ['value' => ['value_minor'], 'weighted_minor' => ['value_minor'], 'base_value' => ['value_minor']];
    }

    public function customFieldEntity(): ?string
    {
        return OpportunityEntity::KEY;
    }

    public function resource(Model $model): JsonResource
    {
        return OpportunityResource::make($model);
    }

    public function sorts(): array
    {
        return [
            ...parent::sorts(),
            'name' => ListSort::column('name'),
            'expected_close_on' => ListSort::column('expected_close_on'),
            'value' => ListSort::column('value_minor', ['value']),
        ];
    }

    public function columns(): array
    {
        return [
            self::text('name'),
            self::named('party'),
            self::named('pipeline'),
            self::named('stage'),
            self::status(),
            self::money('value'),
            ListColumn::make('probability', 'crm.columns.probability', ['probability'], fn (array $row) => $row['probability'].'%'),
            self::date('expected_close_on'),
            self::named('owner'),
            self::named('branch'),
            self::named('reason'),
            self::date('closed_on'),
            self::at('created_at'),
        ];
    }

    public function exportRelations(): array
    {
        return OpportunityResource::RELATIONS;
    }
}
