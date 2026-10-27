<?php

namespace Modules\Pharmacy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Pharmacy\Support\Access;

/**
 * GET pharmacy/write-offs/{id}: `pharmacy.writeoff.view` where the write-off
 * is; anything else is not found. Also the base of its cancel request.
 */
class ShowWriteOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(app(Access::class)->can($this->user(), 'pharmacy.writeoff.view', $this->route('pharmacy_write_off')->location_id), 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
