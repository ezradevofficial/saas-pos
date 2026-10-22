<?php

namespace App\Core\Privacy\Http\Requests;

use App\Core\Privacy\Consents;
use Illuminate\Validation\Rule;

/** NFR-08: record a customer's grant or withdrawal of marketing consent (core.consent.manage). */
class StoreConsentRequest extends PrivacyRequestAction
{
    protected string $permission = 'core.consent.manage';

    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', Rule::in(Consents::SUBJECT_TYPES)],
            'subject_id' => ['required', 'uuid'],
            'purpose' => ['required', 'string', Rule::in((array) config('privacy.purposes'))],
            'channel' => ['required', 'string', Rule::in((array) config('privacy.channels'))],
            'granted' => ['required', 'boolean'],
            // The back office records what the customer said; other sources come from their modules.
            'evidence' => ['nullable', 'string', 'max:500'],
            // The version of the wording the customer agreed to, when there was one (a form, a notice).
            'wording_version' => ['nullable', 'string', 'max:40'],
        ];
    }
}
