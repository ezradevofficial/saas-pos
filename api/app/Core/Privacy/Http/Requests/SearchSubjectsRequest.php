<?php

namespace App\Core\Privacy\Http\Requests;

use App\Core\Privacy\Consents;
use App\Core\Privacy\PrivacySubjects;
use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/**
 * NFR-08: find a data subject by name, for a request or a consent. Data
 * request readers search every subject type; someone who only reads
 * consent finds customers only, never users or employees (review, low).
 */
class SearchSubjectsRequest extends PrivacyRequestAction
{
    public function authorize(): bool
    {
        return $this->allowedTypes() !== [];
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in($this->allowedTypes())],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return list<string> */
    private function allowedTypes(): array
    {
        $user = $this->user();
        if ($user === null) {
            return [];
        }

        $types = app(PrivacySubjects::class)->types();
        if ($user->can('core.privacy_request.view', Scope::tenant())) {
            return $types;
        }

        return $user->can('core.consent.view', Scope::tenant()) ? array_values(array_intersect($types, Consents::SUBJECT_TYPES)) : [];
    }
}
