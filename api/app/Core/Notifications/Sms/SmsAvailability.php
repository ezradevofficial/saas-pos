<?php

namespace App\Core\Notifications\Sms;

use App\Core\Http\ApiException;
use Illuminate\Contracts\Foundation\Application;

/**
 * AUTH-01..AUTH-05, NOT-01 (owner decision 2026-10-22, launch email-only):
 * the one answer to "can the platform send a text message?". Every SMS
 * path asks it: sign-up, sign-in, two-factor, password reset and
 * invitations refuse the phone channel when it says no
 * (`sms_unavailable`), the notifier skips SMS deliveries with the reason
 * `sms_unavailable`, `public/branding` reports it to the apps, and
 * `app:preflight` warns "SMS unavailable: email-only mode".
 *
 * Available only when SMS_DRIVER (`services.sms.driver`) names a driver
 * this release implements (SmsSender::DRIVERS). `log` writes messages to
 * the log, so it counts only in local and testing, where it is also the
 * default; `none` switches SMS off anywhere. Turning SMS on later means
 * adding a provider to SmsSender::DRIVERS and setting SMS_DRIVER: nothing
 * else changes.
 */
final class SmsAvailability
{
    /** Turns SMS off explicitly, in any environment (email-only mode). */
    public const NONE = 'none';

    public const ERROR_CODE = 'sms_unavailable';

    private const DEVELOPMENT = ['local', 'testing'];

    public function __construct(private readonly Application $app) {}

    /** The SMS driver in effect, or null in email-only mode. */
    public function driver(): ?string
    {
        $configured = config('services.sms.driver');
        $driver = $configured === null || $configured === '' ? ($this->app->environment(self::DEVELOPMENT) ? 'log' : null) : (string) $configured;

        if ($driver === null || $driver === self::NONE || ! in_array($driver, SmsSender::DRIVERS, true)) {
            return null;
        }

        return $driver === 'log' && ! $this->app->environment(self::DEVELOPMENT) ? null : $driver;
    }

    public function available(): bool
    {
        return $this->driver() !== null;
    }

    /**
     * Refuse a request that would need a text message when SMS is
     * unavailable (422 `sms_unavailable`, the message on $field when given).
     *
     * @throws ApiException
     */
    public function assertAvailable(?string $field = null, string $message = 'auth.sms_unavailable.use_email'): void
    {
        if (! $this->available()) {
            throw self::refusal($field, $message);
        }
    }

    public static function refusal(?string $field = null, string $message = 'auth.sms_unavailable.use_email'): ApiException
    {
        $text = __($message);

        return new ApiException(422, self::ERROR_CODE, $text, $field === null ? [] : [$field => [$text]]);
    }
}
