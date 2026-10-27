<?php

namespace Modules\Salon\Appointments;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Salon\Availability\Planner;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\Resource;
use Modules\Salon\Models\Service;
use Modules\Salon\Models\Staff;
use Modules\Salon\Reminders\Notices;
use Modules\Salon\SalonServiceProvider;
use Modules\Salon\Support\Errors;
use Modules\Salon\Support\Parties;
use Modules\Salon\Support\Places;
use Modules\Salon\Support\Settings;

/**
 * SAL-06 to SAL-09, SAL-12: booking and rebooking.
 *
 * Every way of taking staff time (the front desk, a till, the public page, the walk-in queue) comes through here, so
 * double booking is refused once, in one place (SAL-08): the staff and resource rows that may host the lines are
 * locked in id order inside the transaction, the plan reads what is taken after the locks, and the lines are written
 * before they are released. Two requests for the same time give one booking and one `slot_taken` (409).
 */
class Bookings
{
    public const NUMBER_TYPE = 'salon.appointment';

    public const NUMBER = 'SAL-{BRANCH}-{YYYY}-{00001}';

    /** Plan failures that mean "someone else has it" (409) rather than "not on offer" (422). */
    private const TAKEN = ['slot_taken', 'resource_taken'];

    public function __construct(
        private readonly Planner $planner,
        private readonly Pricing $pricing,
        private readonly Numbering $numbering,
        private readonly Places $places,
        private readonly Parties $parties,
        private readonly Auditor $auditor,
        private readonly Notices $notices,
    ) {}

    public static function numberType(): DocumentNumberType
    {
        return new DocumentNumberType(self::NUMBER_TYPE, SalonServiceProvider::MODULE, self::NUMBER, NumberFormat::RESET_YEARLY, ['BRANCH'], langKey: 'salon.numbering.appointment');
    }

    /**
     * @param  array{branch_id: string, starts_at: string, lines: list<array{service_id: string, staff_id?: ?string, resource_id?: ?string}>, client_id?: ?string, guest_name?: ?string, guest_phone?: ?string, guest_email?: ?string, notes?: ?string, locale?: ?string, source?: string, status?: string, id?: ?string, device_id?: ?string, location_id?: ?string, idempotency_key?: ?string, hold_deposit?: bool}  $in
     * @param  ?string  $overrideReason  set only after the caller checked `salon.appointment.override`: hours and time off are then not applied (the clash check always is)
     */
    public function create(array $in, ?User $by, ?string $overrideReason = null): Appointment
    {
        $branch = $this->places->openBranch((string) $in['branch_id']);
        $company = Company::query()->findOrFail($branch->company_id);
        $source = (string) ($in['source'] ?? 'staff');

        if (! empty($in['location_id'])) {
            $this->places->openLocation((string) $in['location_id'], $branch->id);
        }

        $client = ! empty($in['client_id']) ? $this->parties->customer((string) $in['client_id'], $company->id) : null;

        if ($client === null && trim((string) ($in['guest_name'] ?? '')) === '') {
            throw Errors::make(422, 'client_required', field: 'guest_name');
        }

        $start = CarbonImmutable::parse($in['starts_at'])->utc();
        $wanted = $this->wanted($company->id, $in['lines']);
        $prices = $this->prices($wanted, $company, $start);

        $appointment = Errors::transaction(function () use ($in, $by, $overrideReason, $branch, $company, $client, $start, $wanted, $prices, $source) {
            $plan = $this->planLocked($branch, $company->id, $wanted, $start, $overrideReason, null);
            $settings = Settings::for($company->id);
            $total = array_sum($prices);
            $end = collect($plan)->max(fn (array $l) => $l['ends_at']);
            $walkIn = $source === 'walk_in';
            $status = (string) ($in['status'] ?? ($walkIn ? Appointment::CHECKED_IN : Appointment::BOOKED));
            $deposit = $walkIn ? 0 : self::percentOf($total, $settings['deposit_percent']);
            $hold = $source === 'online' && $settings['deposit_online_required'] && $deposit > 0 ? now()->addMinutes($settings['hold_minutes']) : null;

            $appointment = Appointment::create([
                'id' => $in['id'] ?? (string) Str::uuid7(),
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'location_id' => $in['location_id'] ?? null,
                'device_id' => $in['device_id'] ?? null,
                'number' => $this->numbering->next(self::NUMBER_TYPE, new NumberContext($company, $branch))->number,
                'client_id' => $client?->id,
                'guest_name' => $client === null ? mb_substr(trim((string) $in['guest_name']), 0, 120) : null,
                'guest_phone' => $client === null ? ($in['guest_phone'] ?? null) : null,
                'guest_email' => $client === null ? ($in['guest_email'] ?? null) : null,
                'locale' => in_array($in['locale'] ?? 'en', ['en', 'fr'], true) ? ($in['locale'] ?? 'en') : 'en',
                'source' => $source,
                'status' => $status,
                'starts_at' => $start,
                'ends_at' => $end,
                'total_minor' => $total,
                'currency' => $company->base_currency,
                'deposit_required_minor' => $deposit,
                'deposit_hold_until' => $hold,
                'notes' => isset($in['notes']) ? mb_substr((string) $in['notes'], 0, 500) : null,
                'checked_in_at' => $status === Appointment::CHECKED_IN ? now() : null,
                'idempotency_key' => $in['idempotency_key'] ?? null,
                'created_by' => $by?->id,
            ]);

            $this->writeLines($appointment, $plan, $prices, $company->base_currency);

            if ($overrideReason !== null) {
                $this->auditor->record('salon.appointment.override', $appointment, null, ['reason' => $overrideReason, 'starts_at' => $start->toIso8601String()]);
            }

            $this->afterCommit(fn () => $this->notices->send(Notices::CONFIRMED, $appointment->refresh()));

            return $appointment;
        });

        return $appointment->refresh();
    }

