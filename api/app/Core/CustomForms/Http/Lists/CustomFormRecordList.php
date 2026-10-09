<?php

namespace App\Core\CustomForms\Http\Lists;

use App\Core\CustomFields\CustomFieldDefinitions;
use App\Core\CustomFields\CustomFieldLists;
use App\Core\CustomForms\CustomFormRecord;
use App\Core\CustomForms\CustomForms;
use App\Core\CustomForms\CustomFormType;
use App\Core\CustomForms\Http\Resources\CustomFormRecordResource;
use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The records of one custom form type (CF-04; EXP-01): newest first by
 * default, with a column per header custom field (CF-03) and per line
 * total (CF-05). Custom columns, sorts and filters follow field rules
 * and the fields' role visibility (RBAC-05).
 */
class CustomFormRecordList extends ListDefinition
{
    public function __construct(private readonly CustomFormType $type) {}

    public function name(): string
    {
        return 'custom-forms-'.$this->type->key;
    }

    public function auditAction(): string
    {
        return 'core.custom_form.export';
    }

    public function title(array $filters): string
    {
        return $this->type->name;
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function customFieldEntity(): string
    {
        return $this->type->entity();
    }

    public function resource(Model $model): JsonResource
    {
        return CustomFormRecordResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'number' => ListSort::column('number'),
            'status' => ListSort::column('status'),
            'created_at' => ListSort::column('created_at'),
            'amount' => ListSort::column('amount_minor'),
            ...app(CustomFieldLists::class)->sorts($this->type->entity()),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function columns(): array
    {
        $totals = [];

        if ($this->type->has_lines) {
            foreach (app(CustomFieldDefinitions::class)->active($this->type->lineEntity()) as $field) {
                if (in_array($field->type, CustomForms::TOTALLED, true)) {
                    $key = $field->key;
                    $totals[] = new ListColumn('total_'.$key, __('core.custom_form.fields.total', ['field' => $field->label]), ['totals'],
                        fn (array $row, Model $model, ExportValues $values) => is_array($row['totals']->{$key} ?? null) ? $values->money($row['totals']->{$key}) : $values->decimal($row['totals']->{$key} ?? null),
                        literal: true);
                }
            }
        }

        return [
            ListColumn::text('number', 'core.custom_form.columns.number'),
            ListColumn::make('status', 'core.custom_form.columns.status', ['status'], fn (array $row) => __('core.custom_form.statuses.'.$row['status'])),
            ListColumn::make('company', 'core.custom_form.columns.company', ['company'], fn (array $row) => $row['company']['name']),
            ListColumn::make('branch', 'core.custom_form.columns.branch', ['branch'], fn (array $row) => $row['branch']['name'] ?? null),
            ListColumn::make('location', 'core.custom_form.columns.location', ['location'], fn (array $row) => $row['location']['name'] ?? null),
            ListColumn::make('amount', 'core.custom_form.columns.amount', ['amount'], fn (array $row, Model $model, ExportValues $values) => $values->money($row['amount'])),
            ...$totals,
            ListColumn::make('created_by', 'core.custom_form.columns.created_by', ['created_by'], fn (array $row) => $row['created_by']['name']),
            ListColumn::make('created_at', 'core.custom_form.columns.created_at', ['created_at'],
                fn (array $row, CustomFormRecord $record, ExportValues $values) => $values->dateTime($row['created_at'], $record->company_id)),
            ...app(CustomFieldLists::class)->columns($this->type->entity()),
        ];
    }

    public function exportRelations(): array
    {
        return ['type', 'company', 'branch', 'location', 'creator'];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status'])) {
            $summary[__('core.list.status')] = __('core.custom_form.statuses.'.$filters['status']);
        }

        return [...$summary, ...app(CustomFieldLists::class)->summary($this->type->entity(), $filters['custom'] ?? null)];
    }
}
