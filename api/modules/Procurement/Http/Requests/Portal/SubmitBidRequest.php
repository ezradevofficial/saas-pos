<?php

namespace Modules\Procurement\Http\Requests\Portal;

use Modules\Procurement\Http\Requests\ProcurementRules;

/**
 * PUT supplier-portal/bid (PRC-09): `{currency, lines: [{tender_line_id,
 * unit_price_minor}], technical_response?, file_ids?}`. Lines and files are
 * checked against the session's own tender and bidder (PortalBids).
 */
class SubmitBidRequest extends PortalRequest
{
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'size:3'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.tender_line_id' => ['required', 'uuid'],
            'lines.*.unit_price_minor' => ['required', 'string', ProcurementRules::MINOR],
            'technical_response' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'file_ids' => ['sometimes', 'array', 'max:10'],
            'file_ids.*' => ['uuid', 'distinct'],
        ];
    }
}