    /**
     * SAL-09: moves an open appointment to another time, staff member or resource. The old time is freed in the same
     * transaction (its lines are rewritten in place after the locks), so a failed move changes nothing.
     *
     * @param  list<array{id: string, staff_id?: ?string, resource_id?: ?string}>  $changes
     */
    public function move(Appointment $appointment, string $startsAt, array $changes, User $by, ?string $overrideReason = null): Appointment
    {
        $start = CarbonImmutable::parse($startsAt)->utc();

        return Errors::transaction(function () use ($appointment, $start, $changes, $overrideReason) {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, Appointment::OPEN, true)) {
                throw Errors::make(409, 'appointment_not_open');
            }

            $branch = $this->places->openBranch($locked->branch_id);
            $company = Company::query()->findOrFail($locked->company_id);
            $lines = Line::query()->where('appointment_id', $locked->id)->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $by_id = collect($changes)->keyBy('id');

            foreach ($by_id as $id => $unused) {
                if (! $lines->contains('id', $id)) {
                    throw Errors::make(422, 'line_unknown', field: 'lines');
                }
            }

            $wanted = $lines->map(fn (Line $line) => [
                'service' => Service::query()->findOrFail($line->service_id),
                'staff_id' => $by_id->get($line->id)['staff_id'] ?? $line->staff_id,
                'resource_id' => array_key_exists('resource_id', (array) $by_id->get($line->id)) ? $by_id->get($line->id)['resource_id'] : $line->resource_id,
            ])->values()->all();

            $plan = $this->planLocked($branch, $company->id, $wanted, $start, $overrideReason, $locked->id);
            $before = ['starts_at' => $locked->starts_at->toIso8601String(), 'staff' => $lines->pluck('staff_id')->all()];

            foreach ($lines as $index => $line) {
                $planned = $plan[$index];
                $line->forceFill([
                    'staff_id' => $planned['staff']->id, 'resource_id' => $planned['resource']?->id,
                    'starts_at' => $planned['starts_at'], 'ends_at' => $planned['ends_at'], 'blocked_until' => $planned['blocked_until'],
                ])->save();
            }

            $locked->forceFill(['starts_at' => $start, 'ends_at' => collect($plan)->max(fn (array $l) => $l['ends_at'])])->save();
            $this->auditor->record('salon.appointment.move', $locked, $before, ['starts_at' => $start->toIso8601String(), 'staff' => collect($plan)->map(fn (array $l) => $l['staff']->id)->all(), 'override_reason' => $overrideReason]);
            $this->afterCommit(fn () => $this->notices->send(Notices::CONFIRMED, $locked->refresh()));

            return $locked->refresh();
        });
    }

    /**
     * Locks the staff and resources that may host the lines, reads what is taken and plans (SAL-08). Runs inside the
     * caller's transaction: the locks are released at its end.
     *
     * @param  list<array{service: Service, staff_id?: ?string, resource_id?: ?string}>  $wanted
     * @return list<array{service: Service, staff: Staff, resource: ?resource, starts_at: CarbonImmutable, ends_at: CarbonImmutable, blocked_until: CarbonImmutable}>
     */
    public function planLocked(Branch $branch, string $companyId, array $wanted, CarbonImmutable $start, ?string $overrideReason, ?string $ignoreAppointment): array
    {
        [$staff, $resources] = $this->lock($branch, $companyId, $wanted);

        $minutes = (int) collect($wanted)->sum(fn (array $w) => $w['service']->duration_minutes + $w['service']->buffer_minutes);
        $context = $this->planner->context($branch, $staff, $resources, $start->subDay(), $start->addMinutes($minutes + 1), $ignoreAppointment);
        $plan = $this->planner->plan($context, $wanted, $start, $overrideReason === null);

        if (is_string($plan) && $overrideReason !== null && in_array($plan, ['outside_hours', 'staff_off'], true)) {
            $plan = $this->planner->plan($context, $wanted, $start, false);
        }

        if (is_string($plan)) {
            throw in_array($plan, self::TAKEN, true) ? Errors::make(409, 'slot_taken') : Errors::make(422, $plan, field: 'lines');
        }

        return $plan;
    }

    /**
     * SAL-06: the open start times of a day, from the grid of the company (read without locks: a time shown here is
     * checked again when it is booked).
     *
     * @param  list<array{service: Service, staff_id?: ?string, resource_id?: ?string}>  $wanted
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, lines: list<array<string, mixed>>}>
     */
    public function slots(Branch $branch, string $companyId, array $wanted, string $date, int $stepMinutes, ?CarbonImmutable $notBefore = null): array
    {
        [$from, $to] = $this->places->day($branch, $date);
        $staff = $this->pool($branch, $companyId, $wanted);
        $resources = $this->resourcePool($branch, $wanted);
        $context = $this->planner->context($branch, $staff, $resources, $from->subDay(), $to->addDay());
        $slots = [];

        for ($at = $from; $at->lessThan($to); $at = $at->addMinutes($stepMinutes)) {
            if ($notBefore !== null && $at->lessThan($notBefore)) {
                continue;
            }

            $plan = $this->planner->plan($context, $wanted, $at);

            if (is_array($plan)) {
                $slots[] = ['starts_at' => $at, 'ends_at' => collect($plan)->max(fn (array $l) => $l['ends_at']), 'lines' => $plan];
            }
        }

        return $slots;
    }

    /**
     * The services a request names, in order, read under the tenant (another company's or an archived one is not found),
     * an add-on only after a service that offers it.
     *
     * @param  list<array{service_id: string, staff_id?: ?string, resource_id?: ?string}>  $lines
     * @return list<array{service: Service, staff_id: ?string, resource_id: ?string}>
     */
    public function wanted(string $companyId, array $lines, bool $online = false): array
    {
        $services = Service::query()->where('company_id', $companyId)->whereNull('archived_at')->when($online, fn ($q) => $q->where('online_bookable', true))
            ->whereKey(array_column($lines, 'service_id'))->get()->keyBy('id');
        $wanted = [];
        $offered = [];

        foreach ($lines as $index => $line) {
            $service = $services->get($line['service_id'] ?? '');

            if ($service === null) {
                throw Errors::make(422, 'service_unknown', field: "lines.{$index}.service_id");
            }

            if ($service->kind === 'addon' && ! in_array($service->id, $offered, true)) {
                throw Errors::make(422, 'addon_not_offered', field: "lines.{$index}.service_id");
            }

            $offered = [...$offered, ...(array) $service->addon_ids];
            $wanted[] = ['service' => $service, 'staff_id' => $line['staff_id'] ?? null, 'resource_id' => $line['resource_id'] ?? null, 'online' => $online];
        }

        return $wanted;
    }

    /** The list price of each wanted service (422 `price_missing` when the item has none). @return list<int> */
    public function prices(array $wanted, Company $company, CarbonImmutable $at): array
    {
        return array_map(function (array $w, int $index) use ($company, $at) {
            return $this->pricing->unit($w['service'], $company, $at) ?? throw Errors::make(422, 'price_missing', ['service' => $w['service']->name], "lines.{$index}.service_id");
        }, $wanted, array_keys($wanted));
    }

    /** @param list<array<string, mixed>> $plan */
    public function writeLines(Appointment $appointment, array $plan, array $prices, string $currency): void
    {
        foreach ($plan as $index => $line) {
            Line::create([
                'appointment_id' => $appointment->id,
                'service_id' => $line['service']->id,
                'item_id' => $line['service']->item_id,
                'staff_id' => $line['staff']->id,
                'resource_id' => $line['resource']?->id,
                'position' => $index + 1,
                'kind' => $line['service']->kind,
                'starts_at' => $line['starts_at'],
                'ends_at' => $line['ends_at'],
                'blocked_until' => $line['blocked_until'],
                'price_minor' => $prices[$index],
                'currency' => $currency,
                'holds' => true,
            ]);
        }
    }

    /** a percentage of an amount, rounded half up to the minor unit */
    public static function percentOf(int $amount, string|int|float $percent): int
    {
        return (int) BigDecimal::of((string) $amount)->multipliedBy((string) $percent)->dividedBy(100, 0, RoundingMode::HALF_UP)->toBigInteger()->toInt();
    }

    /**
     * @param  list<array{service: Service, staff_id?: ?string, resource_id?: ?string}>  $wanted
     * @return array{0: Collection<int, Staff>, 1: Collection<int, resource>}
     */
    private function lock(Branch $branch, string $companyId, array $wanted): array
    {
        $staffIds = $this->pool($branch, $companyId, $wanted)->modelKeys();
        $resourceIds = $this->resourcePool($branch, $wanted)->modelKeys();
        sort($staffIds);
        sort($resourceIds);

        // In id order, always: two requests asking for the same rows then wait for each other instead of deadlocking.
        $staff = Staff::query()->whereKey($staffIds)->orderBy('id')->lockForUpdate()->get();
        $resources = Resource::query()->whereKey($resourceIds)->orderBy('id')->lockForUpdate()->get();

        return [$staff, $resources];
    }

    /** The active staff of the branch that may be picked for the wanted lines. */
    private function pool(Branch $branch, string $companyId, array $wanted)
    {
        $named = array_values(array_filter(array_column($wanted, 'staff_id')));
        $any = collect($wanted)->contains(fn (array $w) => ($w['staff_id'] ?? null) === null);
        $qualified = collect($wanted)->flatMap(fn (array $w) => ($w['staff_id'] ?? null) === null ? ($w['service']->staff_ids ?: ['*']) : [])->unique()->values()->all();

        $online = collect($wanted)->contains(fn (array $w) => $w['online'] ?? false);

        return Staff::query()->where('company_id', $companyId)->whereNull('archived_at')->when($online, fn ($q) => $q->where('accepts_online', true))
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->where(function ($q) use ($named, $any, $qualified) {
                $q->whereKey($named);

                if ($any) {
                    $qualified === ['*'] || in_array('*', $qualified, true) ? $q->orWhereRaw('true') : $q->orWhereIn('id', $qualified);
                }
            })->orderBy('id')->get();
    }

    private function resourcePool(Branch $branch, array $wanted)
    {
        $named = array_values(array_filter(array_column($wanted, 'resource_id')));
        $configured = collect($wanted)->flatMap(fn (array $w) => (array) $w['service']->resource_ids)->unique()->values()->all();
        $ids = array_values(array_unique([...$named, ...$configured]));

        return $ids === [] ? Resource::query()->whereRaw('false')->get() : Resource::query()->where('branch_id', $branch->id)->whereNull('archived_at')->whereKey($ids)->orderBy('id')->get();
    }

    private function afterCommit(callable $fn): void
    {
        DB::connection(TenantContext::CONNECTION)->afterCommit(fn () => rescue($fn, report: true));
    }
}
