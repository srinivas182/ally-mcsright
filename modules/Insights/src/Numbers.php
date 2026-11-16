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
        $now = $this->totals($this->closed($people, $from, $to)->get());
        $before = $this->totals($this->closed($people, $from->subYear(), $to->subYear())->get());
        $goal = $people === null ? Goals::for(null, (int) $to->format('Y')) : null;
        $ytd = $people === null ? $this->totals($this->closed(null, $to->startOfYear(), $to)->get()) : null;

        $months = [];
        for ($m = $to->startOfMonth()->subMonths(11); $m->lte($to); $m = $m->addMonth()) {
            $t = $this->totals($this->closed($people, $m, $m->endOfMonth())->get());
            $months[] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'sides' => $t['sides'], 'volume_cents' => $t['volume_cents'],
                'gci_cents' => $money ? $t['gci_cents'] : null, 'company_cents' => $money ? $t['company_cents'] : null];
        }

        $pending = Deal::query()->where('status', 'under_contract')->when($people !== null, fn (Builder $q) => $q->whereIn('agent_user_id', $people))->get();
        $buckets = [];
        foreach ([30, 60, 90] as $days) {
            $in = $pending->filter(fn (Deal $d): bool => $d->closing_date !== null && $d->closing_date->lte($to->addDays($days)) && $d->closing_date->gte($to->startOfDay()));
            $buckets[] = ['days' => $days, 'deals' => $in->count(), 'volume_cents' => (int) $in->sum('price_cents'),
                'gci_cents' => $money ? (int) $in->sum('gci_cents') : null, 'company_cents' => $money ? $in->sum(fn (Deal $d): int => $this->companyCents($d)) : null];
        }

        $agents = $this->closed($people, $from, $to)->get()->groupBy('agent_user_id')->map(fn (Collection $deals, int|string $id): array => [
            'ulid' => (string) User::query()->whereKey((int) $id)->value('ulid'), 'name' => (string) (User::query()->find((int) $id)->name ?? 'Agent'),
            'sides' => $deals->count(), 'volume_cents' => (int) $deals->sum('price_cents'), 'gci_cents' => $money ? (int) $deals->sum('gci_cents') : null,
        ])->sortByDesc(fn (array $a): int => $a['gci_cents'] ?? $a['volume_cents'])->take(10)->values()->all();

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
            'pending' => ['deals' => $pending->count(), 'volume_cents' => (int) $pending->sum('price_cents'), 'buckets' => $buckets],
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
