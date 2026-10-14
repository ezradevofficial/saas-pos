<?php

namespace Modules\Procurement\Documents;

use App\Core\CustomFields\CustomFieldWriter;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Procurement\CustomFields\RequisitionEntity;
use Modules\Procurement\Models\Requisition;
use Modules\Procurement\Models\RequisitionLine;
use Modules\Procurement\Support\LineResolver;
use Modules\Procurement\Support\ProcurementError;
use Modules\Procurement\Support\Tx;

/**
 * PRC-02: purchase requisitions.
 *
 * - create() and update(): a draft at a branch (a location of it and a
 *   department of its company optional), lines checked against the
 *   company (LineResolver), the estimated total = Σ round_half_up(qty ×
 *   estimated unit price), custom fields (CF-01).
 * - submit(): numbered (NUM-01) and the `procurement.requisition` flow
 *   started, in one transaction (pending).
 * - completed() / cancelled(): the flow's end (approved, rejected) or
 *   cancellation (WF-10, WF-11).
 * - cancel(): a draft or pending requisition, its flow cancelled.
 * - ordered(): marked when an RFQ, a tender or a PO takes it on.
 */
class Requisitions
{
    public function __construct(
        private readonly LineResolver $resolver,
        private readonly DocumentNumbers $numbers,
        private readonly WorkflowEngine $engine,
        private readonly CustomFieldWriter $customFields,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $by): Requisition
    {
        return Tx::run(function () use ($data, $by) {
            $branch = Branch::query()->with('company')->findOrFail($data['branch_id']);
            $requisition = new Requisition([
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'status' => Requisition::DRAFT,
                'requested_by' => $by->id,
                'currency' => $this->resolver->currency($data['currency'] ?? $branch->company->base_currency),
            ]);
            $this->fill($requisition, $branch, $data);
            $this->customFields->fill(RequisitionEntity::KEY, $requisition, $data['custom'] ?? null, creating: true);
            $requisition->save();
            $this->customFields->saved(RequisitionEntity::KEY, $requisition);
            $this->writeLines($requisition, $data['lines']);

            return $requisition->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Requisition $requisition, array $data): Requisition
    {
        return Tx::run(function () use ($requisition, $data) {
            $requisition = Requisition::query()->whereKey($requisition->id)->lockForUpdate()->firstOrFail();

            if ($requisition->status !== Requisition::DRAFT) {
                throw ProcurementError::make('document_not_draft', status: 409);
            }

            $branch = Branch::query()->with('company')->findOrFail($requisition->branch_id);

            if (array_key_exists('currency', $data)) {
                $requisition->currency = $this->resolver->currency((string) $data['currency']);
            }

            $this->fill($requisition, $branch, $data);
            $this->customFields->fill(RequisitionEntity::KEY, $requisition, $data['custom'] ?? null, creating: false);
            $requisition->save();
            $this->customFields->saved(RequisitionEntity::KEY, $requisition);

            if (isset($data['lines'])) {
                RequisitionLine::query()->where('requisition_id', $requisition->id)->delete();
                $this->writeLines($requisition, $data['lines']);
            }

            return $requisition->refresh();
        });
    }

    public function submit(Requisition $requisition, User $by): Requisition
    {
        return Tx::run(function () use ($requisition, $by) {
            $requisition = Requisition::query()->whereKey($requisition->id)->lockForUpdate()->firstOrFail();

            if ($requisition->status !== Requisition::DRAFT) {
                throw ProcurementError::make('document_not_draft', status: 409);
            }

            if (! $requisition->lines()->exists()) {
                throw ProcurementError::make('document_empty', field: 'lines');
            }

            $requisition->fill([
                'number' => $requisition->number ?? $this->numbers->next(DocumentNumbers::REQUISITION, $requisition->company_id, $requisition->branch_id),
                'status' => Requisition::PENDING,
                'submitted_at' => CarbonImmutable::now(),
            ])->save();
            $this->engine->start(RequisitionType::KEY, $requisition->id, $by);

            return $requisition->refresh();
        });
    }

    /** WF-10: the flow approved or rejected the requisition. */
    public function completed(string $requisitionId, string $outcome, ?string $userId): void
    {
        Tx::run(function () use ($requisitionId, $outcome, $userId) {
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
        Tx::run(function () use ($requisitionId) {
            $requisition = Requisition::query()->whereKey($requisitionId)->lockForUpdate()->first();

            if ($requisition !== null && $requisition->status === Requisition::PENDING) {
                $requisition->fill(['status' => Requisition::CANCELLED, 'cancelled_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    public function cancel(Requisition $requisition, User $by, string $reason): Requisition
    {
        return Tx::run(function () use ($requisition, $by, $reason) {
            $requisition = Requisition::query()->whereKey($requisition->id)->lockForUpdate()->firstOrFail();

            if (! in_array($requisition->status, [Requisition::DRAFT, Requisition::PENDING], true)) {
                throw ProcurementError::make('document_not_open', status: 409);
            }

            $workflow = $this->engine->current(RequisitionType::KEY, $requisition->id);

            if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                $this->engine->cancel($workflow, null, $reason);
            }

            $requisition->fill(['status' => Requisition::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            return $requisition->refresh();
        });
    }

    /** An approved requisition, locked, that an RFQ, a tender or a PO takes on; refused otherwise. */
    public function approved(string $companyId, string $requisitionId, string $field = 'requisition_id'): Requisition
    {
        $requisition = Requisition::query()->where('company_id', $companyId)->whereKey($requisitionId)->lockForUpdate()->first();

        if ($requisition === null || $requisition->status !== Requisition::APPROVED) {
            throw ProcurementError::make('requisition_not_approved', field: $field);
        }

        return $requisition;
    }

    public function markOrdered(Requisition $requisition): void
    {
        if ($requisition->status === Requisition::APPROVED) {
            $requisition->fill(['status' => Requisition::ORDERED])->save();
        }
    }

    /** @param array<string, mixed> $data */
    private function fill(Requisition $requisition, Branch $branch, array $data): void
    {
        if (array_key_exists('location_id', $data)) {
            $location = $data['location_id'] === null ? null : Location::query()->where('branch_id', $branch->id)->whereNull('archived_at')->find($data['location_id']);

            if ($data['location_id'] !== null && $location === null) {
                throw ProcurementError::make('location_not_at_branch', field: 'location_id');
            }

            $requisition->location_id = $location?->id;
        }

        if (array_key_exists('department_id', $data)) {
            $requisition->department_id = $this->resolver->department($branch->company_id, $data['department_id']);
        }

        foreach (['purpose', 'needed_by'] as $field) {
            if (array_key_exists($field, $data)) {
                $requisition->{$field} = $data[$field];
            }
        }
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(Requisition $requisition, array $lines): void
    {
        $total = BigDecimal::zero();

        foreach (array_values($lines) as $index => $input) {
            $line = $this->resolver->line($requisition->company_id, $input, "lines.{$index}");
            $price = isset($input['estimated_unit_price_minor']) ? (int) $input['estimated_unit_price_minor'] : null;

            RequisitionLine::query()->create([
                'requisition_id' => $requisition->id,
                'line_no' => $index + 1,
                'item_id' => $line['item_id'],
                'description' => $line['description'],
                'uom_id' => $line['uom_id'],
                'qty' => $line['qty'],
                'estimated_unit_price_minor' => $price,
                'cost_centre_id' => $line['cost_centre_id'],
                'project_id' => $line['project_id'],
                'needed_by' => $input['needed_by'] ?? null,
            ]);

            if ($price !== null) {
                $total = $total->plus(BigDecimal::of($line['qty'])->multipliedBy($price)->toScale(0, RoundingMode::HalfUp));
            }
        }

        $requisition->fill(['estimated_total_minor' => (string) $total])->save();
    }
}
