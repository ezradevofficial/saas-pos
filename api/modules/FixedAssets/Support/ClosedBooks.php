<?php

namespace Modules\FixedAssets\Support;

use App\Core\Rbac\ModuleRegistry;
use Carbon\CarbonImmutable;
use Modules\Accounting\Contracts\ClosedPeriods;

/**
 * FA-12, FA-13, FA-15 to FA-17 (decision 10): nothing Fixed assets posts
 * may be dated on or before the last day of the company's latest closed
 * Accounting period. Asked through Accounting's read contract, only while
 * Accounting is active; without it, no period is closed.
 */
class ClosedBooks
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /** The company's last closed day (Y-m-d), or null. */
    public function closedThrough(string $companyId): ?string
    {
        if (! class_exists(ClosedPeriods::class) || ! $this->modules->isActive('accounting')) {
            return null;
        }

        return app(ClosedPeriods::class)->closedThrough($companyId);
    }

    /** 422 `period_closed` when $date is in a closed period. */
    public function assertOpen(string $companyId, CarbonImmutable|string $date, string $field = 'date'): void
    {
        $day = $date instanceof CarbonImmutable ? $date->toDateString() : substr($date, 0, 10);
        $closed = $this->closedThrough($companyId);

        if ($closed !== null && $day <= $closed) {
            throw FaError::make('period_closed', ['date' => $day, 'closed' => $closed], $field);
        }
    }
}
