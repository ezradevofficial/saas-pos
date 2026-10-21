<?php

namespace App\Core\Marketplace\Http\Controllers;

use App\Core\Marketplace\Billing\Payables;
use App\Core\Marketplace\Http\Requests\PayoutsRequest;
use App\Core\Marketplace\Http\Requests\RecordPayoutRequest;
use App\Core\Marketplace\Models\MarketplaceDeveloper;
use App\Core\Marketplace\Models\MarketplacePayable;
use App\Core\Platform\PlatformDatabase;
use Illuminate\Http\JsonResponse;

/**
 * MKT-03: developers' payable balances (the operator tenant's ledger) and
 * manual payouts, in the staff console (`platform.marketplace.payouts`).
 */
class PlatformPayoutController
{
    public function __construct(private readonly Payables $payables) {}

    public function index(PayoutsRequest $request): JsonResponse
    {
        $balances = $this->payables->balances();
        $developers = MarketplaceDeveloper::on(PlatformDatabase::CONNECTION)->whereKey(array_unique(array_column($balances, 'developer_id')))->get()->keyBy('id');

        return new JsonResponse(['data' => array_map(fn (array $row) => [
            ...$row,
            'developer' => $developers->get($row['developer_id'])?->only(['id', 'name', 'email', 'first_party']),
        ], $balances)]);
    }

    public function ledger(PayoutsRequest $request, string $developer): JsonResponse
    {
        $model = MarketplaceDeveloper::on(PlatformDatabase::CONNECTION)->findOrFail($developer);

        return new JsonResponse([
            'data' => array_map(fn (MarketplacePayable $entry) => self::entry($entry), $this->payables->ledger($model)),
            'meta' => ['developer' => $model->only(['id', 'name', 'email', 'website', 'first_party'])],
        ]);
    }

    public function store(RecordPayoutRequest $request, string $developer): JsonResponse
    {
        $model = MarketplaceDeveloper::on(PlatformDatabase::CONNECTION)->findOrFail($developer);
        $payout = $this->payables->markPaid($request->staff(), $model, $request->validated('currency'), (int) $request->validated('amount_minor'), $request->validated('reference'));

        return new JsonResponse(['data' => self::entry($payout)], 201);
    }

    /** @return array<string, mixed> */
    private static function entry(MarketplacePayable $entry): array
    {
        return [
            'id' => $entry->id,
            'kind' => $entry->kind,
            'currency' => $entry->currency,
            'amount_minor' => $entry->amount_minor,
            'base_minor' => $entry->base_minor,
            'share_percent' => $entry->share_percent,
            'app_id' => $entry->app_id,
            'operator_invoice_id' => $entry->operator_invoice_id,
            'reference' => $entry->reference,
            'occurred_at' => $entry->occurred_at?->toIso8601String(),
        ];
    }
}
