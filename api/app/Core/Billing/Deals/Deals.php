<?php

namespace App\Core\Billing\Deals;

use App\Core\Audit\AuditContext;
use App\Core\Billing\Catalogue\Catalogue;
use App\Core\Billing\Catalogue\Pricing\CatalogueSnapshot;
use App\Core\Billing\Models\BillingDeal;
use App\Core\Billing\Models\OperatorAccount;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Operator\Operator;
use App\Core\Http\ApiException;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuditor;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADM-02: custom Enterprise deals of the current (customer) tenant.
 *
 * - The subscription engine prices with the deal in force on the pricing
 *   date (snapshot()); a period keeps the deal it was sold at (its price
 *   ids carry `deal:<id>`), like catalogue rows.
 * - Staff draft a deal (sales, finance: `platform.deals.manage`) and
 *   finance approves or rejects it (`platform.deals.approve`), never the
 *   drafter. Two approved deals never overlap. An approved deal is only
 *   ended (its end date set), never edited.
 * - Staff work enters the tenant's context; every step is audited in the
 *   tenant's chain (the model, as the system naming the staff member) and
 *   in the platform chain. The operator's account shows the deal in force.
 */
class Deals
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Catalogue $catalogue,
        private readonly AuditContext $audit,
        private readonly PlatformAuditor $platformAudit,
        private readonly Operator $operator,
    ) {}

    /** The approved deal covering $on, in the current tenant; null without a tenant. */
    public function inForce(CarbonImmutable $on): ?BillingDeal
    {
        if ($this->tenants->id() === null) {
            return null;
        }

        return BillingDeal::query()->inForceOn($on)->orderByDesc('effective_from')->first();
    }

    /**
     * The catalogue snapshot priced for the current tenant: $snapshot with
     * the deal the period was sold at (a `deal:<id>` in $locked) or, without
     * one, the deal in force on $on.
     *
     * @param  array<string, string>  $locked
     */
    public function apply(CatalogueSnapshot $snapshot, array $locked, CarbonImmutable $on): CatalogueSnapshot
    {
        if ($this->tenants->id() === null) {
            return $snapshot;
        }

        $lockedDeal = collect($locked)->first(fn ($id) => is_string($id) && str_starts_with($id, 'deal:'));
        $deal = $lockedDeal !== null
            ? BillingDeal::query()->whereKey(Str::after($lockedDeal, 'deal:'))->whereIn('status', [BillingDeal::APPROVED, BillingDeal::ENDED])->first()
            : $this->inForce($on);

        return $deal === null || $deal->country !== $snapshot->country ? $snapshot : $snapshot->withDeal($deal->id, $deal->lines);
    }

    /**
     * Draft a deal for $tenant (pending approval).
     *
     * @param  array{bundle?: ?string, lines: list<array<string, mixed>>, effective_from: string, effective_to?: ?string, reason: string}  $data
     */
    public function draft(string $tenant, array $data, PlatformUser $staff): BillingDeal
    {
        $deal = $this->asStaff($tenant, $staff, function () use ($data, $staff) {
            $subscription = Subscription::query()->first() ?? throw new ApiException(422, 'no_subscription', __('billing.errors.no_subscription'));
            $snapshot = $this->catalogue->snapshot($subscription->country);
            $lines = [];

            foreach ($data['lines'] as $i => $line) {
                $known = $line['type'] === 'bundle' ? $snapshot->bundle($line['key']) : $snapshot->module($line['key']);

                if ($known === null) {
                    throw new ApiException(422, 'deal_unknown_item', __('billing.deals.unknown_item'), ["lines.{$i}.key" => [__('billing.deals.unknown_item')]]);
                }

                $lines[] = array_filter([
                    'type' => $line['type'],
                    'key' => $line['key'],
                    'amount' => (int) $line['amount'],
                    'extra_outlet_amount' => isset($line['extra_outlet_amount']) ? (int) $line['extra_outlet_amount'] : null,
                    'minimum_amount' => isset($line['minimum_amount']) ? (int) $line['minimum_amount'] : null,
                ], fn ($v) => $v !== null);
            }

            return BillingDeal::query()->create([
                'country' => $subscription->country,
                'currency' => $subscription->currency,
                'bundle' => $data['bundle'] ?? null,
                'lines' => $lines,
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'reason' => $data['reason'],
                'created_by_staff' => $staff->id,
                'created_by_name' => $staff->name,
            ]);
        });

        $this->platformAudit->record('platform.deal.draft', null, null, ['tenant_id' => $tenant, 'deal_id' => $deal->id, 'lines' => $deal->lines, 'effective_from' => $deal->effective_from->toDateString(), 'effective_to' => $deal->effective_to?->toDateString(), 'reason' => $deal->reason]);

        return $deal;
    }

    /** Finance approves a pending deal it did not draft; deals never overlap. */
    public function approve(string $tenant, string $dealId, PlatformUser $staff, ?string $note): BillingDeal
    {
        $deal = $this->asStaff($tenant, $staff, function () use ($dealId, $staff, $note) {
            $deal = $this->pending($dealId, $staff);
            // Serialise approvals of this tenant's deals, then refuse an overlap.
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['billing_deals:'.$this->tenants->require()]);
            $from = $deal->effective_from->toDateString();
            $to = $deal->effective_to?->toDateString() ?? '9999-12-31';
            $overlap = BillingDeal::query()->whereIn('status', [BillingDeal::APPROVED, BillingDeal::ENDED])->where('id', '<>', $deal->id)
                ->where('effective_from', '<=', $to)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
                ->exists();

            if ($overlap) {
                throw new ApiException(422, 'deal_overlaps', __('billing.deals.overlaps'));
            }

            $deal->fill(['status' => BillingDeal::APPROVED, 'decided_by_staff' => $staff->id, 'decided_by_name' => $staff->name, 'decided_at' => CarbonImmutable::now(), 'decision_note' => $note])->save();

            return $deal;
        });

        $this->platformAudit->record('platform.deal.approve', null, ['status' => BillingDeal::PENDING], ['tenant_id' => $tenant, 'deal_id' => $deal->id, 'status' => $deal->status, 'note' => $note]);
        $this->syncAccount($tenant);

        return $deal;
    }

    public function reject(string $tenant, string $dealId, PlatformUser $staff, ?string $note): BillingDeal
    {
        $deal = $this->asStaff($tenant, $staff, function () use ($dealId, $staff, $note) {
            $deal = $this->pending($dealId, $staff);
            $deal->fill(['status' => BillingDeal::REJECTED, 'decided_by_staff' => $staff->id, 'decided_by_name' => $staff->name, 'decided_at' => CarbonImmutable::now(), 'decision_note' => $note])->save();

            return $deal;
        });

        $this->platformAudit->record('platform.deal.reject', null, ['status' => BillingDeal::PENDING], ['tenant_id' => $tenant, 'deal_id' => $deal->id, 'status' => $deal->status, 'note' => $note]);

        return $deal;
    }

    /** End an approved deal on $on (its last day); a period already sold keeps it until it ends. */
    public function end(string $tenant, string $dealId, CarbonImmutable $on, PlatformUser $staff): BillingDeal
    {
        $deal = $this->asStaff($tenant, $staff, function () use ($dealId, $on, $staff) {
            $deal = BillingDeal::query()->whereKey($dealId)->lockForUpdate()->first() ?? abort(404);

            if ($deal->status !== BillingDeal::APPROVED) {
                throw new ApiException(422, 'deal_not_approved', __('billing.deals.not_approved'));
            }

            $last = $on->toDateString() < $deal->effective_from->toDateString() ? $deal->effective_from : $on;

            if ($deal->effective_to !== null && $deal->effective_to->toDateString() < $last->toDateString()) {
                $last = CarbonImmutable::instance($deal->effective_to);
            }

            $deal->fill(['status' => BillingDeal::ENDED, 'effective_to' => $last->toDateString(), 'ended_by_staff' => $staff->id, 'ended_at' => CarbonImmutable::now()])->save();

            return $deal;
        });

        $this->platformAudit->record('platform.deal.end', null, ['status' => BillingDeal::APPROVED], ['tenant_id' => $tenant, 'deal_id' => $deal->id, 'effective_to' => $deal->effective_to?->toDateString()]);
        $this->syncAccount($tenant);

        return $deal;
    }

    /** @return list<BillingDeal> the tenant's deals, newest first */
    public function list(string $tenant): array
    {
        return $this->tenants->run($tenant, fn () => BillingDeal::query()->orderByDesc('created_at')->get()->all());
    }

    private function pending(string $dealId, PlatformUser $staff): BillingDeal
    {
        $deal = BillingDeal::query()->whereKey($dealId)->lockForUpdate()->first() ?? abort(404);

        if ($deal->status !== BillingDeal::PENDING) {
            throw new ApiException(422, 'deal_not_pending', __('billing.deals.not_pending'));
        }

        if ($deal->created_by_staff === $staff->id) {
            throw new ApiException(403, 'deal_own_draft', __('billing.deals.own_draft'));
        }

        return $deal;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function asStaff(string $tenant, PlatformUser $staff, callable $fn): mixed
    {
        return $this->tenants->run($tenant, fn () => $this->audit->actingAs(null, ['platform_user_id' => $staff->id, 'staff_name' => $staff->name], fn () => DB::connection(TenantContext::CONNECTION)->transaction($fn)));
    }

    /** The operator's account shows the deal in force today. */
    private function syncAccount(string $tenant): void
    {
        $deal = $this->tenants->run($tenant, fn () => $this->inForce(CarbonImmutable::now()));
        $summary = $deal === null ? null : ['id' => $deal->id, 'effective_from' => $deal->effective_from->toDateString(), 'effective_to' => $deal->effective_to?->toDateString()];

        if ($this->operator->id() !== null) {
            $this->operator->run(fn () => OperatorAccount::query()->where('customer_tenant_id', $tenant)->update(['deal' => $summary === null ? null : json_encode($summary)]));
        }
    }
}
