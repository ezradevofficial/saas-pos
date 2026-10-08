<?php

namespace App\Core\CountryPacks\Http\Requests;

use App\Core\MasterData\CompanyReach;
use Illuminate\Foundation\Http\FormRequest;

/** CP-01, CP-03: the published country packs are read by holders of `core.tax.view|edit` at any scope. */
class CountryPackViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CompanyReach::class)->anywhere($this->user(), ['core.tax.view', 'core.tax.edit']);
    }

    public function rules(): array
    {
        return [];
    }
}
