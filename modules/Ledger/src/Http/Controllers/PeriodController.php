<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ledger\Actions\ClosePeriod;
use Modules\Ledger\Models\LedgerPeriod;

final class PeriodController
{
    public function index(): Response
    {
        Gate::authorize('finance.ledger.view');

        return Inertia::render('finance/periods/index', [
            'periods' => LedgerPeriod::query()->orderByDesc('starts_on')->get()->map(fn (LedgerPeriod $p): array => [
                'id' => $p->id,
                'starts_on' => $p->starts_on->toDateString(),
                'ends_on' => $p->ends_on->toDateString(),
                'status' => $p->status->value,
                'closed_at' => $p->getAttribute('closed_at')?->toIso8601String(),
                'can_close' => ! $p->isClosed() && $p->ends_on->isPast() && ! $p->ends_on->isToday(),
            ]),
            'can' => ['close' => Gate::allows('finance.periods.close')],
        ]);
    }

    public function close(Request $request, LedgerPeriod $period, ClosePeriod $close): RedirectResponse
    {
        Gate::authorize('finance.periods.close');
        $id = $request->user()?->getAuthIdentifier();

        $close->handle($period, is_numeric($id) ? (int) $id : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Period closed.']);

        return back();
    }
}
