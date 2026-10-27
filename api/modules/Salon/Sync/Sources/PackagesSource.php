<?php

namespace Modules\Salon\Sync\Sources;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Package;
use Modules\Salon\Models\QueueEntry;
use Modules\Salon\SalonServiceProvider;

/**
 * SAL-18, SAL-29: the live packages of the clients who are on the till's schedule or waiting, so a session can be
 * rung offline. The server uses the session up (and checks it) when the sale arrives; a package used elsewhere meanwhile is
 * an exception for a person, never a refused sale.
 */
class PackagesSource implements SnapshotSource
{
    public function key(): string
    {
        return 'salon_packages';
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
        $from = $scope->at->setTimezone($scope->timezone())->startOfDay()->subDays(ScheduleSource::DAYS_BACK)->utc();
        $to = $scope->at->setTimezone($scope->timezone())->startOfDay()->addDays(ScheduleSource::DAYS_AHEAD + 1)->utc();
        $clients = Appointment::query()->where('branch_id', $scope->branch->id)->where('starts_at', '>=', $from)->where('starts_at', '<', $to)->whereNotNull('client_id')->pluck('client_id')
            ->merge(QueueEntry::query()->where('branch_id', $scope->branch->id)->where('status', QueueEntry::WAITING)->whereNotNull('client_id')->pluck('client_id'))->unique()->values()->all();

        return Package::query()->where('company_id', $scope->companyId())->where('status', Package::ACTIVE)->whereIn('customer_id', $clients)->orderBy('customer_id')->orderBy('id')->get()
            ->filter(fn (Package $p) => $p->sessionsLeft() > 0)
            ->map(fn (Package $p) => [
                'id' => $p->id, 'customer_id' => $p->customer_id, 'name' => $p->name, 'sessions_left' => $p->sessionsLeft(), 'session_value_minor' => (string) $p->sessionValue(),
                'currency' => $p->currency, 'expires_on' => $p->expires_on?->toDateString(), 'service_ids' => $p->service_ids,
            ])->values()->all();
    }
}
