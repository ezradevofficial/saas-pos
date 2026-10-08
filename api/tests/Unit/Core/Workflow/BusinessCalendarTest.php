<?php

namespace Tests\Unit\Core\Workflow;

use App\Core\Workflow\Calendar\BusinessCalendar;
use App\Core\Workflow\Calendar\BusinessHours;
use App\Core\Workflow\Calendar\CalendarSpec;
use App\Core\Workflow\Calendar\HolidayFile;
use App\Core\Workflow\Calendar\PublicHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * WF-09, APR-05: business hours and days in the company's time zone,
 * weekends and public holidays from the country packs (CP-01).
 */
class BusinessCalendarTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private BusinessCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganisation();
        $this->calendar = app(BusinessCalendar::class);
    }

    private function nairobi(): CalendarSpec
    {
        return new CalendarSpec('Africa/Nairobi', BusinessCalendar::DEFAULT_HOURS, 'KE');
    }

    private function at(string $local, string $tz = 'Africa/Nairobi'): CarbonImmutable
    {
        return CarbonImmutable::parse($local, $tz);
    }

    private function assertLocal(string $expected, CarbonImmutable $actual, string $tz = 'Africa/Nairobi'): void
    {
        $this->assertSame('UTC', $actual->getTimezone()->getName(), 'due times are returned in UTC');
        $this->assertSame($expected, $actual->setTimezone($tz)->format('Y-m-d H:i'));
    }

    public function test_the_country_packs_holidays_are_loaded_and_movable_ones_are_not_guessed(): void
    {
        $ke = PublicHoliday::query()->where('country', 'KE')->orderBy('month')->orderBy('day')->get();
        $this->assertSame(['new_year', 'labour_day', 'madaraka_day', 'mashujaa_day', 'jamhuri_day', 'christmas_day', 'boxing_day'], $ke->pluck('key')->all());
        $this->assertSame(9, PublicHoliday::query()->where('country', 'CD')->count());

        // Easter and Eid dates are never entered: they wait in the todo list.
        $todo = HolidayFile::read(HolidayFile::path('KE'))->todo();
        $this->assertNotEmpty(array_filter($todo, fn ($t) => str_contains($t, 'Good Friday')));
        $this->assertNotEmpty(array_filter($todo, fn ($t) => str_contains($t, 'Idd-ul-Fitr')));

        // Labels exist in both languages.
        foreach (['KE', 'CD'] as $country) {
            foreach (HolidayFile::read(HolidayFile::path($country))->holidays() as $holiday) {
                $this->assertTrue(trans()->has("holidays.{$country}.{$holiday['key']}", 'en', false));
                $this->assertTrue(trans()->has("holidays.{$country}.{$holiday['key']}", 'fr', false));
            }
        }
    }

    public function test_loading_again_replaces_the_rows_and_the_runtime_role_cannot_write_them(): void
    {
        $this->assertSame(0, Artisan::call('country-packs:holidays', ['code' => 'KE']));
        $this->assertSame(7, PublicHoliday::query()->where('country', 'KE')->count());

        $this->expectException(QueryException::class);
        PublicHoliday::query()->create(['country' => 'KE', 'key' => 'invented', 'month' => 2, 'day' => 2]);
    }

    public function test_a_holiday_file_with_labels_or_impossible_days_is_refused(): void
    {
        foreach ([
            ['code' => 'KE', 'sources' => [], 'todo' => [], 'holidays' => [['key' => 'x', 'month' => 2, 'day' => 30]]],
            ['code' => 'KE', 'sources' => [], 'todo' => [], 'holidays' => [['key' => 'x', 'month' => 2, 'day' => 3, 'name' => 'X day']]],
            ['code' => 'KE', 'sources' => [], 'todo' => [], 'holidays' => [['key' => 'x', 'month' => 2, 'day' => 3, 'date' => '2026-02-03']]],
            ['code' => 'ke', 'sources' => [], 'todo' => [], 'holidays' => []],
        ] as $data) {
            try {
                HolidayFile::fromArray($data);
                $this->fail('accepted '.json_encode($data));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_business_hours_skip_nights_and_weekends(): void
    {
        $spec = $this->nairobi();

        // Thursday 2026-10-08 10:00 + 2h, same day.
        $this->assertLocal('2026-10-08 12:00', $this->calendar->due($this->at('2026-10-08 10:00'), 2, 'business_hours', $spec));
        // Friday 16:00 + 2h: one hour on Friday, one on Monday.
        $this->assertLocal('2026-10-12 09:00', $this->calendar->due($this->at('2026-10-09 16:00'), 2, 'business_hours', $spec));
        // Saturday: counting starts on Monday at opening.
        $this->assertLocal('2026-10-12 09:00', $this->calendar->due($this->at('2026-10-10 10:00'), 1, 'business_hours', $spec));
        // Before opening.
        $this->assertLocal('2026-10-08 09:30', $this->calendar->addBusinessMinutes($this->at('2026-10-08 06:00'), 90, $spec));
        // Eight business hours = one working day.
        $this->assertLocal('2026-10-09 10:00', $this->calendar->due($this->at('2026-10-08 10:00'), 9, 'business_hours', $spec));
    }

    public function test_public_holidays_close_the_whole_day(): void
    {
        $spec = $this->nairobi();

        // Mashujaa Day, Tuesday 2026-10-20: Monday 16:00 + 2h lands on Wednesday.
        $this->assertTrue($this->calendar->isHoliday($this->at('2026-10-20'), 'KE'));
        $this->assertFalse($this->calendar->isHoliday($this->at('2026-10-20'), 'CD'));
        $this->assertLocal('2026-10-21 09:00', $this->calendar->due($this->at('2026-10-19 16:00'), 2, 'business_hours', $spec));

        // Jamhuri Day on a Saturday changes nothing; Christmas and Boxing Day (Fri, Sat 2026).
        $this->assertLocal('2026-12-28 09:00', $this->calendar->due($this->at('2026-12-24 16:00'), 2, 'business_hours', $spec));

        // CD: Independence Day, Tuesday 2026-06-30, in Kinshasa.
        $kinshasa = new CalendarSpec('Africa/Kinshasa', BusinessCalendar::DEFAULT_HOURS, 'CD');
        $this->assertLocal('2026-07-01 08:30', $this->calendar->due($this->at('2026-06-29 16:30', 'Africa/Kinshasa'), 1, 'business_hours', $kinshasa), 'Africa/Kinshasa');

        // Without a country there are no holidays.
        $this->assertLocal('2026-10-20 09:00', $this->calendar->due($this->at('2026-10-19 16:00'), 2, 'business_hours', new CalendarSpec('Africa/Nairobi', BusinessCalendar::DEFAULT_HOURS, null)));
    }

    public function test_time_zones_decide_the_local_working_day(): void
    {
        // 05:30Z is 08:30 in Nairobi (open) but 06:30 in Kinshasa (not yet).
        $instant = CarbonImmutable::parse('2026-10-08T05:30:00Z');

        $this->assertSame('2026-10-08T06:30:00Z', $this->calendar->due($instant, 1, 'business_hours', $this->nairobi())->toIso8601ZuluString());
        $this->assertSame('2026-10-08T08:00:00Z', $this->calendar->due($instant, 1, 'business_hours', new CalendarSpec('Africa/Kinshasa', BusinessCalendar::DEFAULT_HOURS, 'CD'))->toIso8601ZuluString());

        // Late Friday in UTC is already Saturday morning in Nairobi.
        $this->assertLocal('2026-10-12 09:00', $this->calendar->due(CarbonImmutable::parse('2026-10-09T22:00:00Z'), 1, 'business_hours', $this->nairobi()));
    }

    public function test_business_days_keep_the_time_and_skip_closed_days(): void
    {
        $spec = $this->nairobi();

        // Thursday 10:00 + 3 business days: Friday, Monday, Tuesday.
        $this->assertLocal('2026-10-13 10:00', $this->calendar->due($this->at('2026-10-08 10:00'), 3, 'business_days', $spec));
        // Friday 2026-10-16 + 2: Monday, then Wednesday (Tuesday is Mashujaa Day).
        $this->assertLocal('2026-10-21 10:00', $this->calendar->due($this->at('2026-10-16 10:00'), 2, 'business_days', $spec));
        // From a Sunday: counting starts at Monday's opening.
        $this->assertLocal('2026-10-13 08:00', $this->calendar->due($this->at('2026-10-11 15:00'), 1, 'business_days', $spec));
        // From after closing: the next opening, then one day.
        $this->assertLocal('2026-10-13 08:00', $this->calendar->due($this->at('2026-10-11 20:00'), 1, 'business_days', $spec));
    }

    public function test_plain_hours_and_days_ignore_the_calendar(): void
    {
        $spec = $this->nairobi();

        $this->assertLocal('2026-10-10 12:00', $this->calendar->due($this->at('2026-10-10 10:00'), 2, 'hours', $spec));
        $this->assertLocal('2026-10-23 10:00', $this->calendar->due($this->at('2026-10-20 10:00'), 3, 'days', $spec));
    }

    public function test_company_hours_with_a_lunch_break_and_saturday_mornings(): void
    {
        $this->inTenant(function () {
            BusinessHours::create(['company_id' => $this->acme->id, 'hours' => [
                'mon' => [['08:00', '12:00'], ['13:00', '17:00']],
                'tue' => [['08:00', '17:00']], 'wed' => [['08:00', '17:00']], 'thu' => [['08:00', '17:00']], 'fri' => [['08:00', '17:00']],
                'sat' => [['09:00', '13:00']],
                'sun' => [],
            ]]);

            $spec = $this->calendar->forCompany($this->acme->id);
            $this->assertSame('Africa/Nairobi', $spec->timezone);
            $this->assertSame('KE', $spec->country);

            // Monday 11:30 + 1h: half an hour before lunch, half after.
            $this->assertLocal('2026-10-12 13:30', $this->calendar->due($this->at('2026-10-12 11:30'), 1, 'business_hours', $spec));
            // Friday 16:00 + 3h: one hour Friday, two on Saturday morning.
            $this->assertLocal('2026-10-10 11:00', $this->calendar->due($this->at('2026-10-09 16:00'), 3, 'business_hours', $spec));
            // Business days land on the nearest open time: Friday 16:00 + 1 day is Saturday, closed at 16:00.
            $this->assertLocal('2026-10-10 13:00', $this->calendar->due($this->at('2026-10-09 16:00'), 1, 'business_days', $spec));
        });
    }

    public function test_a_document_without_a_company_counts_in_utc_without_holidays(): void
    {
        $spec = $this->calendar->forCompany(null);

        $this->assertSame('UTC', $spec->timezone);
        $this->assertNull($spec->country);
        $this->assertSame(BusinessCalendar::DEFAULT_HOURS, $this->inTenant(fn () => $this->calendar->forCompany($this->branchA->id)->hours), 'an unknown company falls back to the defaults');
    }

    public function test_a_calendar_that_is_never_open_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calendar->due($this->at('2026-10-08 10:00'), 1, 'business_hours', new CalendarSpec('UTC', [], null));
    }

    public function test_working_hours_are_validated(): void
    {
        $this->assertSame([], BusinessCalendar::problems(BusinessCalendar::DEFAULT_HOURS));
        $this->assertSame([], BusinessCalendar::problems(['mon' => [['00:00', '24:00']]]));
        $this->assertNotEmpty(BusinessCalendar::problems(['monday' => [['08:00', '17:00']]]));
        $this->assertNotEmpty(BusinessCalendar::problems(['mon' => [['17:00', '08:00']]]));
        $this->assertNotEmpty(BusinessCalendar::problems(['mon' => [['8:00', '17:00']]]));
        $this->assertNotEmpty(BusinessCalendar::problems(['mon' => [['08:00', '12:00'], ['11:00', '17:00']]]));
        $this->assertNotEmpty(BusinessCalendar::problems([['08:00', '17:00']]));
    }
}
