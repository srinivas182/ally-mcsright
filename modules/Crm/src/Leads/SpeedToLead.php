<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Messaging\VoiceCaller;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Lead;

/**
 * Speed to lead (specs/grow/04): the agent hears about a lead at once — email always; a text and a
 * "press 1 to be connected" call when they opted in and HQ has switched texts and calls on for the brokerage
 * (8am–9pm brokerage time). Response time runs from arrival to the first call, text or email logged.
 */
final class SpeedToLead
{
    public function __construct(private readonly TenantContext $tenants, private readonly MessagingSettings $messaging, private readonly Timeline $timeline) {}

    /**
     * @param  array<int, User>  $agents
     */
    public function alert(Lead $lead, array $agents): void
    {
        $agents = array_values($agents);
        $contact = $lead->contact;
        $name = $contact === null ? 'A new lead' : trim($contact->first_name.' '.mb_substr($contact->last_name, 0, 1).($contact->last_name !== '' ? '.' : ''));
        $what = self::summary($lead);
        $offered = $lead->status === 'offered';
        $minutes = app(LeadRouting::class)->settings()['respond_minutes'];
        $url = $this->link('/leads?lead='.$lead->ulid);
        $awake = $this->awake();
        $tenantId = $this->tenants->id();
        $sms = $awake && $this->messaging->smsFor($tenantId);
        $voice = $awake && $this->messaging->voiceFor($tenantId) && $contact !== null && $contact->primary_phone !== null && ! $contact->do_not_call;
        $prefs = $this->prefsFor(array_map(fn (User $u): int => $u->id, $agents));

        foreach ($agents as $agent) {
            $agent->notify(new LeadAlert(
                $offered ? "Lead up for grabs: {$name}" : "New lead: {$name}",
                $offered ? "{$what}. Offered to several agents: the first to claim it gets it." : "{$what}. Reach out within {$minutes} minutes or it moves to the next agent.",
                $url, $offered ? 'Claim the lead' : 'Open the lead'));

            $p = $prefs[$agent->id] ?? null;
            if ($agent->phone === null || $agent->phone === '' || $p === null) {
                continue;
            }
            try {
                if ($sms && $p->texts_consent_at !== null) {
                    app(SmsSender::class)->send($agent->phone, $offered ? "RightAlly: lead up for grabs — {$name}, {$what}. Claim it: {$url}" : "RightAlly: new lead — {$name}, {$what}. Reach out within {$minutes} min: {$url}");
                }
                if ($voice && ! $offered && $p->calls_consent_at !== null) {
                    $press = $this->link(URL::temporarySignedRoute('crm.leads.connect', CarbonImmutable::now()->addMinutes(30), ['lead' => $lead->ulid, 'agent' => $agent->id], false));
                    app(VoiceCaller::class)->connect($agent->phone, "New lead from RightAlly: {$name}, {$what}. Press 1 to be connected to them now.", $press);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** A text to an agent who opted in to lead texts (8am–9pm brokerage time, when HQ allows texts). */
    public function textAgent(User $agent, string $text): void
    {
        $p = $this->prefsFor([$agent->id])[$agent->id] ?? null;
        if ($p === null || $p->texts_consent_at === null || $agent->phone === null || $agent->phone === '' || ! $this->awake() || ! $this->messaging->smsFor($this->tenants->id())) {
            return;
        }
        try {
            app(SmsSender::class)->send($agent->phone, $text);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function movedOn(Lead $lead, User $agent): void
    {
        $agent->notify(new LeadAlert('A lead moved to another agent', self::summary($lead).': nobody reached out in time, so it went to the next agent.', $this->link('/leads'), 'See your leads'));
    }

    /**
     * Twilio asks what to do after the agent pressed a key on the speed-to-lead call.
     */
    public function connectTwiml(Lead $lead, User $agent, string $digits): string
    {
        $contact = $lead->contact;
        $say = static fn (string $t): string => '<Say voice="Polly.Joanna">'.htmlspecialchars($t, ENT_XML1 | ENT_QUOTES).'</Say>';
        if ($digits !== '1' || $contact === null || $contact->primary_phone === null) {
            return '<?xml version="1.0" encoding="UTF-8"?><Response>'.$say('Okay. The lead is in RightAlly. Goodbye.').'</Response>';
        }
        if ($contact->do_not_call) {
            return '<?xml version="1.0" encoding="UTF-8"?><Response>'.$say('This lead asked not to be called. Please email them instead. Goodbye.').'</Response>';
        }
        if ($lead->assigned_user_id !== $agent->id) {
            return '<?xml version="1.0" encoding="UTF-8"?><Response>'.$say('This lead has moved to another agent. Goodbye.').'</Response>';
        }
        $this->timeline->system($contact, "{$agent->name} pressed 1 on the speed-to-lead call and was connected", $agent->id);
        $this->responded($lead, $agent->id);
        $from = htmlspecialchars($this->messaging->voiceFrom(), ENT_XML1 | ENT_QUOTES);
        $to = htmlspecialchars($contact->primary_phone, ENT_XML1 | ENT_QUOTES);

        return '<?xml version="1.0" encoding="UTF-8"?><Response>'.$say('Connecting you now.')."<Dial callerId=\"{$from}\" timeout=\"25\">{$to}</Dial></Response>";
    }

    /** After a call, text or email is logged on a contact: that answers its open leads. */
    public function activityLogged(Activity $activity): void
    {
        if (! in_array($activity->type, ['call', 'text', 'email'], true) || $activity->direction === 'in' || $activity->user_id === null) {
            return;
        }
        foreach (Lead::query()->where('contact_id', $activity->contact_id)->whereNull('responded_at')->whereIn('status', [...Lead::OPEN, 'new'])->get() as $lead) {
            $this->responded($lead, (int) $activity->user_id);
        }
    }

    public function responded(Lead $lead, int $userId): void
    {
        $now = CarbonImmutable::now();
        Lead::query()->whereKey($lead->id)->whereNull('responded_at')->update(['responded_at' => $now, 'responded_by' => $userId,
            'response_seconds' => max(0, (int) $lead->created_at->diffInSeconds($now)), 'status' => 'responded']);
    }

    /**
     * @return array{texts: bool, calls: bool, away_until: string|null, phone: string|null, sms_allowed: bool, voice_allowed: bool}
     */
    public function prefs(User $user): array
    {
        $p = $this->prefsFor([$user->id])[$user->id] ?? null;
        $tenantId = $this->tenants->id();

        return ['texts' => $p?->texts_consent_at !== null, 'calls' => $p?->calls_consent_at !== null,
            'away_until' => $p?->away_until !== null && CarbonImmutable::parse($p->away_until)->isFuture() ? CarbonImmutable::parse($p->away_until)->toIso8601String() : null,
            'phone' => $user->phone !== null && $user->phone !== '' ? '•••'.substr($user->phone, -4) : null,
            'sms_allowed' => $this->messaging->smsFor($tenantId), 'voice_allowed' => $this->messaging->voiceFor($tenantId)];
    }

    public function savePrefs(User $user, bool $texts, bool $calls, ?int $awayHours): void
    {
        if (($texts || $calls) && ($user->phone === null || $user->phone === '')) {
            throw ValidationException::withMessages(['phone' => 'Add your mobile number to your profile first.']);
        }
        $db = DB::connection(Connections::cell());
        $p = $this->prefsFor([$user->id])[$user->id] ?? null;
        $now = CarbonImmutable::now();
        $db->table('crm_lead_alert_prefs')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'user_id' => $user->id], [
            'texts_consent_at' => $texts ? ($p->texts_consent_at ?? $now) : null,
            'calls_consent_at' => $calls ? ($p->calls_consent_at ?? $now) : null,
            'away_until' => $awayHours !== null && $awayHours > 0 ? $now->addHours(min(24 * 30, $awayHours)) : null,
            'updated_at' => $now, 'created_at' => $p->created_at ?? $now,
        ]);
        app(AuditLogger::class)->record('crm.leads.alert_choices', metadata: ['texts' => $texts, 'calls' => $calls, 'away_hours' => $awayHours], severity: 'notice');
    }

    public static function summary(Lead $lead): string
    {
        $parts = [match ($lead->intent) {
            'sell' => 'selling', 'rent' => 'renting', 'buy' => 'buying', default => 'asking'
        }];
        if ($lead->property !== null) {
            $parts[] = 'about '.$lead->property;
        } elseif ($lead->zip !== null) {
            $parts[] = 'around '.$lead->zip;
        }
        $price = $lead->price_max ?? $lead->price_min;
        if ($price !== null) {
            $parts[] = '$'.number_format($price);
        }

        return implode(' ', $parts).' (via '.self::sourceLabel($lead->source).')';
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            'zillow' => 'Zillow', 'realtor_com' => 'Realtor.com', 'homes_com' => 'Homes.com', 'open_house' => 'open house', 'sign' => 'sign text',
            default => str_replace('_', ' ', $source),
        };
    }

    /** An absolute link on the brokerage's own domain (works from scheduled jobs, not only web requests). */
    public function link(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        $tenant = $this->tenants->current();
        if ($tenant->domains()->where('host', request()->getHost())->exists()) {
            return url($path); // inside a request on the brokerage's own domain
        }
        $host = $tenant->primaryDomain()?->host;

        return $host !== null ? 'https://'.$host.$path : url($path);
    }

    private function awake(): bool
    {
        $tz = (string) ($this->tenants->current()->getAttribute('timezone') ?: 'America/Chicago');
        $hour = CarbonImmutable::now()->setTimezone($tz)->hour;

        return $hour >= 8 && $hour < 21;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, \stdClass>
     */
    private function prefsFor(array $ids): array
    {
        return DB::connection(Connections::cell())->table('crm_lead_alert_prefs')->where('tenant_id', $this->tenants->id())->whereIn('user_id', $ids)
            ->get()->keyBy(fn ($r): int => (int) $r->user_id)->all();
    }
}
