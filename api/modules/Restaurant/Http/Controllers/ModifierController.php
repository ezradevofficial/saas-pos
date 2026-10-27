<?php

namespace Modules\Restaurant\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Restaurant\Http\Requests\ArchiveRequest;
use Modules\Restaurant\Http\Requests\StoreModifierGroupRequest;
use Modules\Restaurant\Http\Requests\UpdateModifierGroupRequest;
use Modules\Restaurant\Http\Requests\ViewRequest;
use Modules\Restaurant\Http\Resources\Presenters;
use Modules\Restaurant\Models\ModifierGroup;
use Modules\Restaurant\Support\Places;
use Modules\Restaurant\Support\Setup;

/** REST-07: modifier groups, their modifiers and what they attach to. */
class ModifierController
{
    public function __construct(private readonly Places $places, private readonly Setup $setup) {}

    public function index(ViewRequest $request): JsonResponse
    {
        $query = ModifierGroup::query()->with(['modifiers', 'links'])->orderBy('name')->orderBy('id');
        $this->places->filterCompanies($query, $request->user(), $request->permission());

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        }

        if (! $request->boolean('archived')) {
            $query->whereNull('archived_at');
        }

        return response()->json(['data' => $query->get()->map(fn (ModifierGroup $g) => Presenters::group($g))->all()]);
    }

    public function store(StoreModifierGroupRequest $request): JsonResponse
    {
        $group = $this->setup->group(null, $request->validated(), (string) $request->input('company_id'));

        return response()->json(['data' => Presenters::group($group->refresh())], 201);
    }

    public function update(UpdateModifierGroupRequest $request, ModifierGroup $restModifierGroup): JsonResponse
    {
        $group = $this->setup->group($restModifierGroup, $request->validated());

        return response()->json(['data' => Presenters::group($group->refresh())]);
    }

    public function archive(ArchiveRequest $request, ModifierGroup $restModifierGroup): JsonResponse
    {
        $restModifierGroup->archive();

        return response()->json(['data' => Presenters::group($restModifierGroup)]);
    }
}
