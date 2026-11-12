<?php

declare(strict_types=1);

namespace Modules\Compensation\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Compensation\Exceptions\InvalidRules;
use Modules\Compensation\RevenueShare\Attribution;
use Modules\Compensation\RevenueShare\Holds;
use Modules\Compensation\RevenueShare\Insights;
use Modules\Compensation\RevenueShare\Members;
use Modules\Compensation\RevenueShare\RevenueShareEngine;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Revenue share trust actions (specs/compensation/02, additions 4, 12, 14). Full screens come with
 * the revenue share screens sprint.
 */
final class RevenueShareController
{
    public function beneficiary(Request $request, Members $members): RedirectResponse
    {
        $data = $request->validate(['beneficiary' => ['required', 'string', 'size:26']]);
        $members->setBeneficiary(self::user($request), User::query()->where('ulid', $data['beneficiary'])->firstOrFail());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Beneficiary saved.']);

        return back();
    }

    public function claim(Request $request, Attribution $attribution): RedirectResponse
    {
        $data = $request->validate(['agent' => ['required', 'string', 'size:26'], 'evidence' => ['required', 'string', 'max:2000']]);
        $attribution->claim(User::query()->where('ulid', $data['agent'])->firstOrFail(), self::user($request), $data['evidence']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your claim was sent to the brokerage.']);

        return back();
    }

    public function decide(Request $request, string $dispute, Attribution $attribution): RedirectResponse
    {
        $data = $request->validate(['upheld' => ['required', 'boolean'], 'effective_from' => ['required', 'date'], 'note' => ['required', 'string', 'max:500']]);
        $attribution->decide($dispute, (bool) $data['upheld'], CarbonImmutable::parse($data['effective_from']), $data['note'], self::manager($request));

        return back();
    }

    public function release(Request $request, int $hold, Holds $holds): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $holds->release($hold, self::manager($request), (string) ($data['note'] ?? 'Reviewed and released'));

        return back();
    }

    public function reject(Request $request, int $hold, Holds $holds): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300']]);
        $holds->reject($hold, self::manager($request), $data['note']);

        return back();
    }

    public function deceased(Request $request, User $user, Members $members): RedirectResponse
    {
        $by = self::user($request);
        abort_unless($by->isKind(UserKind::Owner), 403);
        $data = $request->validate(['on' => ['required', 'date', 'before_or_equal:today'], 'note' => ['required', 'string', 'max:500']]);
        $members->markDeceased($user, CarbonImmutable::parse($data['on']), $by, $data['note']);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private static function manager(Request $request): User
    {
        $user = self::user($request);
        abort_unless($user->can('compensation.calculations.manage'), 403);

        return $user;
    }

    /** Agent: My revenue share. */
    public function mine(Request $request, Insights $insights, RevenueShareEngine $engine): Response
    {
        $user = self::user($request);
        $rules = $insights->rulesFor($user->id);
        $db = DB::connection(Connections::cell());
        $beneficiary = $db->table('rs_members')->where('user_id', $user->id)->value('beneficiary_user_id');

        return Inertia::render('compensation/revenue-share', [
            'wallet_cents' => $engine->wallet($user)->cents,
            'year_cents' => (int) $db->table('rs_entries')->where('recipient_user_id', $user->id)->whereIn('kind', ['share', 'root', 'bonus'])
                ->whereYear('closing_date', now()->year)->sum('amount_cents'),
            'coaching' => $insights->coaching($user, $rules),
            'network' => $insights->network($user, $rules),
            'statement' => $insights->statement($user),
            'missed' => $insights->missed($user),
            'retention' => $insights->retention($user, $rules),
            'beneficiary' => $beneficiary !== null ? User::query()->whereKey((int) $beneficiary)->value('ulid') : null,
            'people' => User::query()->where('status', 'active')->whereKeyNot($user->id)->orderBy('first_name')->get()->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name]),
        ]);
    }

    /** Brokerage: holds, disputes, budget, root account, income disclosure. */
    public function admin(Request $request, Insights $insights, RevenueShareEngine $engine): Response
    {
        self::manager($request);
        $db = DB::connection(Connections::cell());
        $name = fn ($id): ?string => $id !== null ? User::query()->find((int) $id)?->name : null;
        $year = (int) $request->query('year', (string) now()->year);
        $settings = $db->table('rs_settings')->first();

        return Inertia::render('compensation/revenue-share-admin', [
            'holds' => $db->table('rs_holds')->where('rs_holds.status', 'held')->join('rs_entries', 'rs_entries.id', '=', 'rs_holds.entry_id')
                ->orderBy('rs_holds.id')->get(['rs_holds.id', 'rs_holds.reasons', 'rs_entries.amount_cents', 'rs_entries.recipient_user_id', 'rs_entries.source_agent_user_id', 'rs_entries.closing_date', 'rs_entries.level'])
                ->map(fn ($h): array => ['id' => (int) $h->id, 'reasons' => json_decode((string) $h->reasons, true), 'amount_cents' => (int) $h->amount_cents,
                    'recipient' => $name($h->recipient_user_id), 'source' => $name($h->source_agent_user_id), 'date' => (string) $h->closing_date, 'level' => (int) $h->level]),
            'disputes' => $db->table('rs_disputes')->where('status', 'open')->orderBy('id')->get()
                ->map(fn ($d): array => ['ulid' => $d->ulid, 'agent' => $name($d->agent_user_id), 'claimant' => $name($d->claimant_user_id),
                    'current' => $name($d->current_sponsor_user_id), 'evidence' => $d->evidence, 'opened' => substr((string) $d->created_at, 0, 10)]),
            'settings' => ['budget_percent' => $settings?->budget_percent, 'root' => $settings?->root_user_id !== null ? User::query()->whereKey((int) $settings->root_user_id)->value('ulid') : null],
            'budget' => ['paid_cents' => $engine->paidYtd(CarbonImmutable::now()), 'room_cents' => $engine->budgetRoom(CarbonImmutable::now())],
            'disclosure' => $insights->disclosure($year),
            'people' => User::query()->where('status', 'active')->whereIn('kind', [UserKind::Owner, UserKind::Agent, UserKind::Staff])->orderBy('first_name')->get()
                ->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name]),
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        self::manager($request);
        $data = $request->validate(['budget_percent' => ['nullable', 'numeric', 'min:1', 'max:100'], 'root' => ['nullable', 'string', 'size:26']]);
        $root = isset($data['root']) ? User::query()->where('ulid', $data['root'])->value('id') : null;
        DB::connection(Connections::cell())->table('rs_settings')->updateOrInsert(
            ['tenant_id' => app(TenantContext::class)->id()],
            ['budget_percent' => $data['budget_percent'] ?? null, 'root_user_id' => $root, 'updated_at' => now(), 'created_at' => now()],
        );
        app(AuditLogger::class)->record('compensation.revenue_share.settings', metadata: ['budget_percent' => $data['budget_percent'] ?? null, 'root_user_id' => $root], severity: 'warning');

        return back();
    }

    public function simulate(Request $request, Insights $insights): JsonResponse
    {
        self::manager($request);
        $data = $request->validate(['rules' => ['required', 'array']]);
        try {
            return response()->json($insights->simulate($data['rules']));
        } catch (InvalidRules $e) {
            return response()->json(['message' => implode(' ', $e->errors)], 422);
        }
    }
}
