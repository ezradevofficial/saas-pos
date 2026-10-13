<?php

namespace Modules\Loyalty\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST loyalty/device/gift-cards/lookup `{code_hash}` (LOY-23): the live
 * balance of a card of the till's company, by the SHA-256 hash of its
 * normalised code (the till never sends the code itself).
 */
class DeviceGiftCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['code_hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}\z/']];
    }
}
