<?php

namespace App\Core\CustomFields\Http\Controllers;

use App\Core\CustomFields\CustomFieldAccess;
use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\CustomFields\CustomFieldDefinitions;
use App\Core\CustomFields\CustomFieldEntities;
use App\Core\CustomFields\CustomFieldTypes;
use App\Core\CustomFields\Entities\LookupTarget;
use App\Core\CustomFields\Formula\Formula;
use App\Core\CustomFields\Http\Requests\CustomFieldActionRequest;
use App\Core\CustomFields\Http\Requests\CustomFieldLookupRequest;
use App\Core\CustomFields\Http\Requests\CustomFieldMetaRequest;
use App\Core\CustomFields\Http\Requests\CustomFieldRequest;
use App\Core\CustomFields\Http\Requests\CustomFieldRules;
use App\Core\CustomFields\Http\Requests\CustomFieldSchemaRequest;
use App\Core\CustomFields\Http\Requests\ListCustomFieldsRequest;
use App\Core\CustomFields\Http\Requests\StoreCustomFieldRequest;
use App\Core\CustomFields\Http\Requests\UpdateCustomFieldRequest;
use App\Core\CustomFields\Http\Resources\CustomFieldResource;
use App\Core\Exports\ListExport;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CF-01, CF-02: the tenant's custom field definitions per entity, the
 * schema forms render, and lookup candidates. Definitions are archived,
 * never deleted (TEN-06); their values stay on the records. Every change
 * is audited as `core.custom_field.*` (AUD-01, history type
 * `custom_field`).
 */
class CustomFieldController
{
    public const LOOKUP_LIMIT = 20;

    public function index(ListCustomFieldsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = CustomFieldDefinition::query();

        if ($request->filled('entity')) {
            $query->where('entity', $request->validated('entity'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->validated('type'));
        }

        $request->applySort($request->applySearch($request->applyStatus($query), ['label' => 'label', 'key' => 'key']));

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return CustomFieldResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreCustomFieldRequest $request): JsonResponse
    {
        $attributes = CustomFieldRules::attributes($request->validated());

        try {
            $field = DB::connection(TenantContext::CONNECTION)->transaction(fn () => CustomFieldDefinition::create($attributes));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['key' => __('core.custom_field.key_taken')]);
        }

        return CustomFieldResource::make($field->refresh())->response()->setStatusCode(201);
    }

    public function show(CustomFieldRequest $request, CustomFieldDefinition $customField): CustomFieldResource
    {
        return CustomFieldResource::make($customField);
    }

    public function update(UpdateCustomFieldRequest $request, CustomFieldDefinition $customField): CustomFieldResource
    {
        $attributes = CustomFieldRules::attributes($request->validated());
        unset($attributes['entity'], $attributes['key'], $attributes['type']);
        $customField->fill($attributes)->save();

        return CustomFieldResource::make($customField->refresh());
    }

    public function archive(CustomFieldActionRequest $request, CustomFieldDefinition $customField): CustomFieldResource
    {
        if (! $customField->isArchived()) {
            $customField->archive();
        }

        return CustomFieldResource::make($customField->refresh());
    }

    public function restore(CustomFieldActionRequest $request, CustomFieldDefinition $customField): CustomFieldResource
    {
        if ($customField->isArchived()) {
            $customField->restore();
        }

        return CustomFieldResource::make($customField->refresh());
    }

    /** The registered entities, types, lookup targets and the formula language. */
    public function meta(CustomFieldMetaRequest $request, CustomFieldEntities $entities): JsonResponse
    {
        return new JsonResponse(['data' => [
            'entities' => array_values(array_map(fn ($entity) => ['key' => $entity->key(), 'label' => __($entity->label())], $entities->all())),
            'types' => CustomFieldTypes::ALL,
            'lookup_targets' => array_values(array_map(fn (LookupTarget $target) => ['key' => $target->key(), 'label' => __($target->label())], $entities->lookups())),
            'formula' => ['functions' => Formula::FUNCTIONS, 'operators' => Formula::OPERATORS],
        ]]);
    }

    /**
     * CF-03, RBAC-05: the entity's active fields the user may see, in
     * order, each with `readonly` when the user may not change it.
     */
    public function schema(CustomFieldSchemaRequest $request, CustomFieldDefinitions $definitions, CustomFieldAccess $access): JsonResponse
    {
        $entity = (string) $request->validated('entity');
        $rules = $access->for($request->user(), $entity);

        $fields = $definitions->active($entity)
            ->reject(fn (CustomFieldDefinition $field) => in_array($field->key, $rules['hidden'], true))
            ->map(fn (CustomFieldDefinition $field) => [
                'key' => $field->key,
                'type' => $field->type,
                'label' => $field->label,
                'help' => $field->help,
                'default' => $field->default_value,
                'required' => $field->required,
                'unique' => $field->is_unique,
                'min' => $field->bound('min'),
                'max' => $field->bound('max'),
                'pattern' => $field->pattern,
                'options' => array_values($field->options ?? []),
                'lookup_target' => $field->lookup_target,
                'formula_type' => $field->formula_type,
                'show_on_pos' => $field->show_on_pos,
                'position' => $field->position,
                'readonly' => in_array($field->key, $rules['readonly'], true),
            ])->values()->all();

        return new JsonResponse(['data' => $fields]);
    }

    /** Lookup candidates the user may see (`?target=`, `?search=` or `?id=`). */
    public function lookup(CustomFieldLookupRequest $request, CustomFieldEntities $entities): JsonResponse
    {
        $target = $entities->lookup((string) $request->validated('target'));
        $user = $request->user();

        if ($request->filled('id')) {
            $id = strtolower((string) $request->validated('id'));
            $label = $target->labels($user, [$id])[$id] ?? null;

            return new JsonResponse(['data' => $label === null ? [] : [['id' => $id, 'label' => $label]]]);
        }

        return new JsonResponse(['data' => $target->search($user, (string) $request->validated('search', ''), self::LOOKUP_LIMIT)]);
    }
}
