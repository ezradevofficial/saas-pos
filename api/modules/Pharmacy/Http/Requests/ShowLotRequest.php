<?php

namespace Modules\Pharmacy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Pharmacy\Support\Access;

/**
 * GET pharmacy/lots/{lot}: a lot for a holder of `pharmacy.lot.view` at its
 * company (or a place in it). Anything else is not found, so ids out of
 * scope are never confirmed.
 */
class ShowLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(app(Access::class)->allowsCompany($this->user(), $this->permission(), $this->route('pharmacy_lot')->company_id), 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    protected function permission(): string
    {
        return 'pharmacy.lot.view';
    }
}
