<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Actions\PostManualAdjustment;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Models\LedgerJournal;

/** FH1 (specs/finance/22): adjustments waiting for a second person, and the ones already decided. */
final class AdjustmentController
{
    public function index(Request $request, PostManualAdjustment $adjustments): Response
    {
        Gate::authorize('finance.ledger.view');
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $rows = DB::connection(Connections::cell())->table('ledger_adjustment_requests')->where('tenant_id', app(TenantContext::class)->id())->orderByRaw("case when status = 'waiting' then 0 else 1 end")
            ->orderByDesc('id')->limit(100)->get();
        $accountUlids = $rows->flatMap(fn ($r): array => array_column((array) json_decode((string) $r->lines, true), 'account'))->unique()->values()->all();
        $accounts = LedgerAccount::query()->whereIn('ulid', $accountUlids)->get()->mapWithKeys(fn (LedgerAccount $a): array => [$a->ulid => $a->code.' '.$a->name]);
        $people = User::query()->whereIn('id', $rows->pluck('requested_by')->merge($rows->pluck('decided_by'))->filter()->unique()->all())->get()->mapWithKeys(fn (User $u): array => [$u->id => $u->name]);
        $journals = LedgerJournal::query()->whereIn('id', $rows->pluck('journal_id')->filter()->all())->pluck('ulid', 'id');
        $canApprove = PostManualAdjustment::canApprove($user);
        $soleApprover = $canApprove && $adjustments->approvers($user) === [];

        return Inertia::render('finance/adjustments/index', [
            'adjustments' => $rows->map(fn ($r): array => [
                'ulid' => (string) $r->ulid,
                'description' => (string) $r->description,
                'effective_date' => (string) $r->effective_date,
                'total_cents' => (int) $r->total_cents,
                'status' => (string) $r->status,
                'requested_by' => (string) ($people[$r->requested_by] ?? 'Someone'),
                'mine' => (int) $r->requested_by === $user->id,
                'decided_by' => $r->decided_by !== null ? (string) ($people[$r->decided_by] ?? 'Someone') : null,
                'decided_at' => $r->decided_at !== null ? (string) $r->decided_at : null,
                'decision_note' => $r->decision_note !== null ? (string) $r->decision_note : null,
                'self_approved' => (bool) $r->self_approved,
                'journal' => $r->journal_id !== null ? ($journals[$r->journal_id] ?? null) : null,
                'lines' => array_map(fn (array $l): array => ['account' => (string) ($accounts[$l['account']] ?? 'Account'), 'side' => (string) $l['side'],
                    'amount' => (string) $l['amount'], 'memo' => $l['memo'] ?? null], (array) json_decode((string) $r->lines, true)),
            ])->values()->all(),
            'can' => ['adjust' => Gate::allows('finance.ledger.adjust'), 'approve' => $canApprove, 'sole_approver' => $soleApprover],
        ]);
    }

    public function decide(Request $request, string $adjustment, PostManualAdjustment $adjustments): RedirectResponse
    {
        Gate::authorize('finance.ledger.view');
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $data = $request->validate(['decision' => ['required', 'in:approve,decline,withdraw'], 'note' => ['nullable', 'string', 'max:255']]);
        $journal = $adjustments->decide($adjustment, $user, $data['decision'] === 'approve', $data['note'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => match ($data['decision']) {
            'approve' => 'Approved. The adjustment is now in the books.', 'decline' => 'Declined. The books did not change.', default => 'Withdrawn.',
        }]);

        return $journal !== null ? to_route('finance.journals.show', $journal) : back();
    }
}
