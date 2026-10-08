<?php

namespace App\Core\Numbering\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;

/** GET numbering/formats: `core.numbering.view` anywhere (NUM-01). */
class ListNumberFormatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'core.numbering.view');
    }

    public function rules(): array
    {
        return [];
    }
}
