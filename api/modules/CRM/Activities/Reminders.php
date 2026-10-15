<?php

namespace Modules\CRM\Activities;

use App\Core\Identity\Models\User;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Modules\CRM\Models\Activity;
use Modules\CRM\Support\Tx;

/**
 * CRM-27, NOT-02: activity reminders through the core notification
 * service. `crm.activity.reminder` goes to the activity's owner when its
 * reminder time has come, once (`reminded_at`, set under the row lock).
 * Times are shown in the activity's company time zone.
 */
class Reminders
{
    public const REMINDER = 'crm.activity.reminder';

    public function __construct(private readonly Notifier $notifier) {}

    public static function register(EventTypes $types): void
    {
        $types->register(new EventType(
            key: self::REMINDER,
            placeholders: ['subject' => 'Call Amina about the quote', 'kind' => 'Call', 'starts_at' => '2026-10-15 14:30'],
            defaultChannels: [Channels::IN_APP, Channels::EMAIL],
            langKey: 'crm.notifications.activity_reminder',
        ));
    }

    /** The reminders due at $at in the current tenant; how many were sent. */
    public function run(CarbonImmutable $at): int
    {
        $sent = 0;
        $due = Activity::query()->where('status', Activity::OPEN)->whereNull('reminded_at')->whereNotNull('remind_at')
            ->where('remind_at', '<=', $at)->whereNotNull('owner_id')->orderBy('remind_at')->limit(500)->pluck('id');

        foreach ($due as $id) {
            $sent += Tx::run(function () use ($id) {
                $activity = Activity::query()->whereKey($id)->lockForUpdate()->first();

                if ($activity === null || $activity->reminded_at !== null || $activity->status !== Activity::OPEN || $activity->owner_id === null) {
                    return 0;
                }

                $activity->forceFill(['reminded_at' => CarbonImmutable::now()])->save();
                $owner = User::query()->find($activity->owner_id);

                if ($owner === null || ! $owner->isActive()) {
                    return 0;
                }

                $zone = Company::query()->whereKey($activity->company_id)->value('timezone') ?: 'UTC';
                $locale = in_array($owner->locale, Channels::LOCALES, true) ? $owner->locale : 'en';
                $this->notifier->send(new NotificationEvent(self::REMINDER, [$owner->id], [
                    'subject' => $activity->subject,
                    'kind' => __("crm.activity_kinds.{$activity->kind}", [], $locale),
                    'starts_at' => $activity->starts_at->setTimezone($zone)->format('Y-m-d H:i'),
                ], '/crm/calendar?activity='.$activity->id));

                return 1;
            });
        }

        return $sent;
    }
}
