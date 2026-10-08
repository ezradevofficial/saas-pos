<?php

namespace App\Core\Rbac\Http\Requests;

use App\Core\Http\ApiException;
use App\Core\Rbac\FieldRules;
use Illuminate\Validation\Validator;

/**
 * RBAC-05 on writes: a store or update request refuses any input key that
 * names a field hidden from, or read-only for, the user on the request's
 * field rules resource (422 `field_readonly`, naming the field). Runs once
 * the request is authorised, before the rules, so nothing is saved.
 *
 * Using classes set `$fieldRulesResource` and, where an input key differs
 * from the field names rules use, map it in `$fieldRulesInputs`
 * (input key => field names; the key itself is always checked too).
 *
 * @property string $fieldRulesResource
 * @property array<string, list<string>> $fieldRulesInputs
 */
trait GuardsFieldRules
{
    public function withValidator(Validator $validator): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        $rules = app(FieldRules::class)->for($user, $this->fieldRulesResource);
        $restricted = [...$rules['hidden'], ...$rules['readonly']];

        if ($restricted === []) {
            return;
        }

        $inputs = property_exists($this, 'fieldRulesInputs') ? $this->fieldRulesInputs : [];
        $refused = [];

        foreach (array_keys($this->all()) as $key) {
            $fields = [(string) $key, ...($inputs[$key] ?? [])];

            if (array_intersect($fields, $restricted) !== []) {
                $refused[(string) $key] = [__('rbac.errors.field_readonly', ['field' => $key])];
            }
        }

        if ($refused !== []) {
            throw new ApiException(422, 'field_readonly', reset($refused)[0], $refused);
        }
    }
}
