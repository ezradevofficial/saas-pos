<?php

namespace App\Core\Identity\Services;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\Identity\Support\LoginIdentifier;
use App\Core\Identity\Support\PhoneNumber;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * One-time 6-digit codes (AUTH-01, AUTH-03, AUTH-04): valid 30 minutes,
 * single use, at most 5 attempts. Codes are stored as an HMAC bound to the
 * challenge id. The only reader and writer of verification_challenges.
 *
 * Limits per user and purpose, so every flow (contact verification,
 * two-factor, password reset) inherits them (AUTH-10):
 * - at most 5 codes sent per rolling hour, and 60 seconds between sends
 *   (counted from the challenges table);
 * - at most 10 failed verifications per hour across all challenges; past
 *   that even the right code is refused until the window ends;
 * - a new challenge consumes the user's earlier open ones for that purpose;
 * - an exhausted challenge (5 failed attempts) cannot be resent: the user
 *   signs in again, which needs the password, to get a new code.
 */
class Challenges
{
    public const TTL_MINUTES = 30;

    public const MAX_ATTEMPTS = 5;

    public const MAX_SENDS_PER_HOUR = 5;

    public const SEND_COOLDOWN_SECONDS = 60;

    public const MAX_FAILURES_PER_HOUR = 10;

    /**
     * Create a challenge without sending it (for use inside a transaction).
     * Requires the user's tenant context.
     *
     * @return array{0: VerificationChallenge, 1: string} the challenge and its plain code
     *
     * @throws ApiException 429 too_many_requests when the send limits are reached
     */
    public function create(User $user, string $purpose, ?string $channel, ?string $destination): array
    {
        return DB::transaction(function () use ($user, $purpose, $channel, $destination) {
            // Serialise issuing per user so concurrent requests cannot pass the limits together.
            DB::select('select id from users where id = ? for update', [$user->id]);

            if ($channel !== null) {
                $this->assertCanSend($user, $purpose);
            }

            VerificationChallenge::where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
            $id = (string) Str::uuid7();

            $challenge = VerificationChallenge::create([
                'id' => $id,
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'purpose' => $purpose,
                'channel' => $channel,
                'destination' => $destination,
                'code_hash' => self::hash($id, $code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);

            return [$challenge, $code];
        });
    }

    /**
     * Deliver the code. A transport failure is reported, never thrown: the
     * challenge stands and the user can ask for a new code.
     */
    public function send(User $user, VerificationChallenge $challenge, string $code): void
    {
        if ($challenge->channel === null) {
            return;
        }

        rescue(
            fn () => $user->notify(new VerificationCode($code, $challenge->channel, $challenge->purpose, self::TTL_MINUTES)),
            report: true,
        );
    }

    /** Create and send. */
    public function issue(User $user, string $purpose, ?string $channel, ?string $destination): VerificationChallenge
    {
        [$challenge, $code] = $this->create($user, $purpose, $channel, $destination);
        $this->send($user, $challenge, $code);

        return $challenge;
    }

    public function isExhausted(VerificationChallenge $challenge): bool
    {
        return $challenge->attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * An unconsumed challenge of $purpose, or null. Only a contact
     * verification challenge stays open once expired, so it can be resent
     * (sends are capped); an expired two-factor or password-reset challenge
     * can never be used again.
     */
    public function findOpen(string $id, string $purpose): ?VerificationChallenge
    {
        $challenge = $this->findUnconsumed($id, $purpose);

        if ($challenge !== null && $purpose !== VerificationChallenge::PURPOSE_VERIFY_CONTACT && $challenge->expires_at->isPast()) {
            return null;
        }

        return $challenge;
    }

    /** The user's latest unexpired, unconsumed challenge of $purpose, or null. */
    public function latestOpen(User $user, string $purpose): ?VerificationChallenge
    {
        return VerificationChallenge::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Check a code and consume the challenge. Every attempt counts, and is
     * committed before the answer is known, so failures cannot be rolled back.
     *
     * $check replaces the stored-code comparison (a TOTP challenge has no
     * stored code); it runs after the attempt is counted.
     *
     * @param  (Closure(VerificationChallenge, string): bool)|null  $check
     *
     * @throws ApiException 422 invalid_code, 429 too_many_requests
     */
    public function verify(string $id, string $code, string $purpose, ?Closure $check = null): VerificationChallenge
    {
        $challenge = $this->findUnconsumed($id, $purpose) ?? throw self::failure('auth.code.invalid');

        $this->assertWithinFailureBudget($challenge->user_id, $purpose);

        if ($challenge->expires_at->isPast()) {
            throw self::failure('auth.code.expired');
        }

        $counted = VerificationChallenge::whereKey($challenge->id)
            ->whereNull('consumed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts');

        if ($counted === 0) {
            throw self::failure('auth.code.attempts');
        }

        $valid = $check !== null
            ? $check($challenge, $code)
            : hash_equals($challenge->code_hash, self::hash($challenge->id, $code));

        if (! $valid) {
            $this->recordFailedCode($challenge->user_id, $purpose);

            throw self::failure('auth.code.invalid');
        }

        // Single use, even under concurrent requests.
        $consumed = VerificationChallenge::whereKey($challenge->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($consumed === 0) {
            throw self::failure('auth.code.invalid');
        }

        return $challenge->refresh();
    }

    /**
     * Refuse once the user has failed 10 codes of $purpose in the hour, so
     * codes checked without a challenge (TOTP enrolment) share the budget.
     *
     * @throws ApiException 429 too_many_requests
     */
    public function assertWithinFailureBudget(string $userId, string $purpose): void
    {
        $key = self::failureKey($userId, $purpose);

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES_PER_HOUR)) {
            throw self::tooMany(RateLimiter::availableIn($key));
        }
    }

    public function recordFailedCode(string $userId, string $purpose): void
    {
        RateLimiter::hit(self::failureKey($userId, $purpose), 3600);
    }

    /** The 422 invalid_code error, with $key's message. */
    public static function failure(string $key = 'auth.code.invalid'): ApiException
    {
        $message = __($key);

        return new ApiException(422, 'invalid_code', $message, ['code' => [$message]]);
    }

    public static function maskDestination(?string $channel, ?string $destination): ?string
    {
        if ($destination === null) {
            return null;
        }

        return $channel === 'sms'
            ? PhoneNumber::mask($destination)
            : LoginIdentifier::maskEmail($destination);
    }

    private function assertCanSend(User $user, string $purpose): void
    {
        $recent = VerificationChallenge::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNotNull('channel')
            ->where('created_at', '>', now()->subHour())
            ->orderBy('created_at')
            ->pluck('created_at');

        $last = $recent->last();

        if ($last !== null && $last->gt(now()->subSeconds(self::SEND_COOLDOWN_SECONDS))) {
            throw self::tooMany((int) ceil(now()->diffInSeconds($last->copy()->addSeconds(self::SEND_COOLDOWN_SECONDS), true)));
        }

        if ($recent->count() >= self::MAX_SENDS_PER_HOUR) {
            $oldest = $recent[$recent->count() - self::MAX_SENDS_PER_HOUR];

            throw self::tooMany((int) ceil(now()->diffInSeconds($oldest->copy()->addHour(), true)));
        }
    }

    private function findUnconsumed(string $id, string $purpose): ?VerificationChallenge
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $challenge = VerificationChallenge::find($id);

        return $challenge !== null && $challenge->purpose === $purpose && $challenge->consumed_at === null
            ? $challenge
            : null;
    }

    private static function failureKey(string $userId, string $purpose): string
    {
        return 'otp-failures|'.$purpose.'|'.$userId;
    }

    private static function tooMany(int $seconds): ApiException
    {
        $seconds = max(1, $seconds);

        return new ApiException(
            429, 'too_many_requests', __('core.errors.too_many_requests', ['seconds' => $seconds]),
            headers: ['Retry-After' => $seconds],
        );
    }

    private static function hash(string $id, string $code): string
    {
        return hash_hmac('sha256', $id.'|'.$code, (string) config('app.key'));
    }
}
