<?php

namespace App\Core\Identity\Services;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Identity\Notifications\VerificationCode;
use App\Core\Identity\Support\LoginIdentifier;
use App\Core\Identity\Support\PhoneNumber;
use Illuminate\Support\Str;

/**
 * One-time 6-digit codes (AUTH-01, AUTH-03, AUTH-04): valid 30 minutes,
 * single use, at most 5 attempts. Codes are stored as an HMAC bound to the
 * challenge id. The only reader and writer of verification_challenges.
 */
class Challenges
{
    public const TTL_MINUTES = 30;

    public const MAX_ATTEMPTS = 5;

    /**
     * Create a challenge without sending it (for use inside a transaction).
     *
     * @return array{0: VerificationChallenge, 1: string} the challenge and its plain code
     */
    public function create(User $user, string $purpose, ?string $channel, ?string $destination): array
    {
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
    }

    public function send(User $user, VerificationChallenge $challenge, string $code): void
    {
        if ($challenge->channel !== null) {
            $user->notify(new VerificationCode($code, $challenge->channel, $challenge->purpose, self::TTL_MINUTES));
        }
    }

    /** Create and send. */
    public function issue(User $user, string $purpose, ?string $channel, ?string $destination): VerificationChallenge
    {
        [$challenge, $code] = $this->create($user, $purpose, $channel, $destination);
        $this->send($user, $challenge, $code);

        return $challenge;
    }

    /**
     * A challenge of $purpose that can still be used or resent, or null.
     */
    public function findOpen(string $id, string $purpose): ?VerificationChallenge
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $challenge = VerificationChallenge::find($id);

        return $challenge !== null && $challenge->purpose === $purpose && $challenge->consumed_at === null
            ? $challenge
            : null;
    }

    /**
     * Check a code and consume the challenge. Every attempt counts, and is
     * committed before the answer is known, so failures cannot be rolled back.
     *
     * @throws ApiException 422 invalid_code
     */
    public function verify(string $id, string $code, string $purpose): VerificationChallenge
    {
        $challenge = $this->findOpen($id, $purpose) ?? throw self::failure('auth.code.invalid');

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

        if (! hash_equals($challenge->code_hash, self::hash($challenge->id, $code))) {
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

    public function invalidate(VerificationChallenge $challenge): void
    {
        VerificationChallenge::whereKey($challenge->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
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

    private static function hash(string $id, string $code): string
    {
        return hash_hmac('sha256', $id.'|'.$code, (string) config('app.key'));
    }

    private static function failure(string $key): ApiException
    {
        $message = __($key);

        return new ApiException(422, 'invalid_code', $message, ['code' => [$message]]);
    }
}
