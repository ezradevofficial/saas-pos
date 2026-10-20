<?php

namespace App\Core\Billing\Http\Controllers;

use App\Core\Billing\Deals\Deals;
use App\Core\Billing\Http\Requests\DecideDealRequest;
use App\Core\Billing\Http\Requests\PlatformBillingRequest;
use App\Core\Billing\Http\Requests\StoreDealRequest;
use App\Core\Billing\Models\BillingDeal;
use App\Core\Platform\Tenants\PlatformTenants;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * ADM-02: a tenant's custom Enterprise deals in the console. Sales or
 * finance draft, finance approves, rejects or ends (Deals).
 */
class PlatformDealController
{
    public function __construct(
        private readonly Deals $deals,
        private readonly PlatformTenants $tenants,
    ) {}

    public function index(PlatformBillingRequest $request, string $tenant): JsonResponse
    {
        $this->tenants->account404($tenant);

        return response()->json(['data' => array_map(self::present(...), $this->deals->list($tenant))]);
    }

    public function store(StoreDealRequest $request, string $tenant): JsonResponse
    {
        $this->tenants->account404($tenant);

        return response()->json(['data' => self::present($this->deals->draft($tenant, $request->validated(), $request->staff()))], 201);
    }

    public function approve(DecideDealRequest $request, string $tenant, string $deal): JsonResponse
    {
        $this->tenants->account404($tenant);

        return response()->json(['data' => self::present($this->deals->approve($tenant, $deal, $request->staff(), $request->validated('note')))]);
    }

    public function reject(DecideDealRequest $request, string $tenant, string $deal): JsonResponse
    {
        $this->tenants->account404($tenant);

        return response()->json(['data' => self::present($this->deals->reject($tenant, $deal, $request->staff(), $request->validated('note')))]);
    }

    public function end(DecideDealRequest $request, string $tenant, string $deal): JsonResponse
    {
        $this->tenants->account404($tenant);
        $on = $request->validated('last_day') === null ? CarbonImmutable::now()->utc() : CarbonImmutable::parse($request->validated('last_day'), 'UTC');

        return response()->json(['data' => self::present($this->deals->end($tenant, $deal, $on, $request->staff()))]);
    }

    /** @return array<string, mixed> */
    public static function present(BillingDeal $deal): array
    {
        return [
            'id' => $deal->id,
            'status' => $deal->status,
            'country' => $deal->country,
            'currency' => $deal->currency,
            'bundle' => $deal->bundle,
            'lines' => $deal->lines,
            'effective_from' => $deal->effective_from->toDateString(),
            'effective_to' => $deal->effective_to?->toDateString(),
            'reason' => $deal->reason,
            'created_by' => ['id' => $deal->created_by_staff, 'name' => $deal->created_by_name],
            'decided_by' => $deal->decided_by_staff === null ? null : ['id' => $deal->decided_by_staff, 'name' => $deal->decided_by_name],
            'decided_at' => $deal->decided_at?->toIso8601String(),
            'decision_note' => $deal->decision_note,
            'ended_at' => $deal->ended_at?->toIso8601String(),
            'created_at' => $deal->created_at?->toIso8601String(),
        ];
    }
}
