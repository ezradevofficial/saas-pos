<?php

namespace Modules\Loyalty\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Http\Requests\AdjustPointsRequest;
use Modules\Loyalty\Http\Requests\CustomerRequest;
use Modules\Loyalty\Http\Resources\AccountResource;
use Modules\Loyalty\Http\Resources\EntryResource;
use Modules\Loyalty\Ledgers\Ledgers;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\CreditEntry;
use Modules\Loyalty\Models\PointsEntry;
use Modules\Loyalty\Support\LoyaltyAccess;

/**
 * The customer page's Loyalty tab (LOY-02, LOY-07, LOY-14): the
 * customer's points and credit accounts at the companies the user sees,
 * with their latest entries; a manual points adjustment.
 */
class CustomerController
{
    private const RECENT = 20;

    public function __construct(private readonly LoyaltyAccess $access) {}

    public function show(CustomerRequest $request, Party $party): JsonResponse
    {
        $user = $request->user();
        $points = $this->access->companies($user, 'loyalty.points.view');
        $credit = $this->access->companies($user, 'loyalty.credit.view');
        $accounts = Account::query()->where('party_id', $party->id)->with(['company', 'party'])->orderBy('kind')->get()
            ->filter(fn (Account $a) => $this->access->allows($user, $a->kind === Account::POINTS ? 'loyalty.points.view' : 'loyalty.credit.view', $a->company_id))
            ->values();

        return response()->json(['data' => [
            'party' => ['id' => $party->id, 'name' => $party->name, 'tags' => array_values((array) $party->tags)],
            'credit_limit' => $party->creditLimit()?->jsonSerialize(),
            'can' => [
                'points' => $points === null || $points !== [],
                'credit' => $credit === null || $credit !== [],
            ],
            'accounts' => $accounts->map(fn (Account $account) => [
                ...AccountResource::make($account)->resolve($request),
                'entries' => EntryResource::collection(
                    ($account->kind === Account::POINTS ? PointsEntry::query() : CreditEntry::query())
                        ->where('account_id', $account->id)->orderByDesc('occurred_at')->orderByDesc('id')->limit(self::RECENT)->get(),
                )->resolve($request),
            ])->all(),
        ]]);
    }

    /** LOY-07: a manual adjustment of the customer's points at a company, with a reason (audited). */
    public function adjustPoints(AdjustPointsRequest $request, Party $party, Ledgers $ledgers, Auditor $auditor): JsonResponse
    {
        $entry = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($request, $party, $ledgers, $auditor) {
            $account = $ledgers->pointsAccount($request->validated('company_id'), $party->id, $request->user()->id);
            $entry = $ledgers->points($account, 'adjust', $request->validated('points'), [
                'source_type' => 'manual', 'reason' => $request->validated('reason'), 'created_by' => $request->user()->id,
            ]);
            $auditor->record('loyalty.points.adjust', $account, null, [
                'entry_id' => $entry?->id, 'points' => $request->validated('points'), 'reason' => $request->validated('reason'), 'balance' => (string) $account->balance,
            ], ['user_id' => $request->user()->id]);

            return $entry;
        });

        return EntryResource::make($entry)->response()->setStatusCode(201);
    }
}
