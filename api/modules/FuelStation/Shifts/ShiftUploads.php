<?php

namespace Modules\FuelStation\Shifts;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Sync\SnapshotCache;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\FuelStation\Events\ShiftClosed;
use Modules\FuelStation\Events\ShiftOpened;
use Modules\FuelStation\Models\MeterReset;
use Modules\FuelStation\Models\Nozzle;
use Modules\FuelStation\Models\Settlement;
use Modules\FuelStation\Models\Shift;
use Modules\FuelStation\Models\ShiftNozzle;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Sales\FuelSales;
use Modules\FuelStation\Support\Litres;
use Modules\FuelStation\Support\Stations;
use Modules\FuelStation\Tanks\Dips;
use Modules\POS\Sync\Authority;
use Modules\POS\Sync\DevicePlace;
use Modules\POS\Sync\Flags;
use Modules\POS\Sync\Records;
use Modules\POS\Sync\Rejection as PosRejection;

/**
 * FUEL-09, FUEL-10, FUEL-16, FUEL-22: shifts uploaded by a till, idempotent
 * by the device's id. A shift is sent when opened (the opener, the nozzles
 * in use with their opening readings and attendants, optional tank dips)
 * and again when closed (the closing readings, pump tests, what each
 * attendant hands in per tender, optional dips); one upload may carry both.
 *
 * Refused, with a code and the field (the device shows it): a place that is
 * not a station, a nozzle that is unknown or already on another open shift
 * (retryable: it may close), an opening or closing meter reading below the
 * last accepted one (`meter_decreased`, unless an approved reset moved the
 * baseline), a close that lacks a closing reading or a tender declaration
 * (`close_incomplete`), a resend of the same id with other content
 * (`payload_mismatch`). Kept and flagged (the till already used them): an
 * opener or closer without the permission, an attendant without
 * `fuel.sale.create`, a person the till could not prove (`actor_unverified`,
 * AUTH-07, through POS Authority), a gap between the last reading and the
 * opening one (`meter_gap`).
 *
 * Everything runs in one transaction under row locks on the shift and the
 * nozzles, in id order, so concurrent closes and opens cannot double-count
 * or deadlock.
 */
class ShiftUploads
{
    /** A shift may be dated this far ahead of the server before it is refused. */
    private const CLOCK_TOLERANCE_MINUTES = 5;

    public function __construct(
        private readonly Authority $authority,
        private readonly Auditor $auditor,
        private readonly TenantContext $tenants,
        private readonly Stations $stations,
        private readonly Settlements $settlements,
        private readonly Dips $dips,
        private readonly FuelSales $sales,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $shifts
     * @return list<array<string, mixed>> one result per shift, in order
     */
    public function upload(DevicePlace $place, array $shifts): array
    {
        return array_map(function (array $data) use ($place) {
            try {
                return $this->stored(DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->one($place, $data)));
            } catch (Rejection|PosRejection $rejection) {
                // POS Authority names an unknown actor with its own rejection (`user_unknown`): the same clean refusal, never a 500.
                return $this->rejected($data['id'], $rejection);
            } catch (ApiException $e) {
                // A dip or reading the server cannot take (out of the chart, out of order): the same envelope as any refusal.
                return $this->rejected($data['id'], new Rejection($e->errorCode, array_key_first($e->errors) ?: null));
            } catch (UniqueConstraintViolationException $e) {
                $shift = Shift::query()->find($data['id']);

                return match (true) {
                    $shift !== null && $shift->device_id === $place->device->id => $this->stored($shift),
                    str_contains($e->getMessage(), 'fuel_shift_nozzles_one_open') => $this->rejected($data['id'], new Rejection('nozzle_in_use', 'opening.nozzles', retryable: true)),
                    default => $this->rejected($data['id'], new Rejection('id_conflict', 'id')),
                };
            }
        }, $shifts);
    }

    private function one(DevicePlace $place, array $data): Shift
    {
        $station = $this->stations->at($place->location->id) ?? throw new Rejection('not_a_station');
        $shift = Shift::query()->whereKey($data['id'])->lockForUpdate()->first();

        if ($shift !== null && $shift->device_id !== $place->device->id) {
            throw new Rejection('id_conflict', 'id');
        }

        if ($shift === null) {
            $shift = $this->open($place, $station->id, $station->currency, $data);
        } elseif ($shift->opening_fingerprint !== Records::hash($data['opening'])) {
            throw new Rejection('payload_mismatch', 'opening');
        }

        $closing = $data['closing'] ?? null;

        if ($closing === null) {
            return $shift;
        }

        if ($shift->status === Shift::CLOSED) {
            if ($shift->closing_fingerprint !== Records::hash($closing)) {
                throw new Rejection('payload_mismatch', 'closing');
            }

            return $shift;
        }

        $this->close($place, $shift, $closing);

        return $shift->refresh();
    }

