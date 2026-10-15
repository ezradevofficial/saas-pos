<?php

namespace Modules\CRM\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Modules\CRM\Http\Requests\Setup\OptionRequest;
use Modules\CRM\Http\Requests\Setup\SetupListRequest;
use Modules\CRM\Http\Resources\SetupResource;
use Modules\CRM\Models\Option;
use Modules\CRM\Setup\CompanySetup;
use Modules\CRM\Setup\Configuration;

/** CRM-03: lead sources, won reasons and lost reasons. */
class OptionController
{
    public function __construct(private readonly Configuration $configuration) {}

    public function index(SetupListRequest $request, CompanySetup $setup): JsonResponse
    {
        if ($request->filled('company')) {
            $setup->ensure(Company::query()->findOrFail($request->validated('company')));
        }

        $query = $request->applyFilters(Option::query())->with('company')->orderBy('kind')->orderBy('position')->orderBy('name');

        if ($request->filled('kind')) {
            $query->where('kind', $request->validated('kind'));
        }

        return response()->json(['data' => $query->get()->map(fn (Option $option) => SetupResource::option($option))->values()]);
    }

    public function store(OptionRequest $request): JsonResponse
    {
        return $this->present($this->configuration->createOption($request->company(), $request->validated()), 201);
    }

    public function update(OptionRequest $request, Option $crmOption): JsonResponse
    {
        return $this->present($this->configuration->updateOption($crmOption, $request->validated()));
    }

    public function archive(OptionRequest $request, Option $crmOption): JsonResponse
    {
        $crmOption->archive();

        return $this->present($crmOption);
    }

    public function restore(OptionRequest $request, Option $crmOption): JsonResponse
    {
        $crmOption->restore();

        return $this->present($crmOption);
    }

    private function present(Option $option, int $status = 200): JsonResponse
    {
        return response()->json(['data' => SetupResource::option($option->refresh()->load('company'))], $status);
    }
}
