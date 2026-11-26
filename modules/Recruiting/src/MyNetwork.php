<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\RevenueShare\Insights;
use Modules\Core\Branding\WebsiteAddress;
use Modules\Core\Support\Connections;
use Modules\Transactions\Models\Deal;

/**
 * AG1: an agent's own numbers for growing their network and their income: their join link and what it brought,
 * their direct referrals and whole tree, deals closed as an agent this year and the commission earned on them, and
 * revenue share earned. Only the agent's own money is shown; never another agent's earnings.
 */
final class MyNetwork
{
    public function __construct(private readonly SelfJoin $join, private readonly Insights $insights) {}

    /** The link to share: on the brokerage's website when it is live, else the back office's join page. */
    public function shareUrl(User $agent, string $fallbackBase): string
    {
        $site = WebsiteAddress::current();
        $base = $site !== null && $site['live'] ? 'https://'.$site['host'] : rtrim($fallbackBase, '/');

        return $base.'/join/'.$this->join->codeFor($agent);
    }

    /** Counts a visit to an agent's join link (once per browser session). */
    public static function opened(User $sponsor): void
    {
        $db = DB::connection(Connections::cell());
        $day = now()->toDateString();
        $row = ['tenant_id' => $sponsor->tenant_id, 'sponsor_user_id' => $sponsor->id, 'day' => $day];
        if ($db->table('recruiting_link_opens')->where($row)->exists()) {
            $db->table('recruiting_link_opens')->where($row)->increment('opens');
        } else {
            $db->table('recruiting_link_opens')->insert($row + ['opens' => 1]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function for(User $agent): array
    {
        $db = DB::connection(Connections::cell());
        $year = CarbonImmutable::now()->year;
        $apps = $db->table('recruiting_join_applications')->where('sponsor_user_id', $agent->id);
        $tree = $this->insights->network($agent);
        $count = static function (array $nodes) use (&$count): int {
            return array_sum(array_map(fn (array $n): int => 1 + $count($n['children']), $nodes));
        };

        return [
            'joins' => [
                'opens' => (int) $db->table('recruiting_link_opens')->where('sponsor_user_id', $agent->id)->sum('opens'),
                'opens_30' => (int) $db->table('recruiting_link_opens')->where('sponsor_user_id', $agent->id)->where('day', '>=', now()->subDays(29)->toDateString())->sum('opens'),
                'started' => (clone $apps)->count(),
                'joined' => (clone $apps)->where('status', 'joined')->count(),
            ],
            'direct' => count($tree),
            'network' => $count($tree),
            'year' => $year,
            'closed' => $this->closed($agent, $year),
            'revenue_share_cents' => (int) $db->table('rs_entries')->where('recipient_user_id', $agent->id)->whereIn('kind', ['share', 'root', 'bonus'])
                ->whereYear('closing_date', $year)->sum('amount_cents'),
            'tree' => $tree,
        ];
    }

    /**
     * Deals the agent closed this year (as the agent or a co-agent) and their own commission on them.
     *
     * @return array{deals: int, commission_cents: int}
     */
    private function closed(User $agent, int $year): array
    {
        $deals = Deal::query()->where('status', 'closed')->whereYear('closed_on', $year)
            ->where(fn ($q) => $q->where('agent_user_id', $agent->id)
                ->orWhereIn('id', DB::connection(Connections::cell())->table('tx_deal_agents')->where('user_id', $agent->id)->select('deal_id')))
            ->get(['id', 'calculation_id']);
        $cents = 0;
        foreach (Calculation::query()->whereIn('id', $deals->pluck('calculation_id')->filter()->all())->get() as $calc) {
            foreach ((array) ($calc->result['agents'] ?? []) as $row) {
                if ((int) ($row['user_id'] ?? 0) === $agent->id) {
                    $cents += (int) ($row['agent_net_cents'] ?? 0);
                }
            }
        }

        return ['deals' => $deals->count(), 'commission_cents' => $cents];
    }
}
