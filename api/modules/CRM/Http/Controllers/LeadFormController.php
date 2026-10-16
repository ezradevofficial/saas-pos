<?php

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\CRM\Http\Requests\Setup\LeadFormRequest;
use Modules\CRM\Http\Requests\Setup\SetupListRequest;
use Modules\CRM\Http\Resources\SetupResource;
use Modules\CRM\Models\LeadForm;
use Modules\CRM\Setup\Configuration;

/** CRM-06: public lead forms (the link is answered once, at creation and rotation). */
class LeadFormController
{
    private const RELATIONS = ['company', 'branch', 'source', 'team'];

    public function __construct(private readonly Configuration $configuration) {}

    public function forms(SetupListRequest $request): JsonResponse
    {
        $forms = $request->applyFilters(LeadForm::query())->with(self::RELATIONS)->orderBy('name')->get();

        return response()->json(['data' => $forms->map(fn (LeadForm $form) => SetupResource::form($form))->values()]);
    }

    public function store(LeadFormRequest $request): JsonResponse
    {
        [$form, $token] = $this->configuration->saveForm(null, $request->company(), $request->validated());

        return $this->present($form, $token, 201);
    }

    public function update(LeadFormRequest $request, LeadForm $crmLeadForm): JsonResponse
    {
        [$form] = $this->configuration->saveForm($crmLeadForm, $request->company(), $request->validated());

        return $this->present($form);
    }

    public function rotate(LeadFormRequest $request, LeadForm $crmLeadForm): JsonResponse
    {
        [$form, $token] = $this->configuration->rotateForm($crmLeadForm);

        return $this->present($form, $token);
    }

    public function archive(LeadFormRequest $request, LeadForm $crmLeadForm): JsonResponse
    {
        $crmLeadForm->archive();

        return $this->present($crmLeadForm);
    }

    private function present(LeadForm $form, ?string $token = null, int $status = 200): JsonResponse
    {
        return response()->json(['data' => SetupResource::form($form->refresh()->load(self::RELATIONS), $token)], $status);
    }
}
