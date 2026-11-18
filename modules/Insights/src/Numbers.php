<?php

declare(strict_types=1);

namespace Modules\Insights;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Compensation\Models\Calculation;
use Modules\Core\Access\Fields;
use Modules\Core\Access\Permissions;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Crm\Leads\LeadReports;
use Modules\Transactions\Models\Deal;

/**
 * The broker dashboard's numbers (specs/insights/01, K1): closed sales, volume, GCI and company dollar against last year
 * and the goal, twelve months of trend, the pending pipeline with its cash forecast, lead sources back to commission,
 * and agents joining and leaving. Everything is limited to the people the viewer's "See brokerage numbers" scope
 * covers (their team, office or everyone), and money is hidden from roles without commission access.
 */
final class Numbers
{
    /** @var array<int, int> calculation id => company dollar cents */
    private array $company = [];

    /** The deal columns the dashboard adds up (Sprint 60: not whole deals). */
    private const COLUMNS = ['id', 'tenant_id', 'agent_user_id', 'status', 'price_cents', 'gci_cents', 'calculation_id', 'closed_on', 'closing_date'];

    /**
     * Whose numbers this person may see: null for everyone, a list of agent ids, or false for none.
     *
     * @return list<int>|null|false
     */
    public static function people(User $viewer): array|null|false
    {
        if ($viewer->kind === UserKind::Owner) {
            return null;
        }
        $permissions = app(Permissions::class);
        if ($viewer->kind === UserKind::Staff && ! $permissions->governed($viewer)) {
            return null;
        }
        $scope = $permissions->scopeFor($viewer, 'insights.view');

        return $scope === null ? false : $permissions->peopleInScope($viewer, $scope);
    }

    /**
     * @param  list<int>|null  $people
     * @return array<string, mixed>
     */
    public function dashboard(User $viewer, ?array $people, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $money = Fields::visible($viewer, 'commission');
        // Sprint 60 (load test): every closed deal the page needs, once, with only the columns it adds up (it was
        // fifteen full loads), and each deal's company share from one look-up of the calculations.
        $first = min($from->subYear(), $to->startOfMonth()->subMonths(11), $to->startOfYear());
        $closedDeals = $this->closed($people, $first, $to)->get(self::COLUMNS);
        $pendingDeals = Deal::query()->where('status', 'under_contract')->when($people !== null, fn (Builder $q) => $q->whereIn('agent_user_id', $people))->get(self::COLUMNS);
        $this->preloadCompany($closedDeals->concat($pendingDeals));
        // Plain rows, made once: reading a date off a deal makes a new date object every time (thousands per page).
        $closed = $this->rows($closedDeals);
        $pending = $this->rows($pendingDeals);
        $between = fn (CarbonImmutable $a, CarbonImmutable $b): array => self::within($closed, 'on', $a->toDateString(), $b->toDateString());
        $now = self::sum($between($from, $to));
        $before = self::sum($between($from->subYear(), $to->subYear()));
        $goal = $people === null ? Goals::for(null, (int) $to->format('Y')) : null;
        $ytd = $people === null ? self::sum($between($to->startOfYear(), $to)) : null;

        $months = [];
        for ($m = $to->startOfMonth()->subMonths(11); $m->lte($to); $m = $m->addMonth()) {
            $t = self::sum($between($m, $m->endOfMonth()));
            $months[] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'sides' => $t['sides'], 'volume_cents' => $t['volume_cents'],
                'gci_cents' => $money ? $t['gci_cents'] : null, 'company_cents' => $money ? $t['company_cents'] : null];
        }

        $buckets = [];
        foreach ([30, 60, 90] as $days) {
            $t = self::sum(self::within($pending, 'due', $to->toDateString(), $to->addDays($days)->toDateString()));
            $buckets[] = ['days' => $days, 'deals' => $t['sides'], 'volume_cents' => $t['volume_cents'],
                'gci_cents' => $money ? $t['gci_cents'] : null, 'company_cents' => $money ? $t['company_cents'] : null];
        }

