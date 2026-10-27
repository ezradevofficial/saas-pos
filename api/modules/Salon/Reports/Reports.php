<?php

namespace Modules\Salon\Reports;

use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Salon\Availability\StaffCalendar;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\Package;
use Modules\Salon\Models\Service;
use Modules\Salon\Models\Staff;
use Modules\Salon\Support\Places;

/**
 * SAL-27: the salon's reports, for a company or one branch and a period (local days of the branch's time zone, from
 * and to inclusive). Read-only; the controllers narrow `$branchIds` to the places the reader may see.
 */
class Reports
{
    public function __construct(private readonly Places $places, private readonly StaffCalendar $calendar) {}

    /**
     * Utilisation per staff member: minutes booked and served over minutes available (hours less breaks and time off).
     *
     * @param  list<string>  $branchIds
     * @return array{from: string, to: string, staff: list<array<string, mixed>>}
     */
    public function utilisation(string $companyId, array $branchIds, string $from, string $to): array
    {
        $rows = [];

        foreach (Branch::query()->where('company_id', $companyId)->whereIn('id', $branchIds)->orderBy('name')->get() as $branch) {
            [$start] = $this->places->day($branch, $from);
            [, $end] = $this->places->day($branch, $to);
            $staff = Staff::query()->where('company_id', $companyId)->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))
                ->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', Line::query()->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->select('staff_id')))->orderBy('name')->get();
            $spans = $this->calendar->spans($staff, $branch, $start, $end);
            $lines = Line::query()->join('salon_appointments as a', 'a.id', '=', 'salon_appointment_lines.appointment_id')
                ->where('a.branch_id', $branch->id)->where('salon_appointment_lines.holds', true)
                ->where('salon_appointment_lines.starts_at', '>=', $start)->where('salon_appointment_lines.starts_at', '<', $end)
                ->get(['salon_appointment_lines.staff_id', 'salon_appointment_lines.starts_at', 'salon_appointment_lines.ends_at', 'a.status'])->groupBy('staff_id');

