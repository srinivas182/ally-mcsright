<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Watchtower\Analyst;
use Modules\Core\Watchtower\ErrorTracking;
use Modules\Core\Watchtower\FixRequest;
use Modules\Core\Watchtower\Heartbeats;
use Modules\Core\Watchtower\Incident;
use Modules\Core\Watchtower\Jobs\AnalyseIncident;
use Modules\Core\Watchtower\Release;
use Modules\Core\Watchtower\Settings;
use Modules\Core\Watchtower\SlowPages;
use Modules\Core\Watchtower\SystemChecks;
use Modules\Core\Watchtower\Uptime;

/**
 * HQ › Watchtower (OB1): every problem on RightAlly, the state of each system, and the switches — who gets emails,
 * problem emails, the daily report, the AI root cause, browser errors, slow pages, the uptime checker's address, and
 * whether problems also go to Sentry (set in Outside services).
 */
final class WatchtowerController
{
    public const VIEWS = ['open' => 'Open', 'fixed' => 'Fixed', 'resolved' => 'Resolved', 'ignored' => 'Ignored', 'all' => 'All'];

    public function index(Request $request, OutsideServices $services): Response
    {
        $f = $this->filters($request);
        $rows = Incident::query()
            ->when($f['view'] === 'open', fn ($q) => $q->whereIn('status', Incident::OPEN), fn ($q) => $f['view'] === 'all' ? $q : $q->where('status', $f['view']))
            ->when($f['severity'] !== '', fn ($q) => $q->where('severity', $f['severity']))
            ->when($f['kind'] !== '', fn ($q) => $q->where('kind', $f['kind']))
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 else 2 end")->orderByDesc('last_seen_at')
            ->paginate(50)->withQueryString();
        $open = Incident::query()->whereIn('status', Incident::OPEN)->toBase()->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity')->all();
        $tracking = $services->active(ErrorTracking::SERVICE);
        $token = Settings::uptimeToken();

        return Inertia::render('hq/watchtower', [
            'filters' => $f, 'views' => self::VIEWS, 'severities' => Incident::SEVERITIES, 'kinds' => Incident::KINDS, 'release' => Release::current(),
            'summary' => ['critical' => (int) ($open['critical'] ?? 0), 'high' => (int) ($open['high'] ?? 0), 'normal' => (int) ($open['normal'] ?? 0),
                'today' => Incident::query()->where('first_seen_at', '>=', now()->startOfDay())->count()],
            'checks' => SystemChecks::latest(),
            'tasks' => Settings::db()->table('watchtower_heartbeats')->orderBy('task')->get()->map(fn (object $t): array => ['task' => (string) $t->task, 'expression' => (string) $t->expression,
                'last_started_at' => $t->last_started_at, 'last_status' => $t->last_status, 'last_runtime_ms' => $t->last_runtime_ms])->values()->all(),
            'scheduler' => Heartbeats::pulse(),
            'uptime' => Uptime::summary(),
            'slow' => SlowPages::worst(),
            'settings' => Settings::all(),
            'aiToday' => Analyst::today(),
            'uptimeUrl' => $token !== '' ? 'https://'.config('core.hq_host').'/webhooks/uptime/'.$token : null,
            'heartbeatUrl' => 'https://'.config('core.hq_host').'/up/scheduler',
            'tracking' => $tracking === null ? null : ['provider' => $tracking['provider'], 'mode' => $tracking['mode']],
            'rows' => ['data' => collect($rows->items())->map(fn (Incident $i): array => $this->row($i))->all(), 'total' => $rows->total(),
                'next' => $rows->nextPageUrl(), 'prev' => $rows->previousPageUrl()],
        ]);
    }

