<?php

declare(strict_types=1);

namespace Modules\AI;

use Carbon\CarbonImmutable;
use Modules\AI\Models\AiRequest;
use Modules\Core\Tenancy\CrossTenant;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * What AI costs RightAlly (AQ2, specs/ai/08), across brokerages. Every figure comes from the gateway's request log;
 * prompts and answers are never stored, so nothing here can show what anyone asked. Money is in cents.
 */
final class AiCost
{
    /**
     * @return array{requests: int, cost_cents: int, reused: int, saved_cents: int, light: int, prior_cost_cents: int}
     */
    public function summary(CarbonImmutable $since, object $caller): array
    {
        $now = CarbonImmutable::now();
        $span = (int) $since->diffInDays($now);
        $q = fn () => CrossTenant::query(AiRequest::class, $caller);
        $cost = (int) $q()->where('created_at', '>=', $since)->sum('cost_microcents');
        $prior = (int) $q()->where('created_at', '>=', $since->subDays($span))->where('created_at', '<', $since)->sum('cost_microcents');
        $reused = $q()->where('created_at', '>=', $since)->where('status', 'cached')->count();

        return [
            'requests' => $q()->where('created_at', '>=', $since)->whereIn('status', ['ok', 'cached'])->count(),
            'cost_cents' => intdiv($cost, 1_000_000),
            'reused' => $reused,
            'saved_cents' => intdiv($this->savedByReuse($since, $caller), 1_000_000),
            'light' => $q()->where('created_at', '>=', $since)->where('status', 'ok')->whereIn('model_id', $this->lightModelIds())->count(),
            'prior_cost_cents' => intdiv($prior, 1_000_000),
        ];
    }

    /**
     * @return list<array{tenant: string, requests: int, cost_cents: int, per_request_cents: float}>
     */
    public function byBrokerage(CarbonImmutable $since, object $caller): array
    {
        $rows = CrossTenant::query(AiRequest::class, $caller)->where('created_at', '>=', $since)
            ->selectRaw("tenant_id, sum(case when status in ('ok','cached') then 1 else 0 end) as requests, sum(cost_microcents) as cost")
            ->groupBy('tenant_id')->orderByDesc('cost')->limit(50)->get();
        $names = Tenant::query()->whereIn('id', $rows->pluck('tenant_id')->all())->pluck('name', 'id');

        return array_values($rows->map(fn ($r): array => ['tenant' => (string) ($names[$r->getAttribute('tenant_id')] ?? 'Brokerage '.$r->getAttribute('tenant_id')),
            'requests' => (int) $r->getAttribute('requests'), 'cost_cents' => intdiv((int) $r->getAttribute('cost'), 1_000_000),
            'per_request_cents' => (int) $r->getAttribute('requests') > 0 ? round((int) $r->getAttribute('cost') / 1_000_000 / (int) $r->getAttribute('requests'), 2) : 0.0])->all());
    }

    /**
     * @return list<array{feature: string, requests: int, cost_cents: int, per_request_cents: float, reused_pct: int, failures: int}>
     */
    public function byFeature(CarbonImmutable $since, object $caller): array
    {
        $rows = CrossTenant::query(AiRequest::class, $caller)->where('created_at', '>=', $since)
            ->selectRaw("feature_key, sum(case when status = 'ok' then 1 else 0 end) as ok, sum(case when status = 'cached' then 1 else 0 end) as reused, sum(case when status in ('failed','blocked') then 1 else 0 end) as failures, sum(cost_microcents) as cost")
            ->groupBy('feature_key')->orderByDesc('cost')->get();

        return array_values($rows->map(function ($r): array {
            $ok = (int) $r->getAttribute('ok');
            $reused = (int) $r->getAttribute('reused');
            $cost = (int) $r->getAttribute('cost');

            return ['feature' => (string) $r->getAttribute('feature_key'), 'requests' => $ok + $reused, 'cost_cents' => intdiv($cost, 1_000_000),
                'per_request_cents' => $ok > 0 ? round($cost / 1_000_000 / $ok, 2) : 0.0,
                'reused_pct' => $ok + $reused > 0 ? (int) round(100 * $reused / ($ok + $reused)) : 0, 'failures' => (int) $r->getAttribute('failures')];
        })->all());
    }

