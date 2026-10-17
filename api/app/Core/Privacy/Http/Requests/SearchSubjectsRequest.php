<?php

namespace App\Core\Privacy\Http\Requests;

use App\Core\Privacy\PrivacySubjects;
use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** NFR-08: find a data subject by name, for a request or a consent (either view permission). */
class SearchSubjectsRequest extends PrivacyRequestAction
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->can('core.privacy_request.view', Scope::tenant()) || $user->can('core.consent.view', Scope::tenant()));
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(app(PrivacySubjects::class)->types())],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }
}
