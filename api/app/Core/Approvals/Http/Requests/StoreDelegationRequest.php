<?php

namespace App\Core\Approvals\Http\Requests;

use App\Core\Approvals\Delegations;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * APR-06: POST me/delegations {to_user_id, starts_on, ends_on,
 * document_types?, note?}: let an active colleague act on the user's
 * approvals from `starts_on` to `ends_on` (dates, inclusive, at most a
 * year, not ending in the past), for every document type or the listed ones.
 */
class StoreDelegationRequest extends DelegationRequest
{
    public function rules(): array
    {
        return [
            'to_user_id' => ['required', 'uuid', Rule::notIn([$this->user()->id]), Rule::exists('users', 'id')->where('status', 'active'), function (string $attribute, mixed $value, \Closure $fail) {
                // L1: only someone the pickers offer (sharing a place with the user).
                if (is_string($value) && Str::isUuid($value) && ! app(Delegations::class)->isCandidate($this->user(), $value)) {
                    $fail(__('approvals.delegations.not_candidate'));
                }
            }],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'after_or_equal:yesterday', function (string $attribute, mixed $value, \Closure $fail) {
                $start = $this->input('starts_on');

                if (is_string($start) && is_string($value) && strtotime($value) - strtotime($start) > 366 * 86400) {
                    $fail(__('approvals.delegations.too_long'));
                }
            }],
            'document_types' => ['sometimes', 'nullable', 'array', 'max:50'],
            'document_types.*' => ['required', 'string', 'distinct', Rule::in(app(DocumentTypeRegistry::class)->keys())],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'to_user_id' => __('approvals.attributes.delegate'),
            'starts_on' => __('approvals.attributes.starts_on'),
            'ends_on' => __('approvals.attributes.ends_on'),
            'document_types' => __('approvals.attributes.document_types'),
            'note' => __('approvals.attributes.note'),
        ];
    }
}
