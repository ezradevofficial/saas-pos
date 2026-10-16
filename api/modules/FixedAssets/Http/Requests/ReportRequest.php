<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Rule;

/** GET fixed-assets/reports/{depreciation|movements|net-book-value} (FA-26): `fixed_assets.report.view` at the company; `?company=&from=&to=&group=category|branch|cost_centre`. */
class ReportRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'fixed_assets.report.view';
    }

    public function rules(): array
    {
        return [
            'company' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group' => ['sometimes', 'string', Rule::in(['category', 'branch', 'cost_centre'])],
        ];
    }
}
