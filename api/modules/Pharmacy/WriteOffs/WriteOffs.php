<?php

namespace Modules\Pharmacy\WriteOffs;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\CompanyClock;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\StockWrittenOff;
use Modules\Pharmacy\Items\Profiles;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\LotMovement;
use Modules\Pharmacy\Models\RegisterEntry;
use Modules\Pharmacy\Models\WriteOff;
use Modules\Pharmacy\Models\WriteOffLine;
use Modules\Pharmacy\PharmacyServiceProvider;
use Modules\Pharmacy\Register\ControlledRegister;
use Modules\Pharmacy\Stock\Lots;
use Modules\Pharmacy\Support\PharmacyError;
use Modules\Pharmacy\Support\Places;
use Modules\Pharmacy\Support\Qty;

/**
 * PHA-12: write-offs of lot stock, posted on approval.
 *
 * - create(): checked lines (a lot of the company, a positive quantity the
 *   location holds; reason `expired` only for lots past their expiry),
 *   numbered `WO-{BRANCH}-{YYYY}-{00001}` (NUM-01) and the
 *   `pharmacy.write_off` flow started, in one transaction (pending).
 * - completed(): approved: the lots are reduced (`write_off` movements),
 *   the controlled register written, `StockWrittenOff` raised for Inventory
 *   and everything audited; when stock moved meanwhile and a lot no longer
 *   holds the quantity, it is `failed` with the reason. Rejected ends it.
 * - cancel(): a pending write-off, its flow cancelled.
 */
class WriteOffs
{
    public const NUMBER = 'WO-{BRANCH}-{YYYY}-{00001}';

    public function __construct(
        private readonly Lots $lots,
        private readonly Profiles $profiles,
        private readonly ControlledRegister $register,
        private readonly WorkflowEngine $engine,
        private readonly Numbering $numbering,
        private readonly Places $places,
        private readonly Auditor $auditor,
    ) {}

    public static function numberType(): DocumentNumberType
    {
        return new DocumentNumberType(WriteOffType::KEY, PharmacyServiceProvider::MODULE, self::NUMBER, NumberFormat::RESET_YEARLY, ['BRANCH', 'LOCATION'], langKey: 'pharmacy.numbering.write_off');
    }

