<?php

namespace App\Core\Marketplace\Billing;

use App\Core\Audit\Auditor;
use App\Core\Billing\Events\OperatorInvoicePaid;
use App\Core\Billing\Models\OperatorInvoice;
use App\Core\Billing\Operator\Operator;
use App\Core\Http\ApiException;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\Models\MarketplaceDeveloper;
use App\Core\Marketplace\Models\MarketplacePayable;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuditor;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * MKT-03 (docs/adr/024-marketplace.md): the developers' payable ledger in
 * the operator tenant (ADR 016).
 *
 * - When a platform invoice is paid, each of its `app` lines earns the
 *   app's developer `marketplace.developer_share_percent` (80) of the
 *   line's amount net of discount and tax, rounded half up to a minor
 *   unit; the platform keeps the rest. Recorded once per line (unique
 *   index), in the transaction that paid the invoice.
 * - Payouts are manual until a provider is chosen: staff with
 *   `platform.marketplace.payouts` record what they paid, with its bank
 *   or mobile money reference, never more than the balance.
 * - Balances are per developer and currency (KES and USD never summed).
 */
class Payables
{
    public function __construct(
        private readonly Operator $operator,
        private readonly TenantContext $tenants,
        private readonly PlatformAuditor $platformAudit,
        private readonly Auditor $auditor,
    ) {}

    /** In the operator's context (OperatorInvoicePaid). */
    public function onInvoicePaid(OperatorInvoicePaid $event): void
    {
        $this->recordEarnings($event->invoice);
    }

    public function recordEarnings(OperatorInvoice $invoice): void
    {
        $percent = max(0, min(100, (int) config('marketplace.developer_share_percent', 80)));

        foreach ((array) $invoice->lines as $line) {
            if (($line['type'] ?? null) !== 'app' || ! is_string($line['key'] ?? null)) {
                continue;
            }

            $base = (int) ($line['net_minor'] ?? $line['amount'] ?? 0) - (int) ($line['tax_minor'] ?? 0);
            $app = MarketplaceApp::query()->find($line['key']);

            if ($base <= 0 || $app === null) {
                continue;
            }

            $lineNo = (int) ($line['line_no'] ?? 0);

            if (MarketplacePayable::query()->where('operator_invoice_id', $invoice->id)->where('line_no', $lineNo)->where('kind', MarketplacePayable::EARNING)->exists()) {
                continue;
            }

            MarketplacePayable::create([
                'developer_id' => $app->developer_id,
                'app_id' => $app->id,
                'kind' => MarketplacePayable::EARNING,
                'currency' => $invoice->currency,
                // Half up, in integer maths.
                'amount_minor' => intdiv(2 * $base * $percent + 100, 200),
                'base_minor' => $base,
                'share_percent' => $percent,
                'operator_invoice_id' => $invoice->id,
                'customer_tenant_id' => $invoice->customer_tenant_id,
                'line_no' => $lineNo,
                'occurred_at' => $invoice->paid_at ?? CarbonImmutable::now(),
            ]);
        }
    }

    /**
     * Balances per developer and currency: earned, paid out, owed.
     *
     * @return list<array{developer_id: string, currency: string, earned_minor: int, paid_minor: int, balance_minor: int, last_payout_at: ?string}>
     */
    public function balances(): array
    {
        return $this->operator->run(fn () => MarketplacePayable::query()
            ->selectRaw("developer_id, currency,
                coalesce(sum(amount_minor) filter (where kind = 'earning'), 0) as earned,
                coalesce(sum(amount_minor) filter (where kind = 'payout'), 0) as paid,
                max(occurred_at) filter (where kind = 'payout') as last_payout_at")
            ->groupBy('developer_id', 'currency')
            ->orderBy('developer_id')->orderBy('currency')
            ->get()
            ->map(fn ($row) => [
                'developer_id' => $row->developer_id,
                'currency' => $row->currency,
                'earned_minor' => (int) $row->earned,
                'paid_minor' => (int) $row->paid,
                'balance_minor' => (int) $row->earned - (int) $row->paid,
                'last_payout_at' => $row->last_payout_at === null ? null : CarbonImmutable::parse($row->last_payout_at)->toIso8601String(),
            ])->values()->all());
    }

    /** @return list<MarketplacePayable> a developer's ledger, newest first */
    public function ledger(MarketplaceDeveloper $developer, int $limit = 200): array
    {
        return $this->operator->run(fn () => MarketplacePayable::query()->where('developer_id', $developer->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get()->all());
    }

    /** Staff paid $amount of $developer's $currency balance, by $reference. */
    public function markPaid(PlatformUser $staff, MarketplaceDeveloper $developer, string $currency, int $amount, string $reference): MarketplacePayable
    {
        $payout = $this->operator->run(fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($staff, $developer, $currency, $amount, $reference) {
            // Serialised per developer and currency, so two payouts never both pass the balance check.
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["marketplace_payout:{$developer->id}:{$currency}"]);
            $balance = (int) MarketplacePayable::query()->where('developer_id', $developer->id)->where('currency', $currency)
                ->selectRaw("coalesce(sum(case when kind = 'earning' then amount_minor else -amount_minor end), 0) as balance")->value('balance');

            if ($amount < 1 || $amount > $balance) {
                $message = __('marketplace.errors.payout_over_balance');

                throw new ApiException(422, 'payout_over_balance', $message, ['amount_minor' => [$message]]);
            }

            $payout = MarketplacePayable::create([
                'developer_id' => $developer->id,
                'kind' => MarketplacePayable::PAYOUT,
                'currency' => $currency,
                'amount_minor' => $amount,
                'reference' => mb_substr($reference, 0, 100),
                'recorded_by' => $staff->id,
                'occurred_at' => CarbonImmutable::now(),
            ]);

            // AUD-01: in the operator's chain, which owns the ledger.
            $this->auditor->record('core.marketplace_payout.create', $payout, null, [
                'developer_id' => $developer->id, 'currency' => $currency, 'amount_minor' => $amount, 'reference' => $payout->reference, 'staff_id' => $staff->id,
            ]);

            return $payout;
        }));

        $this->platformAudit->record('platform.marketplace.payout', $payout, null, [
            'developer_id' => $developer->id, 'currency' => $currency, 'amount_minor' => $amount, 'reference' => $payout->reference,
        ]);

        return $payout;
    }
}