    public function show(Incident $incident): Response
    {
        $ids = Settings::db()->table('watchtower_incident_brokerages')->where('incident_id', $incident->id)->limit(20)->pluck('tenant_id')->all();

        return Inertia::render('hq/watchtower-problem', [
            'incident' => [...$this->row($incident), 'message' => $incident->message, 'file' => $incident->file, 'line' => $incident->line, 'trace' => $incident->trace,
                'context' => $incident->context, 'first_release' => $incident->first_release, 'fixed_in' => $incident->fixed_in,
                'regressed_at' => $incident->regressed_at?->toIso8601String(), 'resolved_at' => $incident->resolved_at?->toIso8601String(), 'analysis' => $incident->analysis],
            'days' => Settings::db()->table('watchtower_incident_days')->where('incident_id', $incident->id)->where('day', '>=', now()->subDays(13)->toDateString())->orderBy('day')
                ->get(['day', 'count'])->map(fn (object $d): array => ['day' => (string) $d->day, 'count' => (int) $d->count])->all(),
            'brokerages' => Tenant::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn (Tenant $t): array => ['id' => $t->id, 'name' => $t->name])->all(),
            'fixRequest' => FixRequest::for($incident),
            'release' => Release::current(),
            'statuses' => Incident::STATUSES,
            'ai' => Settings::on('ai'),
        ]);
    }

    /** Acknowledge, mark fixed (in a release: if it comes back after that, it is a regression), ignore, or reopen. */
    public function update(Request $request, Incident $incident): RedirectResponse
    {
        $d = $request->validate(['action' => ['required', Rule::in(['acknowledge', 'fixed', 'ignore', 'reopen'])], 'fixed_in' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\-]+$/']]);
        $action = (string) $d['action'];
        $values = match ($action) {
            'acknowledge' => ['status' => 'acknowledged'],
            'fixed' => ['status' => 'fixed', 'fixed_in' => $d['fixed_in'] ?? Release::current(), 'fixed_at' => now(), 'regressed_at' => null, 'ongoing' => false],
            'ignore' => ['status' => 'ignored'],
            default => ['status' => 'new', 'resolved_at' => null],
        };
        $id = $request->user()?->getAuthIdentifier();
        $incident->forceFill([...$values, 'updated_by' => is_numeric($id) ? (int) $id : null])->save();
        app(AuditLogger::class)->record('watchtower.problem.'.$action, metadata: ['reference' => $incident->reference()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => match ($action) {
            'acknowledge' => 'Acknowledged: no more emails unless it gets worse.',
            'fixed' => "Marked fixed in {$incident->fixed_in}. If it happens again you will be told it came back.",
            'ignore' => 'Ignored: still counted, never emailed.',
            default => 'Reopened.',
        }]);

        return back();
    }

    /** Ask the AI to explain this problem (again), for example after the code changed. */
    public function analyse(Incident $incident): RedirectResponse
    {
        if (! Settings::on('ai') || ! Analyst::underCap()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => Settings::on('ai') ? 'The AI has explained as many problems as it may today. Raise the daily limit or try tomorrow.' : 'The AI root cause is switched off.']);

            return back();
        }
        $incident->forceFill(['analysis' => ['status' => 'pending', 'release' => $incident->last_release, 'at' => now()->toIso8601String()]])->save();
        AnalyseIncident::dispatch($incident->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Asked the AI to explain it. The answer appears here in a minute or two.']);

        return back();
    }

    /** The switches and who gets emails. */
    public function settings(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'recipients' => ['present', 'array', 'max:5'], 'recipients.*' => ['required', 'email:rfc', 'max:160'],
            'alerts' => ['required', 'boolean'], 'digest' => ['required', 'boolean'], 'ai' => ['required', 'boolean'], 'ai_daily_cap' => ['required', 'integer', 'min:0', 'max:500'],
            'browser_errors' => ['required', 'boolean'], 'slow_pages' => ['required', 'boolean'],
        ], ['recipients.max' => 'Up to 5 email addresses.', 'recipients.*.email' => 'That is not an email address.']);
        $id = $request->user()?->getAuthIdentifier();
        Settings::save(['recipients' => array_values(array_map('strval', $d['recipients'])), 'alerts' => (bool) $d['alerts'], 'digest' => (bool) $d['digest'], 'ai' => (bool) $d['ai'],
            'ai_daily_cap' => (int) $d['ai_daily_cap'], 'browser_errors' => (bool) $d['browser_errors'], 'slow_pages' => (bool) $d['slow_pages']], is_numeric($id) ? (int) $id : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Watchtower settings saved.']);

        return back();
    }

    /** A new uptime webhook address; the old one stops working at once. */
    public function uptimeAddress(Request $request): RedirectResponse
    {
        $id = $request->user()?->getAuthIdentifier();
        Settings::rotateUptimeToken(is_numeric($id) ? (int) $id : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'New address made. Paste it into your uptime checker: the old one has stopped working.']);

        return back();
    }

    /** @return array<string, mixed> */
    private function row(Incident $i): array
    {
        return ['id' => $i->id, 'reference' => $i->reference(), 'kind' => $i->kind, 'severity' => $i->severity, 'status' => $i->status, 'title' => $i->title,
            'location' => $i->location, 'surface' => $i->surface, 'occurrences' => $i->occurrences, 'last_hour' => $i->lastHour(), 'brokerages' => $i->brokerages,
            'last_release' => $i->last_release, 'ongoing' => $i->ongoing, 'regressed' => $i->regressed_at !== null && in_array($i->status, Incident::OPEN, true),
            'explained' => (((array) $i->analysis)['status'] ?? null) === 'done',
            'first_seen_at' => $i->first_seen_at->toIso8601String(), 'last_seen_at' => $i->last_seen_at->toIso8601String()];
    }

    /** @return array{view: string, severity: string, kind: string} */
    private function filters(Request $request): array
    {
        $view = (string) $request->query('view', 'open');
        $severity = (string) $request->query('severity', '');
        $kind = (string) $request->query('kind', '');

        return ['view' => array_key_exists($view, self::VIEWS) ? $view : 'open', 'severity' => array_key_exists($severity, Incident::SEVERITIES) ? $severity : '',
            'kind' => array_key_exists($kind, Incident::KINDS) ? $kind : ''];
    }
}
