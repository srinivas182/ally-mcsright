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
// Follow-up plan steps (specs/crm/01).
Schedule::command('crm:run-plans')->everyFifteenMinutes()->onOneServer();
// Nightly database intelligence (specs/crm/01, additions 10-12, 15).
Schedule::command('crm:intelligence')->dailyAt('01:30')->onOneServer();
// Lead ponds: uncontacted company leads return to the pond (specs/crm/01, differentiator 22).
Schedule::command('crm:ponds')->hourly()->onOneServer();
// Audit log: seal any waiting events (specs/core/04).
Schedule::command('audit:flush')->everyMinute()->withoutOverlapping()->onOneServer();
// Audit retention (daily) and the owners' weekly security digest (Mondays).
Schedule::command('audit:maintain retention')->dailyAt('03:30')->onOneServer();
Schedule::command('audit:maintain weekly-digest')->weeklyOn(1, '13:00')->onOneServer();
// Audit streaming to brokerages' security tools (every minute).
Schedule::command('audit:maintain stream')->everyMinute()->withoutOverlapping()->onOneServer();
// Temporary access: remove expired grants (specs/core/03).
Schedule::command('core:expire-access')->hourly()->onOneServer();
