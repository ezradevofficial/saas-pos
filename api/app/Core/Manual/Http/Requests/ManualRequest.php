<?php

namespace App\Core\Manual\Http\Requests;

use App\Core\Localisation\Http\SetLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Any signed-in user may read the manual; what they see is filtered by
 * module and permission (VisibleTopics). `locale` defaults to the user's.
 */
class ManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['locale' => ['sometimes', 'string', Rule::in(SetLocale::SUPPORTED)]];
    }

    public function locale(): string
    {
        $locale = $this->validated('locale') ?? $this->user()->locale;

        return in_array($locale, SetLocale::SUPPORTED, true) ? $locale : app()->getLocale();
    }
}
