<?php

namespace Modules\Manufacturing\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Manufacturing\Http\Lists\BackflushList;
use Modules\Manufacturing\Http\Lists\ConsumptionList;
use Modules\Manufacturing\Http\Lists\RecipeCostingList;
use Modules\Manufacturing\Http\Lists\YieldList;
use Modules\Manufacturing\Models\Backflush;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\Order;

/**
 * GET manufacturing/consumption (MFG-18), manufacturing/yield (MFG-19),
 * manufacturing/backflushes (MFG-07, MFG-08) and
 * manufacturing/recipe-costing (MFG-06): `manufacturing.report.view`,
 * with the list contract (ListsManufacturing). Consumption and yield cover
 * released, in-progress and closed orders (`?status=`), dated by release
 * (`?from=`, `?to=`), `?order=`; backflushes are dated by the sale and
 * `?kind=consume|return`; recipe costing is by company, `?kind=`.
 */
class ReportListRequest extends FormRequest
{
    use ListsManufacturing;

    public const LISTS = [
        'manufacturing-consumption' => ConsumptionList::class,
        'manufacturing-yield' => YieldList::class,
        'manufacturing-backflushes' => BackflushList::class,
        'manufacturing-recipe-costing' => RecipeCostingList::class,
    ];

    public function list(): ListDefinition
    {
        $class = self::LISTS[$this->route()?->defaults['list'] ?? 'manufacturing-consumption'];

        return new $class;
    }

    public function listName(): string
    {
        return (string) ($this->route()?->defaults['list'] ?? 'manufacturing-consumption');
    }

    protected function viewPermission(): string
    {
        return 'manufacturing.report.view';
    }

    protected function byCompany(): bool
    {
        return $this->listName() === 'manufacturing-recipe-costing';
    }

    protected function statuses(): array
    {
        return in_array($this->listName(), ['manufacturing-consumption', 'manufacturing-yield'], true) ? Order::RUNNING_OR_CLOSED : [];
    }

    protected function extraRules(): array
    {
        return match ($this->listName()) {
            'manufacturing-backflushes' => ['kind' => ['sometimes', 'string', Rule::in([Backflush::CONSUME, Backflush::RETURN])]],
            'manufacturing-recipe-costing' => ['kind' => ['sometimes', 'string', Rule::in(Bom::KINDS)]],
            default => ['order' => ['sometimes', 'uuid', Rule::exists('mfg_orders', 'id')]],
        };
    }
}
