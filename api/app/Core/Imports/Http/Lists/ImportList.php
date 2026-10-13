<?php

namespace App\Core\Imports\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Imports\Http\Resources\ImportResource;
use App\Core\Imports\Import;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/** IMP-02: past imports, newest first, with search, filters and export (EXP-01). */
class ImportList extends ListDefinition
{
    public function name(): string
    {
        return 'imports';
    }

    public function auditAction(): string
    {
        return 'core.import.export';
    }

    public function title(array $filters): string
    {
        return __('imports.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return ImportResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'created_at' => ListSort::column('created_at'),
            'file_name' => ListSort::column('file_name'),
            'importer' => ListSort::column('importer'),
            'status' => ListSort::column('status'),
            'total_rows' => ListSort::column('total_rows'),
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function exportRelations(): array
    {
        return ['user:id,name'];
    }

    public function columns(): array
    {
        $count = fn (string $key) => ListColumn::make($key, "imports.list_columns.{$key}", [$key],
            fn (array $row, Import $import, ExportValues $values) => $values->integer($row[$key]));

        return [
            ListColumn::make('created_at', 'imports.list_columns.created_at', ['created_at'],
                fn (array $row, Import $import, ExportValues $values) => $values->dateTime($row['created_at'])),
            ListColumn::make('importer', 'imports.list_columns.importer', ['importer_label'], fn (array $row) => $row['importer_label']),
            ListColumn::text('file_name', 'imports.list_columns.file_name'),
            ListColumn::make('mode', 'imports.list_columns.mode', ['mode'],
                fn (array $row, Import $import, ExportValues $values) => $values->enum('imports.modes', $row['mode'])),
            ListColumn::make('status', 'imports.list_columns.status', ['status'],
                fn (array $row, Import $import, ExportValues $values) => $values->enum('imports.statuses', $row['status'])),
            $count('total_rows'),
            $count('created_rows'),
            $count('updated_rows'),
            $count('failed_rows'),
            ListColumn::make('user', 'imports.list_columns.user', ['user'], fn (array $row) => $row['user']['name'] ?? ''),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (($filters['importer'] ?? null) !== null) {
            $summary[__('imports.list_columns.importer')] = __("imports.importers.{$filters['importer']}");
        }

        if (($filters['status'] ?? null) !== null) {
            $summary[__('imports.list_columns.status')] = __("imports.statuses.{$filters['status']}");
        }

        return $summary;
    }
}
