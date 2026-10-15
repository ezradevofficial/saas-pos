<?php

namespace Modules\Procurement\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/** POST supplier-portal/sessions (PRC-07): public and rate-limited; `{token}`, the bidder's emailed link token, is the credential. */
class StartSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['token' => ['required', 'string', 'size:48']];
    }
}
