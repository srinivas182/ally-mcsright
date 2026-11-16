<?php

declare(strict_types=1);

namespace Modules\Movewise\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections as ControlDb;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Movewise\Launch\Readiness;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Connection;
use Modules\Movewise\Models\State;
use Modules\Movewise\Movewise;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HQ → Movewise launch (specs/movewise/08): each state's launch checks, and the audit packs counsel and regulators ask
 * for — the Fair Housing pack (every set of three shown and why; every AIlly reply replaced for Fair Housing) and the
 * consent pack (every introduction's consent, word for word, and how it ended). Downloads are password-confirmed and
 * logged; phone numbers are never in them.
 */
final class HqAuditController
{
    public function index(Readiness $readiness): Response
    {
        return Inertia::render('hq/movewise-launch', [
            'states' => State::query()->orderBy('name')->get()->map(fn (State $s): array => ['code' => $s->code, 'name' => $s->name, 'on' => $s->on,
                'turned_on_at' => $s->turned_on_at?->toDateString(), 'checks' => $readiness->for($s), 'ready' => $readiness->ready($s)])->values()->all(),
            'order' => (array) config('movewise.launch_order'),
        ]);
    }

    public function fairHousing(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $this->log($request, 'fair_housing', $from, $to);
        $shows = DB::connection(ControlDb::control())->table('movewise_shows')->whereBetween('created_at', [$from, $to])->orderBy('id');
        $names = Agent::query()->get(['id', 'profile', 'brokerage_name'])->keyBy('id');
        $hq = app(Movewise::class)->feedTenant();
        $replaced = $hq !== null ? app(TenantContext::class)->runAs($hq, fn () => DB::connection(ControlDb::cell())->table('crm_fair_housing_log')->where('tenant_id', $hq->id)
            ->where('where', 'Movewise AIlly')->whereBetween('created_at', [$from, $to])->orderBy('id')->get(['created_at', 'phrases'])->all()) : [];

        return response()->streamDownload(function () use ($shows, $names, $replaced): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Movewise Fair Housing audit pack. Ranking uses only the home, area, price, lead type and language the person chose; nothing else about the person is read.'], escape: '');
            fputcsv($out, ['Set shown (UTC)', 'Set', 'State', 'ZIP', 'City', 'Price', 'Lead type', 'Language', 'Position', 'Agent', 'Brokerage', 'Kept for an agent not shown lately', 'Why this agent'], escape: '');
            foreach ($shows->cursor() as $s) {
                $ctx = (array) json_decode((string) $s->context, true);
                foreach ((array) json_decode((string) $s->agents, true) as $i => $a) {
                    $agent = $names[(int) ($a['agent'] ?? 0)] ?? null;
                    fputcsv($out, [(string) $s->created_at, (string) $s->ulid, $ctx['state'] ?? '', $ctx['zip'] ?? '', $ctx['city'] ?? '', $ctx['price'] ?? '', $ctx['lead_type'] ?? '',
                        $ctx['language'] ?? '', $i + 1, (string) ($agent->profile['display_name'] ?? ''), (string) ($agent->brokerage_name ?? ''), ($a['fresh'] ?? false) ? 'yes' : 'no',
                        implode('; ', (array) ($a['reasons'] ?? []))], escape: '');
                }
            }
            fputcsv($out, [], escape: '');
            fputcsv($out, ['AIlly replies replaced for Fair Housing (UTC)', 'Phrases caught'], escape: '');
            foreach ($replaced as $r) {
                fputcsv($out, [(string) $r->created_at, implode('; ', (array) json_decode((string) $r->phrases, true))], escape: '');
            }
            fclose($out);
        }, 'movewise-fair-housing-'.$from->toDateString().'-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store']);
    }

    public function consent(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $this->log($request, 'consent', $from, $to);
        $rows = Connection::query()->whereBetween('consent_at', [$from, $to])->orderBy('id');
        $names = Agent::query()->get(['id', 'profile', 'brokerage_name', 'licence_number', 'state'])->keyBy('id');
        $accounts = Tenant::query()->pluck('name', 'id');

        return response()->streamDownload(function () use ($rows, $names, $accounts): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Introduction', 'Consent given (UTC)', 'IP', 'Agent', 'Licence', 'State', 'Brokerage', 'Account', 'Way of contact', 'Consent wording', 'Status', 'Accepted (UTC)',
                'Ended (UTC)', 'How it ended', 'Relay number', 'Handed to the agent\'s own number (UTC)', 'Referral agreement signed (UTC)'], escape: '');
            foreach ($rows->cursor() as $c) {
                /** @var Connection $c */
                $a = $names[$c->agent_id] ?? null;
                fputcsv($out, [$c->ulid, $c->consent_at->toDateTimeString(), $c->consent_ip, (string) ($a->profile['display_name'] ?? ''), (string) ($a->licence_number ?? ''),
                    (string) ($a->state ?? ''), (string) ($a->brokerage_name ?? ''), (string) ($accounts[$c->tenant_id] ?? ''), $c->method, $c->consent_text, $c->status,
                    $c->accepted_at?->toDateTimeString(), $c->closed_at?->toDateTimeString(), $c->end_reason, $c->relay_number, $c->handed_over_at?->toDateTimeString(),
                    $c->referral_signed_at?->toDateTimeString()], escape: '');
            }
            fclose($out);
        }, 'movewise-consent-'.$from->toDateString().'-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(Request $request): array
    {
        $d = fn (string $k, CarbonImmutable $default): CarbonImmutable => is_string($request->query($k)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($k)) === 1
            ? CarbonImmutable::parse((string) $request->query($k)) : $default;

        return [$d('from', CarbonImmutable::now()->subDays(90))->startOfDay(), $d('to', CarbonImmutable::now())->endOfDay()];
    }

    private function log(Request $request, string $pack, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $u = $request->user();
        abort_unless($u instanceof User, 403);
        app(AuditLogger::class)->record('movewise.audit_pack', metadata: ['pack' => $pack, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'by' => $u->id], severity: 'notice');
    }
}
