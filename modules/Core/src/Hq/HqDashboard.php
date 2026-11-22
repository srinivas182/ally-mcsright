<?php

declare(strict_types=1);

namespace Modules\Core\Hq;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Events\CrossTenantAccessed;
use Modules\Core\Tenancy\Guard\TenantGuard;
use Throwable;

/**
 * HQ2: how the platform is doing, on one page: money (monthly recurring revenue, paying brokerages and agents), growth
 * (live brokerages, sign-ups, Health Checks), use (people, AI) and what needs attention (failed sign-ups, payments
 * overdue, open support, serious errors). Counts and sums only: no names, emails or client details.
 *
 * Each figure reads its table only when that module is installed, so a missing module shows an empty figure instead of
 * breaking the page.
 */
final class HqDashboard
{
    public const REASON = 'HQ dashboard: platform totals (counts and sums only) across every brokerage';

    /**
     * @return array{kpis: array<string, int|null>, months: list<array{month: string, label: string, brokerages: int, signups: int, health_checks: int}>, ai_days: list<array{day: string, label: string, requests: int, cost_cents: int}>, attention: list<array{label: string, count: int, href: string}>}
     */
    public function build(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $monthStart = $now->startOfMonth();
        $since30 = $now->subDays(30);
        $control = Connections::control();
        $hqIds = $this->safe(fn (): array => DB::connection($control)->table('tenants')->where('type', 'hq')->pluck('id')->map(fn ($id): int => (int) $id)->all(), []);

        $tenants = fn (): Builder => DB::connection($control)->table('tenants')->whereIn('type', ['brokerage', 'solo']);
        $brokerageMrr = $this->sumOrNull($control, 'billing_tenant_subscriptions', fn (Builder $q): int => (int) $q->whereIn('status', ['active', 'grace'])
            ->selectRaw("sum(case when `interval` = 'year' then price_cents / 12 else price_cents end) as mrr")->value('mrr'));
        $agentMrr = $this->cross('billing_agent_subscriptions', fn (Builder $q): int => (int) $q->whereIn('status', ['active', 'grace'])
            ->selectRaw('sum(case when price_cents > subsidy_cents then price_cents - subsidy_cents else 0 end) as mrr')->value('mrr'));

        $kpis = [
            'mrr_cents' => $brokerageMrr === null && $agentMrr === null ? null : (int) $brokerageMrr + (int) $agentMrr,
            'paying_brokerages' => $this->sumOrNull($control, 'billing_tenant_subscriptions', fn (Builder $q): int => $q->whereIn('status', ['active', 'grace'])->distinct()->count('tenant_id')),
            'paying_agents' => $this->cross('billing_agent_subscriptions', fn (Builder $q): int => $q->whereIn('status', ['active', 'grace'])->distinct()->count('user_id')),
            'live_brokerages' => $this->safe(fn (): int => $tenants()->where('status', 'active')->count(), null),
            'new_brokerages_month' => $this->safe(fn (): int => $tenants()->where('created_at', '>=', $monthStart)->count(), null),
            'new_brokerages_last_month' => $this->safe(fn (): int => $tenants()->whereBetween('created_at', [$monthStart->subMonth(), $monthStart])->count(), null),
            'people' => $this->sumOrNull($control, 'identity_directory', fn (Builder $q): int => $q->whereNotIn('tenant_id', $hqIds)->count()),
            'signups_30' => $this->sumOrNull($control, 'signups', fn (Builder $q): int => $q->where('created_at', '>=', $since30)->count()),
            'health_checks_30' => $this->sumOrNull($control, 'health_checks', fn (Builder $q): int => $q->where('created_at', '>=', $since30)->count()),
            'ai_requests_30' => $this->cross('ai_requests', fn (Builder $q): int => $q->where('created_at', '>=', $since30)->count()),
            'ai_cost_cents_30' => $this->cross('ai_requests', fn (Builder $q): int => intdiv((int) $q->where('created_at', '>=', $since30)->sum('cost_microcents'), 1_000_000)),
        ];

        return ['kpis' => $kpis, 'months' => $this->months($now, $control), 'ai_days' => $this->aiDays($now), 'attention' => $this->attention($control, $now)];
    }

    /**
     * New brokerages, sign-ups and Health Checks in each of the last 12 months.
     *
     * @return list<array{month: string, label: string, brokerages: int, signups: int, health_checks: int}>
     */
    private function months(CarbonImmutable $now, ?string $control): array
    {
        $from = $now->startOfMonth()->subMonths(11);
        $count = fn (string $table, ?callable $where = null): array => $this->safe(function () use ($table, $where, $control, $from): array {
            if (! Schema::connection($control)->hasTable($table)) {
                return [];
            }
            $q = DB::connection($control)->table($table)->where('created_at', '>=', $from);
            if ($where !== null) {
                $where($q);
            }
            $out = [];
            foreach ($q->pluck('created_at') as $at) {
                $key = CarbonImmutable::parse((string) $at)->format('Y-m');
                $out[$key] = ($out[$key] ?? 0) + 1;
            }

            return $out;
        }, []);
        $brokerages = $count('tenants', fn (Builder $q) => $q->whereIn('type', ['brokerage', 'solo']));
        $signups = $count('signups');
        $checks = $count('health_checks');

        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $from->addMonths($i);
            $key = $m->format('Y-m');
            $rows[] = ['month' => $key, 'label' => $m->format('M'), 'brokerages' => $brokerages[$key] ?? 0, 'signups' => $signups[$key] ?? 0, 'health_checks' => $checks[$key] ?? 0];
        }

