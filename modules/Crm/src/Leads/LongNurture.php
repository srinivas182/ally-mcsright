<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Consent;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\FairHousing;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Inbox\SendGuard;
use Modules\Crm\Models\AiThread;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Message;
use Modules\Crm\Models\Nurture;
use Modules\Crm\Models\SavedSearch;
use Modules\Crm\Searches\SavedSearches;
use Modules\Listings\Search\MarketStats;

/**
 * Lead-IQ long nurture (specs/grow/09): 12 months of light AI follow-up for leads nobody is talking to.
 *
 * - Who: leads 7+ days old that are still leads, with no active AI conversation and no message either way for 7
 *   days, reachable by email or (with written consent) text, in a brokerage that switched it on.
 * - When: a gentle schedule that slows down while they don't answer and speeds up when they show interest again
 *   (back on the website, or new homes or price drops for their saved search).
 * - What: each message has a real reason (new homes, a price drop, their home's value, the market), written by the
 *   AI from MLS facts only, Fair Housing checked, sent through the inbox checks (consent, quiet hours, opt-outs).
 * - Stops: the moment they reply (their agent is alerted to take over), an agent writes to them, they become a
 *   client, they opt out, or after 12 months.
 */
final class LongNurture
{
    public const FEATURE = 'crm.nurture';

    /** Days to wait before each touch (then the last one repeats), stretched while they don't answer. */
    private const DAYS = [0, 7, 14, 21, 30, 30, 45, 45, 60];

    public function __construct(private readonly TenantContext $tenants, private readonly Timeline $timeline) {}

    public function enabled(): bool
    {
        return (bool) DB::connection(Connections::cell())->table('crm_ai_responder_settings')->where('tenant_id', $this->tenants->id())->value('nurture_enabled');
    }

    /** Starts nurture for every lead that has gone quiet. Returns how many started. */
    public function enrol(): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        $now = CarbonImmutable::now();
        $started = 0;
        $leads = Lead::query()->whereNotNull('contact_id')->where('status', '!=', 'pond')->whereBetween('created_at', [$now->subMonths(12), $now->subDays(7)])
            ->whereNotIn('contact_id', Nurture::query()->select('contact_id'))->orderBy('id')->limit(500)->get();
        foreach ($leads->unique('contact_id') as $lead) {
            $contact = Contact::query()->find($lead->contact_id);
            if ($contact === null || $contact->lifecycle !== Lifecycle::Lead || ! $this->reachable($contact)
                || AiThread::query()->where('contact_id', $contact->id)->where('status', 'active')->exists()
                || Message::query()->where('contact_id', $contact->id)->where('created_at', '>', $now->subDays(7))->exists()) {
                continue;
            }
            Nurture::query()->create(['contact_id' => $contact->id, 'lead_id' => $lead->id, 'status' => 'active', 'next_at' => $now,
                'ends_on' => CarbonImmutable::parse($lead->created_at)->addMonths(12)->toDateString()]);
            $this->timeline->system($contact, 'Long follow-up started: the AI assistant checks in now and then for up to 12 months, until they reply or an agent takes over');
            $started++;
        }

