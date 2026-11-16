<?php

declare(strict_types=1);

namespace Modules\Insights;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Crm\Models\Lead;
use Modules\Transactions\Models\Deal;

/**
 * Weekly anomaly alerts (specs/insights/01, K2): big changes owners should know about, sent through the notification
 * centre once each per four weeks.
 */
final class Anomalies
{
    /**
     * @return list<array{key: string, text: string}>
     */
    public function find(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $found = [];
        // Lead volume by source: the last 30 days against the 30 before.
        $recent = Lead::query()->where('created_at', '>=', $today->subDays(30))->get(['source'])->countBy('source');
        $before = Lead::query()->whereBetween('created_at', [$today->subDays(60), $today->subDays(30)])->get(['source'])->countBy('source');
        foreach ($before as $source => $n) {
            $now = (int) ($recent[$source] ?? 0);
            if ($n >= 10 && $now <= $n / 2) {
                $found[] = ['key' => 'leads_down:'.$source, 'text' => "Leads from {$source} fell from {$n} to {$now} in the last 30 days."];
            }
        }
        // Speed to lead: median first reply this month against last month.
        $median = function (CarbonImmutable $from, CarbonImmutable $to): ?float {
            $s = Lead::query()->whereBetween('created_at', [$from, $to])->whereNotNull('response_seconds')->pluck('response_seconds')->sort()->values();

            return $s->count() >= 10 ? (float) $s[intdiv($s->count(), 2)] / 60 : null;
        };
        $nowSpeed = $median($today->subDays(30), $today->endOfDay());
        $thenSpeed = $median($today->subDays(60), $today->subDays(30));
        if ($nowSpeed !== null && $thenSpeed !== null && $thenSpeed > 0 && $nowSpeed >= 2 * $thenSpeed && $nowSpeed >= 5) {
            $found[] = ['key' => 'speed_slower', 'text' => 'First replies to leads are slower: median '.round($nowSpeed).' minutes, from '.round($thenSpeed).' the month before.'];
        }
        // Pipeline against the same time last year.
        $pipeline = (int) Deal::query()->where('status', 'under_contract')->sum('price_cents');
        $lastYear = (int) Deal::query()->whereNotNull('contract_date')->where('contract_date', '<=', $today->subYear())
            ->where(fn ($q) => $q->whereNull('closed_on')->orWhere('closed_on', '>', $today->subYear()))->whereNotIn('status', ['fell_through', 'cancelled'])->sum('price_cents');
        if ($lastYear >= 100000000 && $pipeline < $lastYear * 0.7) {
            $found[] = ['key' => 'pipeline_down', 'text' => 'Volume under contract is '.round(100 - 100 * $pipeline / $lastYear).'% below this time last year.'];
        }

        return $found;
    }

    /** Alerts owners about new anomalies (each one at most every four weeks). */
    public function alert(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $db = DB::connection(Connections::cell());
        $owners = User::query()->where('kind', UserKind::Owner)->where('status', 'active')->get();
        $sent = 0;
        foreach ($this->find($today) as $a) {
            if ($db->table('insights_anomalies')->where('key', $a['key'])->where('found_at', '>=', $today->subWeeks(4))->exists() || $owners->isEmpty()) {
                continue;
            }
            $db->table('insights_anomalies')->insert(['tenant_id' => $owners->first()->tenant_id, 'key' => $a['key'], 'text' => $a['text'], 'found_at' => CarbonImmutable::now()]);
            foreach ($owners as $o) {
                $o->notify(new AnomalyMail($a['text']));
            }
            $sent++;
        }

        return $sent;
    }
}