        return $rows;
    }

    /**
     * AI requests and model cost on each of the last 30 days.
     *
     * @return list<array{day: string, label: string, requests: int, cost_cents: int}>
     */
    private function aiDays(CarbonImmutable $now): array
    {
        $from = $now->startOfDay()->subDays(29);
        $rows = $this->cross('ai_requests', fn (Builder $q): array => $q->where('created_at', '>=', $from)->get(['created_at', 'cost_microcents'])
            ->reduce(function (array $carry, object $r): array {
                $key = CarbonImmutable::parse((string) $r->created_at)->format('Y-m-d');
                $carry[$key] = ['requests' => ($carry[$key]['requests'] ?? 0) + 1, 'cost' => ($carry[$key]['cost'] ?? 0) + (int) $r->cost_microcents];

                return $carry;
            }, [])) ?? [];

        $out = [];
        for ($i = 0; $i < 30; $i++) {
            $d = $from->addDays($i);
            $key = $d->format('Y-m-d');
            $out[] = ['day' => $key, 'label' => $d->format('j M'), 'requests' => $rows[$key]['requests'] ?? 0, 'cost_cents' => intdiv($rows[$key]['cost'] ?? 0, 1_000_000)];
        }

        return $out;
    }

    /**
     * What HQ should look at now, each with the page that deals with it. Only items with something in them are listed.
     *
     * @return list<array{label: string, count: int, href: string}>
     */
    private function attention(?string $control, CarbonImmutable $now): array
    {
        $items = [
            ['Sign-ups not finished (not paid)', $this->sumOrNull($control, 'signups', fn (Builder $q): int => $q->whereIn('status', ['draft', 'pending'])->where('updated_at', '<=', $now->subHour())->where('updated_at', '>=', $now->subDays(7))->count()), '/hq/signups'],
            ['Sign-ups that failed', $this->sumOrNull($control, 'signups', fn (Builder $q): int => $q->where('status', 'failed')->where('created_at', '>=', $now->subDays(30))->count()), '/hq/signups?status=failed'],
            ['Brokerage payments overdue', $this->sumOrNull($control, 'billing_tenant_subscriptions', fn (Builder $q): int => $q->where('status', 'grace')->count()), '/hq/billing'],
            ['Health Checks asking for a call', $this->sumOrNull($control, 'health_checks', fn (Builder $q): int => $q->where('contact_ok', true)->where('created_at', '>=', $now->subDays(14))->count()), '/hq/health-checks'],
            ['Support questions waiting', $this->sumOrNull($control, 'support_tickets', fn (Builder $q): int => $q->where('status', 'open')->count()), '/hq/support'],
            ['Serious errors not fixed', $this->sumOrNull($control, 'watchtower_incidents', fn (Builder $q): int => $q->whereIn('status', ['new', 'acknowledged'])->whereIn('severity', ['critical', 'high'])->count()), '/hq/watchtower'],
            ['MLS boards brokerages asked for', $this->sumOrNull($control, 'idx_board_requests', fn (Builder $q): int => $q->where('status', 'open')->count()), '/hq/idx'],
        ];

        $out = [];
        foreach ($items as [$label, $count, $href]) {
            if ((int) $count > 0) {
                $out[] = ['label' => $label, 'count' => (int) $count, 'href' => $href];
            }
        }

        return $out;
    }

    /**
     * @template T
     *
     * @param  callable(Builder): T  $read
     * @return T|null
     */
    private function sumOrNull(?string $connection, string $table, callable $read): mixed
    {
        return $this->safe(fn () => Schema::connection($connection)->hasTable($table) ? $read(DB::connection($connection)->table($table)) : null, null);
    }

    /**
     * A per-brokerage table read across every brokerage in this cell, recorded in the audit log as HQ access.
     *
     * @template T
     *
     * @param  callable(Builder): T  $read
     * @return T|null
     */
    private function cross(string $table, callable $read): mixed
    {
        $cell = Connections::cell();

        return $this->safe(function () use ($cell, $table, $read) {
            if (! Schema::connection($cell)->hasTable($table)) {
                return null;
            }
            CrossTenantAccessed::dispatch(self::class, $table, self::REASON);

            return TenantGuard::without(fn () => $read(DB::connection($cell)->table($table)));
        }, null);
    }

    /**
     * @template T
     * @template D
     *
     * @param  callable(): T  $read
     * @param  D  $default
     * @return T|D
     */
    private function safe(callable $read, mixed $default): mixed
    {
        try {
            return $read();
        } catch (Throwable $e) {
            report($e);

            return $default;
        }
    }
}
