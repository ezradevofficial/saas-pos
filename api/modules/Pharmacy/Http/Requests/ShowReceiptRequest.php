<?php

namespace Modules\Pharmacy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Pharmacy\Support\Access;

/** GET pharmacy/receipts/{receipt}: `pharmacy.lot.view` where it was received; anything else is not found. */
class ShowReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(app(Access::class)->can($this->user(), 'pharmacy.lot.view', $this->route('pharmacy_receipt')->location_id), 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
