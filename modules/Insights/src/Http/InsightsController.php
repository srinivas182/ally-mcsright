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
use Modules\Insights\Planner;
use Modules\Insights\RoiReport;

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

    /** The business planner (K3). */
    public function plan(Request $request, Planner $planner): Response
    {
        return Inertia::render('insights/plan', $planner->view(self::user($request)));
    }

    public function savePlan(Request $request, Planner $planner): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['income' => ['required', 'numeric', 'min:1000', 'max:100000000'], 'take_home' => ['required', 'boolean'],
            'average_price' => ['nullable', 'numeric', 'min:10000', 'max:100000000'], 'commission_rate' => ['nullable', 'numeric', 'min:0.1', 'max:10'],
            'closing_rate' => ['nullable', 'integer', 'min:5', 'max:100'], 'agreement_rate' => ['nullable', 'integer', 'min:5', 'max:100'],
            'appointment_rate' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $inputs = ['income_cents' => (int) round((float) $data['income'] * 100), 'take_home' => (bool) $data['take_home']];
        if (isset($data['average_price'])) {
            $inputs['average_price_cents'] = (int) round((float) $data['average_price'] * 100);
        }
        if (isset($data['commission_rate'])) {
            $inputs['commission_rate'] = round((float) $data['commission_rate'] / 100, 4);
        }
        foreach (['closing_rate', 'agreement_rate', 'appointment_rate'] as $k) {
            if (isset($data[$k])) {
                $inputs[$k] = round((int) $data[$k] / 100, 3);
            }
        }
        $planner->save($user, (int) CarbonImmutable::today()->format('Y'), $inputs);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plan saved. Your goal is updated too.']);

        return back();
    }

    /** Last month with RightAlly (K3): for people who see the whole brokerage's numbers and pay. */
    public function roi(Request $request, RoiReport $roi): Response
    {
        $user = self::user($request);
        abort_unless(Numbers::people($user) === null && Fields::visible($user, 'commission'), 403);
        $month = $request->query('month');
        $at = is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? CarbonImmutable::parse($month.'-01') : CarbonImmutable::today()->subMonth();

        return Inertia::render('insights/roi', $roi->month($at));
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
