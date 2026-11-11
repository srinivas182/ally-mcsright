<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Platform jobs (specs/core/01, specs/finance/01)
Schedule::command('tenants:lifecycle')->dailyAt('03:00')->onOneServer();
Schedule::command('ledger:reconcile')->dailyAt('04:00')->onOneServer();
// Weekly red-team run against real models (specs/ai/01); exits non-zero on any leak.
Schedule::command('ai:red-team')->weeklyOn(1, '05:00')->onOneServer();
// Collect finished AI batches and purge stored results after 7 days (specs/ai/01).
Schedule::command('ai:batches:collect')->everyTenMinutes()->onOneServer();
