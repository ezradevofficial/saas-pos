<?php

namespace Modules\CRM\Http\Resources;

use App\Core\CustomFields\CustomFieldPresenter;
use App\Core\Rbac\FieldRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\CustomFields\LeadEntity;
use Modules\CRM\Models\Lead;
use Modules\CRM\Support\CrmAccess;

/**
 * CRM-05: a lead, its place, owner, source, value, conversion and custom
 * fields; fields hidden by the reader's field rules are left out (RBAC-05,
 * resource `crm_lead`); `can` says what the reader may do (RBAC-09).
 *
 * @mixin Lead
 */
class LeadResource extends JsonResource
{
    public const RELATIONS = ['company', 'branch', 'owner', 'team', 'source', 'party', 'opportunity'];

    public function toArray(Request $request): array
    {
        /** @var Lead $lead */
        $lead = $this->resource;
        $user = $request->user();
        $access = app(CrmAccess::class);
        $may = fn (string $permission) => $user !== null && $access->canAt($user, "crm.lead.{$permission}", $lead->branch_id);

        $data = [
            'id' => $lead->id,
            'company' => Refs::named($lead->company),
            'branch' => Refs::named($lead->branch),
            'owner' => Refs::user($lead->owner),
            'team' => Refs::named($lead->team),
            'status' => $lead->status,
            'name' => $lead->name,
            'organisation' => $lead->organisation,
            'title' => $lead->title,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'source' => Refs::named($lead->source),
            'channel' => $lead->channel,
            'estimated_value' => Refs::money($lead->estimated_value_minor, $lead->currency),
            'notes' => $lead->notes,
            'disqualify_reason' => $lead->disqualify_reason,
            'party' => Refs::named($lead->party),
            'opportunity' => Refs::named($lead->opportunity),
            'converted_at' => Refs::at($lead->converted_at),
            'custom' => (object) app(CustomFieldPresenter::class)->present($user, LeadEntity::KEY, $lead->custom),
            'archived_at' => Refs::at($lead->archived_at),
            'created_at' => Refs::at($lead->created_at),
            'updated_at' => Refs::at($lead->updated_at),
            'can' => [
                'edit' => $lead->isOpen() && $may('edit'),
                'assign' => $lead->isOpen() && $may('assign'),
                'convert' => $lead->isOpen() && $may('convert'),
                'archive' => $may('archive'),
            ],
        ];

        if ($user !== null) {
            $hidden = app(FieldRules::class)->for($user, LeadEntity::KEY)['hidden'];

            if (in_array('estimated_value_minor', $hidden, true)) {
                $hidden[] = 'estimated_value';
            }

            $data = array_diff_key($data, array_flip($hidden));
        }

        return $data;
    }
}
