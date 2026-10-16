<?php

namespace Modules\CRM\Http\Resources;

use App\Core\CustomFields\CustomFieldPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\CustomFields\OpportunityEntity;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Support\CrmAccess;

/**
 * CRM-14: an opportunity, its stage, value (weighted by its probability),
 * outcome and base value once won (CUR-04); in the detail its activities
 * and quotes. Fields hidden by the reader's field rules are left out
 * (RBAC-05, resource `crm_opportunity`).
 *
 * @mixin Opportunity
 */
class OpportunityResource extends JsonResource
{
    public const RELATIONS = ['company', 'branch', 'owner', 'team', 'party', 'lead', 'pipeline', 'stage', 'reason'];

    private bool $detail = false;

    /** @var array<string, mixed> */
    private array $extra = [];

    /** @param array<string, mixed> $extra */
    public function detail(array $extra): self
    {
        $this->detail = true;
        $this->extra = $extra;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Opportunity $opportunity */
        $opportunity = $this->resource;
        $user = $request->user();
        $access = app(CrmAccess::class);
        $may = fn (string $permission) => $user !== null && $access->canAt($user, "crm.opportunity.{$permission}", $opportunity->branch_id);
        $open = $opportunity->status === Opportunity::OPEN;

        $data = [
            'id' => $opportunity->id,
            'company' => Refs::named($opportunity->company),
            'branch' => Refs::named($opportunity->branch),
            'owner' => Refs::user($opportunity->owner),
            'team' => Refs::named($opportunity->team),
            'party' => Refs::named($opportunity->party),
            'lead' => Refs::named($opportunity->lead),
            'pipeline' => Refs::named($opportunity->pipeline),
            'stage' => $opportunity->stage === null ? null : ['id' => $opportunity->stage->id, 'name' => $opportunity->stage->name, 'probability' => $opportunity->stage->probability],
            'name' => $opportunity->name,
            'value' => Refs::money($opportunity->value_minor, $opportunity->currency),
            'expected_close_on' => Refs::date($opportunity->expected_close_on),
            'probability' => $opportunity->probability,
            'weighted_minor' => $opportunity->weightedMinor(),
            'status' => $opportunity->status,
            'reason' => Refs::named($opportunity->reason),
            'close_note' => $opportunity->close_note,
            'closed_on' => Refs::date($opportunity->closed_on),
            'base_value' => Refs::money($opportunity->base_value_minor, $opportunity->base_currency),
            'notes' => $opportunity->notes,
            'custom' => (object) app(CustomFieldPresenter::class)->present($user, OpportunityEntity::KEY, $opportunity->custom),
            'archived_at' => Refs::at($opportunity->archived_at),
            'created_at' => Refs::at($opportunity->created_at),
            'can' => [
                'edit' => $open && $may('edit'),
                'close' => $may('close'),
                'archive' => $may('archive'),
            ],
            ...($this->detail ? $this->extra : []),
        ];

        if ($user !== null) {
            $hidden = $access->hiddenFields($user, OpportunityEntity::KEY);

            if (in_array('value_minor', $hidden, true)) {
                array_push($hidden, 'value', 'weighted_minor', 'base_value');
            }

            $data = array_diff_key($data, array_flip($hidden));
        }

        return $data;
    }
}
