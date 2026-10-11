<?php

namespace App\Core\CustomForms;

use App\Core\Audit\Auditor;
use App\Core\CustomFields\CustomFieldDefinitions;
use App\Core\CustomFields\CustomFieldError;
use App\Core\CustomFields\CustomFieldWriter;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Definitions\FlowDefinitions;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CF-04, CF-05: writing custom form records. The caller (a Form Request)
 * has checked permissions and validated the input (header and line custom
 * fields through CustomFieldValidator).
 *
 * - create(): the record at its place, numbered (NUM-01) with its type's
 *   number type, its header values (defaults, formulas), its lines and the
 *   attachments it names; then submitted when asked. One transaction.
 * - update(): a draft of an active type only. Only the keys given change.
 *   Lines, when given, are matched by id: a line without an id is created,
 *   a line with one is updated in place (only the keys given change, so
 *   values of fields the saver can't see or change stay), and an active
 *   line left out is archived, never deleted (TEN-06). Each save of lines,
 *   on create too, writes `core.custom_form.lines` with the lines before
 *   and after (AUD-01). Attachments, when given, are the record's list:
 *   new ones are tied to it and the ones left out archived; both audited.
 * - submit(): the stored record is checked again (required header and
 *   line fields, at least one line when the type has lines, an amount when
 *   the flow reads it: 422 with field errors); then, with a workflow, the
 *   record's flow starts (pending); without, the record is submitted. A
 *   draft of an archived type is neither changed nor submitted (409).
 * - Totals (CF-05): every number and money line field is summed into
 *   `totals` (money as minor units with its currency, one currency per
 *   field); the first money total (else the first money header value) is
 *   the record's amount, for lists and the approvals inbox.
 */
class CustomForms
{
    /** Line field types with a total. */
    public const TOTALLED = ['number', 'money'];

    /** The largest amount in minor units a bigint (the amount column) holds. */
    public const MAX_MINOR = '9223372036854775807';

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

            $this->attach($type, $record, $data['attachments'] ?? [], $by, detach: false);

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
            $this->assertTypeActive($type);

            $this->writer->fill($type->entity(), $record, $data['custom'] ?? null, creating: false);

            $lines = $type->has_lines && array_key_exists('lines', $data) && $data['lines'] !== null
                ? $this->writeLines($type, $record, $data['lines'])
                : $record->lines()->get()->all();

            $this->applyTotals($type, $record, $lines);
            $record->save();
            $this->writer->saved($type->entity(), $record);

            if (array_key_exists('attachments', $data) && $data['attachments'] !== null) {
                $this->attach($type, $record, $data['attachments'], $by, detach: true);
            }

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
            $this->assertTypeActive($type);
            $this->assertSubmittable($type, $record);

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

            if ($record->isArchived()) {
                throw new ApiException(409, 'custom_form_archived', __('core.custom_form.errors.archived'));
            }

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

    /** TEN-06: archive a record; refused while it waits in its flow (cancel it first). */
    public function archive(CustomFormRecord $record): CustomFormRecord
    {
        return $this->transaction(function () use ($record) {
            $record = CustomFormRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            if ($record->status === CustomFormRecord::PENDING) {
                throw new ApiException(409, 'custom_form_pending', __('core.custom_form.errors.archive_pending'));
            }

            if (! $record->isArchived()) {
                $record->archive();
            }

            return $record;
        });
    }

    /** TEN-06, CF-02: restore a record; its unique header values must still be free (ADR 011). */
    public function restore(CustomFormRecord $record): CustomFormRecord
    {
        return $this->transaction(function () use ($record) {
            $record = CustomFormRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            if ($record->isArchived()) {
                $this->writer->assertRestorable($record->type->entity(), $record);
                $record->restore();
            }

            return $record;
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
     * The totals of the line fields with one (CF-05). A field without any
     * value has no total (null), money or number alike.
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

            if ($values === []) {
                $totals[$field->key] = null;

                continue;
            }

            if ($field->type === 'number') {
                $sum = BigDecimal::zero();

                foreach ($values as $value) {
                    $sum = $sum->plus(BigDecimal::of((string) $value));
                }

                $totals[$field->key] = (string) $sum->strippedOfTrailingZeros();

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

    /**
     * CF-05: the money line fields whose total would not fit a bigint of
     * minor units (the amount column), as field errors (`totals.<key>`).
     *
     * @param  list<CustomFormLine>  $lines
     * @return array<string, list<string>>
     */
    public function totalErrors(CustomFormType $type, array $lines): array
    {
        $errors = [];
        $max = BigDecimal::of(self::MAX_MINOR);

        foreach ($this->totals($type, $lines) as $key => $total) {
            if (is_array($total) && BigDecimal::of((string) $total['amount_minor'])->abs()->isGreaterThan($max)) {
                $field = $this->definitions->byKey($type->lineEntity())[$key] ?? null;
                $errors["totals.{$key}"] = [__('core.custom_form.errors.total_too_large', ['field' => $field?->label ?? $key])];
            }
        }

        return $errors;
    }

    /** @param list<CustomFormLine> $lines */
    private function applyTotals(CustomFormType $type, CustomFormRecord $record, array $lines): void
    {
        $totals = $type->has_lines ? $this->totals($type, $lines) : [];
        $errors = $type->has_lines ? $this->totalErrors($type, $lines) : [];

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

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
     * The record's lines from $input, matched by id: a line without an id
     * is created; a line naming one of the record's active lines updates it
     * in place (only the keys given change); an active line left out is
     * archived. Positions follow $input. Audited as `core.custom_form.lines`
     * with the lines before and after (AUD-01).
     *
     * @param  list<array{id?: ?string, custom?: ?array}>  $input
     * @return list<CustomFormLine> the active lines, in order
     */
    private function writeLines(CustomFormType $type, CustomFormRecord $record, array $input): array
    {
        $existing = CustomFormLine::query()->where('record_id', $record->id)->whereNull('archived_at')->orderBy('position')->get()->keyBy('id');
        $before = $existing->map(fn (CustomFormLine $line) => ['id' => $line->id, 'position' => $line->position, 'custom' => $line->custom])->values()->all();
        $kept = array_values(array_filter(array_map(fn ($line) => $line['id'] ?? null, $input)));

        // Positions are unique among active lines: park the current ones before renumbering.
        CustomFormLine::query()->where('record_id', $record->id)->whereNull('archived_at')->update(['position' => DB::raw('-position')]);

        foreach ($existing as $id => $line) {
            if (! in_array($id, $kept, true)) {
                $line->forceFill(['archived_at' => CarbonImmutable::now(), 'position' => abs($line->position)])->save();
            }
        }

        $lines = [];

        foreach (array_values($input) as $position => $line) {
            $id = $line['id'] ?? null;
            $model = $id === null ? new CustomFormLine(['record_id' => $record->id]) : $existing->get($id);

            if ($model === null) {
                // The Form Request refuses unknown ids; a line archived meanwhile is not brought back.
                throw ValidationException::withMessages(["lines.{$position}.id" => [__('core.custom_form.errors.line_unknown')]]);
            }

            $model->position = $position + 1;
            $this->writer->fill($type->lineEntity(), $model, $line['custom'] ?? ($id === null ? [] : null), creating: $id === null);
            $model->save();
            $this->writer->saved($type->lineEntity(), $model);
            $lines[] = $model;
        }

        $this->auditor->record('core.custom_form.lines', $record, $record->wasRecentlyCreated ? null : ['lines' => $before], [
            'lines' => array_map(fn (CustomFormLine $line) => ['id' => $line->id, 'position' => $line->position, 'custom' => $line->custom], $lines),
        ]);

        return $lines;
    }

    /**
     * Tie the attachments $ids names to the record (uploaded for the type by
     * the saver, or already the record's); with $detach, the record's
     * attachments left out are archived (never deleted). Both audited.
     *
     * @param  list<string>  $ids
     */
    private function attach(CustomFormType $type, CustomFormRecord $record, array $ids, User $by, bool $detach): void
    {
        if (! $type->attachments) {
            return;
        }

        $new = $ids === [] ? collect() : CustomFormAttachment::query()->where('type_id', $type->id)->whereIn('id', $ids)
            ->whereNull('record_id')->whereNull('archived_at')->get();

        foreach ($new as $file) {
            $file->forceFill(['record_id' => $record->id])->save();
            $this->auditor->record('core.custom_form.attach', $record, null, ['attachment' => $file->id, 'name' => $file->name]);
        }

        if (! $detach) {
            return;
        }

        $removed = CustomFormAttachment::query()->where('record_id', $record->id)->whereNull('archived_at')->whereNotIn('id', $ids === [] ? [''] : $ids)->get();

        foreach ($removed as $file) {
            $file->forceFill(['archived_at' => CarbonImmutable::now()])->save();
            $this->auditor->record('core.custom_form.detach', $record, ['attachment' => $file->id, 'name' => $file->name], null);
        }
    }

    private function assertDraft(CustomFormRecord $record): void
    {
        if (! $record->isEditable()) {
            throw new ApiException(422, 'custom_form_not_draft', __('core.custom_form.errors.not_draft'));
        }
    }

    /** A draft of an archived type stays as it is: read only (409). */
    private function assertTypeActive(CustomFormType $type): void
    {
        if ($type->isArchived()) {
            throw new ApiException(409, 'custom_form_type_archived', __('core.custom_form.errors.type_archived'));
        }
    }

    /**
     * Submit checks the stored record (CF-04, CF-05): required header and
     * line fields have a value, a type with lines has at least one, and a
     * flow reading the amount gets one. 422 with field errors.
     */
    private function assertSubmittable(CustomFormType $type, CustomFormRecord $record): void
    {
        $errors = [];

        foreach ($this->definitions->active($type->entity()) as $field) {
            if ($field->required && $field->type !== 'formula' && ($record->custom[$field->key] ?? null) === null) {
                $errors["custom.{$field->key}"] = [(new CustomFieldError('required'))->message($field)];
            }
        }

        if ($type->has_lines) {
            $lines = $record->lines()->get();

            if ($lines->isEmpty()) {
                $errors['lines'] = [__('core.custom_form.errors.lines_required')];
            }

            foreach ($lines->values() as $index => $line) {
                foreach ($this->definitions->active($type->lineEntity()) as $field) {
                    if ($field->required && $field->type !== 'formula' && ($line->custom[$field->key] ?? null) === null) {
                        $errors["lines.{$index}.custom.{$field->key}"] = [(new CustomFieldError('required'))->message($field)];
                    }
                }
            }
        }

        if ($type->workflow && $record->amount_minor === null && $this->flowReadsAmount($type, $record)) {
            $errors['amount'] = [__('core.custom_form.errors.amount_required')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Whether the flow the record would start has a condition on its amount. */
    private function flowReadsAmount(CustomFormType $type, CustomFormRecord $record): bool
    {
        $documentType = $type->documentType();
        $graph = app(FlowDefinitions::class)->publishedFor($documentType, $record->company_id)?->flow()->toArray()
            ?? app(FlowDefinitions::class)->defaultGraph(new CustomFormDocumentType($type), null);

        $reads = function (mixed $node) use (&$reads): bool {
            if (! is_array($node)) {
                return false;
            }

            if (($node['field'] ?? null) === 'amount' || ($node['other'] ?? null) === 'amount') {
                return true;
            }

            foreach ($node as $child) {
                if ($reads($child)) {
                    return true;
                }
            }

            return false;
        };

        return $reads($graph);
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
