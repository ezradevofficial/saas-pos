<?php

namespace App\Core\Platform\Reports\Http;

use App\Core\Platform\Http\Requests\PlatformRequest;
use Carbon\CarbonImmutable;

/**
 * ADM-06: revenue and usage reports (`platform.reports.view`) for a range
 * of UTC days, at most two years; this month by default.
 */
class ReportsRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.reports.view';

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function from(): CarbonImmutable
    {
        return $this->validated('from') ? CarbonImmutable::parse($this->validated('from'), 'UTC') : CarbonImmutable::now()->utc()->startOfMonth();
    }

    public function to(): CarbonImmutable
    {
        $to = $this->validated('to') ? CarbonImmutable::parse($this->validated('to'), 'UTC') : CarbonImmutable::now()->utc()->startOfDay();

        return $to->min($this->from()->addYears(2));
    }
}
