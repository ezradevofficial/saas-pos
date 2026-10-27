<?php

namespace Modules\FuelStation\Support;

use App\Core\Identity\Pin\TillSignIn;
use Carbon\CarbonInterface;

/**
 * FUEL-14, FUEL-17: who a till's records cannot rule out. A till names who
 * opened, closed or sold; when it could not prove it (no verified sign-in
 * attestation, AUTH-07) the name is only a claim, so everyone who was signed
 * in on that till in the window is treated as a party to the record and can
 * never approve it.
 */
final class DeviceActors
{
    /** A sign-in older than this before the window starts has expired (pos.actor.session_max_hours). */
    private const SESSION_HOURS = 24;

    /** @return list<string> user ids signed in on the device between $from (less a session) and $to */
    public static function signedIn(?string $deviceId, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($deviceId === null) {
            return [];
        }

        return TillSignIn::query()->where('device_id', $deviceId)->where('signed_in_at', '>=', $from->copy()->subHours((int) config('pos.actor.session_max_hours', self::SESSION_HOURS)))
            ->where('signed_in_at', '<=', $to)->pluck('user_id')->unique()->values()->all();
    }
}
