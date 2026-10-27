<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Modules\Pharmacy\Http\Lists\SlowMoverList;
use Modules\Pharmacy\Http\StockQuery;

/**
 * GET pharmacy/reports/slow-movers (PHA-24): `pharmacy.report.view`; lots with
 * stock that did not sell at their place for `?days=` days (default 90, a
 * report parameter, not a rule), with the time each last sold.
 */
class ListSlowMoversRequest extends ListLotsRequest
{
    public const DEFAULT_DAYS = 90;

    public function list(): ListDefinition
    {
        return new SlowMoverList;
    }

    protected function viewPermission(): string
    {
        return 'pharmacy.report.view';
    }

    protected function extraRules(): array
    {
        return [...parent::extraRules(), 'days' => ['sometimes', 'integer', 'between:1,3650']];
    }

    public function builder(): Builder
    {
        return StockQuery::slow(parent::builder(), (int) $this->validated('days', self::DEFAULT_DAYS));
    }
}
