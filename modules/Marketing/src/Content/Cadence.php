<?php

declare(strict_types=1);

namespace Modules\Marketing\Content;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use stdClass;

/**
 * MK3/MK4: how much AI writing a desk may publish — the same rules for HQ, every brokerage and every agent, enforced
 * here (on the server), never only on screen. Owner's decision, November 2026.
 *
 * | Level | How often | How it is reached |
 * | 1 Starting | 3 a week, a person approves each | every new desk |
 * | 2 Trusted | up to 1 a day | after 4 weeks at level 1: ≥ 10 approved, ≥ 80% of pages indexed, no warnings |
 * | 3 HQ only | up to 4 a day per state (2 landing pages + 2 articles) | after 8 more healthy weeks at level 2 |
 *
 * Hard ceilings, whatever the level: a brokerage or agent site 1 a day; HQ 4 a day per state. Without Search Console
 * numbers a desk stays at level 1. A desk drops a level (and HQ is told) when indexing falls below 80% or Search
 * Console reports a warning. Only AI-written content counts; what people write themselves is not limited.
 */
final class Cadence
{
    public const CEILING_TENANT_PER_DAY = 1;

    public const CEILING_HQ_PER_DAY = 4;

    public const MIN_INDEXED_PCT = 80;

    /** @return array{week: int, day: int} how many drafts a desk may produce at its level */
    public static function allowance(stdClass $desk): array
    {
        $hq = (int) $desk->tenant_id === 0;
        $ceiling = $hq ? self::CEILING_HQ_PER_DAY : self::CEILING_TENANT_PER_DAY;

        return match ((int) $desk->level) {
            3 => $hq ? ['week' => $ceiling * 7, 'day' => $ceiling] : ['week' => 7, 'day' => 1],
            2 => ['week' => 7, 'day' => 1],
            default => ['week' => 3, 'day' => 1],
        };
    }

    /** How many more AI drafts this desk may write today (0 when paused, off or at its limit). */
    public static function remainingToday(stdClass $desk): int
    {
        if ($desk->mode === 'off' || $desk->paused_reason !== null) {
            return 0;
        }
        $limit = self::allowance($desk);
        $drafts = DB::connection(Connections::control())->table('content_drafts')->where('desk_id', $desk->id)->where('status', '!=', 'skipped');
        $today = (clone $drafts)->where('created_at', '>=', CarbonImmutable::now()->startOfDay())->count();
        $week = (clone $drafts)->where('created_at', '>=', CarbonImmutable::now()->subDays(7))->count();

        return max(0, min($limit['day'] - $today, $limit['week'] - $week));
    }

    /**
     * Daily: move each desk up or down a level from its real numbers. Returns the desks that changed.
     *
     * @return list<array{desk: int, from: int, to: int, why: string}>
     */
    public static function review(): array
    {
        $db = DB::connection(Connections::control());
        $changes = [];
        foreach ($db->table('content_desks')->where('mode', '!=', 'off')->get() as $desk) {
            $since = CarbonImmutable::parse((string) $desk->level_since);
            $stats = $db->table('content_index_stats')->where('desk_id', $desk->id)->orderByDesc('day')->first();
            $indexedPct = $stats !== null && (int) $stats->submitted > 0 ? intdiv((int) $stats->indexed * 100, (int) $stats->submitted) : null;
            $warning = $stats !== null && (int) $stats->warnings > 0;
            $healthy = $indexedPct !== null && $indexedPct >= self::MIN_INDEXED_PCT && ! $warning;
            $approved = $db->table('content_drafts')->where('desk_id', $desk->id)->where('status', 'published')->count();
            $to = (int) $desk->level;
            $why = '';
            if ((int) $desk->level > 1 && ($warning || ($indexedPct !== null && $indexedPct < self::MIN_INDEXED_PCT))) {
                $to = (int) $desk->level - 1;
                $why = $warning ? 'Search Console reported a warning' : "only {$indexedPct}% of pages indexed";
            } elseif ((int) $desk->level === 1 && $healthy && $approved >= 10 && $since->lte(CarbonImmutable::now()->subWeeks(4))) {
                [$to, $why] = [2, '4 healthy weeks and '.$approved.' approved'];
            } elseif ((int) $desk->level === 2 && (int) $desk->tenant_id === 0 && $healthy && $since->lte(CarbonImmutable::now()->subWeeks(8))) {
                [$to, $why] = [3, '8 more healthy weeks'];
            }
            if ($to !== (int) $desk->level) {
                $db->table('content_desks')->where('id', $desk->id)->update(['level' => $to, 'level_since' => now(), 'updated_at' => now()]);
                app(AuditLogger::class)->record('marketing.content.level', metadata: ['desk' => $desk->id, 'from' => (int) $desk->level, 'to' => $to, 'why' => $why],
                    severity: $to < (int) $desk->level ? 'warning' : 'notice', tenantId: (int) $desk->tenant_id > 0 ? (int) $desk->tenant_id : null);
                $changes[] = ['desk' => (int) $desk->id, 'from' => (int) $desk->level, 'to' => $to, 'why' => $why];
            }
        }

        return $changes;
    }
}
