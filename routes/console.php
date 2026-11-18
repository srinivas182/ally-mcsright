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
// Month-end checklist for finished months still open; owners hear once about anything that must be fixed (specs/finance/22).
Schedule::command('ledger:close-check')->dailyAt('04:15')->onOneServer();
Schedule::command('ledger:expire-audit-packs')->dailyAt('03:55')->onOneServer();
// Weekly red-team run against real models (specs/ai/01); exits non-zero on any leak.
Schedule::command('ai:red-team')->weeklyOn(1, '05:00')->onOneServer();
// Weekly AI test sets against real models within a budget (specs/ai/08).
Schedule::command('ai:eval')->weeklyOn(1, '05:30')->onOneServer();
// Daily: email HQ when a brokerage's AI cost jumps (specs/ai/08).
Schedule::command('ai:cost-watch')->dailyAt('06:10')->onOneServer();
// Referral rewards past the 30-day hold (specs/billing/08).
Schedule::command('billing:affiliates-settle')->dailyAt('06:20')->onOneServer();
Schedule::command('network:expire')->hourlyAt(17)->onOneServer(); // AR1
Schedule::command('network:trust')->dailyAt('05:40')->onOneServer(); // AR2
Schedule::command('cells:watch')->everyTenMinutes()->onOneServer(); // PS1
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
// Deal portal: milestone updates, the Monday summary and the check-in after closing (specs/ai/02, §22).
Schedule::command('transactions:portal-updates')->hourlyAt(5)->onOneServer();
// Deal playbooks: emails due, the MLS status guard and the agents' Monday summary (specs/ai/02, §§19–20).
Schedule::command('transactions:playbooks')->hourlyAt(10)->onOneServer();

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
Schedule::command('crm:prospector')->dailyAt('11:35')->onOneServer();
Schedule::command('recruiting:refresh')->dailyAt('10:50')->onOneServer(); // RC1, after the night's MLS sync
Schedule::command('recruiting:campaigns')->everyFifteenMinutes()->onOneServer(); // RC2
Schedule::command('listiq:showing-feedback')->everyFifteenMinutes()->onOneServer(); // SH1
Schedule::command('credits:grant-monthly')->dailyAt('00:20')->onOneServer();
Schedule::command('credits:margin-watch')->dailyAt('06:10')->onOneServer();
Schedule::command('credits:expire-purchases')->dailyAt('00:40')->onOneServer();
Schedule::command('billing:prune-health-checks')->dailyAt('03:40')->onOneServer();
Schedule::command('billing:go-live-weekly')->weeklyOn(1, '14:20')->onOneServer();
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
Schedule::command('movewise:recheck-licences')->dailyAt('04:41')->withoutOverlapping()->onOneServer(); // each licence every 30 days
Schedule::command('movewise:expire-offers')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('movewise:outcomes')->dailyAt('15:07')->withoutOverlapping()->onOneServer(); // status reminders and fallbacks
Schedule::command('movewise:outcomes --scorecards')->weeklyOn(1, '06:13')->onOneServer();
Schedule::command('movewise:outcomes --settle=')->monthlyOn(2, '07:19')->onOneServer(); // last month's partner settlements
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
// Approval requests nobody answered within 7 days (specs/core/16, FL1).
Schedule::command('core:expire-approvals')->dailyAt('02:35')->onOneServer();
// Data past its retention time, and privacy requests due soon (specs/core/18, TR1).
Schedule::command('core:privacy-daily')->dailyAt('03:25')->onOneServer();
// The quarterly access review reminder (specs/core/03, R1): Mondays, from two weeks into each quarter.
// Alerts held for quiet hours and the daily digest (specs/core/10, N1).
// Automations whose wait is over carry on (specs/core/06, W1).
Schedule::command('core:workflows')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
// Scheduled reports, and anomaly alerts on Mondays (specs/insights/01, K2).
Schedule::command('insights:daily')->dailyAt('11:13')->onOneServer();
Schedule::command('core:notice-digests')->hourlyAt(7)->onOneServer();
Schedule::command('core:access-review-reminder')->weeklyOn(1, '14:23')->onOneServer();
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

// DS1: brokerage exports are kept 7 days.
Schedule::command('safeguards:expire-exports')->dailyAt('03:50')->onOneServer();
// DS2: old copies 14 days after a move; dedicated databases' structure after a release.
Schedule::command('safeguards:daily')->dailyAt('04:20')->onOneServer();

// EN1: Monday check-in drafts for agents and retention alerts for owners.
Schedule::command('engage:weekly')->weeklyOn(1, '07:40')->onOneServer();
