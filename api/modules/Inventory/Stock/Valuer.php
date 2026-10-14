<?php

namespace Modules\Inventory\Stock;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Modules\Inventory\Models\CostState;

/**
 * INV-12, INV-20: what stock is worth now at a company's cost (for a
 * document's value when it is asked, conditions and the approvals inbox;
 * the ledger values what really moves when it posts), and typed unit costs
 * turned into minor units of the base currency.
 */
class Valuer
{
    /** @var array<string, CostState|false> */
    private array $states = [];

    public function __construct(private readonly CostEngine $costs) {}

    public function currency(string $companyId): string
    {
        return (string) Company::query()->whereKey($companyId)->value('base_currency');
    }

    /** The company's unit cost of $itemId (per base unit), or null when none is known. */
    public function unit(string $companyId, string $itemId): ?BigDecimal
    {
        $state = $this->states[$companyId.'|'.$itemId] ??= CostState::query()->where('company_id', $companyId)->where('item_id', $itemId)->first() ?? false;

        return $state === false ? null : $this->costs->averageUnit($state);
    }

    /** $qty base units of $itemId at the company's unit cost, whole minor units (0 when unknown). */
    public function value(string $companyId, string $itemId, BigDecimal $qty): BigInteger
    {
        $unit = $this->unit($companyId, $itemId);

        return $unit === null ? BigInteger::zero() : Qty::round($unit->multipliedBy($qty));
    }

    /**
     * A typed unit cost (a decimal string in major units of the company's
     * base currency) times a typed quantity, in minor units; null when blank.
     */
    public function typedCost(?string $unitCost, BigDecimal $qty, string $currency): ?BigInteger
    {
        if ($unitCost === null || $unitCost === '') {
            return null;
        }

        try {
            $minorPerUnit = BigDecimal::of($unitCost)->withPointMovedRight(app(CurrencyDecimals::class)->for($currency));
        } catch (MathException) {
            return null;
        }

        return Qty::round($minorPerUnit->multipliedBy($qty->abs()));
    }

    public function forget(): void
    {
        $this->states = [];
    }
}
