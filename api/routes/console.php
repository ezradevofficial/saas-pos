<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CUR-03: reference rates, daily, for companies with a feed.
Schedule::command('exchange-rates:fetch')->dailyAt('06:30')->withoutOverlapping();

// NOT-05: notification digests, sent at the digest hour in each user's time zone.
Schedule::command('notifications:send-digests')->hourly()->withoutOverlapping()->onOneServer();

// AUTO-01: schedule triggers every minute, date triggers hourly (each company's day starts at its own hour).
Schedule::command('automation:scan schedules')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('automation:scan dates')->hourly()->withoutOverlapping()->onOneServer();
