<?php

namespace App\Core\Platform\SupportAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** RBAC-13c: the console's one-time entry code (`<tenant id>.<secret>`), exchanged for the session's token. Public. */
class ExchangeSupportSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:100']];
    }
}
