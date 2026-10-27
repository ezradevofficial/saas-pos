<?php

namespace Modules\Salon\Commissions;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Payroll\Contracts\ExternalEarnings;
use Modules\Salon\Models\CommissionBatch;
use Modules\Salon\Models\CommissionEntry;
use Modules\Salon\Models\Payout;
use Modules\Salon\Models\Staff;
use Modules\Salon\SalonServiceProvider;
use Modules\Salon\Support\Errors;
use Modules\Salon\Support\Settings;

/**
 * SAL-23, SAL-24: a period's commissions, reviewed and approved before they leave the module.
 *
 * - create(): takes the company's entries not yet in a batch (rows locked, so two batches never take the same entry)
 *   up to the end of the period. A staff member's net is the sum of their entries; a net of zero or less stays
 *   unbatched and is carried into the next batch (a reversal after an export, for instance).
 * - approve(): by someone else. The builder, the person who submitted it and any staff member whose own commission is
 *   in the batch cannot approve it (`self_approval`); the check is here, on the server, whatever the screen shows.
 * - export(): once approved, each payable amount becomes a one-off earning in Payroll's setup when Payroll is active and
 *   the staff member is an HR employee (idempotent by batch and staff); otherwise it is listed in the report.
 */
class Batches
{
    public const NUMBER_TYPE = 'salon.commission_batch';

    public const NUMBER = 'SCB-{YYYY}-{00001}';

    public function __construct(
        private readonly Auditor $auditor,
        private readonly Numbering $numbering,
        private readonly ModuleRegistry $modules,
    ) {}

    public static function numberType(): DocumentNumberType
    {
        return new DocumentNumberType(self::NUMBER_TYPE, SalonServiceProvider::MODULE, self::NUMBER, NumberFormat::RESET_YEARLY, [], langKey: 'salon.numbering.commission_batch');
    }

