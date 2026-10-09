<?php

namespace Modules\POS\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\POS\Http\Resources\ShiftResource;
use Modules\POS\Models\Shift;

/** POS-04, POS-12, EXP-01: shifts, newest first. */
class ShiftList extends ListDefinition
{
    public const RELATIONS = ['company', 'branch', 'location', 'device', 'opener', 'closer', 'balances'];

    public function name(): string
    {
        return 'pos-shifts';
    }

    public function auditAction(): string
    {
        return 'pos.shift.export';
    }

    public function title(array $filters): string
    {
        return __('pos.shift.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return ShiftResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'opened_at' => ListSort::column('opened_at'),
            'closed_at' => ListSort::column('closed_at'),
            'status' => ListSort::column('status'),
        ];
    }

    public function defaultSort(): string
    {
        return '-opened_at';
    }

    public function columns(): array
    {
        $balances = fn (string $key) => fn (array $row, Model $model, ExportValues $values) => implode('; ', array_filter(array_map(
            fn (array $balance) => $values->money($balance[$key]), $row['balances'],
        )));

        return [
            ListColumn::make('opened_at', 'pos.shift.columns.opened_at', ['opened_at'],
                fn (array $row, Shift $shift, ExportValues $values) => $values->dateTime($row['opened_at'], $shift->company_id)),
            ListColumn::make('closed_at', 'pos.shift.columns.closed_at', ['closed_at'],
                fn (array $row, Shift $shift, ExportValues $values) => $values->dateTime($row['closed_at'], $shift->company_id)),
            ListColumn::make('status', 'pos.shift.columns.status', ['status'], fn (array $row) => __('pos.shift.statuses.'.$row['status'])),
            ListColumn::make('location', 'pos.shift.columns.location', ['location'], fn (array $row) => $row['location']['name'] ?? null),
            ListColumn::make('device', 'pos.shift.columns.device', ['device'], fn (array $row) => $row['device']['name'] ?? null),
            ListColumn::make('opened_by', 'pos.shift.columns.opened_by', ['opened_by'], fn (array $row) => $row['opened_by']['name'] ?? null),
            ListColumn::make('opening', 'pos.shift.columns.opening', ['balances'], $balances('opening')),
            ListColumn::make('expected', 'pos.shift.columns.expected', ['balances'], $balances('expected')),
            ListColumn::make('counted', 'pos.shift.columns.counted', ['balances'], $balances('counted')),
            ListColumn::make('variance', 'pos.shift.columns.variance', ['balances'], $balances('variance')),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $summary[__('core.list.status')] = __('pos.shift.statuses.'.$filters['status']);
        }

        return $summary;
    }
}
