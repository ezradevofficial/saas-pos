<?php

namespace Modules\Pharmacy\Support;

use App\Core\Tenancy\CompanyClock;
use Carbon\CarbonImmutable;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\Settings;

/**
 * Each company's day and pharmacy settings, read once per request or job
 * (scoped binding), so a list of lots does not ask the database per row.
 */
class CompanyDays
{
    /** @var array<string, string> */
    private array $days = [];

    /** @var array<string, Settings> */
    private array $settings = [];

    public function today(string $companyId): string
    {
        return $this->days[$companyId] ??= CompanyClock::today($companyId);
    }

    public function settings(string $companyId): Settings
    {
        return $this->settings[$companyId] ??= Settings::for($companyId);
    }

    /** Days from the company's today to $lot's expiry (negative: expired). */
    public function daysLeft(Lot $lot): int
    {
        return (int) CarbonImmutable::parse($this->today($lot->company_id))->diffInDays($lot->expires_on, false);
    }

    /** `expired`, `recalled`, `quarantined`, `near_expiry` or `active`. */
    public function state(Lot $lot): string
    {
        $days = $this->daysLeft($lot);

        return match (true) {
            $days < 0 => 'expired',
            $lot->recall_id !== null => 'recalled',
            $lot->status === Lot::QUARANTINED => 'quarantined',
            $days <= $this->settings($lot->company_id)->near_expiry_days => 'near_expiry',
            default => 'active',
        };
    }
}
