<?php

namespace Modules\FixedAssets\Documents;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\FixedAssets\Categories\Categories;
use Modules\FixedAssets\Depreciation\Spans;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Disposal;
use Modules\FixedAssets\Models\Transfer;
use Modules\FixedAssets\Postings\Postings;
use Modules\FixedAssets\Support\ClosedBooks;
use Modules\FixedAssets\Support\FaError;
use Modules\FixedAssets\Support\History;
use Modules\FixedAssets\Support\Numbers;
use Modules\FixedAssets\Support\Rates;

/**
 * FA-17: disposals (sale, scrap, write-off) through the
 * `fixed_assets.disposal` flow. Approved, a disposal posts at once when it
 * can (else it stays `approved` with the reason, and post() tries again):
 * depreciation from the day after the last charge to the disposal date
 * (pro rata by the company's convention), then the asset's cost and
 * accumulated depreciation out, the proceeds, the gain or loss and the
 * revaluation surplus released to retained earnings (FA-22).
 */
class Disposals
{
    public const KINDS = ['sale', 'scrap', 'write_off'];

    public function __construct(
        private readonly Numbers $numbers,
        private readonly WorkflowEngine $engine,
        private readonly Rates $rates,
        private readonly Spans $spans,
        private readonly ClosedBooks $books,
        private readonly Postings $postings,
        private readonly History $history,
    ) {}

