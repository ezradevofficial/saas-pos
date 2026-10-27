<?php

namespace Modules\Salon\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Rbac\VisibleScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Modules\Salon\Appointments\Bookings;
use Modules\Salon\Appointments\Lifecycle;
use Modules\Salon\Http\Requests\ActionRequest;
use Modules\Salon\Http\Requests\DepositRefundRequest;
use Modules\Salon\Http\Requests\DepositRequest;
use Modules\Salon\Http\Requests\ListAppointmentsRequest;
use Modules\Salon\Http\Requests\MoveAppointmentRequest;
use Modules\Salon\Http\Requests\NotesRequest;
use Modules\Salon\Http\Requests\ReasonRequest;
use Modules\Salon\Http\Requests\ServeRequest;
use Modules\Salon\Http\Requests\StoreAppointmentRequest;
use Modules\Salon\Http\Requests\ViewAppointmentRequest;
use Modules\Salon\Http\Resources\Views;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\Package;
use Modules\Salon\Models\Payment;
use Modules\Salon\Models\Staff;
use Modules\Salon\Payments\Deposits;
use Modules\Salon\Support\Errors;
use Modules\Salon\Support\Places;
use Modules\Salon\Support\Settings;

/** SAL-07 to SAL-13, SAL-16: appointments, their day, deposits and the checkout the till rings. */
class AppointmentController
{
    use Paginates;

    public function __construct(
        private readonly Bookings $bookings,
        private readonly Lifecycle $lifecycle,
        private readonly Deposits $deposits,
        private readonly Views $views,
        private readonly Places $places,
        private readonly ScopeResolver $resolver,
        private readonly Auditor $auditor,
    ) {}

    public function index(ListAppointmentsRequest $request): JsonResponse
    {
        $query = $this->visible(Appointment::query(), $request)->orderBy('starts_at')->orderBy('id');
        $zone = $request->filled('branch_id') ? $this->places->zone((string) $request->input('branch_id')) : 'UTC';

        foreach (['company_id', 'branch_id', 'client_id', 'status', 'source'] as $filter) {
            $request->filled($filter) && $query->where($filter, $request->input($filter));
        }

        $request->filled('staff_id') && $query->whereIn('id', Line::query()->where('staff_id', $request->input('staff_id'))->select('appointment_id'));
        $request->filled('search') && $query->where(fn ($q) => $q->where('number', 'ilike', '%'.addcslashes((string) $request->input('search'), '\\%_').'%')
            ->orWhere('guest_name', 'ilike', '%'.addcslashes((string) $request->input('search'), '\\%_').'%'));

        if ($request->filled('date')) {
            $from = CarbonImmutable::parse($request->input('date').' 00:00:00', $zone)->utc();
            $query->where('starts_at', '>=', $from)->where('starts_at', '<', $from->addDay());
        } else {
            $request->filled('from') && $query->where('starts_at', '>=', CarbonImmutable::parse($request->input('from').' 00:00:00', $zone)->utc());
            $request->filled('to') && $query->where('starts_at', '<', CarbonImmutable::parse($request->input('to').' 00:00:00', $zone)->addDay()->utc());
        }

        return $this->paged($query, $request, fn ($rows) => $this->views->appointments($rows, $request));
    }

    public function show(ViewAppointmentRequest $request, Appointment $salonAppointment): JsonResponse
    {
        return response()->json(['data' => $this->views->appointments(collect([$salonAppointment]), $request, true)[0]]);
    }

    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $override = $this->override($request, Scope::branch($data['branch_id']), $data['override_reason'] ?? null);

        $appointment = $this->bookings->create([
            ...$data, 'client_id' => $data['salon_client_id'] ?? null, 'source' => $data['source'] ?? 'staff', 'lines' => $data['lines'],
        ], $request->user(), $override);

