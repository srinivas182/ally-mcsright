<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Slow pages (OB1). A small sample of each route's response times is counted per hour, plus every request over 2
 * seconds. Once the hour is over, a route where more than 5% of requests took over 300 ms, or where several took over
 * 2 seconds, becomes a "slow page" problem. HQ can switch this off.
 */
final class SlowPages
{
    public const BUDGET_MS = 300;

    public const SLOW_MS = 2000;

    public const MIN_SAMPLES = 20;

    public const MIN_SLOW = 5;

    public static function record(string $route, int $ms): void
    {
        $sampled = mt_rand() / mt_getrandmax() < (float) config('core.watchtower.timing_sample', 0.02);
        $slow = $ms > self::SLOW_MS;
        if ((! $sampled && ! $slow) || ! Settings::on('slow_pages')) {
            return;
        }
        $db = Settings::db();
        $route = mb_substr($route, 0, 200);
        $hour = now()->startOfHour();
        $db->table('watchtower_timings')->insertOrIgnore(['route' => $route, 'hour' => $hour]);
        $add = ['slow' => DB::raw('slow + '.($slow ? 1 : 0))];
        if ($sampled) {
            $add['samples'] = DB::raw('samples + 1');
            foreach ([300 => 'le300', 1000 => 'le1000', 2000 => 'le2000'] as $limit => $column) {
                if ($ms <= $limit) {
                    $add[$column] = DB::raw("{$column} + 1"); // $column is from the fixed list above
                }
            }
        }
        $db->table('watchtower_timings')->where('route', $route)->where('hour', $hour)->update($add);
        $db->table('watchtower_timings')->where('route', $route)->where('hour', $hour)->where('max_ms', '<', $ms)->update(['max_ms' => $ms]);
    }

    /** Hours that have finished: turn slow routes into problems. Returns how many routes were slow. */
    public function evaluate(Recorder $recorder): int
    {
        $db = Settings::db();
        $rows = $db->table('watchtower_timings')->where('evaluated', false)->where('hour', '<', now()->startOfHour())->orderBy('hour')->limit(500)->get();
        $slow = 0;
        foreach ($rows as $r) {
            $over = (int) $r->samples - (int) $r->le300;
            $p95Over = (int) $r->samples >= self::MIN_SAMPLES && $over / max(1, (int) $r->samples) > 0.05;
            if (Settings::on('slow_pages') && ($p95Over || (int) $r->slow >= self::MIN_SLOW)) {
                $slow++;
                $when = CarbonImmutable::parse((string) $r->hour, (string) config('app.timezone'))->setTimezone('America/Chicago')->format('M j, g A');
                $share = (int) $r->samples > 0 ? (int) round(100 * $over / (int) $r->samples) : 0;
                $detail = $p95Over ? "{$share}% of requests took over ".self::BUDGET_MS.' ms (the target is 95% within it)' : '';
                $detail .= (int) $r->slow > 0 ? ($detail !== '' ? '; ' : '').$r->slow.' took over 2 seconds' : '';
                $recorder->record(new Signal('slow', (string) $r->route, mb_substr("Slow page: {$r->route}", 0, 250),
                    message: "{$detail} in the hour from {$when} Central (slowest ".number_format((int) $r->max_ms).' ms).', location: (string) $r->route,
                    context: ['samples' => (int) $r->samples, 'within_300ms' => (int) $r->le300, 'within_1s' => (int) $r->le1000, 'within_2s' => (int) $r->le2000, 'over_2s' => (int) $r->slow]));
            }
            $db->table('watchtower_timings')->where('route', $r->route)->where('hour', $r->hour)->update(['evaluated' => true]);
        }
        $db->table('watchtower_timings')->where('hour', '<', now()->subDays(14))->delete();

        return $slow;
    }

    /**
     * For HQ: the slowest routes over the last 24 hours.
     *
     * @return list<array{route: string, samples: int, over_budget_pct: int, over_2s: int, max_ms: int}>
     */
    public static function worst(int $limit = 10): array
    {
        return array_values(Settings::db()->table('watchtower_timings')->where('hour', '>=', now()->subDay())
            ->selectRaw('route, sum(samples) as samples, sum(le300) as le300, sum(slow) as slow, max(max_ms) as max_ms')->groupBy('route')->get()
            ->map(fn (object $r): array => ['route' => (string) $r->route, 'samples' => (int) $r->samples,
                'over_budget_pct' => (int) $r->samples > 0 ? (int) round(100 * ((int) $r->samples - (int) $r->le300) / (int) $r->samples) : 0,
                'over_2s' => (int) $r->slow, 'max_ms' => (int) $r->max_ms])
            ->filter(fn (array $r): bool => $r['over_budget_pct'] > 5 || $r['over_2s'] > 0)
            ->sortByDesc(fn (array $r): int => $r['over_2s'] * 1000 + $r['over_budget_pct'])->take($limit)->values()->all());
    }
}
