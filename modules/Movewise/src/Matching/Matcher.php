<?php

declare(strict_types=1);

namespace Modules\Movewise\Matching;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Package;
use Modules\Movewise\Models\Person;

/**
 * Choosing the three agents (specs/movewise/00 §7, 04).
 *
 * 1. Eligible: a live package and a live agent, in the home's state, covering its ZIP or city, the price inside the
 *    package's range, the lead type and language matching, and the package under its monthly and daily caps.
 * 2. Ranked: tier first, then fair rotation (fewest times shown in the last week), then a random tie-break.
 * 3. Shown: the top three, keeping one place for an eligible agent not shown recently.
 *
 * Fair Housing: only the home, area, price, lead type and language the person chose are used. Nothing else about the
 * person is read. Every set shown is saved with each agent's reasons, for the audit pack.
 */
final class Matcher
{
    private const TIER = ['preferred' => 3, 'standard' => 2, 'new' => 1];

    /**
     * @param  array{state: string, zip: string|null, city: string|null, price: int|null, lead_type: string, language: string, listing?: string|null}  $context
     * @param  list<int>  $exclude  agents the person has already tried
     * @return array{ulid: string, agents: list<array{agent: int, package: int, fresh: bool, reasons: list<string>}>}
     */
    public function three(Person $person, array $context, array $exclude = []): array
    {
        $now = CarbonImmutable::now();
        $candidates = [];
        foreach ($this->eligible($context, $exclude, $now) as [$agent, $package, $reasons]) {
            if (! isset($candidates[$agent->id])) { // an agent in two packages is one candidate
                $candidates[$agent->id] = ['agent' => $agent, 'package' => $package, 'reasons' => $reasons];
            }
        }
        $shown = $this->recentlyShown(array_keys($candidates), $now->subDays((int) config('movewise.fresh_days', 7)));
        $ranked = array_values($candidates);
        shuffle($ranked); // the tie-break: sorting is stable, so equals stay in this random order
        usort($ranked, fn (array $a, array $b): int => [self::TIER[$b['agent']->tier] ?? 1, $shown[$a['agent']->id] ?? 0] <=> [self::TIER[$a['agent']->tier] ?? 1, $shown[$b['agent']->id] ?? 0]);
        $pick = array_slice($ranked, 0, 3);
        // Keep one place for someone not shown recently, when the top three are all recent.
        $fresh = array_values(array_filter($ranked, fn (array $c): bool => ($shown[$c['agent']->id] ?? 0) === 0));
        if (count($ranked) > 3 && $fresh !== [] && array_filter($pick, fn (array $c): bool => ($shown[$c['agent']->id] ?? 0) === 0) === []) {
            $pick[2] = $fresh[0];
        }
        $agents = array_map(fn (array $c): array => ['agent' => $c['agent']->id, 'package' => $c['package']->id, 'fresh' => ($shown[$c['agent']->id] ?? 0) === 0,
            'reasons' => [...$c['reasons'], 'Tier: '.$c['agent']->tier, 'Shown '.($shown[$c['agent']->id] ?? 0).' times in the last week']], $pick);
        $ulid = (string) Str::ulid();
        DB::connection(Connections::control())->table('movewise_shows')->insert(['ulid' => $ulid, 'person_id' => $person->id, 'context' => json_encode($context),
            'agents' => json_encode($agents), 'created_at' => $now]);

        return ['ulid' => $ulid, 'agents' => $agents];
    }

    /**
     * @param  array{state: string, zip: string|null, city: string|null, price: int|null, lead_type: string, language: string}  $c
     * @param  list<int>  $exclude
     * @return list<array{0: Agent, 1: Package, 2: list<string>}>
     */
    private function eligible(array $c, array $exclude, CarbonImmutable $now): array
    {
        $out = [];
        $suspended = Tenant::query()->where('status', '!=', TenantStatus::Active)->pluck('id')->all();
        foreach (Package::query()->where('state', $c['state'])->where('status', 'active')->whereNotIn('tenant_id', $suspended)->orderBy('id')->get() as $p) {
            if (! in_array($c['lead_type'], $p->lead_types, true) || ! in_array($c['language'], $p->languages, true)) {
                continue;
            }
            if ($c['price'] !== null && ($c['price'] < $p->price_min || ($p->price_max > 0 && $c['price'] > $p->price_max))) {
                continue;
            }
            $zip = $c['zip'] !== null && in_array($c['zip'], $p->areas['zips'], true);
            $city = $c['city'] !== null && in_array(mb_strtolower($c['city']), array_map('mb_strtolower', $p->areas['cities']), true);
            if (! $zip && ! $city) {
                continue;
            }
            if (! $this->underCaps($p, $now)) {
                continue;
            }
            foreach (Agent::query()->whereIn('id', $p->agentIds())->where('status', 'active')->where('state', $c['state'])->whereNotIn('id', $exclude)->get() as $a) {
                $out[] = [$a, $p, array_values(array_filter(['Licensed and live in '.$c['state'], $zip ? 'Covers ZIP '.$c['zip'] : 'Covers '.$c['city'],
                    $c['price'] !== null ? 'Works this price range' : null, ucfirst($c['lead_type']).'s', $c['language'] === 'es' ? 'Speaks Spanish' : null]))];
            }
        }

        return $out;
    }

    private function underCaps(Package $p, CarbonImmutable $now): bool
    {
        $q = fn () => DB::connection(Connections::control())->table('movewise_connections')->where('package_id', $p->id)->whereIn('status', ['offered', 'accepted']);
        if ($q()->where('offered_at', '>=', $now->startOfMonth())->count() >= $p->monthly_cap) {
            return false;
        }

        return $p->daily_cap === null || $q()->where('offered_at', '>=', $now->startOfDay())->count() < $p->daily_cap;
    }

    /**
     * @param  list<int>  $agentIds
     * @return array<int, int> agent => times shown since
     */
    private function recentlyShown(array $agentIds, CarbonImmutable $since): array
    {
        $counts = [];
        foreach (DB::connection(Connections::control())->table('movewise_shows')->where('created_at', '>=', $since)->pluck('agents') as $json) {
            foreach ((array) json_decode((string) $json, true) as $a) {
                $id = (int) ($a['agent'] ?? 0);
                if (in_array($id, $agentIds, true)) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }
}
