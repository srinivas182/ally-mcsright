<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Downtime, seen from outside (OB1). The uptime checker (Better Stack recommended, or UptimeRobot) emails HQ itself
 * when RightAlly is down, because RightAlly may not be able to. Its webhook tells Watchtower too: the outage is recorded
 * (for the uptime %), and when it is over Watchtower emails what else it saw while it was down, usually the reason.
 */
final readonly class Uptime
{
    public function __construct(private Recorder $recorder, private Alerts $alerts) {}

    public function down(string $monitor, ?string $cause, CarbonImmutable $at): void
    {
        $db = Settings::db();
        $monitor = mb_substr($monitor, 0, 160);
        if (! $db->table('watchtower_downtimes')->where('monitor', $monitor)->whereNull('ended_at')->exists()) {
            $db->table('watchtower_downtimes')->insert(['id' => (string) Str::ulid(), 'monitor' => $monitor, 'cause' => $cause === null ? null : Mask::text($cause, 255),
                'started_at' => $at]);
        }
        $i = $this->recorder->record(new Signal('downtime', $monitor, mb_substr("Down: {$monitor}".($cause !== null && $cause !== '' ? ' ('.Mask::text($cause, 200).')' : ''), 0, 250),
            severity: 'critical', message: $cause === null ? null : Mask::text($cause, 255), location: "monitor {$monitor}", surface: 'uptime', ongoing: true));
        // The uptime checker has already emailed: don't send the same news twice.
        $i?->forceFill(['alerted_at' => now(), 'alerted_severity' => $i->severity, 'alerted_occurrences' => $i->occurrences])->save();
    }

    public function up(string $monitor, CarbonImmutable $at): void
    {
        $db = Settings::db();
        $monitor = mb_substr($monitor, 0, 160);
        $open = $db->table('watchtower_downtimes')->where('monitor', $monitor)->whereNull('ended_at')->orderByDesc('started_at')->first();
        if ($open === null) {
            return;
        }
        $db->table('watchtower_downtimes')->where('id', $open->id)->update(['ended_at' => $at]);
        $i = $this->recorder->clear('downtime', $monitor);
        if ($i !== null) {
            $start = CarbonImmutable::parse((string) $open->started_at, (string) config('app.timezone'));
            $during = array_values(Incident::query()->where('kind', '!=', 'downtime')->where('last_seen_at', '>=', $start->subMinutes(5))->where('first_seen_at', '<=', $at)
                ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 else 2 end")->orderByDesc('occurrences')->limit(5)->get()->all());
            $this->alerts->downtimeOver($i, max(1, (int) round(($at->getTimestamp() - $start->getTimestamp()) / 60)), $during);
        }
    }

    /**
     * Uptime over the last 30 days, per monitor that has reported.
     *
     * @return list<array{monitor: string, uptime_pct: float, down_minutes: int, down_now: bool}>
     */
    public static function summary(int $days = 30): array
    {
        $from = now()->subDays($days);
        $total = $days * 24 * 60;
        $out = [];
        $rows = Settings::db()->table('watchtower_downtimes')->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from))->get()->groupBy('monitor');
        foreach ($rows as $monitor => $list) {
            $minutes = 0;
            foreach ($list as $r) {
                $s = CarbonImmutable::parse((string) $r->started_at, (string) config('app.timezone'));
                $e = $r->ended_at === null ? CarbonImmutable::now() : CarbonImmutable::parse((string) $r->ended_at, (string) config('app.timezone'));
                $minutes += max(0, (int) round(($e->getTimestamp() - max($s->getTimestamp(), $from->getTimestamp())) / 60));
            }
            $out[] = ['monitor' => (string) $monitor, 'uptime_pct' => round(max(0, 100 - 100 * $minutes / $total), 3), 'down_minutes' => $minutes,
                'down_now' => $list->contains(fn (object $r): bool => $r->ended_at === null)];
        }

        return $out;
    }
}
