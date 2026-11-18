<?php

declare(strict_types=1);

namespace Modules\Core\Security;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Services\Catalog;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * SOC 2 controls with live evidence (TR1, specs/core/18): for each control, what RightAlly does, the evidence read from
 * the platform today, and whether it is met, needs attention or is a written process the auditor checks elsewhere.
 * HQ shows it to auditors and downloads it as a spreadsheet. Counts only: no personal details.
 */
final class Controls
{
    /**
     * @return list<array{id: string, area: string, control: string, evidence: string, status: string}>
     */
    public function all(): array
    {
        $control = DB::connection(Connections::control());
        $hq = Tenant::query()->where('type', 'hq')->first();
        [$staff, $twoStep] = $hq !== null ? app(TenantContext::class)->runAs($hq, fn (): array => [
            User::query()->where('kind', UserKind::HqStaff)->where('status', UserStatus::Active)->count(),
            User::query()->where('kind', UserKind::HqStaff)->where('status', UserStatus::Active)->whereNotNull('two_factor_confirmed_at')->count(),
        ]) : [0, 0];
        $active = Tenant::query()->where('status', TenantStatus::Active)->where('type', '!=', 'hq')->count();
        $keyed = (int) $control->table('brokerage_keys')->where('status', 'active')->distinct()->count('tenant_id');
        $askFirst = $control->table('support_access')->where('policy', 'ask')->count();
        $visits = $control->table('support_visits')->where('created_at', '>', now()->subDays(90))->count();
        $restores = $control->table('brokerage_restores')->where('created_at', '>', now()->subDays(365));
        $twoPerson = (clone $restores)->whereNotNull('approved_by')->whereColumn('approved_by', '!=', 'requested_by')->count();
        $restoreCount = (clone $restores)->count();
        $vendors = $control->table('outside_services')->where('tenant_id', 0)->where('mode', '!=', 'off')->get(['service', 'provider', 'mode']);
        $bot = $vendors->firstWhere('service', 'bot_protection');
        // Sprint 62: the latest weekly AI red-team run (ai:red-team, Mondays).
        $redTeam = $control->table('ai_red_team_runs')->whereNotNull('finished_at')->orderByDesc('finished_at')->first(['status', 'scenarios_run', 'critical_followed', 'finished_at']);
        $redTeamFresh = $redTeam !== null && CarbonImmutable::parse((string) $redTeam->finished_at)->gt(now()->subDays(8));
        $routes = count(Route::getRoutes()->getRoutes());

        return [
            ['id' => 'CC6.1', 'area' => 'Access', 'control' => 'HQ staff sign in with two steps (password and authenticator app).',
                'evidence' => "{$twoStep} of {$staff} active HQ staff have two-step sign-in.", 'status' => $staff > 0 && $twoStep === $staff ? 'met' : 'attention'],
            ['id' => 'CC6.2', 'area' => 'Access', 'control' => 'Temporary access ends by itself; access is reviewed every quarter; sensitive changes can need a second person.',
                'evidence' => 'Expired access is removed every hour (core:expire-access); quarterly access review reminders; approval rules per brokerage.', 'status' => 'met'],
            ['id' => 'CC6.3', 'area' => 'Access', 'control' => 'RightAlly support views a brokerage only with a reason, for at most 60 minutes, and every visit is listed for the brokerage.',
                'evidence' => "{$visits} support visits in 90 days; {$askFirst} brokerages ask support to get permission first.", 'status' => 'met'],
            ['id' => 'CC6.1-2', 'area' => 'Isolation', 'control' => 'Each brokerage sees only its own data: every page checks its address, every record and every database query is limited to the signed-in brokerage, and the one way across brokerages is audited.',
                'evidence' => "All {$routes} web addresses are checked on every change (public ones against a reviewed list); the database guard is on; cross-brokerage reads are written to the audit log.", 'status' => 'met'],
            ['id' => 'CC6.8', 'area' => 'Protection', 'control' => 'A package with a known vulnerability stops a release; updates are proposed automatically.',
                'evidence' => 'Every pull request runs composer audit and npm audit (high and above fail); Dependabot proposes updates.', 'status' => 'process'],
            ['id' => 'CC7.1', 'area' => 'Protection', 'control' => 'Pages are served with browser security headers, public forms are rate-limited, and addresses RightAlly calls for customers are checked to be public.',
                'evidence' => 'Security headers on every response (nosniff, same-site framing, referrer and permissions policies, HSTS); rate limits on every public form; webhook and stream addresses checked at every send.', 'status' => 'met'],
            ['id' => 'C1.1', 'area' => 'AI', 'control' => 'AI is given only the signed-in brokerage\'s records, treats documents and messages as data, and is attacked every week to check it never follows hidden instructions.',
                'evidence' => $redTeam === null ? 'No red-team run yet (ai:red-team runs every Monday once AI keys are set).'
                    : 'Last red-team run '.CarbonImmutable::parse((string) $redTeam->finished_at)->toFormattedDateString().": {$redTeam->scenarios_run} attacks, {$redTeam->critical_followed} critical followed ({$redTeam->status}).",
                'status' => $redTeamFresh && (int) $redTeam->critical_followed === 0 ? 'met' : 'attention'],
            ['id' => 'CC6.6', 'area' => 'Protection', 'control' => 'Public forms are protected from bots (honeypot, timing, rate limits, and a bot check).',
                'evidence' => $bot !== null ? 'Bot check: '.$bot->provider.' ('.$bot->mode.').' : 'Honeypot, timing and rate limits on; the bot check service is off.',
                'status' => $bot !== null && $bot->mode === 'live' ? 'met' : 'attention'],
            ['id' => 'CC6.7', 'area' => 'Protection', 'control' => 'Each brokerage\'s sensitive data is encrypted with its own key; data travels encrypted.',
                'evidence' => "{$keyed} of {$active} active brokerages have their own key.", 'status' => $active === 0 || $keyed >= $active ? 'met' : 'attention'],
            ['id' => 'CC7.2', 'area' => 'Monitoring', 'control' => 'Every change is written to a tamper-evident audit log, which brokerages can stream to their own tools.',
                'evidence' => 'Audit log with a SHA-256 chain per brokerage, checked by audit:verify; streaming to webhooks, S3, Datadog or Splunk.', 'status' => 'met'],
            ['id' => 'A1.2', 'area' => 'Availability', 'control' => 'Backups can be restored, and a restore needs a second person to approve it.',
                'evidence' => "{$restoreCount} restores in the last year, {$twoPerson} approved by a second person.", 'status' => $restoreCount === $twoPerson ? 'met' : 'attention'],
            ['id' => 'CC8.1', 'area' => 'Change', 'control' => 'Every change goes through a pull request with automated checks (style, types, tests on MySQL) before it ships.',
                'evidence' => 'Process: the auditor checks the code repository\'s merge history.', 'status' => 'process'],
            ['id' => 'CC9.2', 'area' => 'Vendors', 'control' => 'Outside providers are listed, and each is switched on in Test before Live.',
                'evidence' => $vendors->isEmpty() ? 'No outside services switched on yet.' : $vendors->map(fn (object $v): string => (Catalog::get((string) $v->service)['label'] ?? $v->service).': '.$v->provider.' ('.$v->mode.')')->implode('; ').'.',
                'status' => 'met'],
            ['id' => 'P4.2', 'area' => 'Privacy', 'control' => 'People can ask to see, delete or correct their information; requests are confirmed by email and answered within 45 days.',
                'evidence' => 'Privacy requests on every brokerage website, with reminders a week before the answer date; data kept only as long as each brokerage chooses.', 'status' => 'met'],
        ];
    }
}
