<?php

namespace App\Core\CustomForms;

use App\Core\Audit\Auditor;
use App\Core\CustomFields\CustomFieldDefinitions;
use App\Core\CustomFields\CustomFieldWriter;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * CF-04, CF-05: writing custom form records. The caller (a Form Request)
 * has checked permissions and validated the input (header and line custom
 * fields through CustomFieldValidator).
 *
 * - create(): the record at its place, numbered (NUM-01) with its type's
 *   number type, its header values (defaults, formulas), its lines and the
 *   attachments it names; then submitted when asked. One transaction.
 * - update(): a draft only. Only the keys given change; lines, when given,
 *   replace the draft's lines (the previous lines stay in the audit entry,
 *   `core.custom_form.lines`).
 * - submit(): with a workflow, the record's flow starts (pending);
 *   without, the record is submitted.
 * - Totals (CF-05): every number and money line field is summed into
 *   `totals` (money as minor units with its currency, one currency per
 *   field); the first money total (else the first money header value) is
 *   the record's amount, for lists and the approvals inbox.
 */
class CustomForms
{
    /** Line field types with a total. */
    public const TOTALLED = ['number', 'money'];

    public function __construct(
        private readonly CustomFieldWriter $writer,
        private readonly CustomFieldDefinitions $definitions,
        private readonly Numbering $numbering,
        private readonly WorkflowEngine $engine,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{company_id: string, branch_id: ?string, location_id: ?string, custom: ?array, lines: ?list<array>, attachments: ?list<string>, submit: bool}  $data
     */
    public function create(CustomFormType $type, array $data, User $by): CustomFormRecord
    {
        return $this->transaction(function () use ($type, $data, $by) {
            $record = new CustomFormRecord([
                'type_id' => $type->id,
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'status' => CustomFormRecord::DRAFT,
                'created_by' => $by->id,
            ]);
            $record->setRelation('type', $type);
            $this->writer->fill($type->entity(), $record, $data['custom'] ?? null, creating: true);

            $context = new NumberContext(
                Company::query()->findOrFail($record->company_id),
                $record->branch_id === null ? null : Branch::query()->findOrFail($record->branch_id),
                $record->location_id === null ? null : Location::query()->findOrFail($record->location_id),
            );
            $record->number = $this->numbering->next($type->number_type, $context)->number;
            $this->applyTotals($type, $record, []);
            $record->save();
            $this->writer->saved($type->entity(), $record);

            if ($type->has_lines) {
                $lines = $this->writeLines($type, $record, $data['lines'] ?? []);
                $this->applyTotals($type, $record, $lines);
                $record->save();
            }

            $this->attach($type, $record, $data['attachments'] ?? []);

            if ($data['submit'] ?? false) {
                $this->submit($record, $by);
            }

            return $record->refresh();
        });
    }

    /** @param array{custom?: ?array, lines?: ?list<array>, attachments?: ?list<string>, submit?: bool} $data */
    public function update(CustomFormRecord $record, array $data, User $by): CustomFormRecord
    {
        return $this->transaction(function () use ($record, $data, $by) {
            $record = CustomFormRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $type = $record->type;
            $this->assertDraft($record);

            $this->writer->fill($type->entity(), $record, $data['custom'] ?? null, creating: false);

            if ($type->has_lines && array_key_exists('lines', $data) && $data['lines'] !== null) {
                $before = $record->lines()->get()->map(fn (CustomFormLine $line) => $line->custom)->all();
                // A draft's lines are replaced as a whole; the audit entry keeps the previous ones.
                $record->lines()->delete();
                $lines = $this->writeLines($type, $record, $data['lines']);
                $this->auditor->record('core.custom_form.lines', $record, ['lines' => $before], ['lines' => array_map(fn (CustomFormLine $line) => $line->custom, $lines)]);
            } else {
                $lines = $record->lines()->get()->all();
            }

            $this->applyTotals($type, $record, $lines);
            $record->save();
            $this->writer->saved($type->entity(), $record);
            $this->attach($type, $record, $data['attachments'] ?? []);

            if ($data['submit'] ?? false) {
                $this->submit($record, $by);
            }

            return $record->refresh();
        });
    }

    public function submit(CustomFormRecord $record, User $by): CustomFormRecord
    {
        return $this->transaction(function () use ($record, $by) {
            $record = CustomFormRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($record);
            $type = $record->type;

            $record->fill([
                'status' => $type->workflow ? CustomFormRecord::PENDING : CustomFormRecord::SUBMITTED,
                'submitted_at' => CarbonImmutable::now(),
            ])->save();

            if ($type->workflow) {
                $this->engine->start($type->documentType(), $record->id, $by);
            }

            return $record->refresh();
        });
    }

    /** A draft is cancelled; a pending record's flow is cancelled (WF-11), which cancels the record. */
    public function cancel(CustomFormRecord $record, User $by, string $reason): CustomFormRecord
    {
        return $this->transaction(function () use ($record, $by, $reason) {
            $record = CustomFormRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            if (! in_array($record->status, [CustomFormRecord::DRAFT, CustomFormRecord::PENDING], true)) {
                throw new ApiException(422, 'custom_form_not_open', __('core.custom_form.errors.not_open'));
            }

            if ($record->status === CustomFormRecord::PENDING) {
                $workflow = $this->engine->current($record->type->documentType(), $record->id);

                if ($workflow !== null && $workflow->status === 'running') {
                    $this->engine->cancel($workflow, $by, $reason);
                }
            }

            $record->fill(['status' => CustomFormRecord::CANCELLED, 'decided_at' => CarbonImmutable::now()])->save();

            return $record->refresh();
        });
    }

    /** WF-10: the record's flow ended; `approved` approves it, any other outcome rejects it. */
    public function completed(CustomFormRecord $record, string $outcome): void
    {
        if ($record->status !== CustomFormRecord::PENDING) {
            return;
        }

        $record->fill([
            'status' => $outcome === 'approved' ? CustomFormRecord::APPROVED : CustomFormRecord::REJECTED,
            'decided_at' => CarbonImmutable::now(),
        ])->save();
    }

    public function flowCancelled(CustomFormRecord $record): void
    {
        if ($record->status === CustomFormRecord::PENDING) {
            $record->fill(['status' => CustomFormRecord::CANCELLED, 'decided_at' => CarbonImmutable::now()])->save();
        }
    }

    /**
     * The totals of the line fields with one (CF-05).
     *
     * @param  list<CustomFormLine>  $lines
     * @return array<string, mixed> field key => decimal string, or {amount_minor, currency}
     */
    public function totals(CustomFormType $type, array $lines): array
    {
        $totals = [];

        foreach ($this->definitions->active($type->lineEntity()) as $field) {
            if (! in_array($field->type, self::TOTALLED, true)) {
                continue;
            }

            $values = array_values(array_filter(array_map(fn (CustomFormLine $line) => $line->custom[$field->key] ?? null, $lines), fn ($value) => $value !== null));

            if ($field->type === 'number') {
                $sum = BigDecimal::zero();

                foreach ($values as $value) {
                    $sum = $sum->plus(BigDecimal::of((string) $value));
                }

                $totals[$field->key] = (string) $sum->stripTrailingZeros();

                continue;
            }

            $currencies = array_values(array_unique(array_map(fn (array $value) => (string) $value['currency'], $values)));

            if (count($currencies) !== 1) {
                $totals[$field->key] = null;

                continue;
            }

            $sum = BigDecimal::zero();

            foreach ($values as $value) {
                $sum = $sum->plus(BigDecimal::of((string) $value['amount_minor']));
            }

            $totals[$field->key] = ['amount_minor' => (string) $sum, 'currency' => $currencies[0]];
        }

        return $totals;
    }

    /** @param list<CustomFormLine> $lines */
    private function applyTotals(CustomFormType $type, CustomFormRecord $record, array $lines): void
    {
        $totals = $type->has_lines ? $this->totals($type, $lines) : [];
        $amount = null;

        foreach ($totals as $total) {
            if (is_array($total)) {
                $amount = $total;

                break;
            }
        }

        if ($amount === null) {
            foreach ($this->definitions->active($type->entity()) as $field) {
                $value = $record->custom[$field->key] ?? null;

                if ($field->type === 'money' && is_array($value)) {
                    $amount = $value;

                    break;
                }
            }
        }

        $record->totals = $totals === [] ? new \stdClass : $totals;
        $record->amount_minor = $amount === null ? null : (string) $amount['amount_minor'];
        $record->amount_currency = $amount['currency'] ?? null;
    }

    /**
     * @param  list<array{custom?: ?array}>  $input
     * @return list<CustomFormLine>
     */
    private function writeLines(CustomFormType $type, CustomFormRecord $record, array $input): array
    {
        $lines = [];

        foreach (array_values($input) as $position => $line) {
            $model = new CustomFormLine(['record_id' => $record->id, 'position' => $position + 1]);
            $this->writer->fill($type->lineEntity(), $model, $line['custom'] ?? [], creating: true);
            $model->save();
            $this->writer->saved($type->lineEntity(), $model);
            $lines[] = $model;
        }

        return $lines;
    }

    /** @param list<string> $ids attachments uploaded for the type, by their uploader or already the record's */
    private function attach(CustomFormType $type, CustomFormRecord $record, array $ids): void
    {
        if ($ids !== [] && $type->attachments) {
            CustomFormAttachment::query()->where('type_id', $type->id)->whereIn('id', $ids)->whereNull('record_id')->update(['record_id' => $record->id]);
        }
    }

    private function assertDraft(CustomFormRecord $record): void
    {
        if (! $record->isEditable()) {
            throw new ApiException(422, 'custom_form_not_draft', __('core.custom_form.errors.not_draft'));
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($work);
    }
}
