<?php

namespace Modules\Salon\Http\Controllers;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\Salon\Appointments\Bookings;
use Modules\Salon\Availability\StaffCalendar;
use Modules\Salon\Http\Requests\ListAppointmentsRequest;
use Modules\Salon\Http\Requests\ViewRequest;
use Modules\Salon\Http\Resources\Views;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\Resource;
use Modules\Salon\Models\Staff;
use Modules\Salon\Support\Errors;
use Modules\Salon\Support\Places;
use Modules\Salon\Support\Settings;

/** SAL-06, SAL-07: open times for a booking, and the day's calendar per staff member and resource. */
class AvailabilityController
{
    public function __construct(
        private readonly Bookings $bookings,
        private readonly Places $places,
        private readonly Views $views,
        private readonly StaffCalendar $calendar,
        private readonly ScopeResolver $resolver,
    ) {}

    public function availability(ViewRequest $request): JsonResponse
    {
        $branch = $this->places->openBranch((string) $request->input('branch_id'));
        $date = (string) ($request->input('date') ?: throw Errors::make(422, 'date_required', field: 'date'));
        $services = (array) $request->input('service_ids', []);

        if ($services === []) {
            throw Errors::make(422, 'service_unknown', field: 'service_ids');
        }

        $wanted = $this->bookings->wanted($branch->company_id, array_map(fn (string $id) => ['service_id' => $id, 'staff_id' => $request->input('staff_id')], array_values($services)));
        $slots = $this->bookings->slots($branch, $branch->company_id, $wanted, $date, Settings::for($branch->company_id)['slot_minutes']);

        return response()->json(['data' => ['date' => $date, 'slots' => array_map(fn (array $slot) => [
            'starts_at' => $slot['starts_at']->toIso8601String(), 'ends_at' => $slot['ends_at']->toIso8601String(),
            'lines' => array_map(fn (array $l) => [
                'service_id' => $l['service']->id, 'staff_id' => $l['staff']->id, 'staff_name' => $l['staff']->name, 'resource_id' => $l['resource']?->id,
                'starts_at' => $l['starts_at']->toIso8601String(), 'ends_at' => $l['ends_at']->toIso8601String(),
            ], $slot['lines']),
        ], $slots)]]);
    }

    /**
     * The calendar of a branch for one or more days: each staff member's duty and off spans, the resources, and the
     * appointments the reader may see (everything at the place with `view`, their own with `view_own`).
     */
    public function calendar(ListAppointmentsRequest $request): JsonResponse
    {
        $branch = $this->places->openBranch((string) $request->input('branch_id'));
        $date = (string) ($request->input('date') ?: CarbonImmutable::now()->setTimezone($this->places->zone($branch))->toDateString());
        [$from] = $this->places->day($branch, $date);
        $to = $from->addDays((int) $request->input('days', 1));
        $staff = Staff::query()->where('company_id', $branch->company_id)->whereNull('archived_at')->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))->orderBy('name')->orderBy('id')->get();
        $spans = $this->calendar->spans($staff, $branch, $from, $to);
        $iso = fn (array $pairs) => array_map(fn (array $p) => [$p[0]->toIso8601String(), $p[1]->toIso8601String()], $pairs);

        $all = $this->resolver->can($request->user(), 'salon.appointment.view', $branch->id === '' ? null : Scope::branch($branch->id));
        $own = $staff->where('user_id', $request->user()->id)->pluck('id')->all();
        $appointments = Appointment::query()->where('branch_id', $branch->id)->where('starts_at', '>=', $from)->where('starts_at', '<', $to)
            ->whereIn('status', Appointment::HOLDING)
            ->when(! $all, fn ($q) => $q->whereIn('id', Line::query()->whereIn('staff_id', $own)->select('appointment_id')))
            ->orderBy('starts_at')->orderBy('id')->get();

        return response()->json(['data' => [
            'date' => $date, 'days' => (int) $request->input('days', 1), 'timezone' => $this->places->zone($branch),
            'staff' => $staff->map(fn (Staff $s) => ['id' => $s->id, 'name' => $s->name, 'title' => $s->title, 'color' => $s->color, 'work' => $iso($spans[$s->id]['work'] ?? []), 'off' => $iso($spans[$s->id]['off'] ?? [])])->values()->all(),
            'resources' => $this->views->resources(Resource::query()->where('branch_id', $branch->id)->whereNull('archived_at')->orderBy('name')->get()),
            'appointments' => $this->views->appointments($appointments, $request),
        ]]);
    }
}
