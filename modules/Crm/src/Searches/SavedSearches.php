<?php

declare(strict_types=1);

namespace Modules\Crm\Searches;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\SavedSearch;
use Modules\Listings\Search\ListingSearch;

/**
 * Saved searches with alerts (specs/grow/08): new listings and price drops matching a contact's search, by email
 * (instant, daily or weekly at 8am brokerage time), each listing once per kind and price, never after an email
 * opt-out, with a one-click unsubscribe. Listings come only through ListingSearch (licensed MLSs, MLS rules).
 */
final class SavedSearches
{
    private const FILTERS = ['q', 'min_price', 'max_price', 'beds', 'baths', 'min_sqft', 'types', 'statuses', 'bounds'];

    public function __construct(private readonly TenantContext $tenants, private readonly ListingSearch $search, private readonly ContactVisibility $visibility, private readonly Timeline $timeline) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function create(Contact $contact, array $filters, string $name, string $frequency, User $by): SavedSearch
    {
        if (! $this->visibility->canManage($by, $contact)) {
            throw ValidationException::withMessages(['search' => 'You cannot set up searches for this contact.']);
        }
        $clean = array_intersect_key(ListingSearch::clean($filters), array_flip(self::FILTERS));
        $errors = [];
        if (! in_array($frequency, SavedSearch::FREQUENCIES, true)) {
            $errors['frequency'] = 'Choose how often to send.';
        }
        if ($frequency !== 'off' && $contact->primary_email === null) {
            $errors['frequency'] = 'This contact has no email address for alerts.';
        }
        if ($clean['q'] === '' && $clean['bounds'] === null && $clean['min_price'] === null && $clean['max_price'] === null) {
            $errors['q'] = 'Give a place, a map area or a price range.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $search = SavedSearch::query()->create(['contact_id' => $contact->id, 'user_id' => $by->id, 'frequency' => $frequency, 'filters' => $clean,
            'name' => mb_substr(trim(strip_tags($name)) ?: self::describe($clean), 0, 100), 'last_checked_at' => CarbonImmutable::now()]);
        $this->timeline->system($contact, 'Saved search "'.$search->name.'" set up ('.$frequency.' alerts)', $by->id);
        app(AuditLogger::class)->record('crm.saved_search.created', $search, metadata: ['frequency' => $frequency]);

