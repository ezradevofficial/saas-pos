<?php

namespace Modules\FixedAssets\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\CustomFields\AssetEntity;
use Modules\FixedAssets\Http\Resources\AssetResource;
use Modules\FixedAssets\Http\Resources\Values;
use Modules\FixedAssets\Models\Asset;

/**
 * FA-26, LAY-04, EXP-01: the asset register, by tag. No field rules
 * resource; the value columns are left out of an export for a reader who
 * holds `fixed_assets.value.view` nowhere (rows the reader may not value
 * show them blank, RBAC-05).
 */
class AssetList extends ListDefinition
{
    public const RELATIONS = ['company', 'branch', 'location', 'category', 'custodianUser', 'costCentre', 'department', 'supplier'];

    public function name(): string
    {
        return 'fixed-assets-register';
    }

    public function auditAction(): string
    {
        return 'fixed_assets.asset.export';
    }

    public function title(array $filters): string
    {
        return __('fixed_assets.lists.register');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function hiddenFields(Request $request): array
    {
        return [...parent::hiddenFields($request), ...(Values::anywhere($request) ? [] : Asset::VALUE_FIELDS)];
    }

    public function customFieldEntity(): ?string
    {
        return AssetEntity::KEY;
    }

    public function resource(Model $model): JsonResource
    {
        return AssetResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'number' => ListSort::column('number'),
            'name' => ListSort::column('name'),
            'status' => ListSort::column('status'),
            'acquired_on' => ListSort::column('acquired_on'),
            'in_service_on' => ListSort::column('in_service_on'),
            'created_at' => ListSort::column('created_at'),
        ];
    }

    public function defaultSort(): string
    {
        return 'number';
    }

    public function columns(): array
    {
        $money = fn (string $key) => ListColumn::make($key, 'fixed_assets.columns.'.$key, [$key], fn (array $row, $model, ExportValues $values) => $values->money($row[$key] ?? null));
        $named = fn (string $key) => ListColumn::make($key, 'fixed_assets.columns.'.$key, [$key], fn (array $row) => $row[$key]['name'] ?? null);

        return [
            ListColumn::text('number', 'fixed_assets.columns.number'),
            ListColumn::text('name', 'fixed_assets.columns.name'),
            ListColumn::make('status', 'fixed_assets.columns.status', ['status'], fn (array $row) => __('fixed_assets.statuses.'.$row['status'])),
            $named('category'),
            $named('location'),
            $named('custodian'),
            $named('cost_centre'),
            ListColumn::text('serial_number', 'fixed_assets.columns.serial_number'),
            ListColumn::make('acquired_on', 'fixed_assets.columns.acquired_on', ['acquired_on'], fn (array $row) => $row['acquired_on']),
            ListColumn::make('in_service_on', 'fixed_assets.columns.in_service_on', ['in_service_on'], fn (array $row) => $row['in_service_on']),
            $money('base_cost'),
            $money('accumulated'),
            $money('impairment'),
            $money('book_value'),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $summary[__('core.list.status')] = __('fixed_assets.statuses.'.$filters['status']);
        }

        return $summary;
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
