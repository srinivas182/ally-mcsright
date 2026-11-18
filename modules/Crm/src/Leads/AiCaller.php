<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Messaging\VoiceCaller;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Consent;
use Modules\Crm\Inbox\SendGuard;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Voice\CallNotes;
use Modules\Crm\Voice\PollVoiceCall;
use Modules\Crm\Voice\VoiceAgents;
use stdClass;
use Throwable;

/**
 * Lead-IQ calls (LQ1, specs/grow/14). Within a minute of a new lead, the brokerage's assistant calls them in the
 * brokerage's own name and voice, says it is an AI assistant, and offers three things by keypad: talk to an agent now
 * (warm transfer: the agent hears who it is and presses 1 to take the call; if they don't, the next agent is tried),
 * a link to pick a time with the agent, or no more calls. Spanish is one key away.
 *
 * The FCC treats AI voices as artificial voices under the TCPA, so calls go only to people who gave written consent to
 * automated calls (Consent::canAutoCall), never on Do Not Call, only 8am–9pm where they are, and never past the
 * brokerage's monthly limit. Every call that is not made says why, on the lead.
 *
 * VO1: when HQ (or the brokerage) has connected a voice provider in Outside services (Retell, Vapi or Bland), the call
 * is a spoken conversation instead of the keypad menu: the AI agent set up in the provider's account answers
 * questions, transfers to the agent or sends the booking link. RightAlly then reads how it ended and the transcript,
 * writes the agent's hand-off note, and emails it to them. In Test mode only the listed test numbers are called.
 */
final class AiCaller
{
    /** Agents tried in turn on a transfer (the lead's agent first). */
    public const MAX_AGENTS = 3;

    private const VOICES = ['en' => ['female' => 'Polly.Joanna', 'male' => 'Polly.Matthew'], 'es' => ['female' => 'Polly.Lupe', 'male' => 'Polly.Miguel']];

    public function __construct(private readonly TenantContext $tenants, private readonly Consent $consent, private readonly Timeline $timeline, private readonly MessagingSettings $messaging) {}

