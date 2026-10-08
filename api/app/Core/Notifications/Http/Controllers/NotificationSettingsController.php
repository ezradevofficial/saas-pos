<?php

namespace App\Core\Notifications\Http\Controllers;

use App\Core\Notifications\Http\Requests\UpdateNotificationSettingsRequest;
use App\Core\Notifications\Http\Resources\EventTypeData;
use App\Core\Notifications\Models\NotificationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * NOT-02, NOT-04: the event types (any signed-in user reads them, with
 * the tenant's mandatory channels) and the admin's mandatory toggles
 * (audited as `core.notification_setting.*`).
 */
class NotificationSettingsController
{
    public function eventTypes(): JsonResponse
    {
        return new JsonResponse(['data' => EventTypeData::all()]);
    }

    public function update(UpdateNotificationSettingsRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request) {
            foreach ($request->validated('event_types') as $entry) {
                $setting = NotificationSetting::query()->firstOrNew(['event_type' => $entry['event_type']]);
                $channels = array_values($entry['mandatory_channels']);
                sort($channels);

                if (! $setting->exists && $channels === []) {
                    continue;
                }

                $setting->fill(['mandatory_channels' => $channels, 'updated_by' => $request->user()->id]);

                if ($setting->isDirty('mandatory_channels')) {
                    $setting->save();
                }
            }
        });

        return new JsonResponse(['data' => EventTypeData::all()]);
    }
}