    /** @param array<string, mixed> $data */
    private function open(DevicePlace $place, string $stationId, string $currency, array $data): Shift
    {
        $o = $data['opening'];
        $opener = $this->authority->user($o['opened_by_id'], 'opening.opened_by_id');
        $flags = new Flags;
        $openedAt = CarbonImmutable::parse($o['opened_at'])->utc();

        if ($openedAt->greaterThan(now()->addMinutes(self::CLOCK_TOLERANCE_MINUTES))) {
            $flags->add('clock_ahead');
        }

        if (! $this->authority->can($opener, 'fuel.shift.open', $place->scope())) {
            $flags->add('opener_not_permitted', null, ['permission' => 'fuel.shift.open']);
        }

        $attested = $this->attested($place, $opener, $o['actor_proof'] ?? null, $openedAt, $flags);
        $ids = array_column($o['nozzles'], 'nozzle_id');

        if (count($ids) !== count(array_unique($ids))) {
            throw new Rejection('nozzle_duplicate', 'opening.nozzles');
        }

        // Locked in id order: two openings that share a nozzle cannot deadlock.
        $nozzles = Nozzle::query()->whereKey($ids)->where('station_id', $stationId)->whereNull('archived_at')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $rows = [];

        foreach ($o['nozzles'] as $i => $n) {
            $nozzle = $nozzles->get($n['nozzle_id']) ?? throw new Rejection('nozzle_unknown', "opening.nozzles.{$i}.nozzle_id");
            $attendant = $this->authority->user($n['attendant_id'], "opening.nozzles.{$i}.attendant_id");
            $reading = Litres::of($n['reading']);
            $rowFlags = [];
            $gap = '0';

            // FUEL-16: readings never go down; a gap above the last one is dispensing nobody recorded.
            if ($nozzle->last_reading_litres !== null) {
                $last = Litres::of($nozzle->last_reading_litres);

                if ($reading->isLessThan($last)) {
                    throw new Rejection('meter_decreased', "opening.nozzles.{$i}.reading");
                }

                if ($reading->isGreaterThan($last)) {
                    $gap = (string) $reading->minus($last);
                    $rowFlags[] = ['code' => 'meter_gap', 'litres' => $gap];
                }
            }

            if (! $this->authority->can($attendant, 'fuel.sale.create', $place->scope())) {
                $rowFlags[] = ['code' => 'attendant_not_permitted'];
            }

            // AUTH-07: the till's attestation that the attendant was signed in; without it the name is a claim (and the shift's approvers are narrowed, ShiftVarianceType).
            if ($this->authority->proven($place->device, $attendant, $n['attendant_proof'] ?? null) === null) {
                $rowFlags[] = ['code' => 'attendant_unverified'];
            }

            $rows[] = [$nozzle, $attendant, $reading, $rowFlags, $gap];
        }

        // FUEL-17: a meter waiting for a reset decision is not read until it is decided, so the shift cannot start on a baseline about to move.
        if (MeterReset::query()->whereIn('nozzle_id', $ids)->where('status', MeterReset::PENDING)->exists()) {
            throw new Rejection('reset_pending', 'opening.nozzles', retryable: true);
        }

        if (ShiftNozzle::query()->whereIn('nozzle_id', $ids)->where('is_open', true)->exists()) {
            throw new Rejection('nozzle_in_use', 'opening.nozzles', retryable: true);
        }

        $shift = Shift::create([
            'id' => $data['id'], 'company_id' => $place->company->id, 'branch_id' => $place->branch->id, 'location_id' => $place->location->id,
            'station_id' => $stationId, 'device_id' => $place->device->id, 'status' => Shift::OPEN,
            'opened_by' => $opener->id, 'opened_at' => $openedAt, 'opened_received_at' => now(), 'opening_fingerprint' => Records::hash($o), 'flags' => $flags->all(),
        ]);

        foreach ($rows as [$nozzle, $attendant, $reading, $rowFlags, $gap]) {
            ShiftNozzle::create([
                'shift_id' => $shift->id, 'nozzle_id' => $nozzle->id, 'attendant_id' => $attendant->id, 'is_open' => true,
                'opening_litres' => (string) $reading, 'gap_litres' => $gap, 'flags' => $rowFlags,
            ]);
            $nozzle->forceFill(['last_reading_litres' => (string) $reading, 'last_reading_at' => $openedAt])->save();
        }

        foreach ($o['dips'] ?? [] as $i => $dip) {
            $this->dip($place, $stationId, $dip, 'shift_open', $opener, $shift, "opening.dips.{$i}");
        }

        $this->auditor->record('fuel.shift.open', $shift, null, [
            'station_id' => $stationId, 'currency' => $currency, 'nozzles' => collect($rows)->map(fn ($r) => ['nozzle_id' => $r[0]->id, 'attendant_id' => $r[1]->id, 'reading' => (string) $r[2]])->all(), ...$attested,
        ], ['user_id' => $opener->id, 'device_time' => $openedAt]);
        $this->sales->adopt($shift);
        ShiftOpened::dispatch($this->tenants->require(), $shift->id, $shift->location_id);
        SnapshotCache::bump($this->tenants->require());

        return $shift;
    }