    /** A new lead: decide whether to call, and queue the call or record why not. */
    public function queue(Lead $lead): void
    {
        $contact = $lead->contact;
        if ($contact === null) {
            return;
        }
        $s = app(AiResponder::class)->settings();
        $db = DB::connection(Connections::cell());
        $made = $db->table('crm_ai_calls')->where('tenant_id', $this->tenants->id())->where('status', '!=', 'skipped')
            ->where('created_at', '>=', CarbonImmutable::now()->startOfMonth())->count();
        $why = match (true) {
            ! $this->messaging->voiceFor($this->tenants->id()) => 'Calls are not switched on for this brokerage yet (RightAlly HQ turns them on).',
            $made >= $s['voice_monthly_cap'] => 'The monthly limit of '.$s['voice_monthly_cap'].' assistant calls was reached.',
            $contact->primary_phone === null => 'No phone number.',
            $contact->do_not_call => 'They are on Do Not Call.',
            ! $this->consent->canAutoCall($contact) => 'No written consent to automated calls, so the assistant did not call (it texts or emails when it may).',
            default => null,
        };
        $ulid = (string) Str::ulid();
        $db->table('crm_ai_calls')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'lead_id' => $lead->id, 'contact_id' => $contact->id,
            'status' => $why === null ? 'queued' : 'skipped', 'reason' => $why, 'lang' => $s['voice_language'] === 'es' ? 'es' : 'en',
            'agents' => json_encode($this->agentsFor($lead)), 'created_at' => now(), 'updated_at' => now()]);
        if ($why !== null) {
            $this->timeline->system($contact, 'AI assistant did not call: '.$why);

            return;
        }
        CallLead::dispatch($ulid);
    }

    /**
     * Whether the assistant will call this person (so the agent's own press-1 call is not placed at the same time).
     */
    public function willCall(Contact $contact): bool
    {
        return app(AiResponder::class)->settings()['voice_enabled'] && $this->consent->canAutoCall($contact);
    }

    /**
     * Places the call. Returns false when it is too early or late where they are (the job tries again later).
     */
    public function place(string $ulid): bool
    {
        $call = $this->find($ulid);
        if ($call === null || $call->status !== 'queued') {
            return true;
        }
        $contact = Contact::query()->find((int) $call->contact_id);
        $lead = Lead::query()->find((int) $call->lead_id);
        if ($contact === null || $lead === null || ! $this->consent->canAutoCall($contact)) {
            $this->update($call, ['status' => 'skipped', 'reason' => 'Consent or phone number changed before the call.']);

            return true;
        }
        if (SendGuard::quietHours((string) $contact->primary_phone) !== null) {
            return false;
        }
        $s = app(AiResponder::class)->settings();
        $voice = app(OutsideServices::class)->active('voice_calls', $this->tenants->id());
        if ($voice !== null) {
            $this->converse($call, $lead, $contact, $voice, $s);

            return true;
        }
        $lang = (string) $call->lang;
        $sid = app(VoiceCaller::class)->connect((string) $contact->primary_phone, $this->script($lead, $contact, $lang, $s), $this->url($call, 'menu'), $this->voice($lang));
        $this->update($call, ['status' => 'calling', 'call_sid' => $sid !== '' ? $sid : null]);
        $this->timeline->system($contact, 'AI assistant ('.$s['assistant_name'].') called '.($lang === 'es' ? 'in Spanish' : 'in English').' within a minute of the lead.');
        app(AuditLogger::class)->asAutomation('lead_iq_call', $lead->assigned_user_id, 'Lead-IQ call', fn () => app(AuditLogger::class)
            ->record('crm.ai_call.placed', metadata: ['lead' => $lead->ulid, 'lang' => $lang], severity: 'info'));

        return true;
    }

    /**
     * VO1: a spoken AI conversation through the voice provider. Only the facts the AI agent needs are sent.
     *
     * @param  array{provider: string, mode: string, keys: array<string, string>, test_contacts: list<string>, level: string}  $voice
     * @param  array<string, mixed>  $s
     */
    private function converse(stdClass $call, Lead $lead, Contact $contact, array $voice, array $s): void
    {
        if (! app(OutsideServices::class)->mayReach('voice_calls', (string) $contact->primary_phone, $this->tenants->id())) {
            $this->update($call, ['status' => 'skipped', 'reason' => 'AI voice calls are in Test mode: only the test numbers are called.']);
            $this->timeline->system($contact, 'AI assistant did not call: AI voice calls are in Test mode (only test numbers are called).');

            return;
        }
        $lang = (string) $call->lang;
        $agent = $lead->assigned_user_id !== null ? User::query()->find($lead->assigned_user_id) : null;
        $facts = ['first_name' => $contact->first_name, 'brokerage' => (string) $s['brand_name'], 'assistant_name' => (string) $s['assistant_name'],
            'language' => $lang === 'es' ? 'Spanish' : 'English', 'interest' => $lead->property ?? match ($lead->intent) {
                'sell' => 'selling their home', 'rent' => 'renting', default => 'finding a home'
            }, 'agent_first_name' => $agent !== null ? $agent->first_name : '', 'transfer_to' => $agent !== null ? (string) $agent->phone : '',
            'booking_link' => app(SpeedToLead::class)->link(URL::temporarySignedRoute('crm.leads.book', CarbonImmutable::now()->addDays(7), ['call' => $call->ulid], false))];
        try {
            $id = app(VoiceAgents::class)->start($voice['provider'], $voice['keys'], (string) $contact->primary_phone, $facts, (string) $call->ulid);
        } catch (Throwable $e) {
            report($e);
            $this->update($call, ['status' => 'failed', 'provider' => $voice['provider'], 'reason' => 'The voice provider did not start the call. HQ can check the connection in Outside services.']);
            $this->timeline->system($contact, 'AI assistant could not call: the voice provider did not start the call.');

            return;
        }
        $this->update($call, ['status' => 'calling', 'provider' => $voice['provider'], 'provider_call_id' => $id]);
        $this->timeline->system($contact, 'AI assistant ('.$s['assistant_name'].') called for a conversation '.($lang === 'es' ? 'in Spanish' : 'in English').' within a minute of the lead.');
        app(AuditLogger::class)->asAutomation('lead_iq_call', $lead->assigned_user_id, 'Lead-IQ call', fn () => app(AuditLogger::class)
            ->record('crm.ai_call.placed', metadata: ['lead' => $lead->ulid, 'lang' => $lang, 'provider' => $voice['provider']], severity: 'info'));
        PollVoiceCall::dispatch((string) $call->ulid)->delay(CarbonImmutable::now()->addMinutes(2));
    }

    /**
     * VO1: reads how a conversation ended and hands it to the agent. Returns false while the call is still going.
     */
    public function collect(string $ulid): bool
    {
        $call = $this->find($ulid);
        if ($call === null || $call->status !== 'calling' || $call->provider === null || $call->provider_call_id === null) {
            return true;
        }
        $voice = app(OutsideServices::class)->active('voice_calls', $this->tenants->id());
        if ($voice === null) {
            $this->update($call, ['status' => 'failed', 'reason' => 'AI voice calls were switched off before the result came back.']);

            return true;
        }
        try {
            $result = app(VoiceAgents::class)->result((string) $call->provider, $voice['keys'], (string) $call->provider_call_id);
        } catch (Throwable $e) {
            report($e);
            $result = null;
        }
        if ($result === null) {
            $this->update($call, ['polls' => (int) $call->polls + 1]);
            if ((int) $call->polls + 1 >= 30) {
                $this->update($call, ['status' => 'failed', 'reason' => 'The voice provider never said how the call ended.']);

                return true;
            }

            return false;
        }
        $contact = Contact::query()->find((int) $call->contact_id);
        $lead = Lead::query()->find((int) $call->lead_id);
        $status = match ($result['outcome']) {
            'transferred' => 'transferred', 'no_answer' => 'no_answer', default => 'talked'
        };
        $notes = ['summary' => $result['summary'], 'next_step' => '', 'follow_up' => 'none', 'wants_agent' => false, 'stop_calls' => false];
        if ($contact !== null && trim($result['transcript']) !== '') {
            try {
                $notes = app(CallNotes::class)->write($contact, $result['transcript'], 'ai_call', $lead?->assigned_user_id);
            } catch (Throwable $e) {
                report($e); // the provider's own summary is used
            }
        }
        $this->update($call, ['status' => $status, 'transcript' => $result['transcript'] !== '' ? Crypt::encryptString($result['transcript']) : null,
            'summary' => $notes['summary'] !== '' ? Crypt::encryptString($notes['summary']) : null]);
        if ($contact !== null && $status !== 'no_answer' && $notes['summary'] !== '') {
            $agent = $lead?->assigned_user_id !== null ? User::query()->find($lead->assigned_user_id) : null;
            app(CallNotes::class)->deliver($contact, $notes, $agent, $status === 'transferred' ? 'AI call, passed to you' : 'AI call notes');
        } elseif ($contact !== null && $status === 'no_answer') {
            $this->timeline->system($contact, 'AI assistant called; nobody answered.');
        }

        return true;
    }

    /**
     * What Twilio should do next. $step: menu, menu-es (they pressed a key), whisper-N (to agent N before joining),
     * accept-N (agent N pressed a key), next-N (the call to agent N ended).
     *
     * @param  array<string, mixed>  $input
     */
    public function twiml(string $ulid, string $step, array $input): string
    {
        $call = $this->find($ulid) ?? abort(404);
        $contact = Contact::query()->find((int) $call->contact_id);
        $lead = Lead::query()->find((int) $call->lead_id);
        if ($contact === null || $lead === null) {
            return self::respond('');
        }
        $digits = (string) ($input['Digits'] ?? '');
        $lang = str_ends_with($step, '-es') ? 'es' : (string) $call->lang;
        $say = fn (string $en, string $es): string => '<Say voice="'.$this->voice($lang).'">'.htmlspecialchars($lang === 'es' ? $es : $en, ENT_XML1 | ENT_QUOTES).'</Say>';

        if ($step === 'menu' || $step === 'menu-es') {
            return match ($digits) {
                '1' => $this->dialAgent($call, 0, $say),
                '2' => $this->sendBooking($call, $contact, $lead, $lang) ? self::respond($say('We just sent you a link to pick a time with an agent. Thank you, goodbye.', 'Le acabamos de enviar un enlace para escoger una hora con un agente. Gracias, adiós.'))
                    : self::respond($say('An agent will reach out to set a time. Thank you, goodbye.', 'Un agente se comunicará para fijar una hora. Gracias, adiós.')),
                '3' => $step === 'menu' ? $this->spanishMenu($call, $lead, $contact) : self::respond(''),
                '9' => $this->stopCalls($call, $contact, $say),
                default => self::respond($say('Thank you. An agent will be in touch. Goodbye.', 'Gracias. Un agente se comunicará. Adiós.')),
            };
        }
        if (preg_match('/^(whisper|accept|next)-(\d)(-es)?$/', $step, $m) !== 1) {
            abort(404);
        }
        $n = (int) $m[2];
        $agents = (array) json_decode((string) $call->agents, true);
        $agent = isset($agents[$n]) ? User::query()->find((int) $agents[$n]) : null;

        return match ($m[1]) {
            'whisper' => $this->whisper($call, $lead, $contact, $n),
            'accept' => $digits === '1' && $agent !== null ? $this->accepted($call, $contact, $lead, $agent) : '<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>',
            // The call to agent N ended: done if they took it, otherwise the next agent (or the booking link).
            default => ($this->find($ulid)->status ?? '') === 'transferred' ? '<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>' : $this->dialAgent($call, $n + 1, $say),
        };
    }

    /** The one-screen hand-off line the agent hears before joining, and sees on the lead. */
    public static function handoff(Lead $lead, Contact $contact): string
    {
        return trim($contact->first_name.' '.$contact->last_name).', '.SpeedToLead::summary($lead).'.';
    }

    public function find(string $ulid): ?stdClass
    {
        return DB::connection(Connections::cell())->table('crm_ai_calls')->where('ulid', $ulid)->where('tenant_id', $this->tenants->id())->first();
    }

    /**
     * Calls this month and how they ended, for the settings page.
     *
     * @return array{made: int, transferred: int, booked: int, skipped: int, cap: int}
     */
    public function month(): array
    {
        $rows = DB::connection(Connections::cell())->table('crm_ai_calls')->where('tenant_id', $this->tenants->id())->where('created_at', '>=', CarbonImmutable::now()->startOfMonth())
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $n = fn (string $s): int => (int) ($rows[$s] ?? 0);

        return ['made' => (int) $rows->sum() - $n('skipped'), 'transferred' => $n('transferred'), 'booked' => $n('booked'), 'skipped' => $n('skipped'),
            'cap' => app(AiResponder::class)->settings()['voice_monthly_cap']];
    }

    /** @param  array<string, mixed>  $values */
    public function update(stdClass $call, array $values): void
    {
        DB::connection(Connections::cell())->table('crm_ai_calls')->where('id', $call->id)->update($values + ['updated_at' => now()]);
    }

    /**
     * Sends the link to pick a time: by text with written consent, otherwise by email.
     */
    public function sendBooking(stdClass $call, Contact $contact, Lead $lead, string $lang): bool
    {
        $link = app(SpeedToLead::class)->link(URL::temporarySignedRoute('crm.leads.book', CarbonImmutable::now()->addDays(7), ['call' => $call->ulid], false));
        $brand = app(AiResponder::class)->settings()['brand_name'];
        $text = $lang === 'es' ? "{$brand}: escoja una hora para hablar con un agente: {$link} Responda STOP para no recibir mensajes."
            : "{$brand}: pick a time to talk with an agent: {$link} Reply STOP to opt out.";
        try {
            if ($this->consent->canAutoText($contact) && SendGuard::quietHours((string) $contact->primary_phone) === null && $this->messaging->smsFor($this->tenants->id())) {
                app(SmsSender::class)->send((string) $contact->primary_phone, $text);
            } elseif ($contact->primary_email !== null) {
                Notification::route('mail', $contact->primary_email)->notify(new BookingLinkMail($brand, $contact->first_name, $link, $lang));
            } else {
                return false;
            }
        } catch (Throwable $e) {
            report($e);

            return false;
        }
        $this->update($call, ['status' => 'booking_sent']);
        $this->timeline->system($contact, 'AI assistant sent a link to pick a time.');

        return true;
    }

    /** @param  callable(string, string): string  $say */
    private function dialAgent(stdClass $call, int $n, callable $say): string
    {
        $agents = (array) json_decode((string) $call->agents, true);
        $agent = isset($agents[$n]) && $n < self::MAX_AGENTS ? User::query()->find((int) $agents[$n]) : null;
        $contact = Contact::query()->find((int) $call->contact_id);
        $lead = Lead::query()->find((int) $call->lead_id);
        if ($agent === null || $agent->phone === null || $agent->phone === '') {
            $sent = $contact !== null && $lead !== null && $this->sendBooking($call, $contact, $lead, (string) $call->lang);
            if ($contact !== null && $lead !== null) {
                $owner = User::query()->find((int) ($agents[0] ?? 0));
                if ($owner !== null) {
                    app(SpeedToLead::class)->textAgent($owner, 'RightAlly: '.self::handoff($lead, $contact).' Nobody could take the AI transfer; call them back.');
                }
                $this->timeline->system($contact, 'AI transfer: no agent could take the call'.($sent ? '; a link to pick a time was sent.' : '.'));
            }
            $this->update($call, ['status' => $sent ? 'booking_sent' : 'no_answer', 'reason' => 'No agent could take the transfer.']);

            return self::respond($say($sent ? 'Our agents are with clients right now. We just sent you a link to pick a time. Goodbye.' : 'Our agents are with clients right now. One will call you back soon. Goodbye.',
                $sent ? 'Nuestros agentes están ocupados. Le enviamos un enlace para escoger una hora. Adiós.' : 'Nuestros agentes están ocupados. Uno le llamará pronto. Adiós.'));
        }
        $this->update($call, ['status' => 'transferring', 'tried' => $n + 1]);
        $from = htmlspecialchars($this->messaging->voiceFrom(), ENT_XML1 | ENT_QUOTES);
        $to = htmlspecialchars($agent->phone, ENT_XML1 | ENT_QUOTES);
        $whisper = htmlspecialchars($this->url($call, "whisper-{$n}"), ENT_XML1 | ENT_QUOTES);
        $next = htmlspecialchars($this->url($call, "next-{$n}"), ENT_XML1 | ENT_QUOTES);

        return self::respond($say('Connecting you with an agent now. One moment please.', 'Le comunico con un agente. Un momento por favor.')
            ."<Dial callerId=\"{$from}\" timeout=\"20\" action=\"{$next}\" method=\"POST\"><Number url=\"{$whisper}\" method=\"POST\">{$to}</Number></Dial>");
    }

    private function whisper(stdClass $call, Lead $lead, Contact $contact, int $n): string
    {
        $line = htmlspecialchars('RightAlly transfer: '.self::handoff($lead, $contact).' Press 1 to take the call.', ENT_XML1 | ENT_QUOTES);
        $accept = htmlspecialchars($this->url($call, "accept-{$n}"), ENT_XML1 | ENT_QUOTES);

        return self::respond("<Gather numDigits=\"1\" timeout=\"6\" action=\"{$accept}\" method=\"POST\"><Say voice=\"Polly.Joanna\">{$line}</Say></Gather><Hangup/>");
    }

    private function accepted(stdClass $call, Contact $contact, Lead $lead, User $agent): string
    {
        $this->update($call, ['status' => 'transferred', 'agent_id' => $agent->id]);
        $this->timeline->system($contact, "AI assistant transferred the call to {$agent->name}.", $agent->id);
        if ($lead->assigned_user_id === $agent->id) {
            app(SpeedToLead::class)->responded($lead, $agent->id);
        }

        return self::respond(''); // empty: the agent is joined to the call
    }

    private function spanishMenu(stdClass $call, Lead $lead, Contact $contact): string
    {
        $this->update($call, ['lang' => 'es']);
        $s = app(AiResponder::class)->settings();
        $text = htmlspecialchars($this->script($lead, $contact, 'es', $s), ENT_XML1 | ENT_QUOTES);
        $action = htmlspecialchars($this->url($call, 'menu-es'), ENT_XML1 | ENT_QUOTES);
        $v = $this->voice('es');

        return self::respond("<Gather numDigits=\"1\" timeout=\"8\" action=\"{$action}\" method=\"POST\"><Say voice=\"{$v}\">{$text}</Say></Gather><Say voice=\"{$v}\">Adiós.</Say>");
    }

    /** @param  callable(string, string): string  $say */
    private function stopCalls(stdClass $call, Contact $contact, callable $say): string
    {
        $this->consent->setDoNotCall($contact, true, null, ['source' => 'agent', 'ref' => 'ai_call_keypad']);
        $this->update($call, ['status' => 'stopped', 'reason' => 'They pressed 9: no more calls.']);
        $this->timeline->system($contact, 'They asked for no more calls (pressed 9 on the assistant call). Do Not Call is on.');

        return self::respond($say('You will not get more calls from us. Goodbye.', 'No recibirá más llamadas de nosotros. Adiós.'));
    }

    /** @param  array<string, mixed>  $s */
    private function script(Lead $lead, Contact $contact, string $lang, array $s): string
    {
        $first = $contact->first_name !== '' ? $contact->first_name : ($lang === 'es' ? 'hola' : 'there');
        $about = $lead->property !== null ? $lead->property : null;
        if ($lang === 'es') {
            return "Hola {$first}, le habla {$s['assistant_name']}, asistente de inteligencia artificial de {$s['brand_name']}. Gracias por su interés"
                .($about !== null ? " en {$about}" : '').'. Para hablar ahora con un agente, oprima 1. Para recibir un enlace y escoger una hora, oprima 2. Para no recibir más llamadas, oprima 9.';
        }
        $what = $about ?? match ($lead->intent) {
            'sell' => 'selling your home', 'rent' => 'renting', default => 'finding a home'
        };

        return "Hi {$first}, this is {$s['assistant_name']}, an AI assistant calling for {$s['brand_name']}. Thanks for your interest in {$what}. "
            .'To talk with an agent now, press 1. To get a link to pick a time, press 2. '.($s['voice_language'] === 'both' ? 'Para español, oprima 3. ' : '').'To stop these calls, press 9.';
    }

    private function voice(string $lang): string
    {
        $gender = app(AiResponder::class)->settings()['voice'] === 'male' ? 'male' : 'female';

        return self::VOICES[$lang === 'es' ? 'es' : 'en'][$gender];
    }

    private function url(stdClass $call, string $step): string
    {
        return app(SpeedToLead::class)->link(URL::temporarySignedRoute('crm.leads.ai-call', CarbonImmutable::now()->addMinutes(30), ['call' => $call->ulid, 'step' => $step], false));
    }

    /**
     * The lead's agent first, then up to two more who take lead calls (with a phone and call alerts on).
     *
     * @return list<int>
     */
    private function agentsFor(Lead $lead): array
    {
        $first = $lead->assigned_user_id !== null ? [(int) $lead->assigned_user_id] : [];
        $others = DB::connection(Connections::cell())->table('crm_lead_alert_prefs')->where('tenant_id', $this->tenants->id())->whereNotNull('calls_consent_at')
            ->whereNotIn('user_id', $first === [] ? [0] : $first)->orderBy('user_id')->pluck('user_id')->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => ($u = User::query()->find($id)) !== null && $u->isActive() && $u->phone !== null && $u->phone !== '')->values()->all();

        return array_slice([...$first, ...$others], 0, self::MAX_AGENTS);
    }

    private static function respond(string $inner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Response>'.$inner.'</Response>';
    }
}
