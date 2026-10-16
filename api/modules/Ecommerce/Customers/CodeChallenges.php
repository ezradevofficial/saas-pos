<?php

namespace Modules\Ecommerce\Customers;

use App\Core\Http\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * ECOM-08: sign-in codes cost the store an SMS or an email, so after
 * `ecommerce.shop.challenge_after` codes from one address in an hour, the
 * next request must first answer a challenge: a proof of work the server
 * checks without any third party (find `solution` so that
 * sha256("{nonce}:{solution}") starts with `challenge_difficulty` zeros).
 * A challenge is single use (any answer consumes it) and lives 10 minutes.
 */
final class CodeChallenges
{
    private const TTL_SECONDS = 600;

    /** Whether the next code from $ip needs a challenge answered. */
    public function required(string $ip): bool
    {
        return RateLimiter::attempts(self::key($ip)) >= max(0, (int) config('ecommerce.shop.challenge_after', 3));
    }

    /** One code sent from $ip (counted for an hour). */
    public function count(string $ip): void
    {
        RateLimiter::hit(self::key($ip), 3600);
    }

    /** @param  mixed  $answer  the request's `challenge`: {id, solution} */
    public function passes(mixed $answer): bool
    {
        if (! is_array($answer) || ! is_string($answer['id'] ?? null) || ! is_scalar($answer['solution'] ?? null) || ! Str::isUuid($answer['id'])) {
            return false;
        }

        $challenge = Cache::pull('ecom-code-challenge:'.$answer['id']);

        if (! is_array($challenge)) {
            return false;
        }

        $solution = mb_substr((string) $answer['solution'], 0, 32);

        return str_starts_with(hash('sha256', $challenge['nonce'].':'.$solution), str_repeat('0', $challenge['difficulty']));
    }

    /** The refusal that carries a new challenge (428). */
    public function refusal(): ApiException
    {
        $id = (string) Str::uuid7();
        $challenge = ['nonce' => Str::random(32), 'difficulty' => max(1, min(6, (int) config('ecommerce.shop.challenge_difficulty', 4)))];
        Cache::put('ecom-code-challenge:'.$id, $challenge, self::TTL_SECONDS);

        return new ApiException(428, 'challenge_required', __('ecommerce.errors.challenge_required'), extra: ['challenge' => ['id' => $id, ...$challenge]]);
    }

    private static function key(string $ip): string
    {
        return 'ecom-code-sends|'.$ip;
    }
}
