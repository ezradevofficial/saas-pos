<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

use App\Core\Billing\Catalogue\Models\CatalogueModule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** What a module includes of another, and what it requires. */
final class ModuleCompositionRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'includes' => ['sometimes', 'array', 'max:10'],
            'includes.*' => ['integer', 'min:1', 'max:1000'],
            'requires' => ['sometimes', 'nullable', 'string', Rule::exists('catalogue_modules', 'key')],
        ];
    }

    public static function check(Validator $validator, FormRequest $request, string $self): void
    {
        $includes = $request->input('includes');

        if (is_array($includes) && $includes !== []) {
            $known = CatalogueModule::whereIn('key', array_keys($includes))->pluck('key')->all();

            foreach (array_keys($includes) as $key) {
                if ($key === $self || ! in_array($key, $known, true)) {
                    $validator->errors()->add("includes.{$key}", __('billing.catalogue.unknown_module'));
                }
            }
        }

        if ($request->input('requires') !== null && $request->input('requires') === $self) {
            $validator->errors()->add('requires', __('billing.catalogue.unknown_module'));
        }
    }
}