        $byAgent = [];
        foreach ($between($from, $to) as $r) {
            $byAgent[$r['agent']][] = $r;
        }
        $names = User::query()->whereIn('id', array_keys($byAgent))->get()->keyBy('id'); // one look-up, not two per agent
        $agents = collect($byAgent)->map(function (array $rows, int $id) use ($names, $money): array {
            $t = self::sum($rows);

            return ['ulid' => (string) ($names->get($id)->ulid ?? ''), 'name' => (string) ($names->get($id)->name ?? 'Agent'),
                'sides' => $t['sides'], 'volume_cents' => $t['volume_cents'], 'gci_cents' => $money ? $t['gci_cents'] : null];
        })->sortByDesc(fn (array $a): int => $a['gci_cents'] ?? $a['volume_cents'])->take(10)->values()->all();
        $pendingTotals = self::sum($pending);

        $agentQuery = fn () => User::query()->where('kind', UserKind::Agent)->when($people !== null, fn ($q) => $q->whereIn('id', $people));

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'money' => $money,
            'scope' => $people === null ? 'everyone' : 'your '.(app(Permissions::class)->scopeFor($viewer, 'insights.view') ?? 'people'),
            'now' => self::mask($now, $money),
            'last_year' => self::mask($before, $money),
            'goal' => $goal !== null && $ytd !== null ? ['year' => (int) $to->format('Y'), 'gci_cents' => $money ? $goal['gci_cents'] : null, 'sides' => $goal['sides'],
                'volume_cents' => $goal['volume_cents'], 'ytd' => self::mask($ytd, $money), 'year_share' => round($to->dayOfYear / ($to->isLeapYear() ? 366 : 365), 3)] : null,
            'months' => $months,
            'pending' => ['deals' => $pendingTotals['sides'], 'volume_cents' => $pendingTotals['volume_cents'], 'buckets' => $buckets],
            'agents' => $agents,
            'people' => ['active' => $agentQuery()->where('status', UserStatus::Active)->count(),
                'joined' => $agentQuery()->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])->count(),
                'left' => $agentQuery()->where('status', UserStatus::Deactivated)->whereBetween('updated_at', [$from->startOfDay(), $to->endOfDay()])->count()],
            'sources' => $people === null && $money ? array_slice(app(LeadReports::class)->returns($from, $to)['sources'], 0, 6) : null,
        ];
    }

    /**
     * The deals behind a number (drill-down).
     *
     * @param  list<int>|null  $people
     * @return list<array{ulid: string, street: string, city: string|null, agent: string, status: string, date: string|null, price_cents: int, gci_cents: int|null}>
     */
    public function deals(User $viewer, ?array $people, string $metric, CarbonImmutable $from, CarbonImmutable $to, ?int $agentId = null, ?int $withinDays = null): array
    {
        $q = $metric === 'pending'
            ? Deal::query()->where('status', 'under_contract')->when($people !== null, fn (Builder $q) => $q->whereIn('agent_user_id', $people))
                ->when($withinDays !== null, fn (Builder $q) => $q->whereBetween('closing_date', [$to->toDateString(), $to->addDays((int) $withinDays)->toDateString()]))
            : $this->closed($people, $from, $to);
        $q->when($agentId !== null, fn (Builder $q) => $q->where('agent_user_id', $agentId));

        return array_values($q->orderByDesc('closed_on')->orderBy('closing_date')->limit(500)->get()->map(fn (Deal $d): array => [
            'ulid' => $d->ulid, 'street' => (string) $d->street, 'city' => $d->city, 'agent' => (string) (User::query()->find($d->agent_user_id)->name ?? 'Agent'), 'status' => $d->status,
            'date' => ($d->closed_on ?? $d->closing_date)?->toDateString(), 'price_cents' => $d->price_cents,
            'gci_cents' => Fields::visible($viewer, 'commission', $d->agent_user_id) ? $d->gci_cents : null,
        ])->all());
    }

    /**
     * @param  list<int>|null  $people
     * @return Builder<Deal>
     */
    public function closed(?array $people, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Deal::query()->where('status', 'closed')->whereBetween('closed_on', [$from->toDateString(), $to->toDateString()])
            ->when($people !== null, fn (Builder $q) => $q->whereIn('agent_user_id', $people));
    }

    /**
     * @param  Collection<int, Deal>  $deals
     * @return array{sides: int, volume_cents: int, gci_cents: int, company_cents: int}
     */
    public function totals(Collection $deals): array
    {
        return ['sides' => $deals->count(), 'volume_cents' => (int) $deals->sum('price_cents'), 'gci_cents' => (int) $deals->sum('gci_cents'),
            'company_cents' => (int) $deals->sum(fn (Deal $d): int => $this->companyCents($d))];
    }

    /** The brokerage's share of a deal: company dollar plus post-cap fees, from its commission calculation. */
    /**
     * @param  Collection<int, Deal>  $deals
     * @return list<array{on: string|null, due: string|null, agent: int, price: int, gci: int, company: int}>
     */
    private function rows(Collection $deals): array
    {
        return array_values($deals->map(fn (Deal $d): array => ['on' => $d->closed_on?->toDateString(), 'due' => $d->closing_date?->toDateString(),
            'agent' => (int) $d->agent_user_id, 'price' => (int) $d->price_cents, 'gci' => (int) $d->gci_cents, 'company' => $this->companyCents($d)])->all());
    }

    /**
     * Rows whose date (closed "on" or "due" to close) falls between two days, both included.
     *
     * @param  list<array{on: string|null, due: string|null, agent: int, price: int, gci: int, company: int}>  $rows
     * @return list<array{on: string|null, due: string|null, agent: int, price: int, gci: int, company: int}>
     */
    private static function within(array $rows, string $date, string $from, string $to): array
    {
        return array_values(array_filter($rows, fn (array $r): bool => $r[$date] !== null && $r[$date] >= $from && $r[$date] <= $to));
    }

    /**
     * @param  list<array{on: string|null, due: string|null, agent: int, price: int, gci: int, company: int}>  $rows
     * @return array{sides: int, volume_cents: int, gci_cents: int, company_cents: int}
     */
    private static function sum(array $rows): array
    {
        return ['sides' => count($rows), 'volume_cents' => (int) array_sum(array_column($rows, 'price')), 'gci_cents' => (int) array_sum(array_column($rows, 'gci')),
            'company_cents' => (int) array_sum(array_column($rows, 'company'))];
    }

    /**
     * Looks up the company share of every deal's calculation in one query per thousand, ahead of companyCents().
     *
     * @param  Collection<int, Deal>  $deals
     */
    private function preloadCompany(Collection $deals): void
    {
        $ids = $deals->pluck('calculation_id')->filter()->unique()->reject(fn ($id): bool => isset($this->company[(int) $id]))->values();
        foreach ($ids->chunk(1000) as $chunk) {
            foreach (Calculation::query()->whereIn('id', $chunk->all())->get(['id', 'result']) as $calc) {
                $sum = 0;
                foreach ((array) ($calc->result['agents'] ?? []) as $row) {
                    $sum += (int) ($row['company_cents'] ?? 0) + (int) ($row['post_cap_fee_cents'] ?? 0);
                }
                $this->company[(int) $calc->id] = $sum;
            }
        }
    }

    public function companyCents(Deal $deal): int
    {
        if ($deal->calculation_id === null) {
            return 0;
        }
        if (! isset($this->company[$deal->calculation_id])) {
            $calc = Calculation::query()->find($deal->calculation_id);
            $sum = 0;
            foreach ((array) ($calc->result['agents'] ?? []) as $row) {
                $sum += (int) ($row['company_cents'] ?? 0) + (int) ($row['post_cap_fee_cents'] ?? 0);
            }
            $this->company[$deal->calculation_id] = $sum;
        }

        return $this->company[$deal->calculation_id];
    }

    /**
     * @param  array{sides: int, volume_cents: int, gci_cents: int, company_cents: int}  $t
     * @return array{sides: int, volume_cents: int, gci_cents: int|null, company_cents: int|null}
     */
    private static function mask(array $t, bool $money): array
    {
        return $money ? $t : ['sides' => $t['sides'], 'volume_cents' => $t['volume_cents'], 'gci_cents' => null, 'company_cents' => null];
    }
}