        return response()->json(['data' => $this->views->appointments(collect([$appointment]), $request, true)[0]], 201);
    }

    public function move(MoveAppointmentRequest $request, Appointment $salonAppointment): JsonResponse
    {
        $data = $request->validated();
        $override = $this->override($request, $salonAppointment->scope(), $data['override_reason'] ?? null);
        $moved = $this->bookings->move($salonAppointment, $data['starts_at'], $data['lines'] ?? [], $request->user(), $override);

        return response()->json(['data' => $this->views->appointments(collect([$moved]), $request, true)[0]]);
    }

    public function checkIn(ServeRequest $request, Appointment $salonAppointment): JsonResponse
    {
        return $this->respond($request, $this->lifecycle->checkIn($salonAppointment, $request->user()));
    }

    public function start(ServeRequest $request, Appointment $salonAppointment): JsonResponse
    {
        return $this->respond($request, $this->lifecycle->start($salonAppointment, $request->user()));
    }

    public function finish(ServeRequest $request, Appointment $salonAppointment): JsonResponse
    {
        return $this->respond($request, $this->lifecycle->finish($salonAppointment, $request->user()));
    }

    public function cancel(ReasonRequest $request, Appointment $salonAppointment): JsonResponse
    {
        return $this->respond($request, $this->lifecycle->cancel($salonAppointment, (string) $request->input('reason'), $request->user()));
    }

    public function noShow(ReasonRequest $request, Appointment $salonAppointment): JsonResponse
    {
        return $this->respond($request, $this->lifecycle->noShow($salonAppointment, (string) $request->input('reason'), $request->user()));
    }

    public function waiveFee(ReasonRequest $request, Appointment $salonAppointment): JsonResponse
    {
        return $this->respond($request, $this->lifecycle->waiveFee($salonAppointment, (string) $request->input('reason'), $request->user()));
    }

    /** SAL-25: the formula used or notes of the visit; audited by field name, never by value. */
    public function notes(NotesRequest $request, Appointment $salonAppointment): JsonResponse
    {
        $salonAppointment->forceFill(['service_notes' => $request->input('service_notes')])->save();
        $this->auditor->record('salon.appointment.notes', $salonAppointment, null, ['fields' => ['service_notes']]);

        return $this->respond($request, $salonAppointment->refresh());
    }

    /** SAL-16: what the till rings for this appointment. The prices on the sale come from the price list; these are the plan's. */
    public function checkout(ViewAppointmentRequest $request, Appointment $salonAppointment): JsonResponse
    {
        $settings = Settings::for($salonAppointment->company_id);
        $lines = Line::query()->where('appointment_id', $salonAppointment->id)->orderBy('position')->orderBy('id')->get();
        $view = $this->views->appointments(collect([$salonAppointment]), $request)[0];
        $packages = $salonAppointment->client_id === null ? collect() : Package::query()->where('customer_id', $salonAppointment->client_id)->where('status', Package::ACTIVE)->orderBy('id')->get()
            ->filter(fn (Package $p) => $p->sessionsLeft() > 0);

        return response()->json(['data' => [
            'appointment_id' => $salonAppointment->id, 'number' => $salonAppointment->number, 'status' => $salonAppointment->status, 'currency' => $salonAppointment->currency,
            'client_id' => $salonAppointment->client_id, 'client_name' => $view['client_name'],
            'lines' => collect($view['lines'])->map(fn (array $l) => ['line_id' => $l['id'], 'item_id' => $l['item_id'], 'service_id' => $l['service_id'], 'name' => $l['service_name'], 'staff_id' => $l['staff_id'], 'price_minor' => $l['price_minor'], 'kind' => $l['kind']])->values()->all(),
            'fee' => $salonAppointment->fee_status === 'due' && $settings['fee_item_id'] !== null ? ['item_id' => $settings['fee_item_id'], 'amount_minor' => (string) $salonAppointment->fee_minor, 'reason' => $salonAppointment->fee_reason] : null,
            'tip_item_id' => $settings['tip_item_id'], 'deposit_balance_minor' => (string) $this->deposits->balance($salonAppointment),
            'packages' => $packages->map(fn (Package $p) => ['id' => $p->id, 'name' => $p->name, 'sessions_left' => $p->sessionsLeft(), 'session_value_minor' => (string) $p->sessionValue(), 'service_ids' => $p->service_ids, 'expires_on' => $p->expires_on?->toDateString()])->values()->all(),
            'lines_count' => $lines->count(),
        ]]);
    }

    public function deposit(DepositRequest $request, Appointment $salonAppointment): JsonResponse
    {
        $payment = $this->deposits->take($salonAppointment, $request->validated(), $request->user());

        return response()->json(['data' => $this->views->payments(collect([$payment]))[0]], match ($payment->status) {
            Payment::FAILED => 422,
            Payment::PENDING => 202,
            default => 201,
        });
    }

    public function refundDeposit(DepositRefundRequest $request, Appointment $salonAppointment): JsonResponse
    {
        $payment = $this->deposits->refund($salonAppointment, $request->validated(), $request->user());

        return response()->json(['data' => $this->views->payments(collect([$payment]))[0]], 201);
    }

    public function checkPayment(ActionRequest $request, Payment $salonPayment): JsonResponse
    {
        return response()->json(['data' => $this->views->payments(collect([$this->deposits->check($salonPayment)]))[0]]);
    }

    /**
     * Narrows a list to what the reader may see: every appointment at the places where they hold `view`, plus their own
     * (as staff) at the places where they hold only `view_own`.
     */
    private function visible(Builder $query, ListAppointmentsRequest $request): Builder
    {
        $user = $request->user();
        $all = $this->resolver->visibleIds($user, 'salon.appointment.view');
        $own = $this->resolver->visibleIds($user, 'salon.appointment.view_own');
        $ownIds = Staff::query()->where('user_id', $user->id)->whereNull('archived_at')->pluck('id')->all();

        return $query->where(fn (Builder $w) => $w
            ->where(fn (Builder $a) => $this->inPlaces($a, $all))
            ->orWhere(fn (Builder $b) => $this->inPlaces($b, $own)->whereIn('id', Line::query()->whereIn('staff_id', $ownIds)->select('appointment_id'))));
    }

    private function inPlaces(Builder $query, VisibleScope $visible): Builder
    {
        return $visible->all ? $query->whereRaw('true') : $query->where(fn (Builder $q) => $q->whereIn('branch_id', $visible->idsFor(Scope::BRANCH))->orWhereIn('location_id', $visible->idsFor(Scope::LOCATION)));
    }

    /** The reason to book outside hours: allowed only to a holder of `salon.appointment.override` at the place (audited by Bookings). */
    private function override(FormRequest $request, Scope $scope, ?string $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        if (! $this->resolver->can($request->user(), 'salon.appointment.override', $scope)) {
            throw Errors::make(403, 'override_not_permitted', field: 'override_reason');
        }

        return $reason;
    }

    private function respond(FormRequest $request, Appointment $appointment): JsonResponse
    {
        return response()->json(['data' => $this->views->appointments(collect([$appointment]), $request, true)[0]]);
    }
}