    /** @param array<string, mixed> $c */
    private function close(DevicePlace $place, Shift $shift, array $c): void
    {
        $closer = $this->authority->user($c['closed_by_id'], 'closing.closed_by_id');
        $flags = new Flags;
        $closedAt = CarbonImmutable::parse($c['closed_at'])->utc();

        if ($closedAt->lessThan($shift->opened_at)) {
            throw new Rejection('closed_before_opened', 'closing.closed_at');
        }

        if ($closedAt->greaterThan(now()->addMinutes(self::CLOCK_TOLERANCE_MINUTES))) {
            $flags->add('clock_ahead');
        }

        if (! $this->authority->can($closer, 'fuel.shift.close', $place->scope())) {
            $flags->add('closer_not_permitted', null, ['permission' => 'fuel.shift.close']);
        }

        $attested = $this->attested($place, $closer, $c['actor_proof'] ?? null, $closedAt, $flags);
        $rows = ShiftNozzle::query()->where('shift_id', $shift->id)->orderBy('nozzle_id')->lockForUpdate()->get();
        $nozzles = Nozzle::query()->whereKey($rows->pluck('nozzle_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $given = collect($c['nozzles'])->keyBy('nozzle_id');

        foreach ($given as $id => $_) {
            if (! $rows->contains('nozzle_id', $id)) {
                throw new Rejection('nozzle_unknown', 'closing.nozzles');
            }
        }

        // FUEL-10: a shift closes only with every reading and every attendant's tenders.
        $missing = [];

        foreach ($rows as $row) {
            if (! $given->has($row->nozzle_id)) {
                $missing[] = $this->nozzleLabel($nozzles->get($row->nozzle_id));
            }
        }

        $declared = [];

        foreach ($c['tenders'] ?? [] as $entry) {
            foreach ($entry['tenders'] as $tender) {
                $declared[$entry['attendant_id']][$tender['kind']] = (int) $tender['amount_minor'];
            }
        }

        $attendants = $rows->pluck('attendant_id')->unique();

        foreach (array_keys($declared) as $id) {
            if (! $attendants->contains($id)) {
                throw new Rejection('attendant_unknown', 'closing.tenders');
            }
        }

        foreach ($attendants as $id) {
            if (! isset($declared[$id]['cash'])) {
                $missing[] = (string) User::query()->whereKey($id)->value('name');
            }
        }

        if ($missing !== []) {
            throw new Rejection('close_incomplete', 'closing', replace: ['missing' => implode(', ', $missing)]);
        }

        foreach ($rows as $row) {
            $i = $given->keys()->search($row->nozzle_id);
            $entry = $given->get($row->nozzle_id);
            $reading = Litres::of($entry['reading']);
            $resets = MeterReset::query()->where('shift_id', $shift->id)->where('nozzle_id', $row->nozzle_id)->where('status', MeterReset::APPROVED)->orderBy('decided_at')->get();
            $latest = $resets->isEmpty() ? null : Litres::of($resets->last()->reading_after);

            if ($reading->isLessThan(Meters::floor($row->opening_litres, $latest))) {
                throw new Rejection('meter_decreased', "closing.nozzles.{$i}.reading");
            }

            $tests = Litres::of($entry['test_litres'] ?? '0');
            $adjustment = $resets->reduce(fn (BigDecimal $sum, MeterReset $r) => $sum->plus(Litres::of($r->reading_before)->minus(Litres::of($r->reading_after))), Litres::zero());

            if (Meters::litres($row->opening_litres, $reading, $tests, $adjustment)->isNegative()) {
                throw new Rejection('test_exceeds_litres', "closing.nozzles.{$i}.test_litres");
            }

            $row->forceFill(['closing_litres' => (string) $reading, 'test_litres' => (string) $tests, 'reset_litres' => (string) $adjustment, 'is_open' => false])->save();
            $nozzles->get($row->nozzle_id)->forceFill(['last_reading_litres' => (string) $reading, 'last_reading_at' => $closedAt])->save();
        }

        $shift->forceFill([
            'status' => Shift::CLOSED, 'closed_by' => $closer->id, 'closed_at' => $closedAt, 'closed_received_at' => now(), 'closing_fingerprint' => Records::hash($c),
            'note' => $c['note'] ?? null, 'flags' => [...($shift->flags ?? []), ...$flags->all()],
        ])->save();

        $this->settlements->settle($shift, $declared, $closer, 'close');

        foreach ($c['dips'] ?? [] as $i => $dip) {
            $this->dip($place, $shift->station_id, $dip, 'shift_close', $closer, $shift, "closing.dips.{$i}");
        }

        $settlements = Settlement::query()->where('shift_id', $shift->id)->get();
        $this->auditor->record('fuel.shift.close', $shift, ['status' => Shift::OPEN], [
            'status' => Shift::CLOSED,
            'settlements' => $settlements->map(fn (Settlement $s) => ['number' => $s->number, 'attendant_id' => $s->attendant_id, 'status' => $s->status, 'expected_minor' => (string) $s->expected_minor, 'collected_minor' => (string) $s->collected_minor, 'variance_minor' => (string) $s->variance_minor])->all(),
            ...$attested,
        ], ['user_id' => $closer->id, 'device_time' => $closedAt]);
        ShiftClosed::dispatch($this->tenants->require(), $shift->id, $shift->location_id, $settlements->first()?->currency ?? '', [
            'litres' => (string) $rows->reduce(fn (BigDecimal $sum, ShiftNozzle $r) => $sum->plus(Litres::of($r->meter_litres ?? '0')), Litres::zero()),
            'expected_minor' => (string) $settlements->sum('expected_minor'), 'collected_minor' => (string) $settlements->sum('collected_minor'), 'variance_minor' => (string) $settlements->sum('variance_minor'),
        ]);
        SnapshotCache::bump($this->tenants->require());
    }

    /** @param array<string, mixed> $dip */
    private function dip(DevicePlace $place, string $stationId, array $dip, string $kind, User $by, Shift $shift, string $field): void
    {
        $tank = Tank::query()->whereKey($dip['tank_id'])->where('station_id', $stationId)->whereNull('archived_at')->first() ?? throw new Rejection('tank_unknown', "{$field}.tank_id");
        $this->dips->record($tank, [...$dip, 'kind' => $kind], $by, 'device', $shift->id);
    }

    /**
     * AUTH-07: whether the till proved who did it (a verified sign-in attestation, checked by POS Authority); never a reason to refuse.
     *
     * @return array{actor_verified: bool, actor_online: bool|null, actor_flags: list<string>}
     */
    private function attested(DevicePlace $place, User $user, ?array $proof, CarbonImmutable $at, Flags $flags): array
    {
        $actor = $this->authority->proven($place->device, $user, $proof);

        if ($actor === null) {
            $flags->add('actor_unverified');
        }

        $session = $this->authority->sessionFlags($place->device, $user, $proof, $at);

        foreach ($session as $code) {
            $flags->add($code);
        }

        return ['actor_verified' => $actor !== null, 'actor_online' => $actor?->online, 'actor_flags' => $session];
    }

    private function nozzleLabel(Nozzle $nozzle): string
    {
        $pump = DB::table('fuel_pumps')->where('id', $nozzle->pump_id)->value('code');

        return trim("{$pump} {$nozzle->code}");
    }

    /** @return array<string, mixed> */
    private function stored(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'status' => 'stored',
            'state' => $shift->status,
            'flags' => $shift->flags ?? [],
            'settlements' => $shift->status === Shift::CLOSED
                ? Settlement::query()->where('shift_id', $shift->id)->orderBy('id')->get()->map(fn (Settlement $s) => [
                    'attendant_id' => $s->attendant_id, 'status' => $s->status, 'number' => $s->number,
                    'expected_minor' => (string) $s->expected_minor, 'collected_minor' => (string) $s->collected_minor, 'variance_minor' => (string) $s->variance_minor,
                ])->all()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function rejected(string $id, Rejection|PosRejection $rejection): array
    {
        return ['id' => $id, 'status' => 'rejected', 'error' => $rejection->toArray()];
    }
}
