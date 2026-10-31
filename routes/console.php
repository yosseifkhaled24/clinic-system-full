<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Appointment end-of-day cleanup (feature C)
|--------------------------------------------------------------------------
|
| Idempotent command: expired unpaid misses are deleted, expired paid
| misses are retained as no_show, completed+unpaid rows are flagged for
| investigation. Scheduled just after Cairo midnight so the previous
| clinic day is unambiguously over regardless of DST shifts. The explicit
| timezone keeps the run anchored to Africa/Cairo even if the server
| runs UTC; withoutOverlapping() guards slow runs.
|
| Requires the standard Laravel scheduler on the server:
|   * * * * * cd /path-to-backend && php artisan schedule:run >> /dev/null 2>&1
|
*/
Schedule::command('appointments:end-of-day')
    ->dailyAt('00:10')
    ->timezone('Africa/Cairo')
    ->withoutOverlapping();
