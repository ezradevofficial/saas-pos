<?php

namespace Modules\CRM\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\Target;
use Modules\CRM\Models\Team;
use Modules\CRM\Targets\Targets;

/**
 * CRM-30: a target, its subject, period and amount, with its actual and
 * the share reached (computed when read; null when the metric's module
 * is off, `available: false`).
 *
 * @mixin Target
 */
class TargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Target $target */
        $target = $this->resource;
        $targets = app(Targets::class);
        $subject = match ($target->subject_type) {
            'user' => User::query()->find($target->subject_id),
            'team' => Team::query()->find($target->subject_id),
            default => Branch::query()->find($target->subject_id),
        };

        return [
            'id' => $target->id,
            'company' => Refs::named($target->company),
            'branch' => Refs::named($target->branch),
            'subject_type' => $target->subject_type,
            'subject' => $subject === null ? null : ['id' => (string) $subject->getKey(), 'name' => (string) $subject->getAttribute('name')],
            'metric' => $target->metric,
            'period_start' => Refs::date($target->period_start),
            'period_end' => Refs::date($target->period_end),
            'currency' => $target->currency,
            'amount_minor' => (string) $target->amount_minor,
            ...$targets->actual($target),
            'archived_at' => Refs::at($target->archived_at),
            'can' => ['manage' => $request->user() !== null && $targets->mayManage($request->user(), $target)],
        ];
    }
}
