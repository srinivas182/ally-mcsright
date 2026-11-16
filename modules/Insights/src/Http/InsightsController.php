<?php

declare(strict_types=1);

namespace Modules\Insights\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Access\Fields;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Insights\AgentSuccess;
use Modules\Insights\Goals;
use Modules\Insights\Numbers;

/** The broker dashboard and each agent's success dashboard (specs/insights/01, K1). */
final class InsightsController
{
    public function __construct(private readonly Numbers $numbers) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = self::user($request);
        $people = Numbers::people($user);
        if ($people === false) {
            return redirect('/insights/me');
        }
        [$from, $to] = self::range($request);

        return Inertia::render('insights/index', $this->numbers->dashboard($user, $people, $from, $to) + [
            'canSetGoal' => $people === null && Fields::visible($user, 'commission'),
            'preset' => (string) $request->query('range', 'ytd'),
        ]);
    }

    public function drill(Request $request): JsonResponse
    {
        $user = self::user($request);
        $people = Numbers::people($user);
        $data = $request->validate(['metric' => ['required', 'in:closed,pending'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'month' => ['nullable', 'date_format:Y-m'], 'agent' => ['nullable', 'string', 'size:26'], 'within' => ['nullable', 'integer', 'in:30,60,90']]);
        $agent = isset($data['agent']) ? User::query()->where('ulid', $data['agent'])->value('id') : null;
        if ($people === false) {
            // An agent drills only into their own deals.
            $people = [$user->id];
            $agent = $user->id;
        }
        if ($agent !== null && $people !== null && ! in_array((int) $agent, $people, true)) {
            abort(403);
        }
        [$from, $to] = isset($data['month']) ? [CarbonImmutable::parse($data['month'].'-01'), CarbonImmutable::parse($data['month'].'-01')->endOfMonth()] : self::range($request);

        return response()->json(['deals' => $this->numbers->deals($user, $people, $data['metric'], $from, $to, $agent !== null ? (int) $agent : null,
            isset($data['within']) ? (int) $data['within'] : null)]);
    }

    public function me(Request $request, AgentSuccess $success, ?string $agent = null): Response
    {
        $user = self::user($request);
        $target = $user;
        if ($agent !== null) {
            $target = User::query()->where('ulid', $agent)->firstOrFail();
            $people = Numbers::people($user);
            abort_unless($target->id === $user->id || $people === null || ($people !== false && in_array($target->id, $people, true)), 403);
        }
        abort_if($target->kind === UserKind::Assistant, 404);

        $data = $success->for($target);
        if (! Fields::visible($user, 'commission', $target->id)) {
            // Roles without commission access see activity, not pay.
            foreach (['ytd', 'last_year'] as $k) {
                $data[$k]['gci_cents'] = null;
                $data[$k]['company_cents'] = null;
            }
            $data['goal']['gci_cents'] = null;
            $data['cap'] = null;
            $data['pipeline'] = array_map(fn (array $d): array => ['gci_cents' => null] + $d, $data['pipeline']);
        }

        return Inertia::render('insights/me', $data + ['isSelf' => $target->id === $user->id]);
    }

    public function goal(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['for' => ['required', 'in:brokerage,me'], 'year' => ['required', 'integer', 'between:2020,2100'],
            'gci' => ['nullable', 'numeric', 'min:0', 'max:1000000000'], 'sides' => ['nullable', 'integer', 'min:0', 'max:100000'], 'volume' => ['nullable', 'numeric', 'min:0', 'max:100000000000']]);
        if ($data['for'] === 'brokerage') {
            abort_unless(Numbers::people($user) === null && Fields::visible($user, 'commission'), 403);
        }
        $cents = fn (string $k): ?int => isset($data[$k]) ? (int) round((float) $data[$k] * 100) : null;
        Goals::save($data['for'] === 'me' ? $user : null, (int) $data['year'], $cents('gci'), isset($data['sides']) ? (int) $data['sides'] : null, $cents('volume'), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Goal saved.']);

        return back();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function range(Request $request): array
    {
        $today = CarbonImmutable::today();

        return match ((string) $request->query('range', 'ytd')) {
            'month' => [$today->startOfMonth(), $today],
            'quarter' => [$today->firstOfQuarter(), $today],
            'last12' => [$today->subYear()->addDay(), $today],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()],
            'custom' => [CarbonImmutable::parse((string) $request->query('from', $today->startOfYear()->toDateString())), CarbonImmutable::parse((string) $request->query('to', $today->toDateString()))],
            default => [$today->startOfYear(), $today],
        };
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