        return $search;
    }

    /**
     * A search a website visitor saved for themselves (specs/websites/07): daily alerts to their own email, owned by
     * their agent (or the brokerage's first owner when no agent is assigned yet).
     *
     * @param  array<string, mixed>  $filters
     */
    public function createForVisitor(Contact $contact, array $filters, string $name, ?int $agentId): SavedSearch
    {
        $clean = array_intersect_key(ListingSearch::clean($filters), array_flip(self::FILTERS));
        if ($clean['q'] === '' && $clean['bounds'] === null && $clean['min_price'] === null && $clean['max_price'] === null) {
            throw ValidationException::withMessages(['query' => 'Add a place, a map area or a price range first.']);
        }
        if ($contact->primary_email === null) {
            throw ValidationException::withMessages(['query' => 'Your account has no email address for alerts.']);
        }
        $agentId ??= User::query()->where('kind', UserKind::Owner)->orderBy('id')->value('id');
        abort_if($agentId === null, 409);
        $name = mb_substr(trim(strip_tags($name)) ?: self::describe($clean), 0, 100);
        $search = SavedSearch::query()->where('contact_id', $contact->id)->where('name', $name)->first()
            ?? SavedSearch::query()->create(['contact_id' => $contact->id, 'user_id' => $agentId, 'frequency' => 'daily', 'filters' => $clean, 'name' => $name,
                'last_checked_at' => CarbonImmutable::now()]);
        $this->timeline->system($contact, 'Saved a search on the website: "'.$search->name.'" (daily alerts)');

        return $search;
    }

    public function setFrequency(SavedSearch $search, string $frequency, User $by): void
    {
        if (! in_array($frequency, SavedSearch::FREQUENCIES, true) || ! $this->visibility->canManage($by, $search->contact)) {
            throw ValidationException::withMessages(['frequency' => 'Choose how often to send.']);
        }
        $search->forceFill(['frequency' => $frequency, 'unsubscribed_at' => $frequency === 'off' ? $search->unsubscribed_at : null])->save();
    }

    public function unsubscribe(SavedSearch $search): void
    {
        $search->forceFill(['frequency' => 'off', 'unsubscribed_at' => CarbonImmutable::now()])->save();
        $this->timeline->system($search->contact, 'Turned off email alerts for "'.$search->name.'"');
    }

    /**
     * Matches now (for "preview" on the contact page and for alerts).
     *
     * @return list<array<string, mixed>>
     */
    public function matches(SavedSearch $search, ?CarbonImmutable $since = null, int $limit = 12): array
    {
        $filters = $search->filters + ['per_page' => $limit, 'sort' => 'newest'];
        if ($since !== null) {
            $filters['since'] = $since->toIso8601String();
        }

        return $this->search->search($this->tenants->id(), $filters)['results'];
    }

    /** Sends every due alert for this brokerage (run every 15 minutes). Returns the emails sent. */
    public function sendDue(): int
    {
        $tz = (string) ($this->tenants->current()->getAttribute('timezone') ?: 'America/Chicago');
        $now = CarbonImmutable::now();
        $local = $now->setTimezone($tz);
        $sent = 0;
        foreach (SavedSearch::query()->whereIn('frequency', ['instant', 'daily', 'weekly'])->whereNull('unsubscribed_at')->with('contact')->get() as $search) {
            if (! self::due($search, $local)) {
                continue;
            }
            $since = $search->last_checked_at ?? $search->created_at;
            $search->forceFill(['last_checked_at' => $now])->save();
            $contact = $search->contact;
            if ($contact->primary_email === null || ConsentEvent::query()->where('contact_id', $contact->id)->where('channel', 'email')->latest('id')->value('action') === 'opt_out') {
                continue;
            }
            $picked = [];
            foreach ($this->matches($search, $since, 24) as $l) {
                $kind = is_string($l['added_at'] ?? null) && CarbonImmutable::parse($l['added_at'])->gt($since) ? 'new'
                    : (($l['reduced'] ?? false) && is_string($l['price_changed_at'] ?? null) && CarbonImmutable::parse($l['price_changed_at'])->gt($since) ? 'price_drop' : null);
                if ($kind === null || DB::connection(Connections::cell())->table('crm_search_alerts_sent')->where('saved_search_id', $search->id)
                    ->where('listing_ulid', $l['ulid'])->where('kind', $kind)->where('price', (int) ($l['price'] ?? 0))->exists()) {
                    continue;
                }
                $picked[] = $l + ['kind' => $kind];
                if (count($picked) === 12) {
                    break;
                }
            }
            if ($picked === []) {
                continue;
            }
            $agent = User::query()->find($search->user_id) ?? ($contact->owner_user_id !== null ? User::query()->find($contact->owner_user_id) : null);
            $stop = $this->stopUrl($search);
            Mail::to((string) $contact->primary_email, $contact->name)->send(new SearchAlertMail($search->name, $contact->first_name, $picked, $this->tenants->current()->name,
                $agent?->name, $agent?->phone, $stop, Inbox::replyAddress(app(Inbox::class)->conversationFor($contact))));
            foreach ($picked as $l) {
                DB::connection(Connections::cell())->table('crm_search_alerts_sent')->insertOrIgnore(['tenant_id' => $this->tenants->id(), 'saved_search_id' => $search->id,
                    'listing_ulid' => $l['ulid'], 'kind' => $l['kind'], 'price' => (int) ($l['price'] ?? 0), 'sent_at' => $now]);
            }
            $search->forceFill(['last_sent_at' => $now, 'sent_count' => $search->sent_count + 1])->save();
            $new = count(array_filter($picked, fn (array $l): bool => $l['kind'] === 'new'));
            $this->timeline->system($contact, "Sent alert \"{$search->name}\": {$new} new, ".(count($picked) - $new).' price drops');
            $sent++;
        }

        return $sent;
    }

    /** "Send to client": a one-to-one email about one listing, through the inbox (its checks and timeline). */
    public function sendListing(Contact $contact, string $listingUlid, string $note, User $by): void
    {
        $l = $this->search->find($this->tenants->id(), $listingUlid);
        if ($l === null) {
            throw ValidationException::withMessages(['listing' => 'That listing is not available.']);
        }
        $price = isset($l['price']) ? '$'.number_format((int) $l['price']) : '';
        $where = implode(', ', array_filter([$l['address'] ?? null, $l['city'] ?? null, $l['state'] ?? null]));
        $facts = implode(' · ', array_filter([$l['beds'] !== null ? $l['beds'].' bd' : null, $l['baths_full'] !== null ? $l['baths_full'].' ba' : null,
            $l['sqft'] !== null ? number_format((int) $l['sqft']).' sq ft' : null]));
        $body = trim(($note !== '' ? trim($note)."\n\n" : '')."{$where}\n{$price} · {$facts}".(ListingSearch::publicUrl($listingUlid) !== null ? "\n".ListingSearch::publicUrl($listingUlid) : '')
            ."\n\n".$l['attribution']);
        app(Inbox::class)->send($contact, 'email', $body, 'A home for you: '.($where !== '' ? $where : 'new listing'), $by);
    }

    public function stopUrl(SavedSearch $search): string
    {
        return app(SpeedToLead::class)->link(URL::signedRoute('crm.alerts.stop', ['search' => $search->ulid], null, false));
    }

    public static function due(SavedSearch $search, CarbonImmutable $local): bool
    {
        $last = $search->last_checked_at?->setTimezone($local->getTimezone());

        return match ($search->frequency) {
            'instant' => $last === null || $last->lte($local->subMinutes(15)),
            'daily' => $local->hour >= 8 && ($last === null || $last->lt($local->setTime(8, 0))),
            'weekly' => $local->isMonday() && $local->hour >= 8 && ($last === null || $last->lt($local->setTime(8, 0))),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $f
     */
    public static function describe(array $f): string
    {
        $parts = [$f['q'] ?? '' ?: 'Map area'];
        if (($f['beds'] ?? null) !== null) {
            $parts[] = $f['beds'].'+ beds';
        }
        if (($f['max_price'] ?? null) !== null) {
            $parts[] = 'under $'.number_format((int) $f['max_price']);
        } elseif (($f['min_price'] ?? null) !== null) {
            $parts[] = 'over $'.number_format((int) $f['min_price']);
        }

        return implode(', ', $parts);
    }
}
