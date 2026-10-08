<?php

namespace App\Core\MasterData\Http\Requests;

use App\Core\MasterData\CompanyReach;
use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request on a company-level master data resource (tax codes, price
 * lists): `core.{resource}.view` (or `edit`, which implies it) at the
 * company or beneath it to read; `core.{resource}.edit` at the company to
 * change. A company the user cannot see is not found (RBAC-04); one they
 * see without the permission is forbidden.
 */
abstract class CompanyResourceRequest extends FormRequest
{
    /** The permission resource, e.g. `tax` or `price_list`. */
    protected string $resource;

    /** True when the request changes something (needs `edit` at the company). */
    protected bool $edits = false;

    abstract protected function targetCompany(): Company;

    public function authorize(): bool
    {
        $company = $this->targetCompany();
        $reach = app(CompanyReach::class);
        $reaches = $reach->reaches($this->user(), $company, ["core.{$this->resource}.view", "core.{$this->resource}.edit"]);

        abort_unless($reaches || $reach->reaches($this->user(), $company, ['core.company.view']), 404);

        return $this->edits ? $this->user()->can("core.{$this->resource}.edit", $company) : $reaches;
    }

    public function rules(): array
    {
        return [];
    }
}
