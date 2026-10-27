<?php

namespace Modules\Salon\Sync\Sources;

use App\Core\MasterData\Parties\Party;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Client;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\Service;
use Modules\Salon\Models\Staff;
use Modules\Salon\Payments\Deposits;
use Modules\Salon\SalonServiceProvider;

/**
 * SAL-29, NFR-04: the branch's schedule from yesterday to a week ahead, so the day can be viewed and worked offline.
 * Personal data is the least the front desk needs to greet and charge a client: a name. Contacts and notes never reach the
 * till; `has_alerts` says that allergies or notes exist so the stylist looks them up (online) before starting.
 */
class ScheduleSource implements SnapshotSource
{
    public const DAYS_BACK = 1;

    public const DAYS_AHEAD = 7;

    public function __construct(private readonly Deposits $deposits) {}

    public function key(): string
    {
        return 'salon_schedule';
    }

    public function module(): string
    {
        return SalonServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $from = $scope->at->setTimezone($scope->timezone())->startOfDay()->subDays(self::DAYS_BACK)->utc();
        $to = $scope->at->setTimezone($scope->timezone())->startOfDay()->addDays(self::DAYS_AHEAD + 1)->utc();
        $appointments = Appointment::query()->where('branch_id', $scope->branch->id)->where('starts_at', '>=', $from)->where('starts_at', '<', $to)
            ->whereNull('anonymised_at')->orderBy('starts_at')->orderBy('id')->get();
        $lines = Line::query()->whereIn('appointment_id', $appointments->modelKeys())->orderBy('position')->orderBy('id')->get()->groupBy('appointment_id');
        $services = Service::query()->whereKey($lines->flatten()->pluck('service_id')->unique())->pluck('name', 'id');
        $staff = Staff::query()->whereKey($lines->flatten()->pluck('staff_id')->unique())->pluck('name', 'id');
        $parties = Party::query()->whereKey($appointments->pluck('client_id')->filter()->unique())->pluck('name', 'id');
        $alerts = Client::query()->whereIn('party_id', $appointments->pluck('client_id')->filter()->unique())
            ->get(['party_id', 'allergies', 'formulas', 'notes'])->filter(fn (Client $c) => filled($c->allergies) || filled($c->formulas) || filled($c->notes))->pluck('party_id')->all();

        return $appointments->map(fn (Appointment $a) => [
            'id' => $a->id, 'number' => $a->number, 'status' => $a->status, 'source' => $a->source,
            'client_id' => $a->client_id, 'client_name' => $a->client_id !== null ? ($parties[$a->client_id] ?? '') : (string) $a->guest_name,
            'has_alerts' => $a->client_id !== null && in_array($a->client_id, $alerts, true),
            'starts_at' => $a->starts_at->toIso8601String(), 'ends_at' => $a->ends_at->toIso8601String(),
            'total_minor' => (string) $a->total_minor, 'currency' => $a->currency, 'fee_minor' => (string) $a->fee_minor, 'fee_status' => $a->fee_status,
            'deposit_required_minor' => (string) $a->deposit_required_minor, 'deposit_balance_minor' => (string) $this->deposits->balance($a),
            'sale_id' => $a->sale_id,
            'lines' => ($lines->get($a->id) ?? collect())->map(fn (Line $l) => [
                'id' => $l->id, 'service_id' => $l->service_id, 'item_id' => $l->item_id, 'name' => $services[$l->service_id] ?? '', 'kind' => $l->kind,
                'staff_id' => $l->staff_id, 'staff_name' => $staff[$l->staff_id] ?? '', 'resource_id' => $l->resource_id,
                'starts_at' => $l->starts_at->toIso8601String(), 'ends_at' => $l->ends_at->toIso8601String(), 'price_minor' => (string) $l->price_minor,
            ])->values()->all(),
        ])->values()->all();
    }
}
