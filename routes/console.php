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

// Key dates on deals (inspection, financing, closing…): due in 2 days, today, or missed yesterday.
Schedule::command('transactions:date-reminders')->dailyAt('12:00')->onOneServer();

// Deal-IQ: re-check open deals daily (dates approaching or missed), and escalate deadlines hourly.
Schedule::command('transactions:scan-open')->dailyAt('10:30')->onOneServer();
Schedule::command('transactions:escalate')->hourly()->onOneServer();

// Association form libraries: new editions of forms brokerages use.
Schedule::command('forms:library-sync')->dailyAt('10:00')->onOneServer();
// Nightly AI brief refresh at batch price (specs/crm/01).
Schedule::command('crm:refresh-briefs')->dailyAt('02:00')->onOneServer();
// Follow-up plan steps (specs/crm/01).
Schedule::command('crm:run-plans')->everyFifteenMinutes()->onOneServer();
// Nightly database intelligence (specs/crm/01, additions 10-12, 15).
Schedule::command('crm:intelligence')->dailyAt('01:30')->onOneServer();
// Lead ponds: uncontacted company leads return to the pond (specs/crm/01, differentiator 22).
Schedule::command('crm:ponds')->hourly()->onOneServer();
Schedule::command('crm:leads-reassign')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('crm:inbox-sweep')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('crm:score-leads')->dailyAt('03:40')->onOneServer();
Schedule::command('credits:grant-monthly')->dailyAt('00:20')->onOneServer();
Schedule::command('credits:margin-watch')->dailyAt('06:10')->onOneServer();
Schedule::command('credits:expire-purchases')->dailyAt('00:40')->onOneServer();
Schedule::command('credits:paused-maps')->dailyAt('14:05')->onOneServer();
Schedule::command('sites:home-reports')->hourlyAt(7)->withoutOverlapping()->onOneServer();
Schedule::command('sites:market-updates')->monthlyOn(1, '11:20')->onOneServer();
Schedule::command('ailly:weekly')->weeklyOn(1, '13:40')->onOneServer();
// Connected apps: due syncs; webhook history kept 30 days (specs/connect/01).
Schedule::command('connect:sync')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('connect:renew-addons')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('ads:refresh-boosts')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('ads:social-posts')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('ads:studio-autopilot')->hourlyAt(11)->withoutOverlapping()->onOneServer();
Schedule::command('ads:social-results')->dailyAt('04:17')->withoutOverlapping()->onOneServer();
Schedule::command('ads:studio-reports')->monthlyOn(1, '13:07')->withoutOverlapping()->onOneServer();
Schedule::command('ads:studio-voices')->dailyAt('05:23')->withoutOverlapping()->onOneServer();
Schedule::command('movewise:alerts')->dailyAt('13:17')->withoutOverlapping()->onOneServer();
Schedule::command('ai:assistant-prune')->dailyAt('03:41')->onOneServer();
Schedule::command('core:daily-briefs')->hourlyAt(2)->weekdays()->withoutOverlapping()->onOneServer();
Schedule::command('crm:fair-housing-pack')->monthlyOn(1, '08:17')->onOneServer();
Schedule::command('crm:nurture')->hourlyAt(23)->withoutOverlapping()->onOneServer();
Schedule::command('crm:search-alerts')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('listings:sync')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('listings:reconcile')->dailyAt('04:20')->onOneServer();
Schedule::command('listings:reconcile --freshness-only')->everyFifteenMinutes()->onOneServer();
// Audit log: seal any waiting events (specs/core/04).
Schedule::command('audit:flush')->everyMinute()->withoutOverlapping()->onOneServer();
// Audit retention (daily) and the owners' weekly security digest (Mondays).
Schedule::command('audit:maintain retention')->dailyAt('03:30')->onOneServer();
Schedule::command('audit:maintain weekly-digest')->weeklyOn(1, '13:00')->onOneServer();
// Audit streaming to brokerages' security tools (every minute).
Schedule::command('audit:maintain stream')->everyMinute()->withoutOverlapping()->onOneServer();
// Temporary access: remove expired grants (specs/core/03).
Schedule::command('core:expire-access')->hourly()->onOneServer();
// Brokerage price increases reach existing agents after 30 days (specs/core/07).
Schedule::command('billing:apply-price-changes')->dailyAt('04:00')->onOneServer();
// Unpaid plans end after the 7-day grace period (specs/core/07).
Schedule::command('billing:end-grace')->dailyAt('04:30')->onOneServer();
// Brokerages are billed for agent subsidies and sponsorships on the 1st (specs/core/07).
Schedule::command('billing:bill-subsidies')->monthlyOn(1, '05:00')->onOneServer();
// Partner statements for last month and payouts by Stripe Connect (specs/core/07).
Schedule::command('billing:partner-payouts')->monthlyOn(2, '06:00')->onOneServer();
// Agents' recurring fee invoices, reminders and late fees (specs/finance/02, part 4).
Schedule::command('payouts:agent-billing')->dailyAt('06:30')->onOneServer();
// Owners are reminded if last month's trust account is not reconciled (specs/transactions/01).
Schedule::command('payouts:trust-reminder')->monthlyOn(10, '08:00')->onOneServer();
// Expiring licences, E&O and memberships (specs/roster/01).
Schedule::command('roster:reminders')->dailyAt('07:00')->onOneServer();
// Licences re-checked against state records when automatic verification is on (specs/roster/01).
Schedule::command('roster:verify')->dailyAt('03:30')->onOneServer();
// Signers who have not signed yet (specs/forms/01).
Schedule::command('forms:reminders')->dailyAt('10:00')->onOneServer();