    /**
     * @return list<array{day: string, cost_cents: int, requests: int}>
     */
    public function daily(CarbonImmutable $since, object $caller): array
    {
        $rows = CrossTenant::query(AiRequest::class, $caller)->where('created_at', '>=', $since)
            ->selectRaw("date(created_at) as day, sum(cost_microcents) as cost, sum(case when status in ('ok','cached') then 1 else 0 end) as requests")
            ->groupByRaw('date(created_at)')->orderBy('day')->get()->keyBy(fn ($r): string => (string) $r->getAttribute('day'));
        $out = [];
        for ($d = $since; $d->lessThanOrEqualTo(CarbonImmutable::today()); $d = $d->addDay()) {
            $r = $rows->get($d->toDateString());
            $out[] = ['day' => $d->toDateString(), 'cost_cents' => $r !== null ? intdiv((int) $r->getAttribute('cost'), 1_000_000) : 0,
                'requests' => $r !== null ? (int) $r->getAttribute('requests') : 0];
        }

        return $out;
    }

    /**
     * Brokerages whose AI cost yesterday was more than three times their usual day and over $5 (AQ2).
     *
     * @return list<array{tenant: string, yesterday_cents: int, usual_cents: int}>
     */
    public function spikes(object $caller): array
    {
        $yesterday = CarbonImmutable::yesterday();
        $rows = CrossTenant::query(AiRequest::class, $caller)->where('created_at', '>=', $yesterday->subDays(14))->where('created_at', '<', $yesterday->addDay())
            ->selectRaw('tenant_id, date(created_at) as day, sum(cost_microcents) as cost')->groupBy('tenant_id')->groupByRaw('date(created_at)')->get()->groupBy('tenant_id');
        $out = [];
        foreach ($rows as $tenantId => $days) {
            $y = (int) ($days->first(fn ($r): bool => (string) $r->getAttribute('day') === $yesterday->toDateString())?->getAttribute('cost') ?? 0);
            $before = (int) $days->filter(fn ($r): bool => (string) $r->getAttribute('day') !== $yesterday->toDateString())->sum(fn ($r): int => (int) $r->getAttribute('cost'));
            $usual = intdiv($before, 14);
            if ($y > 500_000_000 && $y > 3 * $usual) {
                $out[] = ['tenant' => (string) (Tenant::query()->whereKey($tenantId)->value('name') ?? 'Brokerage '.$tenantId), 'yesterday_cents' => intdiv($y, 1_000_000), 'usual_cents' => intdiv($usual, 1_000_000)];
            }
        }

        return $out;
    }

    /** What reused answers would have cost: each one at its feature's average model cost. */
    private function savedByReuse(CarbonImmutable $since, object $caller): int
    {
        $avg = CrossTenant::query(AiRequest::class, $caller)->where('created_at', '>=', $since)->where('status', 'ok')
            ->selectRaw('feature_key, avg(cost_microcents) as a')->groupBy('feature_key')->pluck('a', 'feature_key');
        $reused = CrossTenant::query(AiRequest::class, $caller)->where('created_at', '>=', $since)->where('status', 'cached')
            ->selectRaw('feature_key, count(*) as n')->groupBy('feature_key')->pluck('n', 'feature_key');
        $saved = 0;
        foreach ($reused as $feature => $n) {
            $saved += (int) round((float) ($avg[$feature] ?? 0) * (int) $n);
        }

        return $saved;
    }

    /** @return list<string> model ids HQ set as lighter models */
    private function lightModelIds(): array
    {
        $ids = Models\AiFeatureRoute::query()->whereNotNull('light_model_id')->pluck('light_model_id')->all();

        return array_values(Models\AiModel::query()->whereIn('id', $ids)->pluck('model_id')->map(fn ($m): string => (string) $m)->all());
    }
}
