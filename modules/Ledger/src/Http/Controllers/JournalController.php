<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ledger\Actions\PostManualAdjustment;
use Modules\Ledger\Exceptions\AlreadyReversed;
use Modules\Ledger\Exceptions\ClosedPeriod;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Models\LedgerJournal;
use Modules\Ledger\Models\LedgerLine;

final class JournalController
{
    public function index(Request $request): Response
    {
        Gate::authorize('finance.ledger.view');

        $filters = $request->validate([
            'type' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'account' => ['nullable', 'string', 'size:26'],
        ]);

        $journals = LedgerJournal::query()
            ->withSum('lines as total_cents', 'debit_cents')
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('journal_type', $type))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('effective_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('effective_date', '<=', $to))
            ->when($filters['account'] ?? null, fn ($q, $ulid) => $q->whereHas('lines.account', fn ($a) => $a->where('ulid', $ulid)))
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (LedgerJournal $j): array => [
                'ulid' => $j->ulid,
                'type' => $j->journal_type,
                'date' => $j->effective_date->toDateString(),
                'description' => $j->description,
                'total_cents' => (int) $j->getAttribute('total_cents'),
                'is_reversed' => $j->isReversed(),
                'is_reversal' => $j->isReversal(),
                'is_manual' => $j->journal_type === 'manual.adjustment',
            ]);

        return Inertia::render('finance/journals/index', [
            'journals' => $journals,
            'filters' => array_merge(['type' => '', 'from' => '', 'to' => '', 'account' => ''], array_filter($filters)),
        ]);
    }

    public function show(LedgerJournal $journal): Response
    {
        Gate::authorize('finance.ledger.view');

        return Inertia::render('finance/journals/show', [
            'journal' => [
                'ulid' => $journal->ulid,
                'type' => $journal->journal_type,
                'date' => $journal->effective_date->toDateString(),
                'posted_at' => $journal->posted_at->toIso8601String(),
                'description' => $journal->description,
                'source' => $journal->source_type !== null ? $journal->source_type.' '.$journal->source_id : null,
                'reversal_of' => $journal->reversalOf?->ulid,
                'reversed_by' => $journal->reversedBy?->ulid,
                'is_manual' => $journal->journal_type === 'manual.adjustment',
            ],
            'lines' => $journal->lines()->with('account')->get()->map(fn (LedgerLine $l): array => [
                'account' => $l->account?->code.' '.$l->account?->name,
                'account_ulid' => $l->account?->ulid,
                'debit' => $l->debit(),
                'credit' => $l->credit(),
                'memo' => $l->memo,
            ]),
            'can' => ['reverse' => Gate::allows('finance.ledger.adjust') && ! $journal->isReversed() && ! $journal->isReversal()],
        ]);
    }

    public function reverse(Request $request, LedgerJournal $journal, LedgerService $ledger): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']])['reason'];

        try {
            $reversal = $ledger->reverse($journal, $reason, self::userId($request));
        } catch (AlreadyReversed|ClosedPeriod $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Journal reversed.']);

        return to_route('finance.journals.show', $reversal);
    }

    public function createAdjustment(): Response
    {
        Gate::authorize('finance.ledger.adjust');

        return Inertia::render('finance/adjustments/create', [
            'accounts' => LedgerAccount::query()->where('status', 'active')->orderBy('code')->get()
                ->map(fn (LedgerAccount $a): array => ['ulid' => $a->ulid, 'label' => $a->code.' '.$a->name]),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function storeAdjustment(Request $request, PostManualAdjustment $post): RedirectResponse
    {
        Gate::authorize('finance.ledger.adjust');

        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account' => ['required', 'string', 'size:26'],
            'lines.*.side' => ['required', 'in:debit,credit'],
            'lines.*.amount' => ['required', 'string', 'max:20'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var list<array{account: string, side: string, amount: string, memo?: string|null}> $lines */
        $lines = array_values($data['lines']);

        $journal = $post->handle(
            $data['description'],
            CarbonImmutable::parse($data['effective_date']),
            $lines,
            $data['idempotency_key'],
            self::userId($request),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Adjustment posted.']);

        return to_route('finance.journals.show', $journal);
    }

    private static function userId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }
}
