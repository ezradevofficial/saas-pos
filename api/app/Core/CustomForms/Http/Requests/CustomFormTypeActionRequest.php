<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomForms\CustomFormAccess;
use Illuminate\Foundation\Http\FormRequest;

/** CF-04: archive or restore a custom form type (`core.custom_form_type.manage`). */
class CustomFormTypeActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CustomFormAccess::class)->manages($this->user());
    }

    public function rules(): array
    {
        return [];
    }
}
