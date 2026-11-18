<?php

declare(strict_types=1);

namespace Modules\Sites\Ailly;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Data\RetrievedRecord;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Booking\Availability;
use Modules\Crm\Leads\LeadAlert;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Task;
use Modules\Listings\Search\ListingSearch;
use Modules\Sites\Ai\ContentStudio;
use Modules\Sites\Jobs\WriteClosingBrief;
use Modules\Sites\Mail\BookingConfirmedMail;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SiteEvent;
use Modules\Sites\Models\SiteVisitor;
use Modules\Sites\Visitors\JourneySummary;
use Modules\Sites\Visitors\SiteLeads;
use Modules\Sites\Visitors\Valuation;
use Modules\Sites\Visitors\Visitors;
use Throwable;

/**
 * AIlly, the website assistant (specs/websites/11). Answers visitors' questions, shows matching homes from the MLS,
 * asks for contact details when the brokerage chose, and ends every answer with a next step (book a call, see
 * homes, save the search, value my home). Every captured visitor becomes a lead on the brokerage's (or agent's) CRM.
 *
 * Keeping credits for real people:
 * - bots, too-fast requests and visitors past their daily message limit get no AI;
 * - each brokerage has a daily cap on new conversations and a monthly cap from its package;
 * - a common first question already answered on this website in the last day is answered again for free;
 * - after two off-topic messages the conversation ends politely;
 * - without credits (or if the AI is unavailable), AIlly asks for details so an agent replies instead.
 */
final class Assistant
{
    public const FEATURE = 'sites.ailly';

    private const MAX_MESSAGES = 30;

    private const SCHEMA = ['type' => 'object', 'required' => ['reply', 'on_topic', 'next_step'], 'properties' => [
        'reply' => ['type' => 'string'], 'on_topic' => ['type' => 'boolean'], 'next_step' => ['type' => 'string'], 'lead_score' => ['type' => 'integer'],
        'answered' => ['type' => 'boolean'],
        'valuation' => ['type' => ['object', 'null'], 'properties' => ['zip' => ['type' => ['string', 'null']], 'beds' => ['type' => ['integer', 'null']], 'sqft' => ['type' => ['integer', 'null']]]],
        'search' => ['type' => ['object', 'null'], 'properties' => ['q' => ['type' => ['string', 'null']], 'min_price' => ['type' => ['integer', 'null']],
            'max_price' => ['type' => ['integer', 'null']], 'beds' => ['type' => ['integer', 'null']], 'baths' => ['type' => ['integer', 'null']]]]]];

    private const NEXT_STEPS = ['book_call', 'book_listing', 'see_homes', 'save_search', 'home_value', 'none'];

    public function __construct(private readonly AiGateway $gateway, private readonly TenantContext $tenants, private readonly Visitors $visitors,
        private readonly ListingSearch $search, private readonly ContentStudio $studio) {}

    public static function brokerageSite(): ?Site
    {
        return Site::query()->where('kind', 'brokerage')->where('status', 'live')->first();
    }

