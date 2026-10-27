<?php

namespace Modules\Hotel\Rates;

use Carbon\CarbonImmutable;
use Modules\Hotel\Models\RatePlan;
use Modules\Hotel\Models\RateSeason;
use Modules\Hotel\Models\RoomType;
use Modules\Hotel\Models\Settings;
use Modules\Hotel\Support\HotelError;
use Modules\Hotel\Support\Taxing;

/**
 * HOT-06 to HOT-10: the night-by-night price of a stay. A night's rate is
 * the highest-priority season covering it (its weekend rate when it has one
 * and the night is a weekend day, else its weekday rate), else the plan's
 * own weekday or weekend rate. Each adult above the room type's base adults
 * pays the extra adult rate; each child above the plan's free children pays
 * the child rate. Amounts are minor units of the plan's currency, as quoted
 * (with tax included when the plan says so). The quote is stored on the stay
 * (`nightly`), so later rate changes never change a booked stay.
 */
class RateQuotes
{
    public const MAX_NIGHTS = 365;

    public function __construct(private readonly Taxing $taxing) {}

    /**
     * @return array{currency: string, board: string, tax_inclusive: bool, nights: list<array{date: string, rate_minor: int, extra_minor: int, amount_minor: int, season: ?string}>, total_minor: int, estimate: array{net_minor: int, tax_minor: int, gross_minor: int, flags: list<string>}}
     */
    public function quote(RatePlan $plan, RoomType $type, Settings $settings, CarbonImmutable $arrival, CarbonImmutable $departure, int $adults, int $children, bool $withTax = true): array
    {
        $this->assertBookable($plan, $type, $arrival, $departure, $adults, $children);
        $seasons = $plan->relationLoaded('seasons') ? $plan->seasons : $plan->seasons()->get();
        $extra = max(0, $adults - $type->base_adults) * $plan->extra_adult_minor + max(0, $children - $plan->free_children) * $plan->child_rate_minor;
        $nights = [];

        for ($day = $arrival; $day < $departure; $day = $day->addDay()) {
            [$rate, $season] = $this->rateOn($plan, $seasons, $settings, $day);
            $nights[] = ['date' => $day->toDateString(), 'rate_minor' => $rate, 'extra_minor' => $extra, 'amount_minor' => $rate + $extra, 'season' => $season];
        }

        $total = array_sum(array_column($nights, 'amount_minor'));

        return [
            'currency' => $plan->currency,
            'board' => $plan->board,
            'tax_inclusive' => $plan->tax_inclusive,
            'nights' => $nights,
            'total_minor' => $total,
            'estimate' => $withTax ? $this->estimate($plan, $settings, $nights) : ['net_minor' => $total, 'tax_minor' => 0, 'gross_minor' => $total, 'flags' => []],
        ];
    }

    /** @return array{0: int, 1: ?string} the rate of the night and the season that set it */
    public function rateOn(RatePlan $plan, iterable $seasons, Settings $settings, CarbonImmutable $day): array
    {
        $weekend = in_array($day->dayOfWeekIso, array_map('intval', $settings->weekend_days ?? []), true);
        $season = collect($seasons)
            ->filter(fn (RateSeason $s) => $s->starts_on <= $day && $s->ends_on >= $day)
            ->sortByDesc(fn (RateSeason $s) => [$s->priority, $s->starts_on->toDateString()])
            ->first();

        if ($season !== null) {
            return [$weekend && $season->weekend_rate_minor !== null ? $season->weekend_rate_minor : $season->weekday_rate_minor, $season->name];
        }

        return [$weekend ? $plan->weekend_rate_minor : $plan->weekday_rate_minor, null];
    }

    private function assertBookable(RatePlan $plan, RoomType $type, CarbonImmutable $arrival, CarbonImmutable $departure, int $adults, int $children): void
    {
        $nights = (int) $arrival->diffInDays($departure);

        if ($nights < 1) {
            throw HotelError::make(422, 'dates_invalid', field: 'departure_on');
        }

        if ($nights > self::MAX_NIGHTS) {
            throw HotelError::make(422, 'stay_too_long', ['max' => self::MAX_NIGHTS], 'departure_on');
        }

        if ($plan->room_type_id !== $type->id || ! $plan->active || $plan->archived_at !== null
            || ($plan->valid_from !== null && $plan->valid_from > $arrival) || ($plan->valid_until !== null && $plan->valid_until < $departure->subDay())) {
            throw HotelError::make(422, 'rate_plan_unavailable', field: 'rate_plan_id');
        }

        if ($type->archived_at !== null) {
            throw HotelError::make(422, 'room_type_archived', field: 'room_type_id');
        }

        if ($adults > $type->max_adults || $children > $type->max_children || $adults + $children > $type->max_occupancy) {
            throw HotelError::make(422, 'occupancy_exceeded', ['max' => $type->max_occupancy], 'adults');
        }
    }

    /** @param list<array{date: string, amount_minor: int}> $nights */
    private function estimate(RatePlan $plan, Settings $settings, array $nights): array
    {
        $net = $tax = $gross = 0;
        $flags = [];

        foreach ($nights as $night) {
            $split = $this->taxing->split($plan->company_id, $settings->room_item_id, $settings->levy_tax_code_ids ?? [], $night['amount_minor'], $plan->currency, $plan->tax_inclusive, $night['date']);
            $net += $split['net'];
            $tax += $split['tax'];
            $gross += $split['gross'];
            $flags = array_values(array_unique([...$flags, ...$split['flags']]));
        }

        return ['net_minor' => $net, 'tax_minor' => $tax, 'gross_minor' => $gross, 'flags' => $flags];
    }
}
