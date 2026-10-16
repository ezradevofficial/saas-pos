<?php

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\Activity;
use Modules\CRM\Support\CrmAccess;

/**
 * CRM-26: an activity, its times (ISO 8601, UTC), status, links and, for
 * a logged email, its recipient and subject.
 *
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    public const RELATIONS = ['company', 'branch', 'owner', 'lead', 'opportunity', 'quote', 'party'];

    public function toArray(Request $request): array
    {
        /** @var Activity $activity */
        $activity = $this->resource;
        $user = $request->user();

        return [
            'id' => $activity->id,
            'company' => Refs::named($activity->company),
            'branch' => Refs::named($activity->branch),
            'owner' => Refs::user($activity->owner),
            'kind' => $activity->kind,
            'subject' => $activity->subject,
            'notes' => $activity->notes,
            'starts_at' => Refs::at($activity->starts_at),
            'ends_at' => Refs::at($activity->ends_at),
            'remind_at' => Refs::at($activity->remind_at),
            'reminded_at' => Refs::at($activity->reminded_at),
            'status' => $activity->status,
            'done_at' => Refs::at($activity->done_at),
            'outcome' => $activity->outcome,
            'overdue' => $activity->isOverdue(),
            'lead' => Refs::named($activity->lead),
            'opportunity' => Refs::named($activity->opportunity),
            'quote' => $activity->quote === null ? null : ['id' => $activity->quote->id, 'number' => $activity->quote->number],
            'party' => Refs::named($activity->party),
            'email' => $activity->email === null ? null : ['to' => $activity->email['to'] ?? null, 'subject' => $activity->email['subject'] ?? null, 'sent' => $activity->email['sent'] ?? null],
            'created_at' => Refs::at($activity->created_at),
            'can' => ['edit' => $user !== null && app(CrmAccess::class)->canAt($user, 'crm.activity.manage', $activity->branch_id)],
        ];
    }
}
