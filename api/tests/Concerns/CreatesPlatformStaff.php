<?php

namespace Tests\Concerns;

use App\Core\Platform\Models\PlatformAccessToken;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformTokens;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** Platform staff for tests (ADR 013). */
trait CreatesPlatformStaff
{
    protected string $staffPassword = 'quiet-lantern-harbour-77';

    /** An active staff member with two-factor set up. */
    protected function staff(string $role = PlatformUser::ROLE_OWNER, array $attributes = []): PlatformUser
    {
        $user = new PlatformUser(['name' => 'Staff '.Str::random(4), 'email' => 'staff-'.Str::lower(Str::random(8)).'@example.com', 'role' => $role]);
        $user->forceFill([
            'password' => $this->staffPassword,
            'status' => PlatformUser::STATUS_ACTIVE,
            'two_factor_secret' => app(Google2FA::class)->generateSecretKey(32),
            'two_factor_confirmed_at' => now(),
            ...$attributes,
        ])->save();

        return $user;
    }

    /** A bearer header for $user; full access unless $abilities says otherwise. */
    protected function staffBearer(PlatformUser $user, array $abilities = [PlatformAccessToken::FULL]): array
    {
        return ['Authorization' => 'Bearer '.app(PlatformTokens::class)->issue($user, $abilities, '127.0.0.1', 'tests')];
    }

    /** The current TOTP code for a secret, optionally a step ahead (a fresh step). */
    protected function totp(string $secret, int $stepsAhead = 0): string
    {
        return app(Google2FA::class)->oathTotp($secret, intdiv(now()->getTimestamp(), 30) + $stepsAhead);
    }
}
