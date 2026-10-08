<?php

namespace App\Core\Approvals\Http\Resources;

use App\Core\Approvals\ApprovalPresenter;
use App\Core\Approvals\Delegations;
use App\Core\Approvals\Models\ApprovalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * APR-04: one request in the inbox, for the signed-in user (ApprovalPresenter::item).
 *
 * @mixin ApprovalRequest
 */
class ApprovalItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The viewer's delegations, read once per request (the list renders many items).
        $delegations = $request->attributes->get('approval_delegations')
            ?? tap(app(Delegations::class)->to($request->user()), fn ($d) => $request->attributes->set('approval_delegations', $d));

        return app(ApprovalPresenter::class)->item($this->resource, $request->user(), $delegations);
    }
}
