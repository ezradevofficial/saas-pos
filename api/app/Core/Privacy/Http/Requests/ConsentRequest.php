<?php

namespace App\Core\Privacy\Http\Requests;

use App\Core\Privacy\Consents;
use Illuminate\Validation\Rule;

/** NFR-08: read one customer's consent (core.consent.view at tenant scope). */
class ConsentRequest extends PrivacyRequestAction
{
    protected string $permission = 'core.consent.view';

    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', Rule::in(Consents::SUBJECT_TYPES)],
            'subject_id' => ['required', 'uuid'],
        ];
    }
}