    /**
     * Opens a conversation (or refuses: bots, the daily or monthly cap).
     *
     * @return array{conversation: string, greeting: string, name: string, needs_contact: bool}
     */
    public function start(Site $site, Request $request): array
    {
        $settings = AillySettings::for(self::brokerageSite());
        if (! $settings['enabled'] || self::isBot($request)) {
            throw ValidationException::withMessages(['ailly' => 'Unavailable.']);
        }
        $db = DB::connection(Connections::cell())->table('ailly_conversations')->where('tenant_id', $this->tenants->id());
        $today = (clone $db)->where('created_at', '>=', CarbonImmutable::now()->startOfDay())->count();
        $month = (clone $db)->where('created_at', '>=', CarbonImmutable::now()->startOfMonth())->count();
        if ($today >= $settings['daily_cap'] || $month >= $this->monthlyCap()) {
            throw ValidationException::withMessages(['ailly' => 'Busy.']);
        }
        $vid = $request->input('vid');
        $visitor = Visitors::validVid($vid) ? $this->visitors->resolve($site, (string) $vid) : null;
        $ulid = (string) Str::ulid();
        $variant = Experiments::variant(self::brokerageSite(), $visitor !== null ? $visitor->vid : $ulid);
        DB::connection(Connections::cell())->table('ailly_conversations')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'site_id' => $site->id,
            'visitor_id' => $visitor?->id, 'variant' => $variant, 'page' => mb_substr((string) $request->input('page', ''), 0, 300), 'ip_hash' => hash('sha256', (string) $request->ip().config('app.key')),
            'created_at' => now(), 'updated_at' => now()]);
        $persona = AillySettings::persona($site, self::brokerageSite());
        $back = $visitor !== null ? $this->welcomeBack($visitor) : null;
        $seen = Experiments::apply(self::brokerageSite(), $variant, $settings, $persona['name']);
        $greeting = $back['text'] ?? $seen['greeting'];
        $this->say($ulid, 'ailly', $greeting);

        return ['conversation' => $ulid, 'greeting' => $greeting, 'name' => $persona['name'], 'needs_contact' => $seen['gate'] === 'before',
            'search_url' => $back['url'] ?? null];
    }

    /**
     * A returning visitor's greeting: how many homes are new (or reduced) for their last search since their previous
     * visit. Worked out in code, no AI.
     *
     * @return array{text: string, url: string}|null
     */
    private function welcomeBack(SiteVisitor $visitor): ?array
    {
        if ($visitor->visits < 2) {
            return null;
        }
        $last = SiteEvent::query()->where('visitor_id', $visitor->id)->where('type', 'search')->latest('id')->first();
        if ($last === null || ($last->created_at !== null && $last->created_at->gt(CarbonImmutable::now()->subHour()))) {
            return null; // no search yet, or one from this same visit
        }
        $f = array_filter((array) ($last->data ?? []), fn ($v, $k): bool => in_array($k, ['q', 'min_price', 'max_price', 'beds', 'baths'], true) && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH);
        $since = $last->created_at ?? CarbonImmutable::now()->subDays(7);
        $found = $this->search->search($this->tenants->id(), $f + ['since' => CarbonImmutable::parse($since)->toIso8601String(), 'per_page' => 1]);
        $about = JourneySummary::describeSearch($f);
        if ($found['total'] === 0) {
            return ['text' => 'Welcome back! Nothing new for '.($about ?: 'your last search').' since you looked. Want me to widen it, or set up alerts so you hear first?',
                'url' => '/homes?'.http_build_query($f)];
        }

        return ['text' => 'Welcome back! '.$found['total'].' '.($found['total'] === 1 ? 'home is' : 'homes are').' new or reduced for '.($about ?: 'your last search')
            .' since you looked. Want to see them, or tell me what to change?', 'url' => '/homes?'.http_build_query($f + ['sort' => 'newest'])];
    }

    /**
     * One visitor message and AIlly's answer.
     *
     * @return array{reply: string, next_step: string, homes: list<array<string, mixed>>, needs_contact: bool, ended: bool, value: array<string, mixed>|null, agent: bool}
     */
    public function reply(Site $site, string $conversation, string $text, Request $request): array
    {
        $c = $this->conversation($conversation);
        $settings = AillySettings::for(self::brokerageSite());
        $text = mb_substr(trim(strip_tags($text)), 0, 800);
        if ($text === '' || $c->status !== 'open' || self::isBot($request)) {
            throw ValidationException::withMessages(['message' => 'Unavailable.']);
        }
        $settings['gate'] = Experiments::apply(self::brokerageSite(), $c->variant, $settings, '')['gate'];
        if ($c->taken_by !== null) {
            // An agent has the chat: AIlly stays quiet and the agent hears about it.
            $this->say($conversation, 'visitor', $text);
            DB::connection(Connections::cell())->table('ailly_conversations')->where('id', $c->id)->increment('visitor_messages');
            app(LiveChat::class)->visitorWrote($c, $text);

            return $this->out('', 'none', [], false, agent: true);
        }
        $hasContact = $c->lead_id !== null;
        $answered = (int) $c->ai_replies + (int) $c->cached_replies;
        if (! $hasContact && ($settings['gate'] === 'before' || ($settings['gate'] === 'after' && $answered >= $settings['gate_after']))) {
            return $this->out('Before I go on, could you share your name, email and phone? Then an agent can follow up with exactly what you need.', 'none', [], true);
        }
        if ((int) $c->visitor_messages >= self::MAX_MESSAGES || $this->visitorMessagesToday($c) >= $settings['visitor_daily']) {
            return $this->out('I\'ve answered all I can in this chat. Leave your details and an agent will pick it up from here.', 'book_call', [], ! $hasContact);
        }
        $this->say($conversation, 'visitor', $text);
        DB::connection(Connections::cell())->table('ailly_conversations')->where('id', $c->id)->increment('visitor_messages');

        // A common first question already answered on this website today: free.
        $hash = hash('sha256', $site->id.'|'.self::normalise($text));
        if ((int) $c->visitor_messages === 0 && ($hit = $this->cached($site, $hash)) !== null) {
            DB::connection(Connections::cell())->table('ailly_conversations')->where('id', $c->id)->increment('cached_replies');
            $this->say($conversation, 'ailly', $hit['answer'], cached: true);

            return $this->out($hit['answer'], $hit['next_step'], [], false);
        }

        // The brokerage's own answer to this question: word for word, free.
        if (($own = app(BrokerageAnswers::class)->match($text)) !== null) {
            DB::connection(Connections::cell())->table('ailly_conversations')->where('id', $c->id)->increment('cached_replies');
            $this->say($conversation, 'ailly', $own, from: 'answers');

            return $this->out($own, 'none', [], false);
        }

        $transcript = $this->transcript((int) $c->id);
        $facts = $this->studio->facts($site, null);
        $info = [...$this->siteFacts($site), ...$this->homeFacts((string) $c->page)];
        try {
            $result = $this->gateway->run(new AiTask(self::FEATURE, 'sites.ailly', 'ailly:'.$conversation.':'.((int) $c->visitor_messages + 1),
                inputs: ['name' => AillySettings::persona($site, self::brokerageSite())['name'], 'brokerage' => $site->displayName(), 'area' => (string) ($site->info['area'] ?? $site->info['areas'] ?? 'the area'),
                    'today' => CarbonImmutable::now()->toFormattedDateString(), 'page' => (string) ($c->page ?: 'the home page'),
                    'contact' => $hasContact ? 'the visitor already shared them; do not ask again.'
                        : ($settings['gate'] === 'booking' ? 'only ask when they want to book a call or a showing.' : 'do not ask in your reply; the website asks at the right moment.')],
                context: [RetrievedRecord::fromModel($site, ['website_facts' => implode("\n", [...$info, ...$facts['lines']]),
                    'brokerage_answers' => app(BrokerageAnswers::class)->asContext()])],
                untrustedContent: [$transcript], outputSchema: self::SCHEMA));
            $json = (array) ($result->json ?? []);
        } catch (Throwable $e) {
            report($e);

            return $this->out('I can\'t answer that right now. Leave your name, email and phone and an agent will get back to you shortly.', 'book_call', [], ! $hasContact);
        }
        $homes = is_array($json['search'] ?? null) ? $this->homes($json['search']) : [];
        $value = is_array($json['valuation'] ?? null) ? $this->value($json['valuation']) : null;
        $allowed = ContentStudio::numbers($transcript.' '.implode(' ', [...$info, ...$facts['lines']]).' '.json_encode($homes).' '.json_encode($value));
        $allowed = [...$allowed, ...ContentStudio::numbers(app(BrokerageAnswers::class)->asContext())];
        [$reply, $removed] = ContentStudio::guard(mb_substr(trim((string) ($json['reply'] ?? '')), 0, 1200), $allowed, 'ailly');
        $reply = $reply !== '' ? $reply : 'I can help with homes and real estate here. What would you like to know?';
        $next = in_array($json['next_step'] ?? null, self::NEXT_STEPS, true) ? (string) $json['next_step'] : 'none';
        $onTopic = (bool) ($json['on_topic'] ?? true);
        $score = isset($json['lead_score']) && is_numeric($json['lead_score']) ? max(0, min(100, (int) $json['lead_score'])) : null;

        DB::connection(Connections::cell())->table('ailly_conversations')->where('id', $c->id)
            ->incrementEach(['ai_replies' => 1, 'off_topic' => $onTopic ? 0 : 1], ['lead_score' => $score ?? $c->lead_score, 'updated_at' => now()]);
        $this->say($conversation, 'ailly', $reply, $homes, flags: $removed);
        if ($onTopic && ($json['answered'] ?? true) === false) {
            app(BrokerageAnswers::class)->unanswered($text);
        }
        if ($score !== null && $hasContact) {
            $this->hotAlert((int) $c->id);
        }
        if (! $onTopic && (int) $c->off_topic >= 1) {
            DB::connection(Connections::cell())->table('ailly_conversations')->where('id', $c->id)->update(['status' => 'ended', 'ended_at' => now()]);

            return $this->out('I\'m only able to help with homes and real estate here. If you have a question about buying, selling or this area, start a new chat any time.', 'none', [], false, true);
        }
        if ((int) $c->visitor_messages === 0 && $onTopic && $homes === []) {
            $this->remember($site, $hash, $reply, $next);
        }

        return $this->out($reply, $value !== null && $next === 'none' ? 'book_listing' : $next, $homes, false, value: $value);
    }

    /**
     * A likely price range for the visitor's home from similar homes for sale in its ZIP (a starting point an agent
     * confirms with recent sales; the widget says so).
     *
     * @param  array<string, mixed>  $v
     * @return array{low: int, mid: int, high: int, comps: int, basis: string, zip: string}|null
     */
    private function value(array $v): ?array
    {
        $zip = is_string($v['zip'] ?? null) && preg_match('/^\d{5}$/', $v['zip']) === 1 ? $v['zip'] : null;
        if ($zip === null) {
            return null;
        }
        $e = app(Valuation::class)->estimate($this->tenants->id(), $zip, isset($v['beds']) ? (int) $v['beds'] : null, isset($v['sqft']) ? (int) $v['sqft'] : null);

        return $e !== null ? ['low' => $e['low'], 'mid' => $e['mid'], 'high' => $e['high'], 'comps' => $e['comps'], 'basis' => $e['basis'], 'zip' => $zip] : null;
    }

    /**
     * The visitor shared their details (or asked to book a call or a showing): a lead in the CRM, with the chat.
     *
     * @param  array<string, mixed>  $in
     */
    /**
     * The visitor shared their details, perhaps booking a time: a lead in the CRM with the chat, and, when they chose a
     * time, a call, showing or listing appointment in the agent's tasks with a confirmation email (calendar invite).
     *
     * @param  array<string, mixed>  $in
     * @return array{booked: string|null}
     */
    public function capture(Site $site, string $conversation, array $in, Request $request, SiteLeads $leads): array
    {
        $c = $this->conversation($conversation);
        $want = in_array($in['want'] ?? null, ['call', 'showing', 'listing'], true) ? (string) $in['want'] : null;
        $slot = null;
        $agent = null;
        if ($want !== null && is_string($in['slot'] ?? null) && $in['slot'] !== '') {
            [$agent, $slot] = $this->chosenSlot($site, (string) $in['slot'], (string) ($in['slot_agent'] ?? ''), $want);
        }
        $tz = app(Availability::class)->timezone();
        $when = $slot !== null ? $slot->setTimezone($tz)->format('D M j, g:i A T') : mb_substr((string) ($in['when'] ?? ''), 0, 120);
        $lines = [];
        if ($want === 'call') {
            $lines[] = ($slot !== null ? 'Booked a call' : 'Asked for a call').($when !== '' ? ': '.$when : '').'.';
        }
        if ($want === 'listing') {
            $lines[] = ($slot !== null ? 'Booked a listing appointment' : 'Asked about selling their home').($when !== '' ? ': '.$when : '').'.';
        }
        $home = null;
        if ($want === 'showing' && is_string($in['listing'] ?? null) && preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $in['listing']) === 1) {
            $home = $this->search->find($this->tenants->id(), $in['listing']);
            $lines[] = ($slot !== null ? 'Booked a showing of ' : 'Asked to see ').($home !== null ? ListingSearch::label($home).($home['mls_number'] ? ' (MLS# '.$home['mls_number'].')' : '') : 'a home')
                .($slot !== null ? ': '.$when : '').'.';
        }
        $persona = AillySettings::persona($site, self::brokerageSite())['name'];
        $lines[] = 'Chat with '.$persona.' on the website:';
        $lines[] = mb_substr($this->transcript((int) $c->id), -3000);
        $source = SiteLeads::source('AIlly chat', 'Website assistant');
        $lead = $leads->capture($site, $source, ['name' => (string) ($in['name'] ?? ''), 'email' => (string) ($in['email'] ?? ''), 'phone' => (string) ($in['phone'] ?? ''),
            'message' => mb_substr(implode("\n", $lines), 0, 4000), 'intent' => $want === 'showing' ? 'buy' : ($want === 'listing' ? 'sell' : (string) ($in['intent'] ?? '')),
            'landing_url' => (string) ($c->page ?? ''), 'sms_consent' => (bool) ($in['sms_consent'] ?? false),
            // Texts pick the chat up in the same name; a booked time needs no qualifying texts (the booking confirms it).
            'ai_handoff' => ['skip' => $slot !== null, 'origin' => 'website_chat', 'assistant' => $persona]], $request, 'ailly', $home['ulid'] ?? null, ['conversation' => $conversation], $agent);
        $contact = $lead->contact_id !== null ? Contact::query()->find($lead->contact_id) : null;
        if ($slot !== null) {
            $task = app(Availability::class)->book($agent, $contact instanceof Contact ? $contact : null, $slot, $want, implode("\n", array_slice($lines, 0, -2)));
            if ($contact instanceof Contact) {
                BookingTexts::confirm($task, $contact, $persona);
            }
            $email = (string) ($in['email'] ?? '');
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                Mail::to($email)->send(new BookingConfirmedMail($want, $slot, $tz, $agent->name, $site->displayName(), $home !== null ? ListingSearch::label($home) : null,
                    (string) ($agent->phone ?? '')));
            }
        }
        $outcome = match ($want) {
            'call' => 'call', 'listing' => 'listing', 'showing' => 'showing', default => 'lead'
        };
        $rank = ['none' => 0, 'lead' => 1, 'showing' => 2, 'call' => 3, 'listing' => 4];
        DB::connection(Connections::cell())->table('ailly_conversations')->where('id', $c->id)->update(['lead_id' => $lead->id,
            'outcome' => $rank[$outcome] >= ($rank[$c->outcome] ?? 0) ? $outcome : $c->outcome, 'updated_at' => now()]);
        $visitor = SiteVisitor::query()->where('contact_id', $lead->contact_id)->whereNull('merged_into')->orderBy('id')->first();
        if ($visitor !== null && $c->visitor_id !== null && $visitor->id !== (int) $c->visitor_id) {
            DB::connection(Connections::cell())->table('ailly_conversations')->where('id', $c->id)->update(['visitor_id' => $visitor->id]);
        }
        if ($visitor !== null) {
            WriteClosingBrief::dispatch($visitor->id, true);
        }
        $this->hotAlert((int) $c->id, $want !== null);
        $booked = $slot !== null ? $slot->setTimezone($tz)->format('l, F j \a\t g:i A T') : null;
        $with = $agent !== null ? $agent->first_name : 'an agent';
        $this->say($conversation, 'ailly', match (true) {
            $booked !== null && $want === 'showing' => 'You\'re booked to see the home on '.$booked.' with '.$with.'. A confirmation is on its way.',
            $booked !== null && $want === 'listing' => 'Your home value appointment is booked for '.$booked.' with '.$with.'. A confirmation is on its way.',
            $booked !== null => 'Your call is booked for '.$booked.' with '.$with.'. A confirmation is on its way.',
            $want === 'call' => 'Thanks! An agent will call you'.($when !== '' ? ' '.$when : ' soon').'.',
            $want === 'showing' => 'Thanks! An agent will be in touch to set up the showing.',
            $want === 'listing' => 'Thanks! An agent will be in touch about your home\'s value.',
            default => 'Thanks! I\'ve passed your details to an agent. What else can I help with?',
        });

        return ['booked' => $booked];
    }

    /**
     * Open times to book on this website: the agent's own on an agent's website; otherwise the booking team's, each
     * time offered by the agent with the fewest upcoming bookings.
     *
     * @return list<array{start: string, label: string, agent: string, agent_name: string}>
     */
    public function slots(Site $site, string $kind): array
    {
        $kind = isset(Availability::KINDS[$kind]) ? $kind : 'call';
        $booking = app(Availability::class);
        $tz = $booking->timezone();
        $all = [];
        foreach ($this->bookingAgents($site) as $agent) {
            $load = Task::query()->where('assigned_to', $agent->id)->whereNull('completed_at')->where('due_at', '>=', now())->count();
            foreach ($booking->slots($agent, $kind) as $t) {
                $key = $t->toIso8601String();
                if (! isset($all[$key]) || $all[$key]['load'] > $load) {
                    $all[$key] = ['t' => $t, 'agent' => $agent, 'load' => $load];
                }
            }
        }
        ksort($all);

        return array_values(array_map(fn (array $x): array => ['start' => $x['t']->toIso8601String(), 'label' => $x['t']->setTimezone($tz)->format('D M j, g:i A'),
            'agent' => (string) $x['agent']->ulid, 'agent_name' => (string) $x['agent']->first_name], array_slice($all, 0, 8)));
    }

    /** @return list<User> */
    private function bookingAgents(Site $site): array
    {
        if ($site->kind === 'agent') {
            $agent = User::query()->whereKey($site->user_id)->first();

            return $agent instanceof User ? [$agent] : [];
        }

        return app(Availability::class)->team(AillySettings::for(self::brokerageSite())['team']);
    }

    /**
     * The slot the visitor chose, checked against what this website offers.
     *
     * @return array{0: User, 1: CarbonImmutable}
     */
    private function chosenSlot(Site $site, string $slot, string $agentUlid, string $kind): array
    {
        try {
            $start = CarbonImmutable::parse($slot);
        } catch (Throwable) {
            throw ValidationException::withMessages(['slot' => 'Choose a time.']);
        }
        $agent = collect($this->bookingAgents($site))->first(fn (User $u): bool => $u->ulid === $agentUlid)
            ?? throw ValidationException::withMessages(['slot' => 'Choose one of the times shown.']);
        // Checked before the lead is saved, so a taken time asks the visitor to choose again without a half-made lead.
        if (! collect(app(Availability::class)->slots($agent, $kind, 14, 200))->contains(fn (CarbonImmutable $s): bool => $s->equalTo($start))) {
            throw ValidationException::withMessages(['slot' => 'That time was just taken. Please choose another.']);
        }

        return [$agent, $start];
    }

    /**
     * Tells the lead's agent right away when the chat shows they are ready (lead score 70+, or they asked for a call or
     * a showing): a text if they opted in to lead texts, and an email. Once per conversation.
     */
    private function hotAlert(int $conversationId, bool $asked = false): void
    {
        $c = DB::connection(Connections::cell())->table('ailly_conversations')->where('id', $conversationId)->first();
        if ($c === null || $c->lead_id === null || $c->hot_alerted_at !== null || (! $asked && (int) ($c->lead_score ?? 0) < 70)) {
            return;
        }
        $lead = Lead::query()->whereKey((int) $c->lead_id)->first();
        $agent = $lead !== null && $lead->assigned_user_id !== null ? User::query()->whereKey($lead->assigned_user_id)->first() : null;
        if (! $lead instanceof Lead || ! $agent instanceof User || ! $agent->isActive()) {
            return;
        }
        DB::connection(Connections::cell())->table('ailly_conversations')->where('id', $c->id)->update(['hot_alerted_at' => now()]);
        $why = $c->outcome === 'call' ? 'asked for a call' : ($c->outcome === 'showing' ? 'asked to see a home' : 'looks ready to move (lead score '.(int) $c->lead_score.')');
        $speed = app(SpeedToLead::class);
        $url = LiveChat::link((string) $c->ulid);
        $speed->textAgent($agent, 'RightAlly: hot website lead '.SpeedToLead::summary($lead).' '.$why.'. Join the chat or read the closing brief: '.$url);
        $agent->notify(new LeadAlert('Hot lead from your website chat', SpeedToLead::summary($lead).' '.$why.' in a chat with the website assistant. Join the chat while they are there, or read their closing brief.',
            $url, 'Join the chat'));
    }

    /** The conversation (this brokerage's only). */
    public function conversation(string $ulid): \stdClass
    {
        $c = DB::connection(Connections::cell())->table('ailly_conversations')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first();
        if (! $c instanceof \stdClass) {
            throw ValidationException::withMessages(['conversation' => 'Start a new chat.']);
        }

        return $c;
    }

    /** The package's monthly conversations (HQ sets them per package); 500 without a package. */
    private function monthlyCap(): int
    {
        $cap = DB::connection(Connections::control())->table('billing_tenant_subscriptions')->join('billing_packages', 'billing_packages.id', '=', 'billing_tenant_subscriptions.package_id')
            ->where('billing_tenant_subscriptions.tenant_id', $this->tenants->id())->whereIn('billing_tenant_subscriptions.status', ['active', 'grace'])
            ->orderByDesc('billing_tenant_subscriptions.id')->value('billing_packages.ailly_conversations');

        return $cap !== null ? (int) $cap : 500;
    }

    private function visitorMessagesToday(\stdClass $c): int
    {
        $q = DB::connection(Connections::cell())->table('ailly_conversations')->where('tenant_id', $this->tenants->id())->where('created_at', '>=', CarbonImmutable::now()->startOfDay());
        $q = $c->visitor_id !== null ? $q->where('visitor_id', $c->visitor_id) : $q->where('ip_hash', $c->ip_hash);

        return (int) $q->sum('visitor_messages');
    }

    /**
     * Up to three matching homes from the brokerage's licensed MLS listings (shown by the website, not written by the AI).
     *
     * @param  array<string, mixed>  $s
     * @return list<array{ulid: string, label: string, price: int|null, beds: int|null, baths: int|null, photo: string|null, url: string|null, attribution: string}>
     */
    private function homes(array $s): array
    {
        $found = $this->search->search($this->tenants->id(), array_filter(['q' => $s['q'] ?? null, 'min_price' => $s['min_price'] ?? null, 'max_price' => $s['max_price'] ?? null,
            'beds' => $s['beds'] ?? null, 'baths' => $s['baths'] ?? null, 'per_page' => 3], fn ($v): bool => $v !== null && $v !== ''));

        return array_map(fn (array $h): array => ['ulid' => (string) $h['ulid'], 'label' => ListingSearch::label($h), 'price' => isset($h['price']) ? (int) $h['price'] : null,
            'beds' => isset($h['beds']) ? (int) $h['beds'] : null, 'baths' => isset($h['baths_full']) ? (int) $h['baths_full'] : null,
            'photo' => is_string($h['photo'] ?? null) ? $h['photo'] : null, 'url' => ListingSearch::publicUrl((string) $h['ulid'], ListingSearch::label($h)),
            'attribution' => (string) ($h['attribution'] ?? '')], $found['results']);
    }

    /**
     * C03: on a listing page, what AIlly may say about that home without looking anything up: the features seen in
     * its photos and the nearby schools and transit (straight-line, never rated). Empty elsewhere.
     *
     * @return list<string>
     */
    private function homeFacts(string $page): array
    {
        if (preg_match('#/homes/([0-9A-HJKMNP-TV-Z]{26})#', $page, $m) !== 1) {
            return [];
        }
        $home = $this->search->find($this->tenants->id(), $m[1]);
        if ($home === null) {
            return [];
        }
        $lines = ['The visitor is looking at '.ListingSearch::label($home).'.'];
        if (($home['features'] ?? []) !== []) {
            $lines[] = 'Seen in its photos (confirm with an agent before promising): '.implode(', ', $home['features']).'.';
        }
        if (($home['nearby'] ?? null) !== null) {
            $near = array_map(fn (array $p): string => $p['name'].' ('.mb_strtolower($p['type']).', '.$p['miles'].')', [...$home['nearby']['schools'], ...$home['nearby']['transit'], ...($home['nearby']['shops'] ?? [])]);
            $lines[] = 'Nearby, straight-line: '.implode('; ', $near).'. Never rate or rank schools; attendance depends on the school district.';
        }

        return $lines;
    }

    /** @return list<string> */
    private function siteFacts(Site $site): array
    {
        $i = $site->info;

        return array_values(array_filter([
            'Brokerage: '.$site->displayName(),
            isset($i['area']) || isset($i['areas']) ? 'Serves: '.(string) ($i['area'] ?? $i['areas']) : null,
            isset($i['phone']) ? 'Phone: '.(string) $i['phone'] : null,
            isset($i['email']) ? 'Email: '.(string) $i['email'] : null,
            isset($i['address']) ? 'Office: '.(is_array($i['address']) ? implode(', ', array_filter(array_map('strval', $i['address']))) : (string) $i['address']) : null,
            'Pages: /homes (search homes), /home-value (what is my home worth), /market (market reports), /agents, /contact',
        ]));
    }

    private function transcript(int $conversationId): string
    {
        return DB::connection(Connections::cell())->table('ailly_messages')->where('conversation_id', $conversationId)->orderByDesc('id')->limit(12)->get(['role', 'body'])
            ->reverse()->filter(fn (object $m): bool => $m->role !== 'note')
            ->map(fn (object $m): string => match ($m->role) {
                'visitor' => 'Visitor: ', 'agent' => 'Agent (a person on the team): ', default => 'Assistant: '
            }.(string) $m->body)->implode("\n");
    }

    /**
     * @param  list<array<string, mixed>>  $homes
     * @param  list<string>  $flags  what the content guard removed (the Fair Housing audit)
     */
    private function say(string $conversation, string $role, string $body, array $homes = [], bool $cached = false, array $flags = [], ?string $from = null): void
    {
        $id = (int) DB::connection(Connections::cell())->table('ailly_conversations')->where('tenant_id', $this->tenants->id())->where('ulid', $conversation)->value('id');
        DB::connection(Connections::cell())->table('ailly_messages')->insert(['tenant_id' => $this->tenants->id(), 'conversation_id' => $id, 'role' => $role, 'body' => $body,
            'listings' => $homes !== [] ? json_encode(array_column($homes, 'ulid')) : null, 'cached' => $cached, 'flags' => $flags !== [] ? json_encode($flags) : null,
            'answered_from' => $role === 'ailly' ? ($from ?? ($cached ? 'cache' : 'ai')) : null, 'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u')]);
        DB::connection(Connections::cell())->table('ailly_conversations')->where('id', $id)->update(['updated_at' => now()]);
    }

    /** @return array{answer: string, next_step: string}|null */
    private function cached(Site $site, string $hash): ?array
    {
        $db = DB::connection(Connections::cell())->table('ailly_answer_cache')->where('tenant_id', $this->tenants->id())->where('site_id', $site->id)->where('question_hash', $hash)
            ->where('expires_at', '>', now());
        $row = (clone $db)->first(['id', 'answer', 'next_step']);
        if ($row === null) {
            return null;
        }
        DB::connection(Connections::cell())->table('ailly_answer_cache')->where('id', $row->id)->increment('hits');

        return ['answer' => (string) $row->answer, 'next_step' => (string) $row->next_step];
    }

    private function remember(Site $site, string $hash, string $answer, string $next): void
    {
        DB::connection(Connections::cell())->table('ailly_answer_cache')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'site_id' => $site->id, 'question_hash' => $hash],
            ['answer' => $answer, 'next_step' => $next, 'expires_at' => now()->addDay(), 'updated_at' => now(), 'created_at' => now()]);
    }

    /** Same question, however it is typed: lower case, letters and digits only, single spaces. */
    public static function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($text))));
    }

    public static function isBot(Request $request): bool
    {
        return preg_match(Visitors::BOTS, (string) $request->userAgent()) === 1 || (string) $request->userAgent() === '' || trim((string) $request->input('website', '')) !== '';
    }

    /**
     * @param  list<array<string, mixed>>  $homes
     * @param  array<string, mixed>|null  $value
     * @return array{reply: string, next_step: string, homes: list<array<string, mixed>>, needs_contact: bool, ended: bool, value: array<string, mixed>|null, agent: bool}
     */
    private function out(string $reply, string $next, array $homes, bool $needsContact, bool $ended = false, ?array $value = null, bool $agent = false): array
    {
        return ['reply' => $reply, 'next_step' => $next, 'homes' => $homes, 'needs_contact' => $needsContact, 'ended' => $ended, 'value' => $value, 'agent' => $agent];
    }

    /**
     * New messages for the widget since the last one it showed: an agent's replies and notes while they have the chat.
     *
     * @return array{messages: list<array{id: int, role: string, body: string, by: string|null}>, agent: string|null}
     */
    public function poll(string $conversation, int $after): array
    {
        $c = $this->conversation($conversation);
        $agent = $c->taken_by !== null ? User::query()->whereKey((int) $c->taken_by)->first() : null;
        $messages = array_values(array_filter(app(LiveChat::class)->messages((int) $c->id, $after), fn (array $m): bool => in_array($m['role'], ['agent', 'note'], true)));

        return ['messages' => array_map(fn (array $m): array => ['id' => $m['id'], 'role' => $m['role'], 'body' => $m['body'], 'by' => $m['by']], $messages),
            'agent' => $agent?->first_name];
    }
}
