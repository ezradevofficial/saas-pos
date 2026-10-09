<?php

namespace App\Core\CustomForms\Http\Controllers;

use App\Core\CustomForms\CustomFormAccess;
use App\Core\CustomForms\CustomFormType;
use App\Core\CustomForms\CustomFormTypes;
use App\Core\CustomForms\Http\Requests\CustomFormTypeActionRequest;
use App\Core\CustomForms\Http\Requests\ListCustomFormTypesRequest;
use App\Core\CustomForms\Http\Requests\ShowCustomFormTypeRequest;
use App\Core\CustomForms\Http\Requests\StoreCustomFormTypeRequest;
use App\Core\CustomForms\Http\Requests\UpdateCustomFormTypeRequest;
use App\Core\CustomForms\Http\Resources\CustomFormTypeResource;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CF-04: custom form types. Admins (`core.custom_form_type.manage`) build
 * and change them; everyone gets the types they may use (the Forms menu).
 * Archived, never deleted (TEN-06); audited as `core.custom_form_type.*`.
 */
class CustomFormTypeController
{
    public function __construct(private readonly CustomFormTypes $types) {}

    public function index(ListCustomFormTypesRequest $request, CustomFormAccess $access): AnonymousResourceCollection
    {
        $user = $request->user();
        $manages = $access->manages($user);
        $query = CustomFormType::query()->orderBy('name')->orderBy('id');
        $status = $manages ? $request->validated('status', 'active') : 'active';

        match ($status) {
            'active' => $query->whereNull('archived_at'),
            'archived' => $query->whereNotNull('archived_at'),
            default => null,
        };

        if (($search = trim((string) $request->validated('search', ''))) !== '') {
            $query->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%');
        }

        $types = $query->get()->filter(fn (CustomFormType $type) => $manages
            || $access->anywhere($user, $type, [CustomFormAccess::VIEW, CustomFormAccess::EDIT, CustomFormAccess::CREATE]))->values();

        return CustomFormTypeResource::collection($types);
    }

    public function store(StoreCustomFormTypeRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $type = DB::connection(TenantContext::CONNECTION)->transaction(fn () => CustomFormType::create([
                ...$data,
                'number_type' => CustomFormType::DOCUMENT_PREFIX.$data['key'],
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['key' => __('core.custom_form_type.key_taken')]);
        }

        $this->types->forget();

        return CustomFormTypeResource::make($type->refresh())->response()->setStatusCode(201);
    }

    public function show(ShowCustomFormTypeRequest $request, CustomFormType $customFormType): CustomFormTypeResource
    {
        return CustomFormTypeResource::make($customFormType);
    }

    public function update(UpdateCustomFormTypeRequest $request, CustomFormType $customFormType): CustomFormTypeResource
    {
        $customFormType->fill($request->validated())->save();
        $this->types->forget();

        return CustomFormTypeResource::make($customFormType->refresh());
    }

    public function archive(CustomFormTypeActionRequest $request, CustomFormType $customFormType): CustomFormTypeResource
    {
        if (! $customFormType->isArchived()) {
            $customFormType->archive();
            $this->types->forget();
        }

        return CustomFormTypeResource::make($customFormType->refresh());
    }

    public function restore(CustomFormTypeActionRequest $request, CustomFormType $customFormType): CustomFormTypeResource
    {
        if ($customFormType->isArchived()) {
            $customFormType->restore();
            $this->types->forget();
        }

        return CustomFormTypeResource::make($customFormType->refresh());
    }
}
