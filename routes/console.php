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
// Cap periods reconcile with their entries (specs/compensation/01).
Schedule::command('comp:check-caps')->dailyAt('04:30')->onOneServer();
// CRM task reminders (specs/crm/01).
Schedule::command('crm:task-reminders')->everyFiveMinutes()->onOneServer();
// Nightly AI brief refresh at batch price (specs/crm/01).
Schedule::command('crm:refresh-briefs')->dailyAt('02:00')->onOneServer();
