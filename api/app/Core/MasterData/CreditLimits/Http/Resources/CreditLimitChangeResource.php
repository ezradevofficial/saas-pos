<?php

namespace App\Core\MasterData\CreditLimits\Http\Resources;

use App\Core\MasterData\CreditLimits\CreditLimitChange;
use App\Core\MasterData\CreditLimits\CreditLimitChangeAccess;
use App\Core\MasterData\CreditLimits\CreditLimitChanges;
use App\Core\MasterData\Items\Http\Resources\HidesFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A credit limit change request (MD-01, WF-01). Amounts are
 * {amount_minor, currency} with the amount as a string (ADR 003); they are
 * left out when the party's credit limit is hidden from the user (RBAC-05,
 * field rules on `party`). `increase` is requested minus current (negative
 * for a decrease). `can_cancel` tells the viewer whether cancel would let
 * them (the API checks again).
 *
 * @mixin CreditLimitChange
 */
class CreditLimitChangeResource extends JsonResource
{
    /** Output keys built from the party's credit limit columns. */
    public const SOURCES = [
        'current_limit' => CreditLimitChangeAccess::LIMIT_FIELDS,
        'requested_limit' => CreditLimitChangeAccess::LIMIT_FIELDS,
        'increase' => CreditLimitChangeAccess::LIMIT_FIELDS,
    ];

    public function toArray(Request $request): array
    {
        $user = $request->user();
        // L5, RBAC-05: the party's name when the party field rules show it.
        $nameHidden = in_array('name', HidesFields::hidden($request, CreditLimitChangeAccess::FIELD_RULES), true);

        return HidesFields::apply($request, CreditLimitChangeAccess::FIELD_RULES, [
            'id' => $this->id,
            'number' => $this->number,
            'party' => ['id' => $this->party_id, 'name' => $nameHidden ? null : $this->party?->name],
            'company' => ['id' => $this->company_id, 'name' => $this->company?->name],
            'current_limit' => $this->currentLimit(),
            'requested_limit' => $this->requestedLimit(),
            'increase' => $this->increase(),
            'reason' => $this->reason,
            'status' => $this->status,
            'requested_by' => ['id' => $this->requested_by, 'name' => $this->requester?->name],
            'decided_by' => $this->decided_by === null ? null : ['id' => $this->decided_by, 'name' => $this->decider?->name],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'applied_at' => $this->applied_at?->toIso8601ZuluString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'can_cancel' => $user !== null && $this->resource->isOpen() && app(CreditLimitChangeAccess::class)->cancel($user, $this->resource),
            'can_apply' => $user !== null && $this->status === CreditLimitChange::APPROVED && app(CreditLimitChanges::class)->canSetDirectly($user, $this->company_id),
        ], self::SOURCES);
    }
}
