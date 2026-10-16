<?php

namespace Modules\FixedAssets\Maintenance;

use App\Core\Approvals\Resolvers\ApproverDirectory;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Modules\FixedAssets\Depreciation\Spans;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\MaintenanceSchedule;
use Modules\FixedAssets\Support\Decimals;

/**
 * FA-19, NOT-02: maintenance falling due. A time schedule whose next due
 * date is within its warning days, or a usage schedule whose meter has
 * reached its next due reading, is told once per due date or reading to
 * the holders of `fixed_assets.maintenance.manage` at the asset's place
 * (in-app and email by default).
 */
class Reminders
{
    public const EVENT = 'fixed_assets.maintenance.due';

    public const PERMISSION = 'fixed_assets.maintenance.manage';

    public function __construct(
        private readonly ApproverDirectory $directory,
        private readonly ScopeResolver $scopes,
    ) {}

    public static function register(EventTypes $types): void
    {
        $types->register(new EventType(
            key: self::EVENT,
            placeholders: ['asset_number' => 'FA-NRB-00012', 'asset_name' => 'Delivery van', 'schedule_name' => 'Service', 'due' => '2026-11-01', 'location_name' => 'Main store'],
            defaultChannels: [Channels::IN_APP, Channels::EMAIL],
            mandatoryAllowed: true,
            langKey: 'fixed_assets.notifications.maintenance_due',
        ));
    }

    /** Remind what is due as of $today in the current tenant; how many reminders were sent. */
    public function run(CarbonImmutable $today): int
    {
        $sent = 0;
        $schedules = MaintenanceSchedule::query()->whereNull('archived_at')
            ->whereIn('asset_id', fn ($q) => $q->select('id')->from('fa_assets')->where('status', Asset::ACTIVE))
            ->orderBy('id')->get();

        foreach ($schedules as $schedule) {
            $asset = Asset::query()->with('location')->find($schedule->asset_id);

            if ($asset === null) {
                continue;
            }

            $due = $schedule->basis === 'time'
                ? ($schedule->next_due_on !== null && $schedule->next_due_on->subDays((int) $schedule->warning_days) <= $today ? 'd:'.$schedule->next_due_on->toDateString() : null)
                : ($schedule->next_due_reading !== null && BigDecimal::of(Spans::meter($asset, $today))->isGreaterThanOrEqualTo((string) $schedule->next_due_reading)
                    ? 'u:'.Decimals::show((string) $schedule->next_due_reading) : null);

            if ($due === null || $schedule->reminded_for === $due) {
                continue;
            }

            $chain = $this->scopes->chainOf(Scope::location($asset->location_id)) ?? [];
            $users = $this->directory->holdersAt($this->directory->rolesWith(self::PERMISSION), $chain);

            if ($users !== []) {
                app(Notifier::class)->send(new NotificationEvent(self::EVENT, $users, [
                    'asset_number' => (string) $asset->number,
                    'asset_name' => (string) $asset->name,
                    'schedule_name' => (string) $schedule->name,
                    'due' => substr($due, 2),
                    'location_name' => (string) $asset->location?->name,
                ], '/fixed-assets/assets/'.rawurlencode($asset->id)));
                $sent++;
            }

            $schedule->fill(['reminded_for' => $due])->save();
        }

        return $sent;
    }
}
