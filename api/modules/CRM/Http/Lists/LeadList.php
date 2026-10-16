<?php

namespace Modules\CRM\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\CustomFields\LeadEntity;
use Modules\CRM\Http\Resources\LeadResource;

/** CRM-13, CRM-32: the leads list and its export (field rules `crm_lead`, custom fields). */
class LeadList extends CrmList
{
    public function name(): string
    {
        return 'crm-leads';
    }

    public function auditAction(): string
    {
        return 'crm.lead.export';
    }

    public function title(array $filters): string
    {
        return __('crm.lists.leads');
    }

    public function fieldRules(): ?string
    {
        return LeadEntity::KEY;
    }

    public function fieldSources(): array
    {
        return ['estimated_value' => ['estimated_value_minor']];
    }

    public function customFieldEntity(): ?string
    {
        return LeadEntity::KEY;
    }

    public function resource(Model $model): JsonResource
    {
        return LeadResource::make($model);
    }

    public function sorts(): array
    {
        return [...parent::sorts(), 'name' => ListSort::column('name')];
    }

    public function columns(): array
    {
        return [
            self::text('name'),
            self::text('organisation'),
            self::text('email'),
            self::text('phone'),
            self::status(),
            self::named('source'),
            ListColumn::make('channel', 'crm.columns.channel', ['channel'], fn (array $row) => __('crm.channels.'.$row['channel'])),
            self::named('owner'),
            self::named('branch'),
            self::money('estimated_value'),
            self::at('created_at'),
        ];
    }

    public function exportRelations(): array
    {
        return LeadResource::RELATIONS;
    }
}
