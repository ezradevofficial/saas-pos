<?php

namespace Modules\Salon\Sync;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\POS\Sync\Authority;
use Modules\POS\Sync\DevicePlace;
use Modules\Salon\Appointments\Bookings;
use Modules\Salon\Appointments\Lifecycle;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\QueueEntry;
use Modules\Salon\Queue\Queue;
use Modules\Salon\Support\Access;
use Modules\Salon\Support\Errors;

/**
 * SAL-29, SAL-13, SAL-12, NFR-04, ADR 004: what a salon till sends back (appointments made at the till, the steps of the
 * day, the walk-in queue).
 *
 * - **Idempotent.** The same record again answers the same. Steps only move forward, so an old record arriving late
 *   changes nothing. Each record has its own transaction: one refused record never blocks the rest.
 * - **Checked against the device.** The branch is the one the device sits at; every id is read under the tenant; the
 *   person named must hold the permission at the device's place and carry a verified sign-in proof (`actor_proof`,
 *   AUTH-07) — the device names them, so the server checks.
 * - **Double booking is refused** (`slot_taken`) exactly as on the web: a till cannot take time that is taken.
 */
class DeviceUploads
{
    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly Authority $authority,
        private readonly Bookings $bookings,
        private readonly Lifecycle $lifecycle,
        private readonly Queue $queue,
        private readonly Access $access,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{appointments?: list<array>, events?: list<array>, queue?: list<array>}  $records
     * @return array{appointments: list<array>, events: list<array>, queue: list<array>}
     */
    public function upload(DevicePlace $place, array $records): array
    {
        $results = [];

        foreach (['appointments' => 'appointment', 'events' => 'event', 'queue' => 'queue'] as $key => $kind) {
            $results[$key] = array_map(fn (array $record) => $this->one($place, $kind, $record), $records[$key] ?? []);
        }

        return $results;
    }

    /** @return array<string, mixed> */
    private function one(DevicePlace $place, string $kind, array $record): array
    {
        try {
            return Errors::transaction(fn () => match ($kind) {
                'appointment' => $this->appointment($place, $record),
                'event' => $this->event($place, $record),
                'queue' => $this->queue($place, $record),
            });
        } catch (ApiException $e) {
            return ['id' => $record['id'], 'status' => 'rejected', 'code' => $e->errorCode, 'message' => $e->getMessage(), 'field' => array_key_first($e->errors)];
        } catch (UniqueConstraintViolationException) {
            return ['id' => $record['id'], 'status' => 'rejected', 'code' => 'id_conflict', 'message' => __('salon.errors.id_conflict'), 'field' => 'id'];
        }
    }

