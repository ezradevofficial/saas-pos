<?php

namespace App\Core\Onboarding\Trial;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * ONB-04: whether the tenant is on a trial and until when. `state` is
 * `none` (no trial started), `trial` (running) or `ended`; billing adds
 * its own states (active, past_due, ...) when it implements the provider.
 */
final class TrialStatus
{
    public const NONE = 'none';

    public const TRIAL = 'trial';

    public const ENDED = 'ended';

    public function __construct(
        public readonly string $state,
        public readonly ?CarbonImmutable $endsAt = null,
    ) {}

    public static function none(): self
    {
        return new self(self::NONE);
    }

    public static function until(?DateTimeInterface $endsAt): self
    {
        if ($endsAt === null) {
            return self::none();
        }

        $endsAt = CarbonImmutable::instance($endsAt);

        return new self($endsAt->isFuture() ? self::TRIAL : self::ENDED, $endsAt);
    }

    /** Whole days left, a part day counting as one; 0 once ended. */
    public function daysLeft(): int
    {
        if ($this->endsAt === null || ! $this->endsAt->isFuture()) {
            return 0;
        }

        return (int) ceil(CarbonImmutable::now()->diffInSeconds($this->endsAt) / 86400);
    }

    /** @return array{state: string, ends_at: ?string, days_left: int} */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'ends_at' => $this->endsAt?->toIso8601String(),
            'days_left' => $this->daysLeft(),
        ];
    }
}
