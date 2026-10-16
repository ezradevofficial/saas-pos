<?php

namespace App\Core\CustomFields\Http\Requests;

use App\Core\CustomFields\CustomFieldEntities;
use App\Core\CustomFields\Entities\CustomFieldEntity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CF-01, CF-03: the custom fields a form or list shows for `?entity=`,
 * for anyone who may view that entity's records. An unknown entity, or
 * one of a module the tenant does not have active (RBAC-08), fails
 * validation (422).
 */
class CustomFieldSchemaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entity = $this->entity();

        return $entity === null || $entity->viewAny($this->user());
    }

    public function rules(): array
    {
        return ['entity' => ['required', 'string', Rule::in(array_keys(app(CustomFieldEntities::class)->offered()))]];
    }

    public function entity(): ?CustomFieldEntity
    {
        $key = $this->query('entity');

        return is_string($key) ? (app(CustomFieldEntities::class)->offered()[$key] ?? null) : null;
    }
}
