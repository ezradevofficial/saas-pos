<?php

namespace Modules\Salon\Booking;

use App\Core\Http\ApiException;
use App\Core\Identity\Support\PhoneNumber;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Modules\Ecommerce\Contracts\StorefrontCustomers;
use Modules\Salon\Appointments\Bookings;
use Modules\Salon\Appointments\Pricing;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\Payment;
use Modules\Salon\Models\SalonBranch;
use Modules\Salon\Models\Service;
use Modules\Salon\Models\Staff;
use Modules\Salon\Payments\Deposits;
use Modules\Salon\Support\Errors;
use Modules\Salon\Support\Places;
use Modules\Salon\Support\Settings;

/**
 * SAL-15, SAL-26: what the public page shows and does. No account: a booking is a name and a phone or an email, the
 * services and a time, and nothing else about the visitor is stored (no address, no browser, no profile). Only services
 * and staff the salon put online are offered; the clash, hours and time-off checks are the same as everywhere. A
 * visitor holding a signed-in storefront session (E-commerce active) is booked as that customer instead of a guest.
 *
 * The deposit of a held booking is paid by an M-Pesa push to the phone of the booking: the number and the phone must
 * match, so a stranger who knows only a number cannot start or read anything.
 */
class PublicBooking
{
    public function __construct(
        private readonly Bookings $bookings,
        private readonly Places $places,
        private readonly Deposits $deposits,
        private readonly ModuleRegistry $modules,
        private readonly StorefrontCustomers $storefront,
    ) {}

