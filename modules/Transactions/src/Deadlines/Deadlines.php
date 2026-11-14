<?php

declare(strict_types=1);

namespace Modules\Transactions\Deadlines;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Messaging\VoiceCaller;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Deadlines that cannot be missed (specs/ai/02, Sprint 15D). The escalation ladder for every open key date:
 * the daily email digest (transactions:date-reminders), a text the day before, a text and an automated call on
 * the day, and the broker when it is missed; blockers still open close to closing also go to the broker. Texts
 * and calls only where HQ allows them for the brokerage, to agents who opted in, between 8am and 9pm local time.
 * Each alert is sent once.
 */
final class Deadlines
{
    public const QUIET_FROM = 21;

    public const QUIET_UNTIL = 8;

    public function __construct(private readonly TenantContext $tenants, private readonly MessagingSettings $messaging) {}

    /**
     * @return array{email_days: int, text_days: int, call_on_due: bool, broker_on_missed: bool, closing_blocker_hours: int}
     */
    public function settings(): array
    {
        $r = DB::connection(Connections::cell())->table('tx_deadline_settings')->first();

        return ['email_days' => (int) ($r->email_days ?? 2), 'text_days' => (int) ($r->text_days ?? 1), 'call_on_due' => (bool) ($r->call_on_due ?? true),
            'broker_on_missed' => (bool) ($r->broker_on_missed ?? true), 'closing_blocker_hours' => (int) ($r->closing_blocker_hours ?? 48)];
    }