    /**
     * The person a record names (AUTH-07). They exist, are active, hold the permission at the device's place and carry a
     * verified sign-in attestation.
     *
     * @param  array<string, mixed>  $data
     */
    public function actor(DevicePlace $place, array $data, string $permission): User
    {
        $user = User::query()->find($data['user_id'] ?? '');

        if ($user === null || ! $user->isActive()) {
            throw Errors::make(422, 'actor_unknown', field: 'user_id');
        }

        if (! $this->resolver->can($user, $permission, $place->scope())) {
            throw Errors::make(403, 'not_permitted', field: 'user_id');
        }

        if ($this->authority->proven($place->device, $user, is_array($data['actor_proof'] ?? null) ? $data['actor_proof'] : null) === null) {
            throw Errors::make(422, 'actor_unverified', field: 'user_id');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function appointment(DevicePlace $place, array $data): array
    {
        $existing = Appointment::query()->find($data['id']);

        if ($existing !== null) {
            return $existing->device_id === $place->device->id && $existing->branch_id === $place->branch->id
                ? ['id' => $existing->id, 'status' => 'stored', 'number' => $existing->number, 'appointment_status' => $existing->status]
                : throw Errors::make(422, 'id_conflict', field: 'id');
        }

        $user = $this->actor($place, $data, 'salon.appointment.create');
        $override = null;

        if (! empty($data['override_reason'])) {
            $override = $this->resolver->can($user, 'salon.appointment.override', $place->scope()) ? (string) $data['override_reason'] : throw Errors::make(403, 'not_permitted', field: 'override_reason');
        }

        $walkIn = ($data['source'] ?? 'device') === 'walk_in';
        $appointment = $this->bookings->create([
            'id' => $data['id'], 'branch_id' => $place->branch->id, 'location_id' => $place->location->id, 'device_id' => $place->device->id,
            'client_id' => $data['client_id'] ?? null, 'guest_name' => $data['guest_name'] ?? null, 'guest_phone' => $data['guest_phone'] ?? null,
            'notes' => $data['notes'] ?? null, 'source' => $walkIn ? 'walk_in' : 'device', 'starts_at' => $data['starts_at'],
            'lines' => array_map(fn (array $l) => ['service_id' => $l['service_id'], 'staff_id' => $l['staff_id'] ?? null, 'resource_id' => $l['resource_id'] ?? null], $data['lines']),
        ], $user, $override);

        return ['id' => $appointment->id, 'status' => 'stored', 'number' => $appointment->number, 'appointment_status' => $appointment->status];
    }

    /** @return array<string, mixed> */
    private function event(DevicePlace $place, array $data): array
    {
        $appointment = Appointment::query()->find($data['appointment_id']);

        if ($appointment === null || $appointment->branch_id !== $place->branch->id) {
            throw Errors::make(422, 'appointment_unknown', field: 'appointment_id');
        }

        $type = (string) $data['type'];
        $serve = in_array($type, ['check_in', 'start', 'finish'], true);
        $user = $this->actor($place, $data, $serve ? 'salon.appointment.serve' : 'salon.appointment.cancel');

        // A stylist with only their own calendar serves their own appointments.
        if (! $this->access->touches($user, $appointment, $serve ? 'salon.appointment.view' : 'salon.appointment.cancel')) {
            throw Errors::make(403, 'not_permitted', field: 'appointment_id');
        }

        $at = isset($data['at']) ? CarbonImmutable::parse($data['at'])->utc() : null;
        $after = match ($type) {
            'check_in' => $this->lifecycle->checkIn($appointment, $user, $at),
            'start' => $this->lifecycle->start($appointment, $user, $at),
            'finish' => $this->lifecycle->finish($appointment, $user, $at),
            'no_show' => $this->lifecycle->noShow($appointment, (string) ($data['reason'] ?? 'no_show'), $user),
            'cancel' => $this->lifecycle->cancel($appointment, (string) ($data['reason'] ?? 'cancelled'), $user, $at),
        };

        return ['id' => $data['id'], 'status' => 'stored', 'appointment_status' => $after->status, 'fee_minor' => (string) $after->fee_minor];
    }

    /** @return array<string, mixed> */
    private function queue(DevicePlace $place, array $data): array
    {
        $user = $this->actor($place, $data, 'salon.queue.manage');
        $action = (string) $data['action'];

        if ($action === 'join') {
            $existing = QueueEntry::query()->find($data['id']);

            if ($existing !== null) {
                return $existing->device_id === $place->device->id ? ['id' => $existing->id, 'status' => 'stored', 'entry_status' => $existing->status] : throw Errors::make(422, 'id_conflict', field: 'id');
            }

            $entry = $this->queue->join([
                'id' => $data['id'], 'branch_id' => $place->branch->id, 'device_id' => $place->device->id, 'guest_name' => $data['guest_name'] ?? null, 'guest_phone' => $data['guest_phone'] ?? null,
                'client_id' => $data['client_id'] ?? null, 'service_ids' => $data['service_ids'] ?? [], 'staff_id' => $data['staff_id'] ?? null, 'joined_at' => $data['at'] ?? null,
            ], $user);

            return ['id' => $entry->id, 'status' => 'stored', 'entry_status' => $entry->status];
        }

        $entry = QueueEntry::query()->find($data['entry_id'] ?? '');

        if ($entry === null || $entry->branch_id !== $place->branch->id) {
            throw Errors::make(422, 'queue_entry_unknown', field: 'entry_id');
        }

        if ($action === 'leave') {
            return ['id' => $data['id'], 'status' => 'stored', 'entry_status' => $this->queue->leave($entry)->status];
        }

        if (empty($data['staff_id'])) {
            throw Errors::make(422, 'staff_unknown', field: 'staff_id');
        }

        $seated = $this->queue->seat($entry, (string) $data['staff_id'], $data['resource_id'] ?? null, $user, isset($data['appointment_id']) && Str::isUuid($data['appointment_id']) ? $data['appointment_id'] : null, $place->device->id, $place->location->id);

        return ['id' => $data['id'], 'status' => 'stored', 'entry_status' => $seated['entry']->status, 'appointment_id' => $seated['appointment']->id, 'number' => $seated['appointment']->number];
    }
}
