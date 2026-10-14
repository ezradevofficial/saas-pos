<?php

namespace Modules\Procurement\Support;

use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\MasterData\Dimensions\Department;
use App\Core\MasterData\Dimensions\Project;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Currency\Models\TenantCurrency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * PRC-22: the core records a document names, checked against the
 * document's company (row-level security already hides other tenants'):
 * items shared or of the company and not archived (MD-02, TEN-08), a unit
 * the item has (its base unit or one of its units), tax codes, cost
 * centres, projects and departments of the company (MD-03, MD-05),
 * suppliers (MD-01) and active currencies (CUR-01).
 */
class LineResolver
{
    /**
     * A line's references and quantity, normalised.
     *
     * @param  array<string, mixed>  $line
     * @return array{item_id: ?string, description: string, uom_id: ?string, qty: string, tax_code_id: ?string, cost_centre_id: ?string, project_id: ?string, item: ?Item}
     */
    public function line(string $companyId, array $line, string $field): array
    {
        $item = null;

        if (! empty($line['item_id'])) {
            $item = Item::query()->whereKey($line['item_id'])->whereNull('archived_at')
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->first()
                ?? throw ProcurementError::make('item_unknown', field: "{$field}.item_id");
        }

        $uomId = $line['uom_id'] ?? null;

        if ($item !== null) {
            $uomId ??= $item->base_uom_id;

            if ($uomId !== $item->base_uom_id && ! ItemUom::query()->where('item_id', $item->id)->where('uom_id', $uomId)->exists()) {
                throw ProcurementError::make('uom_not_on_item', field: "{$field}.uom_id");
            }
        } elseif ($uomId !== null && ! Uom::query()->whereKey($uomId)->exists()) {
            throw ProcurementError::make('uom_unknown', field: "{$field}.uom_id");
        }

        $description = trim((string) ($line['description'] ?? ''));

        if ($description === '') {
            $description = $item?->name ?? throw ProcurementError::make('description_required', field: "{$field}.description");
        }

        return [
            'item_id' => $item?->id,
            'item' => $item,
            'description' => mb_substr($description, 0, 500),
            'uom_id' => $uomId,
            'qty' => self::qty((string) $line['qty'], "{$field}.qty"),
            'tax_code_id' => $this->taxCode($companyId, $line['tax_code_id'] ?? null, "{$field}.tax_code_id"),
            'cost_centre_id' => $this->dimension(CostCentre::class, $companyId, $line['cost_centre_id'] ?? null, "{$field}.cost_centre_id"),
            'project_id' => $this->dimension(Project::class, $companyId, $line['project_id'] ?? null, "{$field}.project_id"),
        ];
    }

    public function taxCode(string $companyId, ?string $id, string $field): ?string
    {
        if ($id === null) {
            return null;
        }

        return TaxCode::query()->where('company_id', $companyId)->whereKey($id)->whereNull('archived_at')->value('id')
            ?? throw ProcurementError::make('tax_code_unknown', field: $field);
    }

    public function department(string $companyId, ?string $id, string $field = 'department_id'): ?string
    {
        return $this->dimension(Department::class, $companyId, $id, $field);
    }

    /** @param class-string<CostCentre|Project|Department> $model */
    private function dimension(string $model, string $companyId, ?string $id, string $field): ?string
    {
        if ($id === null) {
            return null;
        }

        return $model::query()->where('company_id', $companyId)->whereKey($id)->whereNull('archived_at')->value('id')
            ?? throw ProcurementError::make('dimension_unknown', field: $field);
    }

    /** MD-01: a supplier party of the company or shared, not archived. */
    public function supplier(string $companyId, string $partyId, string $field = 'party_id'): Party
    {
        $party = Party::query()->whereKey($partyId)->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->first();

        if ($party === null || ! in_array('supplier', $party->roles ?? [], true)) {
            throw ProcurementError::make('party_not_supplier', field: $field);
        }

        return $party;
    }

    /** CUR-01: a currency the tenant has active. */
    public function currency(string $code, string $field = 'currency'): string
    {
        $code = strtoupper($code);

        if (! TenantCurrency::query()->where('code', $code)->where('active', true)->exists()) {
            throw ProcurementError::make('currency_inactive', ['currency' => $code], $field);
        }

        return $code;
    }

    /** A positive decimal quantity with at most 6 decimals, as a canonical string. */
    public static function qty(string $value, string $field): string
    {
        try {
            $qty = BigDecimal::of($value);
        } catch (\Throwable) {
            throw ProcurementError::make('qty_invalid', field: $field);
        }

        if (! $qty->isPositive() || $qty->getScale() > 6) {
            throw ProcurementError::make('qty_invalid', field: $field);
        }

        return (string) $qty->toScale(6, RoundingMode::Unnecessary);
    }
}
