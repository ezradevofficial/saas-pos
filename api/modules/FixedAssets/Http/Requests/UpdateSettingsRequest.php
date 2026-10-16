<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FixedAssets\Depreciation\Calculator;

/** PUT fixed-assets/companies/{company}/settings (FA-01, FA-20): `fixed_assets.settings.edit` at the company; `{convention?: days|mid_month, tax_book?}`. */
class UpdateSettingsRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'fixed_assets.settings.edit';
    }

    public function rules(): array
    {
        return [
            'convention' => ['sometimes', 'string', Rule::in(Calculator::CONVENTIONS)],
            'tax_book' => ['sometimes', 'boolean'],
        ];
    }
}
