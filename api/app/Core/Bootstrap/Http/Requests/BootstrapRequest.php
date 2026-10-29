<?php

namespace App\Core\Bootstrap\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * NFR-02: GET bootstrap?company=&branch=&location=: the place (optional) the
 * theme is also resolved at. Anyone signed in may ask; each part checks the
 * user's rights itself.
 */
class BootstrapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'company' => ['sometimes', 'uuid'],
            'branch' => ['sometimes', 'uuid'],
            'location' => ['sometimes', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function place(): array
    {
        return array_filter($this->validated(), fn ($v) => is_string($v) && $v !== '');
    }
}
