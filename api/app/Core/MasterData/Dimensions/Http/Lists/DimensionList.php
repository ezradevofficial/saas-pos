<?php

namespace App\Core\MasterData\Dimensions\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Identity\Http\Lists\VisibleUserNames;
use App\Core\Identity\Models\User;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\MasterData\Dimensions\Dimension;
use App\Core\MasterData\Dimensions\Dimensions;
use App\Core\MasterData\Dimensions\Http\Resources\DimensionResource;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A company's departments, cost centres or projects (MD-05): sort keys and
 * exportable columns (EXP-01). The owner is named only when the reader
 * may see that user (`core.user.view`, RBAC-04), as the web app shows it;
 * otherwise "Someone you can’t see". No field rules apply.
 */
class DimensionList extends ListDefinition
{
    /** @var array<string, string>|null parent labels by id */
    private ?array $parents = null;

    private ?VisibleUserNames $owners = null;

    public function __construct(
        private readonly string $type,
        private readonly Company $company,
        private readonly User $reader,
    ) {}

    /** @return class-string<Dimension> */
    private function model(): string
    {
        return Dimensions::model($this->type);
    }

    public function name(): string
    {
        return $this->model()::path();
    }

    public function auditAction(): string
    {
        return "core.{$this->type}.export";
    }

    public function title(array $filters): string
    {
        return __("core.dimension.list_titles.{$this->type}", ['company' => $this->company->name]);
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return DimensionResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'code' => ListSort::column('code'),
            'name' => ListSort::column('name'),
            // By the parent's code; top-level rows first either way.
            'parent' => ListSort::by(['parent_id'], function (Builder $query, string $direction) {
                $table = $query->getModel()->getTable();
                $query->orderByRaw("(select p.code from {$table} p where p.id = {$table}.parent_id) {$direction} nulls first");
            }),
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
            ListColumn::text('code', 'core.dimension.columns.code'),
            ListColumn::text('name', 'core.dimension.columns.name'),
            ListColumn::make('parent', 'core.dimension.columns.parent', ['parent_id'],
                fn (array $row) => $row['parent_id'] === null ? null : ($this->parents()[$row['parent_id']] ?? null)),
            ListColumn::make('owner', 'core.dimension.columns.owner', ['owner_user_id'],
                fn (array $row) => ($this->owners ??= new VisibleUserNames($this->reader))->label($row['owner_user_id'])),
            ListColumn::archiveStatus('core.dimension.columns.status'),
            ListColumn::make('created_at', 'core.dimension.columns.created_at', ['created_at'],
                fn (array $row, Dimension $dimension, ExportValues $values) => $values->dateTime($row['created_at'], $dimension->company_id)),
            ListColumn::make('updated_at', 'core.dimension.columns.updated_at', ['updated_at'],
                fn (array $row, Dimension $dimension, ExportValues $values) => $values->dateTime($row['updated_at'], $dimension->company_id)),
        ];
    }

    /** @return array<string, string> "CODE · Name" of the company's rows, by id */
    private function parents(): array
    {
        return $this->parents ??= $this->model()::query()->where('company_id', $this->company->id)->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Dimension $row) => [$row->id => "{$row->code} · {$row->name}"])->all();
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        return $this->searchAndStatus($filters);
    }
}
