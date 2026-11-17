<?php

declare(strict_types=1);

namespace Modules\Insights;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Health\DatabaseHealth;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ContactScore;
use Modules\Transactions\Models\Deal;

/**
 * What a database is worth (DH1, specs/grow/16), for one agent or the whole brokerage:
 *
 * - **Commission forecast** for the next 30, 60 and 90 days, as a range with its reasons: deals under contract by their
 *   closing date (most of them close), people being actively helped (some will), and likely sellers in the database.
 * - **Database value**: repeat and referral business a year from past clients and sphere. From the brokerage's own
 *   history when it has at least three such deals in two years; otherwise from a stated assumption.
 * - **Proof**: the last 12 months' closed deals and commission by where the client came from: the database, paid
 *   portals and ads, or the website and signs.
 *
 * Every rate is shown on the page next to the number it produced.
 */
final class DatabaseValue
{
    /** Share of deals under contract that close (stated assumption). */
    public const PENDING_LOW = 0.85;

    /** People being actively helped (lifecycle "active") who close within 90 days: low and high (stated assumption). */
    public const ACTIVE = [0.10, 0.30];

    /** Likely sellers (seller score 70+) who list within 90 days: low and high (stated assumption). */
    public const LIKELY = [0.02, 0.08];

    /** Deals a year for each past client or sphere contact, when there's too little history (stated assumption). */
    public const REPEAT_RATE = 0.08;

    public const DATABASE_SOURCES = ['sphere', 'referral', 'import', 'manual'];

    public const PAID_SOURCES = ['zillow', 'realtor_com', 'homes_com', 'facebook', 'google'];

    public const OWN_SOURCES = ['website', 'sign', 'open_house'];

    /**
     * @return array{buckets: list<array{days: int, low_cents: int, high_cents: int}>, reasons: list<string>, average_gci_cents: int}
     */
    public function forecast(?User $agent, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $avg = $this->averageGci($agent, $today);
        $pending = Deal::query()->where('status', 'under_contract')->when($agent !== null, fn ($q) => $q->where('agent_user_id', $agent?->id))
            ->where('closing_date', '>=', $today->toDateString())->get(['gci_cents', 'closing_date']);
        $contacts = fn () => Contact::query()->when($agent !== null, fn ($q) => $q->where('owner_user_id', $agent?->id));
        $active = $contacts()->where('lifecycle', 'active')->count();
        $likely = ContactScore::query()->where('seller', '>=', 70)->whereIn('contact_id', $contacts()->whereIn('lifecycle', ['lead', 'past_client', 'sphere'])->select('id'))->count();

        $buckets = [];
        foreach ([30, 60, 90] as $days) {
            $due = $pending->filter(fn (Deal $d): bool => $d->closing_date !== null && $d->closing_date->lte($today->addDays($days)));
            $share = $days / 90;
            $buckets[] = ['days' => $days,
                'low_cents' => (int) round($due->sum('gci_cents') * self::PENDING_LOW + $active * self::ACTIVE[0] * $share * $avg + $likely * self::LIKELY[0] * $share * $avg),
                'high_cents' => (int) round($due->sum('gci_cents') + $active * self::ACTIVE[1] * $share * $avg + $likely * self::LIKELY[1] * $share * $avg)];
        }
        $reasons = [];
        if ($pending->isNotEmpty()) {
            $reasons[] = $pending->count().' deal'.($pending->count() === 1 ? '' : 's').' under contract ('.self::usd((int) $pending->sum('gci_cents')).' commission); '.(int) (self::PENDING_LOW * 100).'% or more usually close.';
        }
        if ($active > 0) {
            $reasons[] = "{$active} people you're actively helping; ".(int) (self::ACTIVE[0] * 100).'–'.(int) (self::ACTIVE[1] * 100).'% may close in 90 days.';
        }
        if ($likely > 0) {
            $reasons[] = "{$likely} likely seller".($likely === 1 ? '' : 's').' in your database; '.(int) (self::LIKELY[0] * 100).'–'.(int) (self::LIKELY[1] * 100).'% may list in 90 days.';
        }
        $reasons[] = 'Average commission per deal: '.self::usd($avg).'.';

        return ['buckets' => $buckets, 'reasons' => $reasons, 'average_gci_cents' => $avg];
    }