            foreach ($staff as $member) {
                $mine = $lines->get($member->id, collect());
                $minutes = fn ($set) => (int) $set->sum(fn ($l) => CarbonImmutable::parse($l->starts_at)->diffInMinutes(CarbonImmutable::parse($l->ends_at)));
                $available = StaffCalendar::availableMinutes($spans[$member->id] ?? ['work' => [], 'off' => []], $start, $end);
                $booked = $minutes($mine);
                $served = $minutes($mine->whereIn('status', [Appointment::COMPLETED, Appointment::PAID]));
                $rows[] = [
                    'staff_id' => $member->id, 'staff_name' => $member->name, 'branch_id' => $branch->id, 'branch_name' => $branch->name,
                    'available_minutes' => $available, 'booked_minutes' => $booked, 'served_minutes' => $served, 'appointments' => $mine->count(),
                    'utilisation_percent' => $available > 0 ? round($booked / $available * 100, 1) : null,
                ];
            }
        }

        return ['from' => $from, 'to' => $to, 'staff' => $rows];
    }

    /**
     * Revenue per service: what paid lines brought in net of discounts and tax, and how many were done.
     *
     * @param  list<string>  $branchIds
     * @return array{from: string, to: string, currencies: array<string, list<array<string, mixed>>>}
     */
    public function services(string $companyId, array $branchIds, string $from, string $to): array
    {
        [$start, $end] = $this->bounds($companyId, $from, $to);
        $rows = Line::query()->join('salon_appointments as a', 'a.id', '=', 'salon_appointment_lines.appointment_id')
            ->where('a.company_id', $companyId)->whereIn('a.branch_id', $branchIds)->where('a.status', Appointment::PAID)
            ->where('a.paid_at', '>=', $start)->where('a.paid_at', '<', $end)
            ->groupBy('salon_appointment_lines.service_id', 'salon_appointment_lines.currency')
            ->selectRaw('salon_appointment_lines.service_id, salon_appointment_lines.currency, count(*) as done, coalesce(sum(salon_appointment_lines.net_minor), 0) as revenue_minor, coalesce(sum(salon_appointment_lines.price_minor), 0) as list_minor')
            ->get();
        $names = Service::query()->whereKey($rows->pluck('service_id'))->pluck('name', 'id');
        $out = [];

        foreach ($rows->sortByDesc('revenue_minor') as $row) {
            $out[$row->currency][] = ['service_id' => $row->service_id, 'service_name' => $names[$row->service_id] ?? '', 'done' => (int) $row->done, 'revenue_minor' => (string) $row->revenue_minor, 'list_minor' => (string) $row->list_minor];
        }

        return ['from' => $from, 'to' => $to, 'currencies' => $out];
    }

    /**
     * No-shows against the bookings that were kept or missed, per staff member (the first line's) and in all.
     *
     * @param  list<string>  $branchIds
     * @return array{from: string, to: string, total: array<string, mixed>, staff: list<array<string, mixed>>}
     */
    public function noShows(string $companyId, array $branchIds, string $from, string $to): array
    {
        [$start, $end] = $this->bounds($companyId, $from, $to);
        $rows = Appointment::query()->where('salon_appointments.company_id', $companyId)->whereIn('salon_appointments.branch_id', $branchIds)
            ->where('salon_appointments.starts_at', '>=', $start)->where('salon_appointments.starts_at', '<', $end)
            ->whereIn('salon_appointments.status', [Appointment::COMPLETED, Appointment::PAID, Appointment::NO_SHOW, Appointment::CANCELLED])
            ->selectRaw('(select l.staff_id from salon_appointment_lines l where l.appointment_id = salon_appointments.id order by l.position, l.id limit 1) as staff_id, salon_appointments.status, salon_appointments.fee_status, salon_appointments.fee_minor')
            ->get();
        $summary = function ($set) {
            $noShow = $set->where('status', Appointment::NO_SHOW)->count();
            $kept = $set->whereIn('status', [Appointment::COMPLETED, Appointment::PAID])->count();

            return [
                'kept' => $kept, 'no_shows' => $noShow, 'cancellations' => $set->where('status', Appointment::CANCELLED)->count(),
                'no_show_rate_percent' => $kept + $noShow > 0 ? round($noShow / ($kept + $noShow) * 100, 1) : null,
                'fees_due_minor' => (string) $set->where('fee_status', 'due')->sum('fee_minor'), 'fees_paid_minor' => (string) $set->where('fee_status', 'paid')->sum('fee_minor'),
            ];
        };
        $names = Staff::query()->whereKey($rows->pluck('staff_id')->filter()->unique())->pluck('name', 'id');

        return [
            'from' => $from, 'to' => $to, 'total' => $summary($rows),
            'staff' => $rows->groupBy('staff_id')->map(fn ($set, $id) => ['staff_id' => (string) $id, 'staff_name' => $names[$id] ?? '', ...$summary($set)])->sortBy('staff_name')->values()->all(),
        ];
    }

    /**
     * Commissions and tips per staff member and currency for the days.
     *
     * @return array{from: string, to: string, staff: list<array<string, mixed>>}
     */
    public function commissions(string $companyId, array $branchIds, string $from, string $to, ?string $onlyStaffId = null): array
    {
        [$start, $end] = $this->bounds($companyId, $from, $to);
        $entries = DB::connection(TenantContext::CONNECTION)->table('salon_commission_entries')->where('company_id', $companyId)->whereIn('branch_id', $branchIds)->where('earned_at', '>=', $start)->where('earned_at', '<', $end)
            ->when($onlyStaffId !== null, fn ($q) => $q->where('staff_id', $onlyStaffId))
            ->groupBy('staff_id', 'currency')
            ->selectRaw("staff_id, currency, coalesce(sum(amount_minor) filter (where kind = 'earn'), 0) as earned, coalesce(sum(amount_minor) filter (where kind = 'reversal'), 0) as reversed, coalesce(sum(amount_minor), 0) as net")
            ->get();
        $tips = DB::connection(TenantContext::CONNECTION)->table('salon_tips')->where('company_id', $companyId)->whereIn('branch_id', $branchIds)->where('earned_at', '>=', $start)->where('earned_at', '<', $end)
            ->when($onlyStaffId !== null, fn ($q) => $q->where('staff_id', $onlyStaffId))
            ->groupBy('staff_id', 'currency')->selectRaw('staff_id, currency, sum(amount_minor) as tips')->get()->keyBy(fn ($r) => $r->staff_id.'|'.$r->currency);
        $names = Staff::query()->whereKey($entries->pluck('staff_id')->merge($tips->pluck('staff_id'))->unique())->pluck('name', 'id');
        $rows = [];

        foreach ($entries as $row) {
            $rows[$row->staff_id.'|'.$row->currency] = [
                'staff_id' => $row->staff_id, 'staff_name' => $names[$row->staff_id] ?? '', 'currency' => $row->currency,
                'earned_minor' => (string) $row->earned, 'reversed_minor' => (string) $row->reversed, 'net_minor' => (string) $row->net, 'tips_minor' => (string) ($tips->get($row->staff_id.'|'.$row->currency)->tips ?? 0),
            ];
        }

        foreach ($tips as $key => $tip) {
            $rows[$key] ??= ['staff_id' => $tip->staff_id, 'staff_name' => $names[$tip->staff_id] ?? '', 'currency' => $tip->currency, 'earned_minor' => '0', 'reversed_minor' => '0', 'net_minor' => '0', 'tips_minor' => (string) $tip->tips];
        }

        return ['from' => $from, 'to' => $to, 'staff' => collect($rows)->sortBy('staff_name')->values()->all()];
    }

    /**
     * What the salon owes in prepaid value: package sessions and value left (live, expiring in 30 days, expired and
     * forfeited), and the deposits held on appointments, per currency.
     *
     * @return array{currencies: array<string, array<string, mixed>>}
     */
    public function liability(string $companyId): array
    {
        $out = [];
        $soon = CarbonImmutable::now()->addDays(30)->toDateString();

        foreach (Package::query()->where('company_id', $companyId)->whereIn('status', [Package::ACTIVE, Package::EXPIRED])->get() as $package) {
            $row = &$out[$package->currency];
            $row ??= ['packages' => 0, 'sessions_left' => 0, 'value_minor' => 0, 'expiring_value_minor' => 0, 'expired_value_minor' => 0, 'deposits_minor' => 0];
            $left = $package->sessions_total - $package->sessions_used;

            if ($package->status === Package::EXPIRED) {
                $row['expired_value_minor'] += $package->remaining_minor;
            } else {
                $row['packages']++;
                $row['sessions_left'] += $left;
                $row['value_minor'] += $package->remaining_minor;

                if ($package->expires_on !== null && $package->expires_on->toDateString() <= $soon) {
                    $row['expiring_value_minor'] += $package->remaining_minor;
                }
            }

            unset($row);
        }

        $held = DB::connection(TenantContext::CONNECTION)->table('salon_payments as p')->join('salon_appointments as a', 'a.id', '=', 'p.appointment_id')->where('a.company_id', $companyId)->where('p.status', 'settled')
            ->groupBy('p.currency')->selectRaw("p.currency, sum(case when p.kind in ('deposit', 'restored') then p.amount_minor else -p.amount_minor end) as held")->get();

        foreach ($held as $row) {
            $out[$row->currency] ??= ['packages' => 0, 'sessions_left' => 0, 'value_minor' => 0, 'expiring_value_minor' => 0, 'expired_value_minor' => 0, 'deposits_minor' => 0];
            $out[$row->currency]['deposits_minor'] = max(0, (int) $row->held);
        }

        return ['currencies' => collect($out)->map(fn (array $r) => array_map('strval', $r))->all()];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC bounds of the local days, in the company's zone */
    private function bounds(string $companyId, string $from, string $to): array
    {
        $zone = (string) (Company::query()->whereKey($companyId)->value('timezone') ?: 'UTC');

        return [CarbonImmutable::parse($from.' 00:00:00', $zone)->utc(), CarbonImmutable::parse($to.' 00:00:00', $zone)->addDay()->utc()];
    }
}
