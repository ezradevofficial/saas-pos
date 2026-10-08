<?php

namespace App\Core\Automation\Jobs;

use App\Core\Automation\Runtime\TimedTriggers;
use App\Core\Tenancy\Jobs\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * AUTO-01: fire one tenant's schedule or date triggers (TimedTriggers) in
 * its context. Unique per tenant and kind while queued, so overlapping
 * scans never queue the same occurrence twice (the dedupe key also guards).
 */
class ScanTimedTriggers implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public const SCHEDULES = 'schedules';

    public const DATES = 'dates';

    public int $uniqueFor = 600;

    public function __construct(
        public string $tenantId,
        public string $kind,
        public string $at,
    ) {
        $this->onQueue(config('automation.queue'));
    }

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->kind;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(TimedTriggers $triggers): void
    {
        $at = CarbonImmutable::parse($this->at)->utc();

        DB::transaction(fn () => $this->kind === self::DATES ? $triggers->dates($at) : $triggers->schedules($at));
    }
}