    /**
     * @return array{people: int, deals_a_year: float, value_cents: int, rate: float, from_history: bool, basis: string}
     */
    public function value(?User $agent, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $people = Contact::query()->whereIn('lifecycle', ['past_client', 'sphere'])->when($agent !== null, fn ($q) => $q->where('owner_user_id', $agent?->id))->count();
        $repeat = Deal::query()->where('status', 'closed')->where('closed_on', '>=', $today->subYears(2)->toDateString())->when($agent !== null, fn ($q) => $q->where('agent_user_id', $agent?->id))
            ->whereIn('contact_id', Contact::query()->where(fn ($q) => $q->whereIn('source', ['sphere', 'referral'])->orWhereIn('lifecycle', ['past_client', 'sphere']))->select('id'))->count();
        $fromHistory = $repeat >= 3 && $people > 0;
        $rate = $fromHistory ? round(($repeat / 2) / $people, 3) : self::REPEAT_RATE;
        $deals = round($people * $rate, 1);
        $avg = $this->averageGci($agent, $today);

        return ['people' => $people, 'deals_a_year' => $deals, 'value_cents' => (int) round($deals * $avg), 'rate' => $rate, 'from_history' => $fromHistory,
            'basis' => $fromHistory ? "From your last two years: {$repeat} deals from past clients, sphere and referrals."
                : 'Assumes about 1 deal a year for every '.(int) round(1 / self::REPEAT_RATE).' past clients and sphere, until you have more history here.'];
    }

    /**
     * @return list<array{group: string, deals: int, gci_cents: int}>
     */
    public function proof(?User $agent, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $deals = Deal::query()->where('status', 'closed')->where('closed_on', '>=', $today->subYear()->toDateString())->when($agent !== null, fn ($q) => $q->where('agent_user_id', $agent?->id))
            ->get(['contact_id', 'gci_cents']);
        $sources = Contact::query()->whereIn('id', $deals->pluck('contact_id')->filter()->all() ?: [0])->get(['id', 'source', 'lifecycle'])->keyBy('id');
        $groups = ['Your database (past clients, sphere, referrals)' => [0, 0], 'Paid portals and ads' => [0, 0], 'Your website, signs and open houses' => [0, 0], 'Other or unknown' => [0, 0]];
        foreach ($deals as $d) {
            $c = $d->contact_id !== null ? $sources->get($d->contact_id) : null;
            $source = $c !== null ? $c->source->value : null;
            $group = match (true) {
                $source !== null && in_array($source, self::DATABASE_SOURCES, true) => 'Your database (past clients, sphere, referrals)',
                $source !== null && in_array($source, self::PAID_SOURCES, true) => 'Paid portals and ads',
                $source !== null && in_array($source, self::OWN_SOURCES, true) => 'Your website, signs and open houses',
                default => 'Other or unknown',
            };
            $groups[$group][0]++;
            $groups[$group][1] += (int) $d->gci_cents;
        }

        return array_map(fn (string $g, array $v): array => ['group' => $g, 'deals' => $v[0], 'gci_cents' => $v[1]], array_keys($groups), array_values($groups));
    }

    /**
     * Everything for the page and the monthly email.
     *
     * @return array{health: array{score: int, total: int, checks: list<array{key: string, label: string, count: int, fix: string, href: string}>, to_fix: int}, forecast: array{buckets: list<array{days: int, low_cents: int, high_cents: int}>, reasons: list<string>, average_gci_cents: int}, value: array{people: int, deals_a_year: float, value_cents: int, rate: float, from_history: bool, basis: string}, proof: list<array{group: string, deals: int, gci_cents: int}>}
     */
    public function report(?User $agent): array
    {
        return ['health' => app(DatabaseHealth::class)->for($agent), 'forecast' => $this->forecast($agent), 'value' => $this->value($agent), 'proof' => $this->proof($agent)];
    }

    /**
     * On the 1st: the report to each owner (whole brokerage) and each active agent (their own).
     */
    public function sendDue(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        if ($today->day !== 1) {
            return 0;
        }
        $sent = 0;
        foreach (User::query()->whereIn('kind', [UserKind::Owner, UserKind::Agent])->where('status', 'active')->get() as $user) {
            $broker = $user->isKind(UserKind::Owner);
            $report = $this->report($broker ? null : $user);
            if ($report['health']['total'] === 0) {
                continue;
            }
            $user->notify(new DatabaseReportMail($report, $broker));
            $sent++;
        }

        return $sent;
    }

    public static function usd(int $cents): string
    {
        return '$'.number_format(intdiv($cents, 100));
    }

    /** Average commission per closed deal in the last year; the planner's defaults when there are fewer than three. */
    private function averageGci(?User $agent, CarbonImmutable $today): int
    {
        $closed = Deal::query()->where('status', 'closed')->where('closed_on', '>=', $today->subYear()->toDateString())->when($agent !== null, fn ($q) => $q->where('agent_user_id', $agent?->id))
            ->where('gci_cents', '>', 0)->get(['gci_cents']);

        return $closed->count() >= 3 ? (int) round($closed->avg('gci_cents') ?? 0) : (int) round(Planner::DEFAULTS['average_price_cents'] * Planner::DEFAULTS['commission_rate']);
    }
}
