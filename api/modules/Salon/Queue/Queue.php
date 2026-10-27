<?php

namespace Modules\Salon\Queue;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Salon\Appointments\Bookings;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\QueueEntry;
use Modules\Salon\Models\Service;
use Modules\Salon\Models\Staff;
use Modules\Salon\Support\Errors;
use Modules\Salon\Support\Parties;
use Modules\Salon\Support\Places;

/**
 * SAL-12: the walk-in queue of a branch. A walk-in waits in arrival order; seating makes an appointment `checked_in`
 * that starts now with the chosen free staff member, through the same locked booking as every other path (SAL-08), so a
 * clash is refused as `slot_taken` and the entry keeps its place. Estimated waits are a greedy plan: each waiting
 * entry, in order, takes the qualified staff member who is free earliest.
 */
class Queue
{
    public function __construct(private readonly Bookings $bookings, private readonly Places $places, private readonly Parties $parties) {}

    /** @param array{branch_id: string, guest_name?: ?string, guest_phone?: ?string, client_id?: ?string, service_ids?: list<string>, staff_id?: ?string, id?: ?string, device_id?: ?string, joined_at?: ?string} $in */
    public function join(array $in, ?User $by): QueueEntry
    {
        $branch = $this->places->openBranch((string) $in['branch_id']);
        $client = ! empty($in['client_id']) ? $this->parties->customer((string) $in['client_id'], $branch->company_id) : null;
        $name = $client?->name ?? trim((string) ($in['guest_name'] ?? ''));

        if ($name === '') {
            throw Errors::make(422, 'client_required', field: 'guest_name');
        }

        $services = (array) ($in['service_ids'] ?? []);

        if ($services !== [] && Service::query()->where('company_id', $branch->company_id)->whereNull('archived_at')->whereKey($services)->count() !== count(array_unique($services))) {
            throw Errors::make(422, 'service_unknown', field: 'service_ids');
        }

        if (! empty($in['staff_id']) && ! Staff::query()->where('company_id', $branch->company_id)->whereNull('archived_at')->whereKey($in['staff_id'])->exists()) {
            throw Errors::make(422, 'staff_unknown', field: 'staff_id');
        }

        return QueueEntry::create([
            'id' => $in['id'] ?? null,
            'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'device_id' => $in['device_id'] ?? null, 'client_id' => $client?->id,
            'guest_name' => mb_substr($name, 0, 120), 'guest_phone' => $client === null ? ($in['guest_phone'] ?? null) : null,
            'service_ids' => array_values($services), 'staff_id' => $in['staff_id'] ?? null, 'status' => QueueEntry::WAITING,
            'joined_at' => isset($in['joined_at']) ? CarbonImmutable::parse($in['joined_at'])->utc() : now(), 'created_by' => $by?->id,
        ])->refresh();
    }

    /**
     * Seats a waiting entry: a walk-in appointment starting now, every line with $staffId. A clash refuses the seating
     * (`slot_taken`) and leaves the entry waiting.
     *
     * @return array{entry: QueueEntry, appointment: Appointment}
     */
    public function seat(QueueEntry $entry, string $staffId, ?string $resourceId, User $by, ?string $appointmentId = null, ?string $deviceId = null, ?string $locationId = null): array
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($entry, $staffId, $resourceId, $by, $appointmentId, $deviceId, $locationId) {
            $locked = QueueEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === QueueEntry::SEATED && $locked->appointment_id !== null) {
                return ['entry' => $locked, 'appointment' => Appointment::query()->findOrFail($locked->appointment_id)];
            }

            if ($locked->status !== QueueEntry::WAITING) {
                throw Errors::make(409, 'queue_entry_closed');
            }

            if ($locked->service_ids === []) {
                throw Errors::make(422, 'queue_no_services');
            }

            $appointment = $this->bookings->create([
                'id' => $appointmentId, 'branch_id' => $locked->branch_id, 'client_id' => $locked->client_id, 'guest_name' => $locked->guest_name, 'guest_phone' => $locked->guest_phone,
                'source' => 'walk_in', 'starts_at' => now()->toIso8601String(), 'device_id' => $deviceId, 'location_id' => $locationId,
                'lines' => array_map(fn (string $id) => ['service_id' => $id, 'staff_id' => $staffId, 'resource_id' => $resourceId], $locked->service_ids),
            ], $by);

            $locked->forceFill(['status' => QueueEntry::SEATED, 'appointment_id' => $appointment->id, 'seated_at' => now()])->save();

            return ['entry' => $locked->refresh(), 'appointment' => $appointment];
        });
    }

    public function leave(QueueEntry $entry): QueueEntry
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($entry) {
            $locked = QueueEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === QueueEntry::WAITING) {
                $locked->forceFill(['status' => QueueEntry::LEFT, 'left_at' => now()])->save();
            }

            return $locked->refresh();
        });
    }

    /**
     * Minutes each waiting entry of a branch would wait: entries in order take the qualified staff member free earliest
     * (their lines already booked now count), for the entry's total service time.
     *
     * @return array<string, int> entry id => minutes
     */
    public function waits(string $branchId): array
    {
        $now = CarbonImmutable::now();
        $waiting = QueueEntry::query()->where('branch_id', $branchId)->where('status', QueueEntry::WAITING)->orderBy('joined_at')->orderBy('id')->get();

        if ($waiting->isEmpty()) {
            return [];
        }

        $staff = Staff::query()->whereNull('archived_at')->where('company_id', $waiting->first()->company_id)->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))->get();
        $free = [];

        foreach ($staff as $member) {
            $until = Line::query()->where('staff_id', $member->id)->where('holds', true)->where('starts_at', '<=', $now)->max('blocked_until');
            $free[$member->id] = max($now->getTimestamp(), $until === null ? 0 : CarbonImmutable::parse($until)->getTimestamp());
        }

        $services = Service::query()->whereKey($waiting->pluck('service_ids')->flatten()->unique()->all())->get()->keyBy('id');
        $out = [];

        foreach ($waiting as $entry) {
            $minutes = (int) collect($entry->service_ids)->sum(fn (string $id) => ($services->get($id)?->duration_minutes ?? 30) + ($services->get($id)?->buffer_minutes ?? 0));
            $options = array_filter($free, fn ($at, $id) => $entry->staff_id === null || $entry->staff_id === $id, ARRAY_FILTER_USE_BOTH);

            if ($options === []) {
                continue;
            }

            asort($options);
            $id = array_key_first($options);
            $out[$entry->id] = max(0, (int) ceil(($free[$id] - $now->getTimestamp()) / 60));
            $free[$id] = max($free[$id], $now->getTimestamp()) + $minutes * 60;
        }

        return $out;
    }
}
