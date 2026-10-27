<?php

namespace Modules\Salon\Sync\Sources;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Carbon\CarbonImmutable;
use Modules\Salon\Availability\StaffCalendar;
use Modules\Salon\Models\Resource;
use Modules\Salon\Models\Staff;
use Modules\Salon\SalonServiceProvider;

/**
 * SAL-29, NFR-04: the staff and resources of the till's branch, with the spans each staff member is on duty and off for
 * the days ahead, worked out by the server (HR's roster and leave included) so the till needs no HR logic offline. Rows of
 * both kinds: `kind` is `staff` or `resource`. Only what the front desk needs: a name, a title, a colour, the user they are.
 */
class StaffSource implements SnapshotSource
{
    /** Days of spans the till gets. */
    public const DAYS = 8;

    public function __construct(private readonly StaffCalendar $calendar) {}

    public function key(): string
    {
        return 'salon_staff';
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
        $from = $scope->at->setTimezone($scope->timezone())->startOfDay()->utc();
        $to = $from->addDays(self::DAYS);
        $staff = Staff::query()->where('company_id', $scope->companyId())->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $scope->branch->id))->orderBy('name')->orderBy('id')->get();
        $spans = $this->calendar->spans($staff, $scope->branch, $from, $to);
        $iso = fn (array $pairs) => array_map(fn (array $p) => [$p[0]->toIso8601String(), $p[1]->toIso8601String()], $pairs);
        $rows = [];

        foreach ($staff as $member) {
            $rows[] = [
                'id' => $member->id, 'kind' => 'staff', 'name' => $member->name, 'title' => $member->title, 'color' => $member->color, 'user_id' => $member->user_id,
                'work' => $iso($spans[$member->id]['work'] ?? []), 'off' => $iso($spans[$member->id]['off'] ?? []),
            ];
        }

        foreach (Resource::query()->where('branch_id', $scope->branch->id)->whereNull('archived_at')->orderBy('name')->orderBy('id')->get() as $resource) {
            $rows[] = ['id' => $resource->id, 'kind' => 'resource', 'name' => $resource->name, 'title' => $resource->kind, 'color' => null, 'user_id' => null, 'work' => [], 'off' => []];
        }

        return $rows;
    }

    public static function window(CarbonImmutable $at): CarbonImmutable
    {
        return $at->addDays(self::DAYS);
    }
}