        return $started;
    }

    /** Sends every touch that is due. Returns the messages sent. */
    public function runDue(): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        $sent = 0;
        foreach (Nurture::query()->where('status', 'active')->where('next_at', '<=', CarbonImmutable::now())->orderBy('next_at')->limit(300)->get() as $n) {
            $sent += $this->touch($n) ? 1 : 0;
        }

        return $sent;
    }

    /** They wrote back: the follow-up has done its job, and their agent takes over. */
    public function onReply(Contact $contact): void
    {
        $n = Nurture::query()->where('contact_id', $contact->id)->where('status', 'active')->first();
        if ($n !== null && $n->touches > 0) {
            $this->replied($n, $contact);
        }
    }

    /** A person took over, they opted out, or someone pressed Stop. */
    public function stop(Contact $contact, string $reason): void
    {
        foreach (Nurture::query()->where('contact_id', $contact->id)->where('status', 'active')->get() as $n) {
            $n->forceFill(['status' => 'stopped', 'stopped_reason' => $reason])->save();
            $this->timeline->system($contact, 'Long follow-up stopped ('.str_replace('_', ' ', $reason).')');
        }
    }

    /** @return array{status: string, touches: int, next_at: string|null, ends_on: string, last_reason: string|null, stopped_reason: string|null}|null */
    public function state(Contact $contact): ?array
    {
        $n = Nurture::query()->where('contact_id', $contact->id)->first();

        return $n === null ? null : ['status' => $n->status, 'touches' => $n->touches, 'next_at' => $n->status === 'active' ? $n->next_at->toIso8601String() : null,
            'ends_on' => $n->ends_on->toDateString(), 'last_reason' => $n->last_reason, 'stopped_reason' => $n->stopped_reason];
    }

    private function touch(Nurture $n): bool
    {
        $now = CarbonImmutable::now();
        $contact = Contact::query()->find($n->contact_id);
        if ($contact === null) {
            $n->forceFill(['status' => 'stopped', 'stopped_reason' => 'contact_removed'])->save();

            return false;
        }
        if ($n->ends_on->lt($now->startOfDay())) {
            $n->forceFill(['status' => 'done'])->save();
            $this->timeline->system($contact, 'Long follow-up finished after 12 months');

            return false;
        }
        if ($contact->lifecycle !== Lifecycle::Lead) {
            $this->stop($contact, 'no_longer_a_lead');

            return false;
        }
        $since = $n->last_touch_at ?? $n->created_at;
        if (Message::query()->where('contact_id', $contact->id)->where('direction', 'in')->where('created_at', '>', $since)->exists()) {
            $this->replied($n, $contact);

            return false;
        }
        $channel = $this->channel($contact);
        if ($channel === null) {
            $quiet = $contact->primary_phone !== null && $contact->primary_email === null ? SendGuard::quietHours($contact->primary_phone) : null;
            if ($quiet !== null) {
                $n->forceFill(['next_at' => $now->addHour()])->save(); // night where they are: the 8am-9pm window comes round

                return false;
            }
            $this->stop($contact, 'cannot_message');

            return false;
        }
        $lead = $n->lead_id !== null ? Lead::query()->find($n->lead_id) : null;
        [$reason, $why, $facts] = $this->reason($contact, $since);
        $agent = ($contact->owner_user_id !== null ? User::query()->find($contact->owner_user_id) : null)
            ?? ($lead?->assigned_user_id !== null ? User::query()->find($lead->assigned_user_id) : null);
        $ai = app(AiResponder::class)->settings();
        try {
            $result = app(AiGateway::class)->run(new AiTask(self::FEATURE, 'crm.nurture', 'crm-nurture:'.$n->ulid.':'.$n->touches,
                inputs: ['assistant' => $ai['assistant_name'], 'agent' => $agent->first_name ?? 'our team', 'brokerage' => $this->tenants->current()->name,
                    'channel' => $channel === 'sms' ? 'text message' : 'email', 'first_name' => $contact->first_name,
                    'months' => (string) max(1, (int) CarbonImmutable::parse($lead->created_at ?? $n->created_at)->diffInMonths($now)).' month(s)',
                    'interest' => $lead !== null ? SpeedToLead::summary($lead) : 'buying or selling a home', 'touch' => (string) ($n->touches + 1),
                    'reason' => $why, 'facts' => $facts],
                untrustedContent: $this->history($contact),
                outputSchema: ['type' => 'object', 'required' => ['reply'], 'properties' => ['reply' => ['type' => 'string']]],
                userId: $agent?->id));
            $reply = trim((string) (((array) $result->json)['reply'] ?? ''));
        } catch (\Throwable $e) {
            report($e);
            $n->forceFill(['next_at' => $now->addDay()])->save(); // try again tomorrow

            return false;
        }
        if ($reply === '' || FairHousing::flags($reply) !== []) {
            $n->forceFill(['next_at' => $now->addDay()])->save();
            $this->timeline->system($contact, 'Long follow-up skipped a message that needed a person to word it');

            return false;
        }
        $blocked = [];
        $sent = app(Inbox::class)->sendAutomated($contact, $channel, mb_substr($reply, 0, $channel === 'sms' ? 480 : 4000),
            $channel === 'email' ? self::subject($reason) : null, $ai['assistant_name'].' for '.($agent->name ?? $this->tenants->current()->name), $blocked);
        if ($sent === null) {
            $this->stop($contact, 'cannot_message');

            return false;
        }
        $touches = $n->touches + 1;
        $unanswered = $n->unanswered + 1;
        $n->forceFill(['touches' => $touches, 'unanswered' => $unanswered, 'last_touch_at' => $now, 'last_reason' => $reason,
            'next_at' => $now->addDays($this->wait($touches, $unanswered, $contact))])->save();
        app(LeadScoring::class)->rescoreContact($contact->id);

        return true;
    }

    /** Days to the next touch: the schedule, stretched by a quarter for each unanswered touch after the third, sooner when they're back. */
    private function wait(int $touches, int $unanswered, Contact $contact): int
    {
        $days = self::DAYS[min($touches, count(self::DAYS) - 1)];
        if ($unanswered > 3) {
            $days = (int) ceil($days * (1 + ($unanswered - 3) / 4));
        }
        if ($this->visitedSince($contact, CarbonImmutable::now()->subDays(3))) {
            $days = min($days, 2); // back on the website: interest again
        }

        return max(1, min(90, $days));
    }

    /**
     * The reason to write now, in words for the AI, and the facts it may use.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function reason(Contact $contact, CarbonImmutable $since): array
    {
        $searches = app(SavedSearches::class);
        foreach (SavedSearch::query()->where('contact_id', $contact->id)->whereNull('unsubscribed_at')->orderByDesc('id')->limit(3)->get() as $s) {
            $homes = $searches->matches($s, $since, 6);
            $new = array_values(array_filter($homes, fn (array $h): bool => is_string($h['added_at'] ?? null) && CarbonImmutable::parse($h['added_at'])->gt($since)));
            $drops = array_values(array_filter($homes, fn (array $h): bool => ($h['reduced'] ?? false) === true));
            if ($new !== [] || $drops !== []) {
                $list = implode('; ', array_map(fn (array $h): string => trim(($h['address'] ?? '').', '.($h['city'] ?? '').' $'.number_format((int) ($h['price'] ?? 0))
                    .(isset($h['beds']) ? ', '.$h['beds'].' bd' : '')), array_slice(array_merge($new, $drops), 0, 3)));

                return $new !== []
                    ? ['new_listings', count($new).' new home(s) came up for their saved search "'.$s->name.'"', $list]
                    : ['price_drop', 'price drop(s) on homes matching their saved search "'.$s->name.'"', $list];
            }
        }
        $report = DB::connection(Connections::cell())->table('site_home_reports')->where('tenant_id', $this->tenants->id())->where('contact_id', $contact->id)
            ->whereNull('unsubscribed_at')->first(['address', 'last_mid']);
        if ($report !== null && (int) $report->last_mid > 0) {
            return ['home_value', 'an update on what their home at '.$report->address.' may be worth', 'Likely worth about $'.number_format((int) $report->last_mid)
                .' (from similar homes for sale; their agent can confirm with recent sales).'];
        }
        $zip = Lead::query()->where('contact_id', $contact->id)->whereNotNull('zip')->latest('id')->value('zip');
        $market = is_string($zip) && preg_match('/^\d{5}$/', $zip) === 1 ? app(MarketStats::class)->forPlace($this->tenants->id(), $zip) : null;
        if ($market !== null) {
            return ['market', 'a short market update for '.$market['name'], $market['active'].' homes for sale, median asking price $'.number_format((int) $market['median_price'])
                .', '.$market['new_30'].' new in the last 30 days, '.$market['reduced_30'].' price reductions, median '.$market['median_dom'].' days on market.'];
        }

        return ['check_in', 'a friendly check-in: are they still thinking about a move, and can '.'their agent help with anything', 'none'];
    }

    private function replied(Nurture $n, Contact $contact): void
    {
        $n->forceFill(['status' => 'replied', 'unanswered' => 0])->save();
        $this->timeline->system($contact, 'Replied to the long follow-up: handed to their agent');
        $conversation = Conversation::query()->where('contact_id', $contact->id)->first();
        $conversation?->forceFill(['needs_reply_since' => CarbonImmutable::now(), 'nudged_at' => null, 'escalated_at' => null])->save();
        $agent = $contact->owner_user_id !== null ? User::query()->find($contact->owner_user_id) : null;
        $agent?->notify(new LeadAlert("{$contact->name} replied after {$n->touches} follow-up(s)", 'They answered the AI follow-up. Reply while they\'re interested.',
            app(SpeedToLead::class)->link('/inbox?c='.app(Inbox::class)->conversationFor($contact)->ulid), 'Reply now'));
        app(AuditLogger::class)->record('crm.nurture.replied', $n, metadata: ['touches' => $n->touches]);
    }

    private function reachable(Contact $contact): bool
    {
        return $this->channel($contact) !== null || ($contact->primary_phone !== null && app(Consent::class)->canAutoText($contact));
    }

    private function channel(Contact $contact): ?string
    {
        $guard = app(SendGuard::class);
        if ($contact->primary_email !== null && $guard->check($contact, 'email', 'x') === []) {
            return 'email'; // long nurture prefers email: lighter than a text
        }

        return $contact->primary_phone !== null && app(Consent::class)->canAutoText($contact) && $guard->check($contact, 'sms', 'x') === [] ? 'sms' : null;
    }

    private function visitedSince(Contact $contact, CarbonImmutable $since): bool
    {
        return DB::connection(Connections::cell())->table('site_visitors')->where('tenant_id', $this->tenants->id())->where('contact_id', $contact->id)
            ->where('last_seen_at', '>', $since)->exists();
    }

    private static function subject(string $reason): string
    {
        return match ($reason) {
            'new_listings' => 'New homes for your search',
            'price_drop' => 'A price drop for your search',
            'home_value' => 'Your home\'s value',
            'market' => 'Your local market this month',
            default => 'Checking in',
        };
    }

    /** @return list<string> */
    private function history(Contact $contact): array
    {
        return array_values(Message::query()->where('contact_id', $contact->id)->latest('id')->limit(8)->get()->reverse()
            ->map(fn (Message $m): string => ($m->direction === 'in' ? $contact->first_name : 'Us').': '.mb_substr($m->body, 0, 600))->all());
    }
}