    /** @param array<string, mixed> $data */
    public function request(Asset $asset, array $data, User $by): Disposal
    {
        try {
            return FaError::transaction(function () use ($asset, $data, $by) {
                $asset = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
                $company = Company::query()->findOrFail($asset->company_id);

                if ($asset->status !== Asset::ACTIVE) {
                    throw FaError::make('asset_not_active', status: 409);
                }

                if (Disposal::query()->where('asset_id', $asset->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                    throw FaError::make('disposal_pending', status: 409);
                }

                if (Transfer::query()->where('asset_id', $asset->id)->where('status', 'pending')->exists()) {
                    throw FaError::make('transfer_pending', status: 409);
                }

                $this->assertDate($asset, (string) $data['disposal_date']);
                $sale = $data['kind'] === 'sale';
                $proceeds = $sale ? (int) ($data['proceeds_minor'] ?? 0) : 0;

                if ($sale && $proceeds <= 0) {
                    throw FaError::make('proceeds_required', field: 'proceeds_minor');
                }

                $currency = $sale ? $this->rates->currency((string) ($data['currency'] ?? $company->base_currency)) : (string) $company->base_currency;
                $party = $sale ? ($data['party_id'] ?? null) : null;

                if ($party !== null) {
                    $row = Party::query()->find($party);

                    if ($row === null || ($row->company_id !== null && $row->company_id !== $company->id)) {
                        throw FaError::make('party_unknown', field: 'party_id');
                    }
                }

                $disposal = Disposal::query()->create([
                    'number' => $this->numbers->next(Numbers::DISPOSAL, $asset->company_id, $asset->branch_id, $asset->location_id),
                    'company_id' => $asset->company_id,
                    'asset_id' => $asset->id,
                    'kind' => $data['kind'],
                    'disposal_date' => $data['disposal_date'],
                    'currency' => $currency,
                    'proceeds_minor' => $proceeds,
                    'party_id' => $party,
                    'reason' => $data['reason'],
                    'status' => 'pending',
                    'requested_by' => $by->id,
                ]);

                $this->engine->start(DisposalType::KEY, $disposal->id, $by);

                return $disposal->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw FaError::make('disposal_pending', status: 409);
        }
    }

    /** WF-10: approved (posted when it can) or rejected. */
    public function completed(string $id, string $outcome, ?string $userId): void
    {
        $approved = FaError::transaction(function () use ($id, $outcome, $userId) {
            $disposal = Disposal::query()->whereKey($id)->lockForUpdate()->first();

            if ($disposal === null || $disposal->status !== 'pending') {
                return false;
            }

            $disposal->fill(['status' => $outcome === 'approved' ? 'approved' : 'rejected', 'decided_by' => $userId, 'decided_at' => CarbonImmutable::now()])->save();

            return $outcome === 'approved';
        });

        if ($approved) {
            try {
                $this->post(Disposal::query()->findOrFail($id), $userId === null ? null : User::query()->find($userId));
            } catch (ApiException $e) {
                // Kept approved with the reason; POST …/post tries again once it can (a closed period reopened, a run reversed).
                Disposal::query()->findOrFail($id)->fill(['post_error' => $e->errorCode])->save();
            }
        }
    }

    /** WF-11: the disposal's flow was cancelled. */
    public function cancelled(string $id): void
    {
        FaError::transaction(function () use ($id) {
            $disposal = Disposal::query()->whereKey($id)->lockForUpdate()->first();

            if ($disposal !== null && $disposal->status === 'pending') {
                $disposal->fill(['status' => 'cancelled', 'cancelled_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    public function cancel(Disposal $disposal, User $by, string $reason): Disposal
    {
        return FaError::transaction(function () use ($disposal, $by, $reason) {
            $disposal = Disposal::query()->whereKey($disposal->id)->lockForUpdate()->firstOrFail();

            if (! in_array($disposal->status, ['pending', 'approved'], true)) {
                throw FaError::make('document_not_open', status: 409);
            }

            $workflow = $this->engine->current(DisposalType::KEY, $disposal->id);

            if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                $this->engine->cancel($workflow, null, $reason);
            }

            $disposal->fill(['status' => 'cancelled', 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            return $disposal->refresh();
        });
    }

    /** Post an approved disposal (FA-17, FA-22). */
    public function post(Disposal $disposal, ?User $by): Disposal
    {
        return FaError::transaction(function () use ($disposal, $by) {
            $disposal = Disposal::query()->whereKey($disposal->id)->lockForUpdate()->firstOrFail();

            if ($disposal->status !== 'approved') {
                throw FaError::make('document_not_open', status: 409);
            }

            $asset = Asset::query()->whereKey($disposal->asset_id)->lockForUpdate()->firstOrFail();
            $company = Company::query()->findOrFail($asset->company_id);
            $date = $disposal->disposal_date;

            if ($asset->status !== Asset::ACTIVE) {
                throw FaError::make('asset_not_active', status: 409);
            }

            $this->assertDate($asset, $date->toDateString());
            $this->books->assertOpen($company->id, $date, 'disposal_date');
            $base = (string) $asset->base_currency;
            $fx = $this->rates->fx($company, (string) $disposal->currency, $date, 'currency');
            $proceeds = Rates::toBase($fx, (int) $disposal->proceeds_minor, (string) $disposal->currency);
            $before = History::book($asset);

            // Depreciation to the disposal date (FA-17), its own ledger source.
            $span = $this->spans->next($asset, $date, (string) Categories::settings($company->id)->convention, disposal: true);
            $depreciation = $span === null ? 0 : $span['charge']->amount;

            if ($depreciation > 0) {
                $asset->fill(['accumulated_minor' => (int) $asset->accumulated_minor + $depreciation]);
                $this->postings->raise(Postings::DEPRECIATION, 'disposal', $disposal->id, $company->id, $asset->branch_id, $asset->cost_centre_id, null, $date, $base,
                    ["depreciation.{$asset->category_id}" => $depreciation, "accumulated.{$asset->category_id}" => $depreciation], $disposal->number, null);
            }

            $cost = (int) $asset->book_cost_minor;
            $accumulated = (int) $asset->accumulated_minor;
            $impairment = (int) $asset->impairment_minor;
            $bookValue = $cost - $accumulated - $impairment;
            $gain = $proceeds - $bookValue;
            $surplus = (int) $asset->surplus_minor;
            $category = $asset->category_id;

            $this->postings->raise(Postings::DISPOSAL, 'disposal', $disposal->id, $company->id, $asset->branch_id, $asset->cost_centre_id, $disposal->party_id, $date, $base, [
                "cost.{$category}" => $cost,
                "accumulated.{$category}" => $accumulated + $impairment,
                'proceeds' => $proceeds,
                'gain' => max(0, $gain),
                'loss' => max(0, -$gain),
                'book_value' => $bookValue,
                'surplus' => $surplus,
            ], $disposal->number, null);

            $asset->fill([
                'status' => Asset::DISPOSED,
                'disposed_on' => $date->toDateString(),
                'depreciated_to' => $date->toDateString(),
                'surplus_minor' => 0,
            ])->save();

            $disposal->fill([
                'status' => 'posted',
                'fx' => $fx,
                'base_proceeds_minor' => $proceeds,
                'depreciation_minor' => $depreciation,
                'cost_minor' => $cost,
                'accumulated_minor' => $accumulated,
                'impairment_minor' => $impairment,
                'book_value_minor' => $bookValue,
                'gain_minor' => $gain,
                'surplus_minor' => $surplus,
                'post_error' => null,
                'posted_by' => $by?->id,
                'posted_at' => CarbonImmutable::now(),
            ])->save();

            $this->history->record($asset, 'disposed', $date, $before, [...History::book($asset), 'proceeds' => (string) $proceeds, 'gain' => (string) $gain],
                'disposal', $disposal->id, $disposal->reason, $by);

            return $disposal->refresh();
        });
    }

    /** FA-17: never before the asset's last charge (`period_depreciated`) nor before it entered service. */
    private function assertDate(Asset $asset, string $date): void
    {
        if (($asset->depreciated_to !== null && $date < $asset->depreciated_to->toDateString())
            || ($asset->in_service_on !== null && $date < $asset->in_service_on->toDateString())) {
            throw FaError::make('period_depreciated', ['date' => $asset->depreciated_to?->toDateString() ?? $asset->in_service_on?->toDateString()], 'disposal_date');
        }
    }
}
