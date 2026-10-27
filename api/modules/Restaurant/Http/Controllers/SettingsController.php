<?php

namespace Modules\Restaurant\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Restaurant\Http\Requests\SettingsRequest;
use Modules\Restaurant\Http\Requests\UpdateSettingsRequest;
use Modules\Restaurant\Http\Resources\Presenters;
use Modules\Restaurant\Support\Setup;

/** REST-13: a company's service charge, its items and the tip presets. */
class SettingsController
{
    public function show(SettingsRequest $request): JsonResponse
    {
        return response()->json(['data' => Presenters::settings($request->companyId())]);
    }

    public function update(UpdateSettingsRequest $request, Setup $setup): JsonResponse
    {
        $company = (string) $request->route('company')->getKey();
        $setup->settings($company, $request->validated());

        return response()->json(['data' => Presenters::settings($company)]);
    }
}
