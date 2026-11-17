<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Actions\ClosePeriod;
use Modules\Ledger\Close\CloseChecks;
use Modules\Ledger\Close\Snapshots;
use Modules\Ledger\Models\LedgerPeriod;

/** Month-end close (specs/finance/01, FH1 specs/finance/22): the checklist, closing with a note, and the statements as closed. */
final class PeriodController
{
    public function index(CloseChecks $checks): Response
    {
        Gate::authorize('finance.ledger.view');
        $periods = LedgerPeriod::query()->orderByDesc('starts_on')->get();
        $labels = array_map(fn ($c): array => ['key' => $c->key(), 'label' => $c->label()], $checks->all());
        $years = [];
        foreach ($periods->groupBy(fn (LedgerPeriod $p): int => $p->starts_on->year) as $year => $months) {
            $years[] = ['year' => (int) $year, 'closed' => $months->contains(fn (LedgerPeriod $p): bool => $p->starts_on->month === 12 && $p->isClosed())];
        }

        return Inertia::render('finance/periods/index', [
            'periods' => $periods->map(function (LedgerPeriod $p) use ($checks): array {
                $finished = $p->ends_on->isPast() && ! $p->ends_on->isToday();
                $findings = $p->isClosed() ? [] : $checks->open($p);

                return [
                    'id' => $p->id,
                    'month' => $p->starts_on->format('F Y'),
                    'starts_on' => $p->starts_on->toDateString(),
                    'ends_on' => $p->ends_on->toDateString(),
                    'status' => $p->status->value,
                    'closed_at' => $p->getAttribute('closed_at')?->toIso8601String(),
                    'checked_at' => $p->checked_at?->toIso8601String(),
                    'finished' => $finished,
                    'can_close' => ! $p->isClosed() && $finished,
                    'findings' => $findings,
                    'close_notes' => $p->close_notes,
                    'year_end' => $p->starts_on->month === 12,
                ];
            })->values()->all(),
            'checks' => $labels,
            'years' => $years,
            'can' => ['close' => Gate::allows('finance.periods.close'), 'export' => Gate::allows('finance.ledger.export')],
        ]);
    }

    public function check(LedgerPeriod $period, CloseChecks $checks): RedirectResponse
    {
        Gate::authorize('finance.periods.close');
        abort_if($period->isClosed(), 422, 'This month is closed.');
        $result = $checks->refresh($period);
        $n = count($result['findings']);
        Inertia::flash('toast', ['type' => $n === 0 ? 'success' : 'info', 'message' => $n === 0 ? 'Everything checks out. You can close the month.'
            : ($n === 1 ? 'One thing to look at.' : "{$n} things to look at.")]);

        return back();
    }

    public function close(Request $request, LedgerPeriod $period, ClosePeriod $close): RedirectResponse
    {
        Gate::authorize('finance.periods.close');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;
        $id = $request->user()?->getAuthIdentifier();

        $close->handle($period, is_numeric($id) ? (int) $id : null, is_string($note) ? $note : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => $period->starts_on->month === 12 ? 'December closed. The year is now closed too.' : 'Month closed.']);

        return back();
    }

    /** The statements exactly as they were when the month (or year) closed. */
    public function statements(Request $request, LedgerPeriod $period, Snapshots $snapshots, TenantContext $tenants): HttpResponse
    {
        Gate::authorize('finance.ledger.export');
        $kind = $request->query('kind') === 'year' ? 'year' : 'month';
        $snap = $snapshots->get($period, $kind) ?? abort(404);
        app(AuditLogger::class)->record('finance.statements_exported', metadata: ['month' => $period->starts_on->format('Y-m'), 'kind' => $kind, 'as_closed' => true], severity: 'notice');

        return Pdf::loadView('ledger::statements', ['pnl' => $snap['payload']['pnl'], 'balance' => $snap['payload']['balance'], 'brokerage' => $tenants->current()->name,
            'closed' => ['at' => $snap['created_at'], 'sha256' => $snap['sha256'], 'intact' => $snapshots->intact($period, $kind),
                'trial_balance' => $snap['payload']['trial_balance'] ?? []]])
            ->setPaper('letter')->stream(($kind === 'year' ? 'year-end-' : 'month-end-').$period->starts_on->format('Y-m').'.pdf');
    }
}
