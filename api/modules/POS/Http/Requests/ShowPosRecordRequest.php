<?php

namespace Modules\POS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET pos/sales/{pos_sale}, pos/shifts/{pos_shift}: one record, for a
 * holder of its view permission at its location (RBAC-04); anything else
 * is not found, so ids out of scope are never confirmed.
 */
class ShowPosRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('pos_sale') ?? $this->route('pos_shift');
        $permission = $this->route('pos_sale') !== null ? 'pos.sale.view' : 'pos.shift.view';

        abort_unless($record !== null && $this->user()->can($permission, $record), 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
