<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Access\Can;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Undo\Undo;
use Modules\Transactions\DealAccess;
use Modules\Transactions\DealIq\DealAssistant;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Playbooks\Playbooks;
use Modules\Transactions\Portal\PartyCalendar;
use Modules\Transactions\Timeline\Timeline;

/** Deal-type playbooks (specs/ai/02, DQ5a): choosing one on a deal, its emails, 1031 links, and the brokerage's editor. */
final class PlaybooksController
{
    public function choose(Request $request, Deal $deal, Playbooks $playbooks, Timeline $timeline): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $added = $playbooks->choose($deal, (string) $request->validate(['playbook' => ['required', 'string']])['playbook'], $user);
        $timeline->refresh($deal->refresh());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Playbook set.'.($added !== [] ? ' Added to the checklist: '.implode(', ', $added).'.' : '')]);

        return back();
    }

    public function send(Request $request, Deal $deal, string $step, Playbooks $playbooks): RedirectResponse
    {
        $n = $playbooks->send($deal, $step, self::authorise($request, $deal));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Sent to {$n} people on the deal."]);

        return back();
    }

    public function skip(Request $request, Deal $deal, string $step, Playbooks $playbooks): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $was = (string) (DB::connection(Connections::cell())->table('tx_playbook_steps')->where('deal_id', $deal->id)->where('step', $step)->value('status') ?? 'waiting');
        $playbooks->skip($deal, $step, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Skipped: '.str_replace('_', ' ', $step).' email',
            'undo' => app(Undo::class)->offer($user, 'deal.step.skip', ['deal' => $deal->id, 'step' => $step, 'was' => $was], 'skipped the '.str_replace('_', ' ', $step).' email')]);

        return back();
    }

    /** 1031: links the sale and the replacement purchase, so both show the exchange deadlines. */
    public function link(Request $request, Deal $deal, Timeline $timeline): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $ulid = $request->validate(['deal' => ['nullable', 'string', 'size:26']])['deal'] ?? null;
        $old = $deal->getAttribute('linked_deal_id') !== null ? Deal::query()->whereKey((int) $deal->getAttribute('linked_deal_id'))->first() : null;
        $other = $ulid !== null ? Deal::query()->where('ulid', $ulid)->firstOrFail() : null;
        if ($other !== null && ($other->id === $deal->id || ! DealAccess::canSee($user, $other))) {
            throw ValidationException::withMessages(['deal' => 'Choose another deal you work on.']);
        }
        $old?->forceFill(['linked_deal_id' => null])->save();
        $deal->forceFill(['linked_deal_id' => $other?->id])->save();
        $other?->forceFill(['linked_deal_id' => $deal->id])->save();
        foreach (array_filter([$deal, $other, $old]) as $d) {
            $timeline->refresh($d->refresh());
        }
        app(AuditLogger::class)->record('transactions.deal.linked', $deal, metadata: ['with' => $other?->ulid], label: $deal->street);

        return back();
    }

    public function calendars(Request $request, Deal $deal, PartyCalendar $calendar): RedirectResponse
    {
        $n = $calendar->invite($deal, self::authorise($request, $deal));
        Inertia::flash('toast', ['type' => $n > 0 ? 'success' : 'info', 'message' => $n > 0 ? "Calendar links sent to {$n} people." : 'Invite people to the deal portal first.']);

        return back();
    }

    public function index(Request $request, Playbooks $playbooks): Response
    {
        $user = self::user($request);

        return Inertia::render('deals/playbooks', [
            'playbooks' => collect(Playbooks::BUILT_IN)->map(fn (array $p, string $key): array => ['key' => $key] + $playbooks->definition($key))->values(),
            'anchors' => Playbooks::ANCHORS,
            'roles' => DealAssistant::ROLES,
            'canEdit' => Can::manage($user, 'transactions.templates'),
        ]);
    }

    public function save(Request $request, string $key, Playbooks $playbooks): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Can::manage($user, 'transactions.templates'), 403);
        $request->validate(['items' => ['present', 'array'], 'dates' => ['present', 'array'], 'emails' => ['present', 'array']]);
        $playbooks->save($key, ['items' => (array) $request->input('items', []), 'dates' => (array) $request->input('dates', []), 'emails' => (array) $request->input('emails', [])], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Playbook saved. New and open deals follow it from now on.']);

        return back();
    }

    public function reset(Request $request, string $key, Playbooks $playbooks): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Can::manage($user, 'transactions.templates'), 403);
        $playbooks->reset($key, $user);

        return back();
    }

    private static function authorise(Request $request, Deal $deal): User
    {
        $user = self::user($request);
        abort_unless(DealAccess::canSee($user, $deal), 403);

        return $user;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
