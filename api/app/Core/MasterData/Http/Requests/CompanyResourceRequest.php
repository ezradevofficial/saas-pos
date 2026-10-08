<?php

namespace App\Core\MasterData\Http\Requests;

use App\Core\MasterData\CompanyReach;
use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request on a company-level master data resource (tax codes, price
 * lists, payment methods, dimensions): `core.{resource}.view` (or another
 * action that implies it) at the company or beneath it to read;
 * `core.{resource}.{action}` (`edit` by default) at the company to
 * change. A company the user cannot see is not found (RBAC-04); one they
 * see without the permission is forbidden.
 */
abstract class CompanyResourceRequest extends FormRequest
{
    /** The permission resource, e.g. `tax` or `price_list`. */
    protected string $resource;

    /** True when the request changes something (needs `{$action}` at the company). */
    protected bool $edits = false;

    /** The action a change needs at the company (`edit`; `create` or `archive` where the catalogue has them). */
    protected string $action = 'edit';

    /** @var list<string> actions that let the user read the resource */
    protected array $readActions = ['view', 'edit'];

    abstract protected function targetCompany(): Company;

    public function authorize(): bool
    {
        $company = $this->targetCompany();
        $reach = app(CompanyReach::class);
        $reaches = $reach->reaches($this->user(), $company, array_map(fn (string $action) => "core.{$this->resource}.{$action}", $this->readActions));

        abort_unless($reaches || $reach->reaches($this->user(), $company, ['core.company.view']), 404);

        return $this->edits ? $this->user()->can("core.{$this->resource}.{$this->action}", $company) : $reaches;
    }

    public function rules(): array
    {
        return [];
    }
}
