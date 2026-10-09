<?php

namespace App\Core\CustomFields\Http\Lists;

use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\CustomFields\CustomFieldEntities;
use App\Core\CustomFields\Http\Resources\CustomFieldResource;
use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The custom field definitions (CF-01): sort keys and exportable columns
 * (EXP-01). No field rules apply to definitions.
 */
class CustomFieldList extends ListDefinition
{
    public function name(): string
    {
        return 'custom-fields';
    }

    public function auditAction(): string
    {
        return 'core.custom_field.export';
    }

    public function title(array $filters): string
    {
        return __('core.custom_field.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return CustomFieldResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'position' => ListSort::by(['position'], fn ($query, string $direction) => $query->orderBy('entity')->orderBy('position', $direction)->orderBy('key', $direction)),
            'label' => ListSort::column('label'),
            'key' => ListSort::column('key'),
            'type' => ListSort::column('type'),
            'created_at' => ListSort::column('created_at'),
            'updated_at' => ListSort::column('updated_at'),
        ];
    }

    public function defaultSort(): string
    {
        return 'position';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('entity', 'core.custom_field.columns.entity', ['entity'], function (array $row) {
                $entity = app(CustomFieldEntities::class)->find($row['entity']);

                return $entity === null ? $row['entity'] : __($entity->label());
            }),
            ListColumn::text('key', 'core.custom_field.columns.key'),
            ListColumn::text('label', 'core.custom_field.columns.label'),
            ListColumn::make('type', 'core.custom_field.columns.type', ['type'],
                fn (array $row, CustomFieldDefinition $field, ExportValues $values) => $values->enum('core.custom_field.types', $row['type'])),
            ListColumn::make('required', 'core.custom_field.columns.required', ['required'],
                fn (array $row) => __('core.custom_field.yes_no.'.($row['required'] ? 'yes' : 'no'))),
            ListColumn::make('show_on_pos', 'core.custom_field.columns.show_on_pos', ['show_on_pos'],
                fn (array $row) => __('core.custom_field.yes_no.'.($row['show_on_pos'] ? 'yes' : 'no'))),
            ListColumn::archiveStatus('core.custom_field.columns.status'),
            ListColumn::make('created_at', 'core.custom_field.columns.created_at', ['created_at'],
                fn (array $row, CustomFieldDefinition $field, ExportValues $values) => $values->dateTime($row['created_at'])),
            ListColumn::make('updated_at', 'core.custom_field.columns.updated_at', ['updated_at'],
                fn (array $row, CustomFieldDefinition $field, ExportValues $values) => $values->dateTime($row['updated_at'])),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = [];

        if (isset($filters['entity'])) {
            $entity = app(CustomFieldEntities::class)->find($filters['entity']);
            $summary[__('core.custom_field.columns.entity')] = $entity === null ? $filters['entity'] : __($entity->label());
        }

        if (isset($filters['type'])) {
            $summary[__('core.custom_field.columns.type')] = $values->enum('core.custom_field.types', $filters['type']);
        }

        return [...$summary, ...$this->searchAndStatus($filters)];
    }
}
