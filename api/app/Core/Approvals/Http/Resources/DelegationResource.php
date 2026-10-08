<?php

namespace App\Core\Approvals\Http\Resources;

use App\Core\Approvals\Models\ApprovalDelegation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * APR-06: a delegation, from the signed-in user's side (`direction`:
 * given or received). `status`: scheduled, active, ended or revoked
 * (today in UTC; requests check their company's day).
 *
 * @mixin ApprovalDelegation
 */
class DelegationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = CarbonImmutable::now()->toDateString();
        $starts = $this->starts_on->format('Y-m-d');
        $ends = $this->ends_on->format('Y-m-d');

        return [
            'id' => $this->id,
            'direction' => $this->from_user_id === $request->user()?->id ? 'given' : 'received',
            'from' => ['id' => $this->from_user_id, 'name' => $this->fromUser?->name],
            'to' => ['id' => $this->to_user_id, 'name' => $this->toUser?->name],
            'starts_on' => $starts,
            'ends_on' => $ends,
            'document_types' => $this->document_types,
            'note' => $this->note,
            'status' => match (true) {
                $this->revoked_at !== null => 'revoked',
                ($this->fromUser !== null && ! $this->fromUser->isActive()) || ($this->toUser !== null && ! $this->toUser->isActive()) => 'ended',
                $today < $starts => 'scheduled',
                $today > $ends => 'ended',
                default => 'active',
            },
            'revoked_at' => $this->revoked_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
