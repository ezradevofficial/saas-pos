<?php

namespace Modules\Pharmacy\Http\Requests;

use Modules\Pharmacy\Support\Access;

/**
 * POST pharmacy/lots/{lot}/quarantine and /release (PHA-11): not found
 * unless the user sees the lot's company (ShowLotRequest); then
 * `pharmacy.lot.quarantine` at the company or a place in it (403): a lot
 * belongs to its company, and holding stock back protects every place.
 * Body `{reason}`.
 */
class LotStatusRequest extends ShowLotRequest
{
    public function authorize(): bool
    {
        parent::authorize();

        return app(Access::class)->allowsCompany($this->user(), 'pharmacy.lot.quarantine', $this->route('pharmacy_lot')->company_id);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
