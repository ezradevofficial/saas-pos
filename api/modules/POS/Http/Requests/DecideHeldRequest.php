<?php

namespace Modules\POS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST pos/{voids|refunds|cash-movements}/{id}/approve|reject: a decision
 * on a held record (H2). A rejection needs a reason. The controller checks
 * the permission at the record's location (404 out of sight, 403 without
 * the permission).
 */
class DecideHeldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reason' => [str_ends_with($this->path(), '/reject') ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }
}
