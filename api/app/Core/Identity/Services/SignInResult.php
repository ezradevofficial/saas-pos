<?php

namespace App\Core\Identity\Services;

use App\Core\Identity\Models\User;

/** Outcome of Authenticate::attempt. */
final class SignInResult
{
    public const OK = 'ok';

    public const TWO_FACTOR_REQUIRED = 'two_factor_required';

    public const LOCKED = 'locked';

    public const INVALID = 'invalid';

    public const UNVERIFIED = 'unverified';

    public const DEACTIVATED = 'deactivated';

    private function __construct(
        public readonly string $status,
        public readonly ?string $token = null,
        public readonly ?User $user = null,
        public readonly ?string $challengeId = null,
        public readonly ?int $retryAfter = null,
    ) {}

    public static function ok(string $token, User $user): self
    {
        return new self(self::OK, token: $token, user: $user);
    }

    public static function twoFactorRequired(string $challengeId): self
    {
        return new self(self::TWO_FACTOR_REQUIRED, challengeId: $challengeId);
    }

    public static function locked(int $retryAfter): self
    {
        return new self(self::LOCKED, retryAfter: $retryAfter);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    public static function unverified(string $challengeId): self
    {
        return new self(self::UNVERIFIED, challengeId: $challengeId);
    }

    public static function deactivated(): self
    {
        return new self(self::DEACTIVATED);
    }
}