    /**
     * @param  array{email_days: int, text_days: int, call_on_due: bool, broker_on_missed: bool, closing_blocker_hours: int}  $s
     */
    public function saveSettings(array $s): void
    {
        if ($s['email_days'] < 0 || $s['email_days'] > 14 || $s['text_days'] < 0 || $s['text_days'] > 7 || $s['closing_blocker_hours'] < 0 || $s['closing_blocker_hours'] > 240) {
            throw ValidationException::withMessages(['ladder' => 'Email up to 14 days before, text up to 7 days before, and alert the broker up to 240 hours before closing.']);
        }
        DB::connection(Connections::cell())->table('tx_deadline_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], $s + ['updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('transactions.deadlines.settings', metadata: $s, severity: 'notice');
    }

    /**
     * An agent's alert choices, what HQ allows the brokerage, and their private calendar link.
     *
     * @return array{sms: bool, voice: bool, sms_allowed: bool, voice_allowed: bool, phone: ?string, calendar_url: ?string}
     */
    public function prefs(User $user): array
    {
        $p = DB::connection(Connections::cell())->table('tx_alert_prefs')->where('user_id', $user->id)->first();

        return ['sms' => $p?->sms_consent_at !== null, 'voice' => $p?->voice_consent_at !== null, 'sms_allowed' => $this->messaging->smsFor($this->tenants->id()),
            'voice_allowed' => $this->messaging->voiceFor($this->tenants->id()), 'phone' => $user->phone !== null && $user->phone !== '' ? '•••'.substr((string) $user->phone, -4) : null,
            'calendar_url' => $p?->calendar_token !== null ? url('/calendar/'.$p->calendar_token.'.ics') : null];
    }

    /** The agent opts in or out of deadline texts and automated calls (their consent is recorded). */
    public function consent(User $user, bool $sms, bool $voice): void
    {
        if (($sms || $voice) && ($user->phone === null || $user->phone === '')) {
            throw ValidationException::withMessages(['phone' => 'Add your mobile number to your profile first.']);
        }
        $db = DB::connection(Connections::cell());
        $p = $db->table('tx_alert_prefs')->where('user_id', $user->id)->first();
        $db->table('tx_alert_prefs')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'user_id' => $user->id], [
            'sms_consent_at' => $sms ? ($p->sms_consent_at ?? now()) : null, 'voice_consent_at' => $voice ? ($p->voice_consent_at ?? now()) : null,
            'updated_at' => now(), 'created_at' => $p->created_at ?? now()]);
        app(AuditLogger::class)->record('transactions.deadlines.consent', metadata: ['sms' => $sms, 'voice' => $voice, 'phone_last4' => substr((string) $user->phone, -4)], severity: 'notice');
    }

    /** Creates (or replaces) the agent's private calendar link; the old link stops working. */
    public function calendarLink(User $user): string
    {
        $token = Str::random(40);
        $db = DB::connection(Connections::cell());
        $p = $db->table('tx_alert_prefs')->where('user_id', $user->id)->first();
        $db->table('tx_alert_prefs')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'user_id' => $user->id],
            ['calendar_token' => $token, 'updated_at' => now(), 'created_at' => $p->created_at ?? now()]);

        return url('/calendar/'.$token.'.ics');
    }

    /**
     * Runs the ladder for this brokerage (hourly).
     *
     * @return array{texts: int, calls: int, broker: int}
     */
    public function escalate(): array
    {
        $tenant = $this->tenants->current();
        $tz = $tenant->timezone !== '' ? $tenant->timezone : 'America/Chicago';
        $now = CarbonImmutable::now($tz);
        $today = $now->startOfDay();
        $awake = $now->hour >= self::QUIET_UNTIL && $now->hour < self::QUIET_FROM;
        $settings = $this->settings();
        $smsOn = $this->messaging->smsFor($tenant->id);
        $voiceOn = $this->messaging->voiceFor($tenant->id);
        $db = DB::connection(Connections::cell());
        $counts = ['texts' => 0, 'calls' => 0, 'broker' => 0];

        $dates = $db->table('tx_dates')->join('tx_deals', 'tx_deals.id', '=', 'tx_dates.deal_id')->whereNull('tx_dates.done_at')
            ->whereIn('tx_deals.status', ['pre_contract', 'under_contract'])->where('tx_dates.due_on', '<=', $today->addDays(max(1, $settings['text_days']))->toDateString())
            ->where('tx_dates.due_on', '>=', $today->subDays(7)->toDateString())
            ->get(['tx_dates.id', 'tx_dates.label', 'tx_dates.due_on', 'tx_deals.ulid', 'tx_deals.street', 'tx_deals.agent_user_id']);
        foreach ($dates as $d) {
            $days = (int) $today->diffInDays(CarbonImmutable::parse(substr((string) $d->due_on, 0, 10), $tz)->startOfDay(), false);
            $agent = User::query()->find((int) $d->agent_user_id);
            if ($agent === null || ! $agent->isActive()) {
                continue;
            }
            $prefs = $db->table('tx_alert_prefs')->where('user_id', $agent->id)->first();
            $when = $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in {$days} days");
            $text = "RightAlly: {$d->label} for {$d->street} is due {$when}. Tick it off in RightAlly once done.";
            if ($awake && $smsOn && $prefs?->sms_consent_at !== null && $days > 0 && $days === $settings['text_days'] && $this->once((int) $d->id, 'text', 'sms')) {
                $counts['texts'] += $this->text((string) $agent->phone, $text);
            }
            if ($awake && $days === 0) {
                if ($smsOn && $prefs?->sms_consent_at !== null && $this->once((int) $d->id, 'due', 'sms')) {
                    $counts['texts'] += $this->text((string) $agent->phone, $text);
                }
                if ($voiceOn && $settings['call_on_due'] && $prefs?->voice_consent_at !== null && $this->once((int) $d->id, 'due', 'voice')) {
                    $counts['calls'] += $this->call((string) $agent->phone, "This is RightAlly for {$tenant->name}. {$d->label} for {$d->street} is due today.");
                }
            }
            if ($days < 0 && $settings['broker_on_missed'] && $this->once((int) $d->id, 'missed', 'email')) {
                $counts['broker'] += $this->toBroker((string) $d->street, $agent, "{$d->label} was due ".CarbonImmutable::parse((string) $d->due_on)->format('m/d/Y').' and is not ticked off.', (string) $d->ulid);
            }
        }

        // Blockers still open close to closing go to the broker (once per finding).
        $hours = $settings['closing_blocker_hours'];
        if ($hours > 0) {
            $findings = $db->table('tx_findings')->join('tx_deals', 'tx_deals.id', '=', 'tx_findings.deal_id')->where('tx_findings.status', 'open')
                ->where('tx_findings.severity', 'blocker')->whereNull('tx_findings.broker_alerted_at')->where('tx_deals.status', 'under_contract')
                ->whereNotNull('tx_deals.closing_date')->where('tx_deals.closing_date', '<=', $now->addHours($hours)->toDateString())
                ->get(['tx_findings.id', 'tx_findings.message', 'tx_deals.ulid', 'tx_deals.street', 'tx_deals.agent_user_id', 'tx_deals.closing_date']);
            foreach ($findings as $f) {
                $agent = User::query()->find((int) $f->agent_user_id);
                $counts['broker'] += $this->toBroker((string) $f->street, $agent, 'Closing '.CarbonImmutable::parse((string) $f->closing_date)->format('m/d/Y').' and still blocked: '.$f->message, (string) $f->ulid);
                $db->table('tx_findings')->where('id', $f->id)->update(['broker_alerted_at' => now()]);
            }
        }

        return $counts;
    }

    /** Records the alert; false when it was already sent. */
    private function once(int $dateId, string $step, string $channel): bool
    {
        $db = DB::connection(Connections::cell());
        if ($db->table('tx_date_alerts')->where('date_id', $dateId)->where('step', $step)->where('channel', $channel)->exists()) {
            return false;
        }
        $db->table('tx_date_alerts')->insert(['tenant_id' => $this->tenants->id(), 'date_id' => $dateId, 'step' => $step, 'channel' => $channel, 'sent_at' => now()]);

        return true;
    }

    private function text(string $phone, string $message): int
    {
        try {
            app(SmsSender::class)->send($phone, $message);

            return 1;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function call(string $phone, string $message): int
    {
        try {
            app(VoiceCaller::class)->call($phone, $message);

            return 1;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function toBroker(string $street, ?User $agent, string $message, string $dealUlid): int
    {
        $brokers = User::query()->whereIn('kind', [UserKind::Owner, UserKind::Staff])->where('status', UserStatus::Active)->get()
            ->filter(fn (User $u): bool => $u->isKind(UserKind::Owner) || $u->can('transactions.review'));
        if ($brokers->isEmpty()) {
            $brokers = User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->get();
        }
        Notification::send($brokers, new BrokerAlert($street, $agent->name ?? 'An agent', $message, $dealUlid));
        app(AuditLogger::class)->record('transactions.deadlines.broker_alert', metadata: ['deal' => $dealUlid, 'message' => $message], severity: 'warning');

        return 1;
    }

    /** The agent's key dates and closings as an iCalendar feed (subscribe in Google, Outlook or Apple Calendar). */
    public static function ics(int $userId): string
    {
        $db = DB::connection(Connections::cell());
        $esc = fn (string $s): string => str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], $s);
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//RightAlly//Deal-IQ//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:RightAlly deadlines'];
        $deals = $db->table('tx_deals')->where('agent_user_id', $userId)->whereIn('status', ['pre_contract', 'under_contract'])->get(['id', 'ulid', 'street', 'closing_date']);
        $events = [];
        foreach ($db->table('tx_dates')->whereIn('deal_id', $deals->pluck('id'))->get() as $d) {
            $deal = $deals->firstWhere('id', $d->deal_id);
            $events[] = ['uid' => 'date-'.$d->id, 'day' => substr((string) $d->due_on, 0, 10), 'title' => ($d->done_at !== null ? 'Done: ' : '').$d->label.' — '.($deal->street ?? ''),
                'deal' => (string) ($deal->ulid ?? '')];
        }
        foreach ($deals->whereNotNull('closing_date') as $deal) {
            $events[] = ['uid' => 'closing-'.$deal->id, 'day' => substr((string) $deal->closing_date, 0, 10), 'title' => 'Closing — '.$deal->street, 'deal' => (string) $deal->ulid];
        }
        $stamp = gmdate('Ymd\THis\Z');
        foreach ($events as $e) {
            $day = str_replace('-', '', $e['day']);
            $next = CarbonImmutable::parse($e['day'])->addDay()->format('Ymd');
            array_push($lines, 'BEGIN:VEVENT', 'UID:'.$e['uid'].'@rightally', 'DTSTAMP:'.$stamp, 'DTSTART;VALUE=DATE:'.$day, 'DTEND;VALUE=DATE:'.$next,
                'SUMMARY:'.$esc($e['title']), 'URL:'.url('/deals/'.$e['deal']), 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.$esc($e['title']), 'TRIGGER:-PT15H', 'END:VALARM', 'END:VEVENT');
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }
}
