<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CUR-03: reference rates, daily, for companies with a feed.
Schedule::command('exchange-rates:fetch')->dailyAt('06:30')->withoutOverlapping()->onOneServer();

// NOT-05: notification digests, sent at the digest hour in each user's time zone.
Schedule::command('notifications:send-digests')->hourly()->withoutOverlapping()->onOneServer();

// AUTO-01: schedule triggers every minute, date triggers hourly (each company's day starts at its own hour).
Schedule::command('automation:scan schedules')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('automation:scan dates')->hourly()->withoutOverlapping()->onOneServer();

// AUTO-05: runs and webhook deliveries a dead worker left behind.
Schedule::command('automation:scan reap')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// APR-05: approval reminders, escalations and final timeouts, in business time.
Schedule::command('approvals:process-timers')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// M4 (WF-10, WF-11): credit limit changes whose flow ended but the queued listener missed.
Schedule::command('credit-limits:reconcile')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// WF-09: reminders, overdue notices and escalation of plain workflow stages, in business time.
Schedule::command('workflow:process-stage-timers')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Payments: STK pushes past their timeout, payouts without a result, manual M-Pesa codes due for a check.
Schedule::command('payments:process-timers')->everyMinute()->withoutOverlapping()->onOneServer();

// Fiscal (POS-10): documents due for the tax authority, retried with backoff until accepted.
Schedule::command('fiscal:process')->everyMinute()->withoutOverlapping()->onOneServer();
