<?php

declare(strict_types=1);

namespace Modules\Movewise\Matching;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Billing\Credits\CreditLedger;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Support\Connections as ControlDb;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Booking\Availability;
use Modules\Crm\Enums\Source;
use Modules\Crm\Leads\LeadIntake;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadSource;
use Modules\Movewise\Bridge\Relay;
use Modules\Movewise\Mail\PersonUpdate;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Connection;
use Modules\Movewise\Models\Package;
use Modules\Movewise\Models\Person;
use Modules\Movewise\Models\State;
use Modules\Movewise\Notifications\IntroductionClosed;
use Modules\Movewise\Notifications\IntroductionOffered;
use Modules\Movewise\Program\Pricing;

/**
 * Choosing an agent and being introduced (specs/movewise/00 §4.3–4.5, 04).
 *
 * - The person picks one of the three agents and one way to be contacted, from their verified mobile. That tap is
 *   the consent, saved word for word. The other two agents are not told and not charged.
 * - The chosen agent has until the accept deadline (AcceptClock). Accepting charges the state's per-connection price
 *   (once, keyed by the connection) and puts the person in the agent's CRM with the brief.
 * - If they don't accept in time nobody is charged, and the person chooses again; the lead never passes to another
 *   agent without the person's choice.
 * - Refunds: the person says they never asked (automatic), or the agent reports a wrong number (HQ decides).
 */
final class Connections
{
    public const LEAD_SOURCE = 'Movewise';

    public function __construct(private readonly TenantContext $tenants) {}

    /** @param  array<string, mixed>  $in  what the person tells us at "Talk to an agent" */
    public function about(Person $p, array $in): Person
    {
        $type = in_array($in['lead_type'] ?? null, ['buyer', 'seller'], true) ? (string) $in['lead_type'] : null;
        $first = mb_substr(trim(strip_tags((string) ($in['first_name'] ?? ''))), 0, 80);
        if ($type === null || $first === '') {
            throw ValidationException::withMessages(['about' => 'Tell us your first name and whether you are buying or selling.']);
        }
        $about = ['lead_type' => $type, 'timeline' => array_key_exists((string) ($in['timeline'] ?? ''), Readiness::TIMELINES) ? (string) $in['timeline'] : null,
            'preapproved' => in_array($in['preapproved'] ?? null, ['yes', 'no', 'unsure'], true) ? (string) $in['preapproved'] : null,
            'selling_first' => (bool) ($in['selling_first'] ?? false), 'language' => ($in['language'] ?? 'en') === 'es' ? 'es' : 'en'];
        if ($type === 'seller') {
            $zip = (string) ($in['home_zip'] ?? '');
            if (preg_match('/^\d{5}$/', $zip) !== 1) {
                throw ValidationException::withMessages(['home_zip' => 'Enter your home\'s ZIP code.']);
            }
            $about += ['home_zip' => $zip, 'home_city' => mb_substr(trim(strip_tags((string) ($in['home_city'] ?? ''))), 0, 60) ?: null,
                'home_state' => strtoupper((string) ($in['home_state'] ?? '')), 'home_price' => max(0, (int) preg_replace('/\D/', '', (string) ($in['home_price'] ?? '0'))) ?: null];
        }
        $p->forceFill(['first_name' => $first, 'about' => $about, 'has_agent' => (bool) ($in['has_agent'] ?? false)])->save();
        $p->forceFill(['readiness' => Readiness::for($p)['score']])->save();

        return $p;
    }

