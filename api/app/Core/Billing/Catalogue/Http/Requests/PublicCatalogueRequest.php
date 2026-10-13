<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ONB-03: the public catalogue of a country (no sign-in). */
class PublicCatalogueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['country' => ['required', 'string', 'size:2', Rule::exists('catalogue_countries', 'country')]];
    }
}
