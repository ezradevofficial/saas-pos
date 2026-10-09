<?php

namespace Modules\POS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST pos/sales/{pos_sale}/review: acknowledge a flagged sale (M3); the controller checks `pos.sale.review` at its location. */
class ReviewSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:500']];
    }
}
