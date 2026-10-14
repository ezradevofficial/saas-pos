<?php

namespace Modules\Stores\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Stores\Http\Lists\ConsumptionList;

/**
 * GET stores/consumption (STR-12): `stores.report.view`; net consumption
 * at the reader's stores, `?group=department|cost_centre|item|period`
 * (default department), `?department=`, `?cost_centre=`, `?item=`,
 * `?location=`, `?from=`, `?to=` (issue and return days in the company's
 * time zone), `?search=` (item code or name).
 */
class ConsumptionRequest extends FormRequest
{
    use ListsStores;

    public const GROUPS = ['department', 'cost_centre', 'item', 'period'];

    public function list(): ListDefinition
    {
        return new ConsumptionList;
    }

    protected function viewPermission(): string
    {
        return 'stores.report.view';
    }

    protected function extraRules(): array
    {
        return ['group' => ['sometimes', 'string', Rule::in(self::GROUPS)]];
    }

    public function group(): string
    {
        return (string) $this->validated('group', 'department');
    }
}
