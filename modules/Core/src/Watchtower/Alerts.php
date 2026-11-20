<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Watchtower\Jobs\AnalyseIncident;
use Modules\Core\Watchtower\Mail\IncidentMail;
use Modules\Core\Watchtower\Mail\WatchtowerReportMail;
use Throwable;

/**
 * Who hears about what, and when (OB1). Critical and high problems: an email straight away, one per problem, never one
 * per error. Again only if it comes back after a fix, gets ten times worse or turns more serious. Everything else: the
 * daily report. Systems that recover say so. HQ can switch the emails and the report off separately.
 */
final readonly class Alerts
{
    /** A bad minute should not become 500 emails: the rest wait for the next minute. */
    public const PER_RUN = 20;

    /** Why this problem should be emailed now, or null. */
    public static function reason(Incident $i): ?string
    {
        if (! in_array($i->status, Incident::OPEN, true)) {
            return null;
        }
        if ($i->regressed_at !== null && ($i->alerted_at === null || $i->regressed_at->greaterThan($i->alerted_at))) {
            return 'regression';
        }
        if (Incident::rank($i->severity) < Incident::rank('high')) {
            return null;
        }
        if ($i->alerted_at === null) {
            return 'new';
        }
        if (Incident::rank($i->severity) > Incident::rank($i->alerted_severity)) {
            return 'worse';
        }
        // Not for something still failing: a check failing every minute is not "ten times worse".
        if (! $i->ongoing && $i->occurrences >= max(10 * $i->alerted_occurrences, $i->alerted_occurrences + 10)) {
            return 'spike';
        }

        return null;
    }

    /** Emails every problem that is due. Returns how many were sent. */
    public function sendDue(): int
    {
        $s = Settings::all();
        if (! $s['alerts'] || $s['recipients'] === []) {
            return 0;
        }
        $sent = 0;
        $candidates = Incident::query()->whereIn('status', Incident::OPEN)
            ->where(fn ($q) => $q->whereIn('severity', ['critical', 'high'])->orWhereNotNull('regressed_at'))
            ->where('last_seen_at', '>=', now()->subDays(2))
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 else 2 end")->orderByDesc('last_seen_at')
            ->limit(200)->get();
        foreach ($candidates as $i) {
            $reason = self::reason($i);
            if ($reason === null || self::awaitingAnalysis($i)) {
                continue;
            }
            if ($sent >= self::PER_RUN) {
                break;
            }
            if ($this->send($s['recipients'], new IncidentMail($i, $reason, FixRequest::for($i), self::link($i)))) {
                $i->forceFill(['alerted_at' => now(), 'alerted_severity' => $i->severity, 'alerted_occurrences' => $i->occurrences])->save();
                $sent++;
            }
        }

        return $sent;
    }

    /** Ask the AI to explain problems worth explaining, in the background, a few a minute, within the daily limit. */
    public function requestAnalyses(): int
    {
        if (! Settings::on('ai')) {
            return 0;
        }
        $asked = 0;
        $candidates = Incident::query()->whereIn('status', Incident::OPEN)->whereNotIn('kind', ['check', 'queue', 'downtime'])
            ->where('last_seen_at', '>=', now()->subDay())->whereNull('analysed_on')
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 else 2 end")->orderByDesc('last_seen_at')->limit(50)->get();
        $regressed = Incident::query()->whereIn('status', Incident::OPEN)->whereNotNull('regressed_at')->whereNotNull('analysed_on')
            ->where('last_seen_at', '>=', now()->subDay())->limit(10)->get();
        foreach ($candidates->concat($regressed) as $i) {
            $state = (array) $i->analysis;
            $pending = ($state['status'] ?? null) === 'pending' && ($state['release'] ?? null) === $i->last_release;
            if ($asked >= 5 || ! Analyst::underCap()) {
                break;
            }
            if ($pending || ! Analyst::worthIt($i)) {
                continue;
            }
            $i->forceFill(['analysis' => ['status' => 'pending', 'release' => $i->last_release, 'at' => now()->toIso8601String()]])->save();
            AnalyseIncident::dispatch($i->id);
            $asked++;
        }

        return $asked;
    }

    /** High problems wait up to 3 minutes for the AI's explanation, so the email says what is wrong and how to fix it. Critical ones never wait. */
    public static function awaitingAnalysis(Incident $i): bool
    {
        $state = (array) $i->analysis;
        if ($i->severity === 'critical' || ($state['status'] ?? null) !== 'pending') {
            return false;
        }
        $at = is_string($state['at'] ?? null) ? CarbonImmutable::parse($state['at']) : null;

        return $at !== null && $at->greaterThan(now()->subMinutes(3));
    }

    /**
     * The outage the uptime checker reported is over: how long it lasted, and what Watchtower saw meanwhile.
     *
     * @param  list<Incident>  $during
     */
    public function downtimeOver(Incident $i, int $minutes, array $during): void
    {
        $s = Settings::all();
        if ($s['alerts'] && $s['recipients'] !== []) {
            $this->send($s['recipients'], new IncidentMail($i, 'recovered', FixRequest::for($i), self::link($i), $during, $minutes));
        }
    }

    /** A check or backlog that was emailed about has cleared. */
    public function recovered(Incident $i): void
    {
        $s = Settings::all();
        if ($s['alerts'] && $s['recipients'] !== [] && $i->alerted_at !== null) {
            $this->send($s['recipients'], new IncidentMail($i, 'recovered', FixRequest::for($i), self::link($i)));
        }
    }

    /** The daily report: every new problem of the day, what is still open, and the state of each system. */
    public function digest(): bool
    {
        $s = Settings::all();
        if (! $s['digest'] || $s['recipients'] === []) {
            return false;
        }
        $since = now()->subDay();
        $new = array_values(Incident::query()->where('first_seen_at', '>=', $since)->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 else 2 end")
            ->orderByDesc('occurrences')->limit(30)->get()->all());
        $open = Incident::query()->whereIn('status', Incident::OPEN)->toBase()->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity')->all();
        $failing = array_values(array_filter(SystemChecks::latest(), fn (array $c): bool => ! $c['ok']));
        $resolved = Incident::query()->where('resolved_at', '>=', $since)->count();

        return $this->send($s['recipients'], new WatchtowerReportMail($new, array_map('intval', $open), $failing, $resolved, self::link(null)));
    }

    public static function link(?Incident $i): string
    {
        return 'https://'.config('core.hq_host').'/hq/watchtower'.($i !== null ? '/'.$i->id : '');
    }

    /** @param list<string> $to */
    private function send(array $to, BaseNotification $mail): bool
    {
        try {
            Notification::route('mail', $to)->notifyNow($mail);

            return true;
        } catch (Throwable) {
            return false; // the mail service itself may be what is down; its check will say so
        }
    }
}
