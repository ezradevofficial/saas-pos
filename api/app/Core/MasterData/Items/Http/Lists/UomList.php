<?php

namespace App\Core\MasterData\Items\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\MasterData\Items\Http\Resources\UomResource;
use App\Core\MasterData\Items\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The tenant's units of measure (MD-02): sort keys and exportable columns
 * (EXP-01). No field rules apply to units.
 */
class UomList extends ListDefinition
{
    public function name(): string
    {
        return 'units';
    }

    public function auditAction(): string
    {
        return 'core.uom.export';
    }

    public function title(array $filters): string
    {
        return __('core.uom.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return UomResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'code' => ListSort::column('code'),
            'name' => ListSort::column('name'),
            'kind' => ListSort::column('kind'),
            'created_at' => ListSort::column('created_at'),
            'updated_at' => ListSort::column('updated_at'),
        ];
    }

    public function defaultSort(): string
    {
        return 'code';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('code', 'core.uom.columns.code'),
            ListColumn::text('name', 'core.uom.columns.name'),
            ListColumn::make('kind', 'core.uom.columns.kind', ['kind'],
                fn (array $row, Uom $uom, ExportValues $values) => $values->enum('core.uom.kinds', $row['kind'])),
            ListColumn::archiveStatus('core.uom.columns.status'),
            ListColumn::make('created_at', 'core.uom.columns.created_at', ['created_at'],
                fn (array $row, Uom $uom, ExportValues $values) => $values->dateTime($row['created_at'])),
            ListColumn::make('updated_at', 'core.uom.columns.updated_at', ['updated_at'],
                fn (array $row, Uom $uom, ExportValues $values) => $values->dateTime($row['updated_at'])),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return $this->searchAndStatus($filters);
    }
}
