<?php

namespace Modules\Loyalty\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Modules\Loyalty\Http\Requests\ProgrammeRequest;
use Modules\Loyalty\Http\Requests\ShowProgrammeRequest;
use Modules\Loyalty\Models\Programme;
use Modules\Loyalty\Programmes;

/** LOY-01: a company's loyalty programme (the defaults until it is first saved). */
class ProgrammeController
{
    public function __construct(private readonly Programmes $programmes) {}

    public function show(ShowProgrammeRequest $request, Company $company): JsonResponse
    {
        return response()->json(['data' => $this->present($company, $this->programmes->for($company->id))]);
    }

    public function update(ProgrammeRequest $request, Company $company): JsonResponse
    {
        $programme = $this->programmes->save($company, $request->validated());

        return response()->json(['data' => $this->present($company, $programme)]);
    }

    /** @return array<string, mixed> */
    private function present(Company $company, ?Programme $programme): array
    {
        $values = $programme === null ? Programmes::defaults($company) : $programme->only(array_keys(Programmes::defaults($company)));

        return [
            'company_id' => $company->id,
            'saved' => $programme !== null,
            ...$values,
            'earn_spend_minor' => (string) $values['earn_spend_minor'],
            'point_value_minor' => (string) $values['point_value_minor'],
            'min_redeem_points' => (string) $values['min_redeem_points'],
            'gift_card_low_minor' => $values['gift_card_low_minor'] === null ? null : (string) $values['gift_card_low_minor'],
            'gift_card_item' => $programme?->gift_card_item_id === null ? null : ['id' => $programme->gift_card_item_id, 'name' => $programme->giftCardItem?->name],
        ];
    }
}
