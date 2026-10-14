<?php

namespace Modules\Stores\Documents;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Modules\Stores\Models\Requisition;
use Modules\Stores\Models\RequisitionLine;
use Modules\Stores\Stock\Errors;
use Modules\Stores\Stock\Lines;
use Modules\Stores\Stock\Places;
use Modules\Stores\Stock\Qty;

/**
 * STR-02 to STR-04: requisitions.
 *
 * - create(): a store, a department (and cost centre) of its company and
 *   checked lines; numbered and its `stores.requisition` flow started in
 *   one transaction (`pending`).
 * - completed(), cancelled(): WF-10, WF-11: the flow approved, rejected or
 *   was cancelled.
 * - cancel(): pending (its flow cancelled) or approved with nothing issued.
 * - close(): approved or partly issued, what is still open is not issued.
 */
class Requisitions
{
    public function __construct(
        private readonly Places $places,
        private readonly Lines $lines,
        private readonly WorkflowEngine $engine,
        private readonly DocumentNumbers $numbers,
    ) {}

    /** @param array{location_id: string, department_id: string, cost_centre_id?: ?string, needed_by?: ?string, note?: ?string, lines: list<array<string, mixed>>} $data */
    public function create(array $data, User $by): Requisition
    {
        $place = $this->places->store($data['location_id']);
        $department = Department::query()->whereKey($data['department_id'])->whereNull('archived_at')->first();

        if ($department === null || $department->company_id !== $place['company_id']) {
            throw Errors::make(422, 'department_other_company', field: 'department_id');
        }

        $costCentreId = $data['cost_centre_id'] ?? null;

        if ($costCentreId !== null) {
            $costCentre = CostCentre::query()->whereKey($costCentreId)->whereNull('archived_at')->first();

            if ($costCentre === null || $costCentre->company_id !== $place['company_id']) {
                throw Errors::make(422, 'cost_centre_other_company', field: 'cost_centre_id');
            }
        }

        $lines = $this->lines->resolve($data['lines'], $place['company_id'], $by);

        return Errors::transaction(function () use ($data, $by, $place, $department, $costCentreId, $lines) {
            $requisition = Requisition::create([
                'number' => $this->numbers->next(RequisitionType::KEY, $place['company_id'], $place['branch_id'], $data['location_id']),
                'company_id' => $place['company_id'],
                'branch_id' => $place['branch_id'],
                'location_id' => $data['location_id'],
                'department_id' => $department->id,
                'cost_centre_id' => $costCentreId,
                'needed_by' => $data['needed_by'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => Requisition::PENDING,
                'requested_by' => $by->id,
            ]);

            foreach ($lines as $index => $line) {
                RequisitionLine::create([
                    'requisition_id' => $requisition->id,
                    'line_no' => $index + 1,
                    'item_id' => $line['item']->id,
                    'uom_id' => $line['uom_id'],
                    'qty' => (string) Qty::of($line['qty']),
                    'qty_base' => (string) $line['qty_base'],
                    'note' => $data['lines'][$index]['note'] ?? null,
                ]);
            }

            $this->engine->start(RequisitionType::KEY, $requisition->id, $by);

            return $requisition->refresh();
        });
    }

    /** WF-10: the flow ended: approved or rejected. */
    public function completed(string $requisitionId, string $outcome, ?string $userId): void
    {
        Errors::transaction(function () use ($requisitionId, $outcome, $userId) {
            $requisition = Requisition::query()->whereKey($requisitionId)->lockForUpdate()->first();

            if ($requisition !== null && $requisition->status === Requisition::PENDING) {
                $requisition->fill([
                    'status' => $outcome === 'approved' ? Requisition::APPROVED : Requisition::REJECTED,
                    'decided_by' => $userId,
                    'decided_at' => CarbonImmutable::now(),
                ])->save();
            }
        });
    }

    /** WF-11: the requisition's flow was cancelled. */
    public function cancelled(string $requisitionId): void
    {
        Errors::transaction(function () use ($requisitionId) {
            $requisition = Requisition::query()->whereKey($requisitionId)->lockForUpdate()->first();

            if ($requisition !== null && $requisition->status === Requisition::PENDING) {
                $requisition->fill(['status' => Requisition::CANCELLED, 'cancelled_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    public function cancel(Requisition $requisition, User $by, string $reason): Requisition
    {
        return Errors::transaction(function () use ($requisition, $by, $reason) {
            $requisition = Requisition::query()->whereKey($requisition->id)->lockForUpdate()->firstOrFail();
            $untouched = $requisition->status === Requisition::APPROVED && ! $requisition->lines()->where('qty_issued', '>', 0)->exists();

            if ($requisition->status !== Requisition::PENDING && ! $untouched) {
                throw Errors::make(409, 'document_not_open');
            }

            $workflow = $this->engine->current(RequisitionType::KEY, $requisition->id);

            if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                $this->engine->cancel($workflow, null, $reason);
            }

            $requisition->fill(['status' => Requisition::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            return $requisition->refresh();
        });
    }

    public function close(Requisition $requisition, User $by, string $reason): Requisition
    {
        return Errors::transaction(function () use ($requisition, $by, $reason) {
            $requisition = Requisition::query()->whereKey($requisition->id)->lockForUpdate()->firstOrFail();

            if (! in_array($requisition->status, Requisition::ISSUABLE, true)) {
                throw Errors::make(409, 'document_not_open');
            }

            $requisition->fill(['status' => Requisition::CLOSED, 'closed_by' => $by->id, 'closed_at' => CarbonImmutable::now(), 'close_reason' => $reason])->save();

            return $requisition->refresh();
        });
    }
}
