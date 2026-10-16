<?php

namespace Modules\FixedAssets\Documents;

use App\Core\Identity\Models\User;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\FixedAssets\Assets\Custodians;
use Modules\FixedAssets\Assets\Places;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Disposal;
use Modules\FixedAssets\Models\Transfer;
use Modules\FixedAssets\Support\FaError;
use Modules\FixedAssets\Support\History;
use Modules\FixedAssets\Support\Numbers;

/**
 * FA-14: transfers of an active asset to another location of its company,
 * custodian or cost centre, through the `fixed_assets.transfer` flow.
 * One open transfer per asset (`transfer_pending`, also a unique index);
 * approved, it applies at once and the history records the move. Posts
 * nothing (decision 9).
 */
class Transfers
{
    public function __construct(
        private readonly Places $places,
        private readonly Custodians $custodians,
        private readonly Numbers $numbers,
        private readonly WorkflowEngine $engine,
        private readonly History $history,
    ) {}

    /** @param array<string, mixed> $data */
    public function request(Asset $asset, array $data, User $by): Transfer
    {
        try {
            return FaError::transaction(function () use ($asset, $data, $by) {
                $asset = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

                if ($asset->status !== Asset::ACTIVE) {
                    throw FaError::make('asset_not_active', status: 409);
                }

                if (Transfer::query()->where('asset_id', $asset->id)->where('status', 'pending')->exists()) {
                    throw FaError::make('transfer_pending', status: 409);
                }

                if (Disposal::query()->where('asset_id', $asset->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                    throw FaError::make('disposal_pending', status: 409);
                }

                $to = isset($data['to_location_id']) ? $this->places->location((string) $data['to_location_id'], $asset->company_id, 'to_location_id')
                    : ['branch_id' => $asset->branch_id, 'location_id' => $asset->location_id];
                $custodian = array_key_exists('to_custodian_user_id', $data) || array_key_exists('to_custodian_employee_id', $data)
                    ? $this->custodians->resolve($data, $asset->company_id, 'to_custodian')
                    : ['custodian_user_id' => $asset->custodian_user_id, 'custodian_employee_id' => $asset->custodian_employee_id];
                $costCentre = array_key_exists('to_cost_centre_id', $data)
                    ? $this->places->costCentre($data['to_cost_centre_id'], $asset->company_id, 'to_cost_centre_id')
                    : $asset->cost_centre_id;

                if ($to['location_id'] === $asset->location_id && $custodian['custodian_user_id'] === $asset->custodian_user_id
                    && $custodian['custodian_employee_id'] === $asset->custodian_employee_id && $costCentre === $asset->cost_centre_id) {
                    throw FaError::make('transfer_nothing', field: 'to_location_id');
                }

                $transfer = Transfer::query()->create([
                    'number' => $this->numbers->next(Numbers::TRANSFER, $asset->company_id, $asset->branch_id, $asset->location_id),
                    'company_id' => $asset->company_id,
                    'asset_id' => $asset->id,
                    'from_branch_id' => $asset->branch_id,
                    'from_location_id' => $asset->location_id,
                    'to_branch_id' => $to['branch_id'],
                    'to_location_id' => $to['location_id'],
                    'from_custodian_user_id' => $asset->custodian_user_id,
                    'from_custodian_employee_id' => $asset->custodian_employee_id,
                    'to_custodian_user_id' => $custodian['custodian_user_id'],
                    'to_custodian_employee_id' => $custodian['custodian_employee_id'],
                    'from_cost_centre_id' => $asset->cost_centre_id,
                    'to_cost_centre_id' => $costCentre,
                    'transfer_date' => $data['transfer_date'],
                    'reason' => $data['reason'],
                    'status' => 'pending',
                    'requested_by' => $by->id,
                ]);

                $this->engine->start(TransferType::KEY, $transfer->id, $by);

                return $transfer->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw FaError::make('transfer_pending', status: 409);
        }
    }

    /** WF-10: the flow ended: approved (applied at once) or rejected. */
    public function completed(string $id, string $outcome, ?string $userId): void
    {
        FaError::transaction(function () use ($id, $outcome, $userId) {
            $transfer = Transfer::query()->whereKey($id)->lockForUpdate()->first();

            if ($transfer === null || $transfer->status !== 'pending') {
                return;
            }

            $transfer->fill(['status' => $outcome === 'approved' ? 'applied' : 'rejected', 'decided_by' => $userId, 'decided_at' => CarbonImmutable::now()])->save();

            if ($outcome !== 'approved') {
                return;
            }

            $asset = Asset::query()->whereKey($transfer->asset_id)->lockForUpdate()->firstOrFail();
            $before = self::place($asset);
            $asset->fill([
                'branch_id' => $transfer->to_branch_id,
                'location_id' => $transfer->to_location_id,
                'custodian_user_id' => $transfer->to_custodian_user_id,
                'custodian_employee_id' => $transfer->to_custodian_employee_id,
                'cost_centre_id' => $transfer->to_cost_centre_id,
            ])->save();
            $this->history->record($asset, 'transferred', $transfer->transfer_date, $before, self::place($asset), 'transfer', $transfer->id, $transfer->reason,
                $userId === null ? null : User::query()->find($userId));
        });
    }

    /** WF-11: the transfer's flow was cancelled. */
    public function cancelled(string $id): void
    {
        FaError::transaction(function () use ($id) {
            $transfer = Transfer::query()->whereKey($id)->lockForUpdate()->first();

            if ($transfer !== null && $transfer->status === 'pending') {
                $transfer->fill(['status' => 'cancelled', 'cancelled_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    public function cancel(Transfer $transfer, User $by, string $reason): Transfer
    {
        return FaError::transaction(function () use ($transfer, $by, $reason) {
            $transfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($transfer->status !== 'pending') {
                throw FaError::make('document_not_open', status: 409);
            }

            $workflow = $this->engine->current(TransferType::KEY, $transfer->id);

            if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                $this->engine->cancel($workflow, null, $reason);
            }

            $transfer->fill(['status' => 'cancelled', 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            return $transfer->refresh();
        });
    }

    private static function place(Asset $asset): array
    {
        return [
            'branch_id' => $asset->branch_id,
            'location_id' => $asset->location_id,
            'custodian_user_id' => $asset->custodian_user_id,
            'custodian_employee_id' => $asset->custodian_employee_id,
            'cost_centre_id' => $asset->cost_centre_id,
        ];
    }
}
