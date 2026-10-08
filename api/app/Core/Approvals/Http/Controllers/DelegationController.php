<?php

namespace App\Core\Approvals\Http\Controllers;

use App\Core\Approvals\Delegations;
use App\Core\Approvals\Http\Requests\DelegationRequest;
use App\Core\Approvals\Http\Requests\StoreDelegationRequest;
use App\Core\Approvals\Http\Resources\DelegationResource;
use App\Core\Approvals\Models\ApprovalDelegation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * APR-06: the signed-in user's delegations: the ones they gave (newest
 * first, revoked ones included) and the ones they received.
 */
class DelegationController
{
    public function __construct(private readonly Delegations $delegations) {}

    public function index(DelegationRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $rows = ApprovalDelegation::query()
            ->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))
            ->with(['fromUser:id,name', 'toUser:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(200)->get();

        return DelegationResource::collection($rows);
    }

    public function store(StoreDelegationRequest $request): JsonResponse
    {
        $delegation = $this->delegations->create($request->user(), $request->validated());

        return DelegationResource::make($delegation->load(['fromUser:id,name', 'toUser:id,name']))->response()->setStatusCode(201);
    }

    public function revoke(DelegationRequest $request): DelegationResource
    {
        return DelegationResource::make($this->delegations->revoke($request->delegation(), $request->user())->load(['fromUser:id,name', 'toUser:id,name']));
    }
}