    public function phoneStart(Person $p, string $raw): void
    {
        $phone = ManageContacts::e164($raw);
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'Enter a US mobile number.']);
        }
        $db = DB::connection(ControlDb::control())->table('movewise_phone_codes');
        if ($db->where('person_id', $p->id)->where('created_at', '>=', CarbonImmutable::now()->subHour())->count() >= 5) {
            throw ValidationException::withMessages(['phone' => 'Too many codes. Try again in an hour.']);
        }
        $code = (string) random_int(100000, 999999);
        $db->insert(['person_id' => $p->id, 'phone_hash' => hash('sha256', $phone), 'code_hash' => hash('sha256', $code), 'expires_at' => CarbonImmutable::now()->addMinutes(10),
            'created_at' => CarbonImmutable::now()]);
        $p->forceFill(['phone' => $phone, 'phone_hash' => hash('sha256', $phone), 'phone_verified_at' => null])->save();
        app(SmsSender::class)->send($phone, 'Your Movewise code is '.$code.'. It works for 10 minutes. Movewise never asks for it by phone.');
    }

    public function phoneVerify(Person $p, string $code): void
    {
        $db = DB::connection(ControlDb::control())->table('movewise_phone_codes');
        $row = $db->where('person_id', $p->id)->where('phone_hash', (string) $p->phone_hash)->where('expires_at', '>', CarbonImmutable::now())->where('attempts', '<', 5)->orderByDesc('id')->first();
        if ($row === null || ! hash_equals((string) $row->code_hash, hash('sha256', (string) preg_replace('/\D/', '', $code)))) {
            if ($row !== null) {
                $db->where('id', $row->id)->increment('attempts');
            }
            throw ValidationException::withMessages(['code' => 'That code isn\'t right, or it has expired.']);
        }
        $db->where('person_id', $p->id)->delete();
        $p->forceFill(['phone_verified_at' => CarbonImmutable::now()])->save();
        $p->forceFill(['readiness' => Readiness::for($p)['score']])->save();
    }

    /** The open introduction the person already has for this kind of move, if any. */
    public function open(Person $p, ?string $leadType = null): ?Connection
    {
        return Connection::query()->where('person_id', $p->id)->whereIn('status', ['offered', 'accepted'])
            ->when($leadType !== null, fn ($q) => $q->where('lead_type', $leadType))->orderByDesc('id')->first();
    }

    /** The person chooses one agent from the three shown, and how to be contacted. This is the consent. */
    public function choose(Person $p, string $showUlid, string $agentUlid, string $method, ?string $slot, ?string $ip): Connection
    {
        $show = DB::connection(ControlDb::control())->table('movewise_shows')->where('ulid', $showUlid)->where('person_id', $p->id)->first() ?? abort(404);
        $agent = Agent::query()->where('ulid', $agentUlid)->firstOrFail();
        $pick = collect((array) json_decode((string) $show->agents, true))->firstWhere('agent', $agent->id) ?? abort(404);
        $context = (array) json_decode((string) $show->context, true);
        $package = Package::query()->findOrFail((int) $pick['package']);
        if ($p->phone_verified_at === null || $p->phone === null) {
            throw ValidationException::withMessages(['phone' => 'Verify your mobile number first.']);
        }
        if (! in_array($method, ['text', 'call', 'time'], true)) {
            throw ValidationException::withMessages(['method' => 'Choose text, call or a time.']);
        }
        if ($this->open($p, (string) $context['lead_type']) !== null) {
            throw ValidationException::withMessages(['agent' => 'You already have an agent for this. Change agent from My Movewise.']);
        }
        if ($agent->status !== 'active' || $package->status !== 'active') {
            throw ValidationException::withMessages(['agent' => 'That agent isn\'t taking introductions right now. Please choose another.']);
        }
        $tenant = Tenant::query()->findOrFail($agent->tenant_id);
        $slotAt = null;
        if ($method === 'time') {
            $slotAt = $slot !== null && $slot !== '' ? CarbonImmutable::parse($slot) : null;
            $open = $this->slots($agent);
            if ($slotAt === null || ! collect($open)->contains(fn (CarbonImmutable $s): bool => $s->equalTo($slotAt))) {
                throw ValidationException::withMessages(['slot' => 'Choose one of the times shown.']);
            }
            $slotAt = $slotAt->utc(); // stored in UTC, like every other time
        }
        $name = (string) ($agent->profile['display_name'] ?? 'the agent');
        $how = match ($method) {
            'text' => 'text me',
            'call' => 'call me',
            default => 'call me for our appointment on '.$slotAt->setTimezone($package->hours['tz'])->format('l j F \a\t g:i a T').', and text me about it',
        };
        $consent = 'I choose '.$name.' of '.$agent->brokerage_name.' (licence '.$agent->licence_number.') and agree that they may '.$how.' at '.self::mask((string) $p->phone)
            .' about '.($context['lead_type'] === 'seller' ? 'selling my home' : 'buying a home').'. Calls and texts may use automated technology. Agreeing isn\'t a condition of any purchase. '
            .'Message and data rates may apply. Reply STOP to stop texts. No other agent will contact me through Movewise.';
        $now = CarbonImmutable::now();
        $c = Connection::query()->create(['person_id' => $p->id, 'show_id' => (int) $show->id, 'agent_id' => $agent->id, 'package_id' => $package->id, 'tenant_id' => $tenant->id,
            'lead_type' => (string) $context['lead_type'], 'method' => $method, 'slot_at' => $slotAt, 'consent_text' => $consent, 'consent_at' => $now, 'consent_ip' => $ip,
            'status' => 'offered', 'offered_at' => $now, 'accept_by' => AcceptClock::deadline($package->hours, $now), 'model' => $package->model]);
        $this->tellAgent($c, fn (User $u) => $u->notify(new IntroductionOffered($this->agentLink($tenant, '/movewise/introductions/'.$c->ulid),
            ($c->lead_type === 'seller' ? 'a seller' : 'a buyer').(isset($context['city']) ? ' in '.$context['city'] : ''),
            $c->accept_by->setTimezone($package->hours['tz'])->format('g:i a T'))));

        return $c;
    }

    /** The agent accepts: the charge (once), then the person goes into their CRM with the brief. */
    public function accept(Connection $c, User $by): Connection
    {
        $agent = $this->guardAgent($c, $by);
        if ($c->status !== 'offered') {
            throw ValidationException::withMessages(['introduction' => 'This introduction is no longer waiting.']);
        }
        if (CarbonImmutable::now()->gt($c->accept_by)) {
            $this->expire($c);
            throw ValidationException::withMessages(['introduction' => 'The time to accept has passed. The person has been asked to choose again.']);
        }
        $package = Package::query()->findOrFail($c->package_id);
        $price = Pricing::upfront(State::query()->findOrFail($agent->state), $c->model, $c->lead_type);
        if ($price > 0) {
            try {
                app(CreditLedger::class)->charge('movewise.connection', $package->payer === 'agent' ? $package->payer_user_id : null, $c->chargeKey(), $price);
            } catch (InsufficientCredits) {
                throw ValidationException::withMessages(['introduction' => 'Not enough credits to accept ('.number_format($price).' needed). Top up and accept before the time runs out.']);
            }
        }
        $person = Person::query()->findOrFail($c->person_id);
        $brief = app(Brief::class)->for($c, true);
        $source = LeadSource::query()->firstOrCreate(['name' => self::LEAD_SOURCE, 'source' => Source::Other->value], ['kind' => 'api', 'token' => Str::random(40), 'created_by' => $by->id]);
        $lead = app(LeadIntake::class)->receive($source, ['first_name' => $person->first_name ?? '', 'email' => $person->email, 'phone' => $person->phone,
            'external_id' => 'movewise:'.$c->ulid, 'intent' => $c->lead_type === 'seller' ? 'sell' : 'buy', 'zip' => $brief['zip'], 'price' => $brief['price'],
            'property' => $brief['property'] ?? '', 'message' => $brief['summary'], 'sms_consent' => $c->method !== 'call', 'consent_text' => $c->consent_text,
            'landing_url' => 'https://'.config('movewise.host').'/', 'ai_handoff' => ['skip' => true, 'origin' => 'movewise', 'assistant' => '']], null, $by);
        if ($lead->contact !== null) {
            app(Timeline::class)->system($lead->contact, "Movewise introduction. The person chose you and agreed: \"{$c->consent_text}\"\n\n".$brief['transcript']);
            if ($c->method === 'time' && $c->slot_at !== null) {
                app(Availability::class)->book($by, $lead->contact, $c->slot_at, 'call', 'Movewise appointment the person booked');
            }
        }
        $c->forceFill(['status' => 'accepted', 'accepted_at' => CarbonImmutable::now(), 'price_credits' => $price, 'lead_id' => $lead->id, 'contact_id' => $lead->contact_id])->save();
        app(Relay::class)->assign($c, $person); // texts go through their Movewise number (MW5)
        app(AuditLogger::class)->record('movewise.connection.accepted', $c, metadata: ['credits' => $price, 'model' => $c->model], label: (string) ($person->first_name ?? ''));
        Mail::to($person->email)->send(new PersonUpdate($this->t($person, 'accepted_h', ['name' => (string) ($agent->profile['display_name'] ?? '')]),
            $this->t($person, 'accepted_b', ['name' => (string) ($agent->profile['display_name'] ?? ''), 'how' => $this->t($person, 'how_'.$c->method)]),
            [[$this->t($person, 'never_asked'), self::signedUrl('/connect/withdraw/'.$c->ulid)], [$this->t($person, 'my_movewise'), 'https://'.config('movewise.host').'/account']],
            (string) $person->locale));

        return $c;
    }

    public function decline(Connection $c, User $by): Connection
    {
        $this->guardAgent($c, $by);
        if ($c->status !== 'offered') {
            throw ValidationException::withMessages(['introduction' => 'This introduction is no longer waiting.']);
        }
        $c->forceFill(['status' => 'declined', 'closed_at' => CarbonImmutable::now()])->save();
        $this->askAgain($c, 'declined');

        return $c;
    }

    /** Offers not accepted in time expire: nobody is charged, and the person is asked to choose again. */
    public function expireDue(): int
    {
        $n = 0;
        foreach (Connection::query()->where('status', 'offered')->where('accept_by', '<', CarbonImmutable::now())->orderBy('id')->limit(500)->get() as $c) {
            $this->expire($c);
            $n++;
        }

        return $n;
    }

    /** "Keep waiting for them": the same agent gets a fresh deadline (once). */
    public function wait(Connection $c, Person $p): Connection
    {
        abort_unless($c->person_id === $p->id, 404);
        if ($c->status !== 'expired' || $this->open($p, $c->lead_type) !== null || $c->refund_reason === 'waited') {
            throw ValidationException::withMessages(['introduction' => 'You can wait once. Please choose another agent, or try again later.']);
        }
        $package = Package::query()->findOrFail($c->package_id);
        $c->forceFill(['status' => 'offered', 'accept_by' => AcceptClock::deadline($package->hours, CarbonImmutable::now()), 'closed_at' => null, 'refund_reason' => 'waited'])->save();
        $tenant = Tenant::query()->findOrFail($c->tenant_id);
        $this->tellAgent($c, fn (User $u) => $u->notify(new IntroductionOffered($this->agentLink($tenant, '/movewise/introductions/'.$c->ulid), 'the person is still waiting for you',
            $c->accept_by->setTimezone($package->hours['tz'])->format('g:i a T'))));

        return $c;
    }

    /** The person says they never asked to be contacted: the introduction ends and the charge is refunded in full. */
    public function withdraw(Connection $c, string $reason): Connection
    {
        if (! in_array($c->status, ['offered', 'accepted'], true)) {
            return $c;
        }
        $refund = $c->status === 'accepted' && $c->price_credits > 0;
        $this->inTenant($c, function () use ($c, $refund): void {
            if ($refund) {
                app(CreditLedger::class)->refund($c->chargeKey(), 'Movewise: the person says they never asked');
            }
            $this->noteOnContact($c, 'Movewise: the person ended this introduction. Please don\'t contact them again about it.');
        });
        $c->forceFill(['status' => 'withdrawn', 'closed_at' => CarbonImmutable::now(), 'refund_status' => $refund ? 'refunded' : null, 'refund_reason' => mb_substr($reason, 0, 300)])->save();
        $this->tellAgent($c, fn (User $u) => $u->notify(new IntroductionClosed('A Movewise introduction has ended', 'The person ended the introduction. '
            .($refund ? 'Your credits for it have been refunded. ' : '').'Please don\'t contact them again about it.', $this->agentLink(Tenant::query()->findOrFail($c->tenant_id), '/movewise'))));
        app(AuditLogger::class)->record('movewise.connection.withdrawn', $c, metadata: ['refunded' => $refund], tenantId: $c->tenant_id);

        return $c;
    }

    /** The agent reports a problem (wrong number, never asked) within the refund window; HQ decides. */
    public function report(Connection $c, User $by, string $reason): Connection
    {
        $this->guardAgent($c, $by);
        $reason = trim(mb_substr($reason, 0, 300));
        if ($c->status !== 'accepted' || $c->price_credits === 0 || $c->refund_status !== null) {
            throw ValidationException::withMessages(['refund' => 'This introduction can\'t be refunded.']);
        }
        if ($c->accepted_at?->lt(CarbonImmutable::now()->subDays((int) config('movewise.refund_days', 7))) ?? true) {
            throw ValidationException::withMessages(['refund' => 'Refunds can be asked for within '.config('movewise.refund_days', 7).' days of accepting.']);
        }
        if ($reason === '') {
            throw ValidationException::withMessages(['refund' => 'Say what went wrong (for example, the number isn\'t theirs).']);
        }
        $c->forceFill(['refund_status' => 'requested', 'refund_reason' => $reason])->save();

        return $c;
    }

    public function resolve(Connection $c, bool $refund, User $hq): Connection
    {
        if ($c->refund_status !== 'requested') {
            throw ValidationException::withMessages(['refund' => 'No refund is waiting on this introduction.']);
        }
        if ($refund) {
            $this->inTenant($c, fn () => app(CreditLedger::class)->refund($c->chargeKey(), 'Movewise refund: '.$c->refund_reason));
        }
        $c->forceFill(['refund_status' => $refund ? 'refunded' : 'denied'])->save();
        $this->tellAgent($c, fn (User $u) => $u->notify(new IntroductionClosed($refund ? 'Movewise refunded an introduction' : 'Movewise refund request', $refund
            ? 'We refunded the credits for an introduction you reported.' : 'We looked into the introduction you reported and couldn\'t refund it. Reply to this email if you have more to add.',
            $this->agentLink(Tenant::query()->findOrFail($c->tenant_id), '/movewise'))));
        app(AuditLogger::class)->record('movewise.connection.refund_'.($refund ? 'granted' : 'denied'), $c, metadata: ['by' => $hq->id], tenantId: $c->tenant_id);

        return $c;
    }

    /** @return list<CarbonImmutable> the agent's open appointment times (their real calendar, from the booking engine) */
    public function slots(Agent $agent): array
    {
        return $this->inTenantOf($agent->tenant_id, function () use ($agent): array {
            $u = User::query()->find($agent->user_id);

            return $u !== null ? app(Availability::class)->slots($u, 'call', 6, 8) : [];
        });
    }

    public static function mask(string $phone): string
    {
        return '(***) ***-'.substr($phone, -4);
    }

    /** A link that needs no sign-in (the "I didn't ask for this" link in emails). */
    public static function signedUrl(string $path): string
    {
        return 'https://'.config('movewise.host').$path.'/'.substr(hash_hmac('sha256', 'movewise:'.$path, (string) config('app.key')), 0, 32);
    }

    public static function checkSigned(string $path, string $sig): bool
    {
        return hash_equals(substr(hash_hmac('sha256', 'movewise:'.$path, (string) config('app.key')), 0, 32), $sig);
    }

    private function expire(Connection $c): void
    {
        $c->forceFill(['status' => 'expired', 'closed_at' => CarbonImmutable::now()])->save();
        $this->askAgain($c, 'expired');
    }

    /** "Jane hasn't been able to reply yet": the person chooses another of the three, waits, or stops. */
    private function askAgain(Connection $c, string $why): void
    {
        $person = Person::query()->find($c->person_id);
        $agent = Agent::query()->find($c->agent_id);
        if ($person === null || $agent === null) {
            return;
        }
        $show = DB::connection(ControlDb::control())->table('movewise_shows')->where('id', $c->show_id)->value('ulid');
        $name = (string) ($agent->profile['display_name'] ?? '');
        Mail::to($person->email)->send(new PersonUpdate($this->t($person, $why.'_h', ['name' => $name]), $this->t($person, $why.'_b', ['name' => $name]),
            array_filter([[$this->t($person, 'choose_other'), 'https://'.config('movewise.host').($person->locale === 'es' ? '/es' : '').'/connect/'.$show],
                $why === 'expired' ? [$this->t($person, 'wait', ['name' => $name]), 'https://'.config('movewise.host').($person->locale === 'es' ? '/es' : '').'/account'] : null]),
            (string) $person->locale));
    }

    private function guardAgent(Connection $c, User $by): Agent
    {
        abort_unless($this->tenants->id() === $c->tenant_id, 404);
        $agent = Agent::query()->findOrFail($c->agent_id);
        abort_unless($agent->user_id === $by->id, 403);

        return $agent;
    }

    private function tellAgent(Connection $c, callable $fn): void
    {
        $agent = Agent::query()->find($c->agent_id);
        if ($agent === null) {
            return;
        }
        $this->inTenantOf($agent->tenant_id, function () use ($agent, $fn): void {
            $u = User::query()->find($agent->user_id);
            if ($u !== null) {
                $fn($u);
            }
        });
    }

    private function noteOnContact(Connection $c, string $note): void
    {
        if ($c->lead_id === null) {
            return;
        }
        $lead = Lead::query()->find($c->lead_id);
        if ($lead?->contact !== null) {
            app(Timeline::class)->system($lead->contact, $note);
        }
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $fn
     * @return T
     */
    private function inTenant(Connection $c, \Closure $fn): mixed
    {
        return $this->inTenantOf($c->tenant_id, $fn);
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $fn
     * @return T
     */
    private function inTenantOf(int $tenantId, \Closure $fn): mixed
    {
        if ($this->tenants->check() && $this->tenants->id() === $tenantId) {
            return $fn();
        }

        return $this->tenants->runAs(Tenant::query()->findOrFail($tenantId), $fn);
    }

    private function agentLink(Tenant $tenant, string $path): string
    {
        $host = $tenant->primaryDomain()?->host;

        return ($host !== null ? 'https://'.$host : rtrim((string) config('app.url'), '/')).$path;
    }

    /** @param  array<string, string>  $r */
    private function t(Person $p, string $key, array $r = []): string
    {
        $es = $p->locale === 'es';
        $text = match ($key) {
            'accepted_h' => $es ? ':name aceptó presentarse contigo' : ':name will be in touch',
            'accepted_b' => $es ? ':name aceptó y te va a :how pronto. Nadie más te contactará a través de Movewise.' : ':name accepted and will :how shortly. Nobody else will contact you through Movewise.',
            'how_text' => $es ? 'enviar un mensaje de texto' : 'text you',
            'how_call' => $es ? 'llamar' : 'call you',
            'how_time' => $es ? 'confirmar tu cita' : 'confirm your appointment',
            'never_asked' => $es ? '¿No pediste esto? Termínalo aquí.' : 'Didn\'t ask for this? End it here.',
            'my_movewise' => $es ? 'Mi Movewise' : 'My Movewise',
            'expired_h' => $es ? ':name todavía no ha podido responder' : ':name hasn\'t been able to reply yet',
            'expired_b' => $es ? 'Nadie te ha contactado y nadie lo hará sin que tú elijas. ¿Quieres elegir a otro agente o esperar a :name?' : 'Nobody has been told about you, and nobody will be without your choice. Would you like to choose another agent, or wait for :name?',
            'declined_h' => $es ? ':name no puede tomar tu solicitud ahora' : ':name can\'t take this on right now',
            'declined_b' => $es ? 'Nadie más ha sido informado. Puedes elegir a otro de los agentes.' : 'Nobody else has been told about you. You can choose one of the other agents.',
            'choose_other' => $es ? 'Elegir a otro agente' : 'Choose another agent',
            'wait' => $es ? 'Esperar a :name' : 'Wait for :name',
            default => $key,
        };
        foreach ($r as $k => $v) {
            $text = str_replace(':'.$k, $v, $text);
        }

        return $text;
    }
}