    /** @return array<string, mixed> */
    public function menu(SalonBranch $page): array
    {
        $company = Company::query()->findOrFail($page->company_id);
        $branch = $this->places->openBranch($page->branch_id);
        $settings = Settings::for($company->id);
        $services = Service::query()->where('company_id', $company->id)->whereNull('archived_at')->where('online_bookable', true)->orderBy('category')->orderBy('name')->get();
        $now = CarbonImmutable::now();

        return [
            'name' => $page->public_name ?: $branch->name,
            'currency' => $company->base_currency,
            'timezone' => $this->places->zone($branch),
            'lead_minutes' => $page->lead_minutes,
            'max_days_ahead' => $page->max_days_ahead,
            'slot_minutes' => $settings['slot_minutes'],
            'deposit_percent' => $settings['deposit_percent'],
            'deposit_required' => $settings['deposit_online_required'] && (float) $settings['deposit_percent'] > 0,
            'cancel_window_hours' => $settings['cancel_window_hours'],
            'services' => $services->map(fn (Service $s) => [
                'id' => $s->id, 'name' => $s->name, 'category' => $s->category, 'kind' => $s->kind, 'duration_minutes' => $s->duration_minutes, 'addon_ids' => $s->addon_ids,
                'price_minor' => ($price = app(Pricing::class)->unit($s, $company, $now)) === null ? null : (string) $price,
            ])->values()->all(),
            'staff' => Staff::query()->where('company_id', $company->id)->whereNull('archived_at')->where('accepts_online', true)
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))->orderBy('name')->get()
                ->map(fn (Staff $s) => ['id' => $s->id, 'name' => $s->name, 'title' => $s->title])->values()->all(),
        ];
    }

    /** @param list<string> $serviceIds @return list<array{starts_at: string, ends_at: string}> */
    public function slots(SalonBranch $page, array $serviceIds, string $date, ?string $staffId): array
    {
        $branch = $this->places->openBranch($page->branch_id);
        $now = CarbonImmutable::now();
        [$dayStart] = $this->places->day($branch, $date);
        $first = $now->addMinutes($page->lead_minutes);

        if ($dayStart->greaterThan($now->addDays($page->max_days_ahead))) {
            return [];
        }

        $wanted = $this->bookings->wanted($page->company_id, array_map(fn (string $id) => ['service_id' => $id, 'staff_id' => $staffId], $serviceIds), online: true);

        return array_map(fn (array $slot) => ['starts_at' => $slot['starts_at']->toIso8601String(), 'ends_at' => $slot['ends_at']->toIso8601String()],
            $this->bookings->slots($branch, $page->company_id, $wanted, $date, Settings::for($page->company_id)['slot_minutes'], $first));
    }

    /**
     * @param  array{guest_name: string, guest_phone?: ?string, guest_email?: ?string, service_ids: list<string>, staff_id?: ?string, starts_at: string, locale?: ?string}  $in
     * @return array<string, mixed>
     */
    public function book(SalonBranch $page, array $in, ?string $bearer, ?string $idempotencyKey): array
    {
        $key = $idempotencyKey === null ? null : mb_substr($idempotencyKey, 0, 80);
        $company = Company::query()->findOrFail($page->company_id);
        $clientId = null;

        if ($bearer !== null && $this->modules->isActive('ecommerce')) {
            $clientId = $this->storefront->partyOf($bearer, $page->company_id) ?? throw Errors::make(401, 'session_invalid');
        }

        $phone = $clientId === null ? $this->phone($in['guest_phone'] ?? null, $company->country) : null;
        $email = $clientId === null ? $this->email($in['guest_email'] ?? null) : null;

        if ($key !== null && ($existing = Appointment::query()->where('branch_id', $page->branch_id)->where('idempotency_key', $key)->first()) !== null) {
            // SAL-15: a key replays only for the contact that made the booking; anyone else's use of it is refused.
            $same = $clientId !== null ? $existing->client_id === $clientId
                : ($phone !== null && $existing->guest_phone !== null && self::samePhone($existing->guest_phone, $phone))
                    || ($email !== null && $existing->guest_email !== null && strtolower($existing->guest_email) === $email);

            return $same ? $this->view($existing) : throw Errors::make(409, 'idempotency_conflict');
        }

        $branch = $this->places->openBranch($page->branch_id);
        $start = CarbonImmutable::parse($in['starts_at'])->utc();
        $now = CarbonImmutable::now();
        $settings = Settings::for($page->company_id);

        if ($start->lessThan($now->addMinutes($page->lead_minutes)) || $start->greaterThan($now->addDays($page->max_days_ahead))) {
            throw Errors::make(422, 'slot_unavailable', field: 'starts_at');
        }

        $minutes = (int) $start->setTimezone($this->places->zone($branch))->format('G') * 60 + (int) $start->setTimezone($this->places->zone($branch))->format('i');

        if ($minutes % $settings['slot_minutes'] !== 0) {
            throw Errors::make(422, 'slot_unavailable', field: 'starts_at');
        }

        if ($clientId === null && $phone === null && $email === null) {
            throw Errors::make(422, 'contact_required', field: 'guest_phone');
        }

        $this->assertNotFlooding($page, $phone, $email, $clientId);

        $wanted = $this->bookings->wanted($page->company_id, array_map(fn (string $id) => ['service_id' => $id, 'staff_id' => $in['staff_id'] ?? null], $in['service_ids']), online: true);
        $appointment = $this->bookings->create([
            'branch_id' => $branch->id, 'client_id' => $clientId, 'guest_name' => $in['guest_name'], 'guest_phone' => $phone, 'guest_email' => $email,
            'locale' => $in['locale'] ?? app()->getLocale(), 'source' => 'online', 'starts_at' => $start->toIso8601String(), 'idempotency_key' => $key,
            'lines' => array_map(fn (array $w) => ['service_id' => $w['service']->id, 'staff_id' => $w['staff_id']], $wanted),
        ], null);

        return $this->view($appointment->refresh());
    }

    /** Starts the deposit push for a held booking; the phone must be the booking's. @return array<string, mixed> */
    public function payDeposit(SalonBranch $page, string $number, string $phone): array
    {
        $appointment = $this->owned($page, $number, $phone);

        // A push already waiting on the guest's phone is reported, not sent again.
        if (Payment::query()->where('appointment_id', $appointment->id)->where('kind', Payment::DEPOSIT)->where('status', Payment::PENDING)
            ->where('created_at', '>', now()->subMinutes(max(1, (int) config('salon.booking.stk_wait_minutes', 2))))->exists()) {
            return $this->view($appointment);
        }

        $due = $appointment->deposit_required_minor - $this->deposits->balance($appointment);

        if ($due <= 0) {
            return $this->view($appointment);
        }

        $method = PaymentMethod::query()->where('company_id', $page->company_id)->where('type', 'mobile_money')->where('active', true)->whereNotNull('provider')->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', $appointment->currency))->orderBy('position')->orderBy('id')->first()
            ?? throw Errors::make(422, 'stk_not_available');

        $this->deposits->take($appointment, ['payment_method_id' => $method->id, 'amount_minor' => $due, 'mode' => 'stk', 'phone' => $phone], null);

        return $this->view($appointment->refresh());
    }

    /** @return array<string, mixed> */
    public function status(SalonBranch $page, string $number, string $phone): array
    {
        return $this->view($this->owned($page, $number, $phone));
    }

    /** @return array<string, mixed> only what the person who booked already knows */
    private function view(Appointment $a): array
    {
        $services = Service::query()->whereKey(Line::query()->where('appointment_id', $a->id)->pluck('service_id'))->orderBy('name')->pluck('name')->all();

        return [
            'number' => $a->number, 'status' => $a->status, 'starts_at' => $a->starts_at->toIso8601String(), 'ends_at' => $a->ends_at->toIso8601String(),
            'services' => $services, 'currency' => $a->currency, 'total_minor' => (string) $a->total_minor,
            'deposit_required_minor' => (string) $a->deposit_required_minor, 'deposit_paid_minor' => (string) max(0, $this->deposits->balance($a)),
            'deposit_hold_until' => $a->deposit_hold_until?->toIso8601String(),
        ];
    }

    private function owned(SalonBranch $page, string $number, string $phone): Appointment
    {
        $appointment = Appointment::query()->where('branch_id', $page->branch_id)->where('number', $number)->where('source', 'online')->first();

        if ($appointment === null || $appointment->guest_phone === null || ! self::samePhone($appointment->guest_phone, PhoneNumber::normalise($phone, Company::query()->find($page->company_id)?->country) ?? $phone)) {
            throw Errors::make(404, 'booking_unknown');
        }

        return $appointment;
    }

    /** At most a few future bookings per contact, so nobody can fill the diary with one number. */
    private function assertNotFlooding(SalonBranch $page, ?string $phone, ?string $email, ?string $clientId): void
    {
        $max = max(1, (int) config('salon.booking.max_open_per_contact', 3));
        $online = fn () => Appointment::query()->where('branch_id', $page->branch_id)->where('source', 'online');

        // A page is never filled by guests alone: caps on what it holds open and on what it takes in a day.
        if ($online()->where('status', Appointment::BOOKED)->where('starts_at', '>', now())->count() >= max(1, (int) config('salon.booking.max_open_per_page', 300))
            || $online()->where('created_at', '>', now()->subDay())->count() >= max(1, (int) config('salon.booking.max_per_page_per_day', 400))) {
            throw new ApiException(429, 'page_busy', __('salon.errors.page_busy'), []);
        }

        $contact = function ($q) use ($phone, $email, $clientId) {
            $q->whereRaw('false');

            if ($clientId !== null) {
                $q->orWhere('client_id', $clientId);
            }

            if ($phone !== null) {
                $q->orWhere('guest_phone', $phone);
            }

            if ($email !== null) {
                $q->orWhereRaw('lower(guest_email) = ?', [strtolower($email)]);
            }
        };

        if ($online()->where($contact)->where('created_at', '>', now()->subDay())->count() >= max(1, (int) config('salon.booking.max_per_contact_per_day', 6))) {
            throw new ApiException(422, 'too_many_bookings', __('salon.errors.too_many_bookings'), []);
        }

        $open = Appointment::query()->where('branch_id', $page->branch_id)->where('source', 'online')->where('status', Appointment::BOOKED)->where('starts_at', '>', now())
            ->where(function ($q) use ($phone, $email, $clientId) {
                $q->whereRaw('false');

                if ($clientId !== null) {
                    $q->orWhere('client_id', $clientId);
                }

                if ($phone !== null) {
                    $q->orWhere('guest_phone', $phone);
                }

                if ($email !== null) {
                    $q->orWhereRaw('lower(guest_email) = ?', [strtolower($email)]);
                }
            })->count();

        if ($open >= $max) {
            throw new ApiException(422, 'too_many_bookings', __('salon.errors.too_many_bookings'), []);
        }
    }

    /** A phone as E.164 (the company's country for a local number); a number that is none is refused. */
    private function phone(?string $value, ?string $country): ?string
    {
        $value = $value === null ? null : trim($value);

        if ($value === null || $value === '') {
            return null;
        }

        return PhoneNumber::normalise($value, $country) ?? throw Errors::make(422, 'phone_invalid', field: 'guest_phone');
    }

    private function email(?string $value): ?string
    {
        $value = $value === null ? null : strtolower(trim($value));

        return $value === null || $value === '' ? null : $value;
    }

    /** Two phone numbers are the same person's when their last nine digits match (+254 and 0 prefixes aside). */
    public static function samePhone(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $a = substr(preg_replace('/\D+/', '', $a) ?? '', -9);
        $b = substr(preg_replace('/\D+/', '', $b) ?? '', -9);

        return strlen($a) >= 7 && $a === $b;
    }
}