    /** @param array{location_id: string, reason: string, note?: ?string, lines: list<array{lot_id: string, qty: string}>} $data */
    public function create(array $data, User $by): WriteOff
    {
        $place = $this->places->require($data['location_id']);
        $today = CompanyClock::today($place['company_id'], $place['branch_id']);
        $lots = Lot::query()->whereKey(array_column($data['lines'], 'lot_id'))->get()->keyBy('id');

        foreach ($data['lines'] as $index => $line) {
            $lot = $lots->get($line['lot_id']);
            $field = "lines.{$index}";

            if ($lot === null || $lot->company_id !== $place['company_id']) {
                throw PharmacyError::make(422, 'lot_unknown', field: "{$field}.lot_id");
            }

            if ($data['reason'] === 'expired' && $lot->expires_on->toDateString() >= $today) {
                throw PharmacyError::make(422, 'lot_not_expired', ['batch' => $lot->batch_number], "{$field}.lot_id");
            }
        }

        // A lot named twice is one line's worth: the held quantity is checked against the sum.
        $asked = [];

        foreach ($data['lines'] as $line) {
            $asked[$line['lot_id']] = Qty::of($asked[$line['lot_id']] ?? '0')->plus(Qty::of((string) $line['qty']));
        }

        foreach ($asked as $lotId => $qty) {
            if ($this->lots->onHand($lots->get($lotId), $data['location_id'])->isLessThan($qty)) {
                throw PharmacyError::make(422, 'insufficient_stock', ['batch' => $lots->get($lotId)->batch_number], 'lines');
            }
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data, $by, $place, $lots) {
            $writeOff = WriteOff::create([
                'number' => $this->numbering->next(WriteOffType::KEY, new NumberContext(
                    Company::query()->findOrFail($place['company_id']), Branch::query()->findOrFail($place['branch_id']), Location::query()->findOrFail($data['location_id']),
                ))->number,
                'company_id' => $place['company_id'],
                'branch_id' => $place['branch_id'],
                'location_id' => $data['location_id'],
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
                'status' => WriteOff::PENDING,
                'requested_by' => $by->id,
            ]);

            foreach ($data['lines'] as $index => $line) {
                WriteOffLine::create([
                    'write_off_id' => $writeOff->id,
                    'line_no' => $index + 1,
                    'item_id' => $lots->get($line['lot_id'])->item_id,
                    'lot_id' => $line['lot_id'],
                    'qty' => (string) Qty::of((string) $line['qty']),
                ]);
            }

            $this->auditor->record('pharmacy.write_off.create', $writeOff, null, ['number' => $writeOff->number, 'reason' => $writeOff->reason, 'lines' => count($data['lines'])]);
            $this->engine->start(WriteOffType::KEY, $writeOff->id, $by);

            return $writeOff->refresh();
        });
    }

    /** WF-10: an approved write-off is posted; a rejected one ends. */
    public function completed(string $writeOffId, string $outcome, ?string $userId): void
    {
        $event = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($writeOffId, $outcome, $userId) {
            $writeOff = WriteOff::query()->whereKey($writeOffId)->lockForUpdate()->first();

            if ($writeOff === null || $writeOff->status !== WriteOff::PENDING) {
                return null;
            }

            $now = CarbonImmutable::now();

            if ($outcome !== 'approved') {
                $writeOff->fill(['status' => WriteOff::REJECTED, 'decided_by' => $userId, 'decided_at' => $now])->save();
                $this->auditor->record('pharmacy.write_off.reject', $writeOff, ['status' => WriteOff::PENDING], ['status' => WriteOff::REJECTED], ['user_id' => $userId]);

                return null;
            }

            $lines = $writeOff->lines()->get();

            foreach ($lines as $line) {
                if ($this->lots->onHand(Lot::query()->findOrFail($line->lot_id), $writeOff->location_id)->isLessThan(Qty::of((string) $line->qty))) {
                    $writeOff->fill(['status' => WriteOff::FAILED, 'failure' => 'insufficient_stock', 'decided_by' => $userId, 'decided_at' => $now])->save();
                    $this->auditor->record('pharmacy.write_off.fail', $writeOff, ['status' => WriteOff::PENDING], ['status' => WriteOff::FAILED, 'failure' => 'insufficient_stock'], ['user_id' => $userId]);

                    return null;
                }
            }

            $writeOff->fill(['status' => WriteOff::POSTED, 'decided_by' => $userId, 'decided_at' => $now, 'posted_at' => $now])->save();
            $sent = [];

            foreach ($lines as $line) {
                $lot = Lot::query()->findOrFail($line->lot_id);
                $qty = Qty::of((string) $line->qty);
                $this->lots->move($lot, $writeOff->location_id, LotMovement::WRITE_OFF, $qty->negated(), 'pharmacy.write_off', $writeOff->id, $line->id, $now, userId: $userId, note: $writeOff->reason);

                if ($this->profiles->of($line->item_id)?->isControlled()) {
                    $this->register->append($writeOff->company_id, $writeOff->location_id, $line->item_id, RegisterEntry::WRITE_OFF, $qty->negated(), 'pharmacy.write_off', $writeOff->id, $line->id, $lot, null, null, $userId, $writeOff->reason, $now);
                }

                $sent[] = ['line_id' => $line->id, 'item_id' => $line->item_id, 'qty' => Qty::show($qty), 'batch_number' => $lot->batch_number];
            }

            $this->auditor->record('pharmacy.write_off.post', $writeOff, ['status' => WriteOff::PENDING], ['status' => WriteOff::POSTED, 'number' => $writeOff->number], ['user_id' => $userId]);

            return new StockWrittenOff($writeOff->tenant_id, $writeOff->id, $writeOff->company_id, $writeOff->location_id, $now->utc()->format('Y-m-d\TH:i:s.u\Z'), $writeOff->number, $sent, $userId);
        });

        // After commit (the event waits for it): Inventory takes the stock out too, when it is active.
        if ($event !== null) {
            event($event);
        }
    }

    /** WF-11: the write-off's flow was cancelled. */
    public function cancelled(string $writeOffId): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($writeOffId) {
            $writeOff = WriteOff::query()->whereKey($writeOffId)->lockForUpdate()->first();

            if ($writeOff !== null && $writeOff->status === WriteOff::PENDING) {
                $writeOff->fill(['status' => WriteOff::CANCELLED, 'cancelled_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    public function cancel(WriteOff $writeOff, User $by, string $reason): WriteOff
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($writeOff, $by, $reason) {
            $writeOff = WriteOff::query()->whereKey($writeOff->id)->lockForUpdate()->firstOrFail();

            if ($writeOff->status !== WriteOff::PENDING) {
                throw PharmacyError::make(409, 'document_not_open');
            }

            $workflow = $this->engine->current(WriteOffType::KEY, $writeOff->id);

            if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                $this->engine->cancel($workflow, null, $reason);
            }

            $writeOff->fill(['status' => WriteOff::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();
            $this->auditor->record('pharmacy.write_off.cancel', $writeOff, ['status' => WriteOff::PENDING], ['status' => WriteOff::CANCELLED, 'reason' => $reason]);

            return $writeOff->refresh();
        });
    }
}
