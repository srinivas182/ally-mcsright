<?php

declare(strict_types=1);

namespace Modules\Crm\Brief;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Simple\HomePeople;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Inbox\SendGuard;
use Modules\Crm\Leads\SpeedToLead;

/**
 * SX6: work without signing in — today's list in the morning email, and (only if the agent opted in) a morning text.
 *
 * Both carry one link to "your list for today": a page that needs no sign-in, showing the people to call with big
 * Call buttons and "how did it go?" buttons that log the call. The link is signed for that agent, works until the
 * end of the day, and shows only names, reasons and numbers for today's list (no notes, no other pages). Logging is
 * a button on that page (POST), never the link itself, so email security scanners that open links change nothing.
 *
 * The morning text goes only to agents who turned it on themselves (their own consent, kept with the time), between
 * 8am and 9pm where their phone is, on weekdays, when HQ allows texts for the brokerage. Replying STOP stops it.
 */
final class MorningList
{
    public const OUTCOMES = ['connected' => 'We talked', 'left_voicemail' => 'Left a message', 'no_answer' => 'No answer'];

    public function __construct(private readonly TenantContext $tenants, private readonly HomePeople $people, private readonly SpeedToLead $links) {}

    /** The signed "your list for today" link (absolute, on the brokerage's back-office address). */
    public function link(User $user): string
    {
        $tz = $user->timezone !== null && $user->timezone !== '' ? $user->timezone : (string) ($this->tenants->current()->getAttribute('timezone') ?: 'America/Chicago');

        return $this->links->link(URL::temporarySignedRoute('crm.morning-list', CarbonImmutable::now($tz)->endOfDay(), ['user' => $user->ulid], false));
    }

    /**
     * Today's people for the email: name, reason and whether they may be called.
     *
     * @return list<array{name: string, reason: string, phone: string|null}>
     */
    public function forEmail(User $user, int $limit = 5): array
    {
        return array_map(fn (array $p): array => ['name' => $p['name'], 'reason' => $p['reason'], 'phone' => $p['call'] ? $p['phone'] : null],
            array_slice($this->people->for($user), 0, $limit));
    }

    public function textOn(User $user): bool
    {
        return DB::connection(Connections::cell())->table('crm_lead_alert_prefs')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)
            ->whereNotNull('morning_text_at')->exists();
    }

    /** The agent's own choice; turning it on needs their mobile number and is kept with the time (their consent). */
    public function setText(User $user, bool $on): void
    {
        if ($on && ($user->phone === null || $user->phone === '')) {
            throw ValidationException::withMessages(['phone' => 'Add your mobile number to your profile first.']);
        }
        $db = DB::connection(Connections::cell())->table('crm_lead_alert_prefs');
        $key = ['tenant_id' => $this->tenants->id(), 'user_id' => $user->id];
        $row = $db->clone()->where($key)->first();
        $db->clone()->updateOrInsert($key, ['morning_text_at' => $on ? ($row->morning_text_at ?? now()) : null, 'updated_at' => now(), 'created_at' => $row->created_at ?? now()]);
        app(AuditLogger::class)->record('crm.morning_text.'.($on ? 'on' : 'off'), $user, severity: 'notice');
    }

    /** Every few minutes: send the morning text to agents who opted in and whose morning has come. */
    public function textDue(): int
    {
        if (! app(MessagingSettings::class)->smsFor($this->tenants->id())) {
            return 0;
        }
        $db = DB::connection(Connections::cell())->table('crm_lead_alert_prefs');
        $sent = 0;
        foreach ($db->clone()->where('tenant_id', $this->tenants->id())->whereNotNull('morning_text_at')->get() as $row) {
            $user = User::query()->whereKey((int) $row->user_id)->where('status', UserStatus::Active)->whereIn('kind', [UserKind::Agent, UserKind::Owner, UserKind::Staff])->first();
            if ($user === null || $user->phone === null || $user->phone === '') {
                continue;
            }
            $tz = $user->timezone !== null && $user->timezone !== '' ? $user->timezone : (string) ($this->tenants->current()->getAttribute('timezone') ?: 'America/Chicago');
            $local = CarbonImmutable::now($tz);
            if ($local->isWeekend() || $local->hour < 8 || (string) ($row->morning_text_sent_on ?? '') === $local->toDateString() || SendGuard::quietHours($user->phone) !== null) {
                continue;
            }
            $db->clone()->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->update(['morning_text_sent_on' => $local->toDateString(), 'updated_at' => now()]);
            $count = count($this->people->for($user));
            if ($count === 0) {
                continue; // nothing to call about: no text
            }
            try {
                app(SmsSender::class)->send($user->phone, "RightAlly: good morning {$user->first_name}. {$count} ".($count === 1 ? 'person' : 'people')
                    .' to call today: '.$this->link($user).' Reply STOP to stop these texts.');
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }
}