    public function create(string $companyId, ?string $branchId, string $periodStart, string $periodEnd, string $currency, User $by): CommissionBatch
    {
        $company = Company::query()->findOrFail($companyId);
        $until = CarbonImmutable::parse($periodEnd.' 00:00:00', $company->timezone ?: 'UTC')->addDay()->utc();

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $branchId, $periodStart, $periodEnd, $currency, $by, $until) {
            $entries = CommissionEntry::query()->where('company_id', $company->id)->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))->where('currency', $currency)->whereNull('batch_id')
                ->where('earned_at', '<', $until)->orderBy('earned_at')->orderBy('id')->lockForUpdate()->get();
            $payable = $entries->groupBy('staff_id')->filter(fn ($rows) => $rows->sum('amount_minor') > 0);

            if ($payable->isEmpty()) {
                throw Errors::make(422, 'nothing_to_batch');
            }

            $batch = CommissionBatch::create([
                'company_id' => $company->id, 'branch_id' => $branchId,
                'number' => $this->numbering->next(self::NUMBER_TYPE, new NumberContext($company))->number,
                'period_start' => $periodStart, 'period_end' => $periodEnd, 'currency' => $currency, 'status' => CommissionBatch::DRAFT, 'created_by' => $by->id,
            ]);
            $staff = Staff::query()->whereKey($payable->keys()->all())->get()->keyBy('id');

            foreach ($payable as $staffId => $rows) {
                Payout::create([
                    'batch_id' => $batch->id, 'staff_id' => $staffId, 'amount_minor' => (int) $rows->sum('amount_minor'), 'currency' => $currency,
                    'entry_count' => $rows->count(), 'employee_id' => $staff->get($staffId)?->employee_id, 'status' => 'pending',
                ]);
                CommissionEntry::query()->whereKey($rows->modelKeys())->update(['batch_id' => $batch->id, 'updated_at' => now()]);
            }

            $this->auditor->record('salon.commission_batch.build', $batch, null, ['entries' => $entries->whereIn('staff_id', $payable->keys())->count(), 'staff' => $payable->count()]);

            return $batch;
        });
    }

    public function submit(CommissionBatch $batch, User $by): CommissionBatch
    {
        return $this->decide($batch, CommissionBatch::DRAFT, function (CommissionBatch $b) use ($by) {
            $b->forceFill(['status' => CommissionBatch::SUBMITTED, 'submitted_by' => $by->id, 'submitted_at' => now()])->save();
            $this->auditor->record('salon.commission_batch.submit', $b);
        });
    }

    public function approve(CommissionBatch $batch, User $by): CommissionBatch
    {
        return $this->decide($batch, CommissionBatch::SUBMITTED, function (CommissionBatch $b) use ($by) {
            $this->assertNotSelf($b, $by);
            $b->forceFill(['status' => CommissionBatch::APPROVED, 'decided_by' => $by->id, 'decided_at' => now()])->save();
            $this->auditor->record('salon.commission_batch.approve', $b);
        });
    }

    public function reject(CommissionBatch $batch, string $reason, User $by): CommissionBatch
    {
        return $this->decide($batch, CommissionBatch::SUBMITTED, function (CommissionBatch $b) use ($reason, $by) {
            // Whoever may decide, rejecting one's own is just as refused: the same four eyes.
            $this->assertNotSelf($b, $by);
            $b->forceFill(['status' => CommissionBatch::REJECTED, 'decided_by' => $by->id, 'decided_at' => now(), 'reject_reason' => mb_substr($reason, 0, 300)])->save();
            Payout::query()->where('batch_id', $b->id)->update(['status' => 'rejected', 'updated_at' => now()]);
            // The entries go back to be batched again.
            CommissionEntry::query()->where('batch_id', $b->id)->update(['batch_id' => null, 'updated_at' => now()]);
            $this->auditor->record('salon.commission_batch.reject', $b, null, ['reason' => $reason]);
        });
    }

    /**
     * @return array{batch: CommissionBatch, refused: list<array{staff_id: string, reason: string}>}
     */
    public function export(CommissionBatch $batch, User $by): array
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($batch, $by) {
            $b = CommissionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (! in_array($b->status, [CommissionBatch::APPROVED], true)) {
                throw Errors::make(409, $b->status === CommissionBatch::EXPORTED ? 'batch_exported' : 'batch_not_approved');
            }

            $settings = Settings::for($b->company_id);
            $payroll = $this->modules->isActive('payroll');
            $refused = [];
            $viaPayroll = false;

            foreach (Payout::query()->where('batch_id', $b->id)->where('status', 'pending')->orderBy('id')->lockForUpdate()->get() as $payout) {
                if (! $payroll || $payout->employee_id === null) {
                    $payout->forceFill(['status' => 'reported'])->save();

                    continue;
                }

                if ($settings['commission_item_code'] === null) {
                    $refused[] = ['staff_id' => $payout->staff_id, 'reason' => 'item_not_set'];

                    continue;
                }

                $result = app(ExternalEarnings::class)->addOnce(
                    $b->company_id, $payout->employee_id, $settings['commission_item_code'], (int) $payout->amount_minor, $payout->currency,
                    $b->period_end->toDateString(), "salon:{$b->id}:{$payout->staff_id}", $by->id,
                );

                if ($result['status'] === ExternalEarnings::REFUSED) {
                    $refused[] = ['staff_id' => $payout->staff_id, 'reason' => (string) ($result['reason'] ?? 'refused')];

                    continue;
                }

                $payout->forceFill(['status' => 'exported', 'payroll_ref' => $result['id'] ?? null])->save();
                $viaPayroll = true;
            }

            if (! Payout::query()->where('batch_id', $b->id)->where('status', 'pending')->exists()) {
                $b->forceFill(['status' => CommissionBatch::EXPORTED, 'exported_by' => $by->id, 'exported_at' => now(), 'export_mode' => $viaPayroll ? 'payroll' : 'report'])->save();
            }

            $this->auditor->record('salon.commission_batch.export', $b, null, ['mode' => $viaPayroll ? 'payroll' : 'report', 'refused' => count($refused)]);

            return ['batch' => $b->refresh(), 'refused' => $refused];
        });
    }

    /** The batch as a CSV: one row per staff member with their amount (the report when Payroll is not used). */
    public function csv(CommissionBatch $batch): string
    {
        $staff = Staff::query()->whereKey(Payout::query()->where('batch_id', $batch->id)->pluck('staff_id'))->get()->keyBy('id');
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['number', 'staff', 'employee_id', 'currency', 'amount_minor', 'entries', 'status', 'period_start', 'period_end'], escape: '\\');

        foreach (Payout::query()->where('batch_id', $batch->id)->orderBy('id')->get() as $payout) {
            fputcsv($out, [
                $batch->number, self::cell($staff->get($payout->staff_id)?->name ?? ''), $payout->employee_id, $payout->currency, $payout->amount_minor,
                $payout->entry_count, $payout->status, $batch->period_start->toDateString(), $batch->period_end->toDateString(),
            ], escape: '\\');
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** A spreadsheet runs a cell that starts with = + - or @ as a formula: it is shown as text. */
    private static function cell(string $text): string
    {
        return $text !== '' && str_contains('=+-@', $text[0]) ? "'".$text : $text;
    }

    private function assertNotSelf(CommissionBatch $batch, User $by): void
    {
        $own = Staff::query()->whereKey(Payout::query()->where('batch_id', $batch->id)->pluck('staff_id'))->pluck('user_id')->filter()->all();

        if (in_array($by->id, [$batch->created_by, $batch->submitted_by], true) || in_array($by->id, $own, true)) {
            throw Errors::make(403, 'self_approval');
        }
    }

    /** Runs $apply on the batch under its row lock when it is in $from. */
    private function decide(CommissionBatch $batch, string $from, callable $apply): CommissionBatch
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($batch, $from, $apply) {
            $b = CommissionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($b->status !== $from) {
                throw Errors::make(409, 'batch_status');
            }

            $apply($b);

            return $b->refresh();
        });
    }
}
