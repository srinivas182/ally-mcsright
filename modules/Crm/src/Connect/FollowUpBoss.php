<?php

declare(strict_types=1);

namespace Modules\Crm\Connect;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Core\Connect\TwoWay;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Tags;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\ContactVisibility;
use Modules\Crm\CrmAccess;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Throwable;

/**
 * Follow Up Boss (specs/connect/04): brings people, their tags, stage, source and agent, and their notes into
 * RightAlly, through Follow Up Boss's own API (OAuth: Bearer token plus RightAlly's registered-system headers).
 *
 * - Safe to repeat: every person and note is remembered by its Follow Up Boss id, so nothing is added twice. A person
 *   already in RightAlly (same email or phone) is linked, not duplicated; one the connecting person can't see is left alone.
 * - Acts as the person who connected it, with their access: a brokerage connection brings company contacts (each
 *   with their agent, matched by email); an agent's own connection brings personal contacts.
 * - Texting consent doesn't come across: imported people are never texted automatically until they opt in here.
 * - A big account comes over in steps of 500 people or notes; each step queues the next.
 *
 * Live two-way sync (specs/connect/05):
 * - In: once everything is over, RightAlly registers webhooks (people and notes) at the connection's own address.
 *   Only the Follow Up Boss account owner may register them; for anyone else RightAlly checks for changes every 15
 *   minutes instead. Every run also checks recently changed people, as a safety net. Deletions are not copied.
 * - Out: new contacts and notes made in RightAlly go to Follow Up Boss (FollowUpBossOutbox). Anything that came from
 *   Follow Up Boss is never sent back, so nothing bounces between the two.
 */
final class FollowUpBoss implements TwoWay
{
    public const API = 'https://api.followupboss.com/v1/';

    /** The Follow Up Boss changes RightAlly listens for. */
    public const EVENTS = ['peopleCreated', 'peopleUpdated', 'peopleTagsCreated', 'peopleStageUpdated', 'notesCreated'];

    private const PAGES_PER_RUN = 5;

    /** True while Follow Up Boss's own changes are being saved, so they are not sent back to it. */
    public static bool $quiet = false;

    public function __construct(private readonly ConnectorKit $kit) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'Follow Up Boss'; // connected with one click (OAuth), not a pasted key
    }

    public function sync(ConnectionData $connection): array
    {
        return $this->quietly(fn (): array => $this->run($connection));
    }

    /** @return array{created: int, updated: int, skipped: int, cursor: string|null, note: string|null, more?: bool} */
    private function run(ConnectionData $connection): array
    {
        $by = $this->by($connection);
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $cursor = is_array($cursor) && is_string($cursor['phase'] ?? null) ? $cursor : ['phase' => 'users'];
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $http = $this->http($connection);

        if ($cursor['phase'] === 'done') {
            return $this->changes($connection, $http, $by, $cursor);
        }
        if ($cursor['phase'] === 'users') {
            $this->users($http, $connection);
            $cursor = ['phase' => 'people'];
        }
        foreach (['people', 'notes'] as $phase) {
            if ($cursor['phase'] !== $phase) {
                continue;
            }
            for ($page = 0; $page < self::PAGES_PER_RUN && $cursor['phase'] === $phase; $page++) {
                // People page by Follow Up Boss's "next" marker; notes by offset (that's what each list offers).
                $query = ['limit' => 100] + ($phase === 'people'
                    ? ['fields' => 'allFields'] + (is_string($cursor['next'] ?? null) ? ['next' => $cursor['next']] : [])
                    : ['offset' => (int) ($cursor['offset'] ?? 0)]);
                $r = $this->get($http, $phase, $query);
                if ($r === null) {
                    return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Follow Up Boss asked us to slow down; carrying on shortly.', 'more' => true];
                }
                $rows = array_values(array_filter((array) $r->json($phase, []), 'is_array'));
                foreach ($rows as $row) {
                    $counts[$phase === 'people' ? $this->person($connection, $row, $by) : $this->note($connection, $row, $by)]++;
                }
                if ($phase === 'people') {
                    $next = $r->json('_metadata.next');
                    $cursor = is_string($next) && $next !== '' && $rows !== [] ? ['phase' => 'people', 'next' => $next] : ['phase' => 'notes', 'offset' => 0];
                } else {
                    $cursor = count($rows) >= 100 ? ['phase' => 'notes', 'offset' => (int) ($cursor['offset'] ?? 0) + count($rows)] : ['phase' => 'done'];
                }
            }
            if ($cursor['phase'] === $phase) { // more pages left in this phase
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => $phase === 'people' ? 'Bringing people over…' : 'Bringing notes over…', 'more' => true];
            }
        }

        if ($cursor['phase'] === 'done') {
            $cursor['since'] = CarbonImmutable::now()->subMinutes(5)->toIso8601String();
            $this->subscribe($connection, $http);
        }

        return $counts + ['cursor' => (string) json_encode($cursor), 'note' => $cursor['phase'] === 'done' ? 'Everything is over from Follow Up Boss.' : null,
            'more' => $cursor['phase'] !== 'done'];
    }

    /**
     * After the import: people changed since the last check (newest first, stopping at the last check), and their
     * new notes. This is the whole live sync when webhooks aren't allowed, and a safety net when they are.
     *
     * @param  array<string, mixed>  $cursor
     * @return array{created: int, updated: int, skipped: int, cursor: string|null, note: string|null, more?: bool}
     */
    private function changes(ConnectionData $connection, PendingRequest $http, User $by, array $cursor): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $since = is_string($cursor['since'] ?? null) ? CarbonImmutable::parse($cursor['since']) : CarbonImmutable::now()->subDay();
        $started = CarbonImmutable::now();
        $next = null;
        for ($page = 0; $page < self::PAGES_PER_RUN; $page++) {
            $r = $this->get($http, 'people', ['limit' => 100, 'sort' => '-updated', 'fields' => 'allFields'] + ($next !== null ? ['next' => $next] : []));
            if ($r === null) {
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Follow Up Boss asked us to slow down; carrying on shortly.'];
            }
            $older = false;
            foreach (array_values(array_filter((array) $r->json('people', []), 'is_array')) as $p) {
                $updated = is_string($p['updated'] ?? null) ? CarbonImmutable::parse($p['updated']) : null;
                if ($updated !== null && $updated->lte($since)) {
                    $older = true;
                    break;
                }
                $result = $this->person($connection, $p, $by);
                $counts[$result]++;
                if ($result !== 'skipped') {
                    $this->notesFor($connection, $http, $by, (string) $p['id'], $counts);
                }
            }
            $n = $r->json('_metadata.next');
            $next = is_string($n) && $n !== '' ? $n : null;
            if ($older || $next === null) {
                break;
            }
        }
        $cursor['since'] = $started->subMinutes(1)->toIso8601String(); // a little overlap: repeats are harmless

        return $counts + ['cursor' => (string) json_encode($cursor), 'note' => $counts['created'] + $counts['updated'] > 0 ? 'Changes brought over from Follow Up Boss.' : null];
    }

    /** @param  array{created: int, updated: int, skipped: int}  $counts */
    private function notesFor(ConnectionData $connection, PendingRequest $http, User $by, string $personId, array &$counts): void
    {
        $r = $this->get($http, 'notes', ['personId' => $personId, 'limit' => 100]);
        foreach (array_values(array_filter((array) ($r?->json('notes', []) ?? []), 'is_array')) as $n) {
            if ($this->note($connection, $n, $by) === 'created') {
                $counts['created']++;
            }
        }
    }

    /** Registers RightAlly's webhooks once (only the Follow Up Boss account owner may); otherwise check every 15 minutes. */
    private function subscribe(ConnectionData $connection, PendingRequest $http): void
    {
        if (isset($connection->settings['live'])) {
            return;
        }
        $url = $this->kit->webhookUrl($connection);
        $ids = [];
        foreach (self::EVENTS as $event) {
            $r = $http->post('webhooks', ['event' => $event, 'url' => $url]);
            if (! $r->successful() || ! is_scalar($r->json('id'))) {
                break;
            }
            $ids[] = (string) $r->json('id');
        }
        $live = count($ids) === count(self::EVENTS);
        if (! $live) {
            foreach ($ids as $id) {
                $http->delete('webhooks/'.$id);
            }
            $ids = [];
        }
        $this->kit->saveSettings($connection, ['live' => $live, 'webhooks' => $ids, 'interval_minutes' => $live ? 60 : 15]);
    }

    public function verifyWebhook(string $raw, array $headers): bool
    {
        $key = (string) ($this->kit->secrets('follow_up_boss')['x_system_key'] ?? '');
        $given = (string) ($headers['fub-signature'] ?? '');

        return $key !== '' && $given !== '' && hash_equals(hash_hmac('sha256', base64_encode($raw), $key), $given);
    }

    public function webhook(ConnectionData $connection, array $payload): array
    {
        return $this->quietly(function () use ($connection, $payload): array {
            $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
            $event = (string) ($payload['event'] ?? '');
            $ids = array_slice(array_values(array_filter(array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', (array) ($payload['resourceIds'] ?? [])),
                fn (string $v): bool => ctype_digit($v))), 0, 100);
            if ($ids === [] || ! in_array($event, self::EVENTS, true)) {
                return $counts;
            }
            $by = $this->by($connection);
            $http = $this->http($connection);
            if ($event === 'notesCreated') {
                foreach (array_slice($ids, 0, 20) as $id) {
                    $r = $this->get($http, 'notes/'.$id, []);
                    if ($r === null) {
                        throw new \RuntimeException('Follow Up Boss asked us to slow down.'); // the job tries again
                    }
                    $counts[$this->note($connection, (array) $r->json(), $by)]++;
                }

                return $counts;
            }
            $r = $this->get($http, 'people', ['id' => implode(',', $ids), 'fields' => 'allFields', 'limit' => 100]);
            if ($r === null) {
                throw new \RuntimeException('Follow Up Boss asked us to slow down.');
            }
            foreach (array_values(array_filter((array) $r->json('people', []), 'is_array')) as $p) {
                $counts[$this->person($connection, $p, $by)]++;
            }

            return $counts;
        });
    }

    public function push(ConnectionData $connection, string $entity, int $localId): void
    {
        $http = $this->http($connection);
        if ($entity === 'contact') {
            $this->pushContact($connection, $http, $localId);
        } elseif ($entity === 'contact_update') {
            $this->pushChange($connection, $http, $localId);
        } elseif ($entity === 'note') {
            $activity = Activity::query()->find($localId);
            if ($activity === null || $activity->type !== 'note' || $this->kit->externalId($connection, 'note', $localId) !== null) {
                return;
            }
            $person = $this->pushContact($connection, $http, (int) $activity->contact_id);
            if ($person === null) {
                return;
            }
            $r = $http->post('notes', ['personId' => (int) $person, 'subject' => 'From RightAlly', 'body' => mb_substr((string) $activity->body, 0, 9000), 'isHtml' => false]);
            self::ok($r);
            $this->kit->remember($connection, 'note', (string) $r->json('id'), $localId);
        }
    }

    /**
     * Name and agent changes to a person who is already linked. Emails, phones, stage and tags are not sent: Follow
     * Up Boss would replace its own lists, and its stages are each account's own.
     */
    private function pushChange(ConnectionData $connection, PendingRequest $http, int $contactId): void
    {
        $known = $this->kit->externalId($connection, 'contact', $contactId);
        $c = Contact::query()->find($contactId);
        if ($known === null || $c === null) {
            return;
        }
        $agent = $c->owner_user_id !== null ? $this->kit->externalId($connection, 'user', (int) $c->owner_user_id) : null;
        $r = $http->put('people/'.$known, array_filter(['firstName' => $c->first_name, 'lastName' => $c->last_name,
            'assignedUserId' => $agent !== null ? (int) $agent : null], fn ($v) => $v !== null && $v !== ''));
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('Follow Up Boss no longer accepts the connection. Please connect it again.');
        }
        if ($r->status() !== 404) { // deleted in Follow Up Boss: nothing to update
            $r->throw();
        }
    }

    /** The person's Follow Up Boss id, adding them there first if needed. */
    private function pushContact(ConnectionData $connection, PendingRequest $http, int $contactId): ?string
    {
        $known = $this->kit->externalId($connection, 'contact', $contactId);
        $c = Contact::query()->find($contactId);
        if ($known !== null || $c === null) {
            return $known;
        }
        $agent = $c->owner_user_id !== null ? $this->kit->externalId($connection, 'user', (int) $c->owner_user_id) : null;
        $r = $http->post('people', array_filter(['firstName' => $c->first_name, 'lastName' => $c->last_name, 'source' => 'RightAlly',
            'emails' => $c->primary_email !== null ? [['value' => $c->primary_email]] : null, 'phones' => $c->primary_phone !== null ? [['value' => $c->primary_phone]] : null,
            'assignedUserId' => $agent !== null ? (int) $agent : null], fn ($v) => $v !== null && $v !== ''));
        self::ok($r);
        $id = (string) $r->json('id');
        $this->kit->remember($connection, 'contact', $id, $c->id);

        return $id;
    }

    public function disconnected(ConnectionData $connection): void
    {
        try {
            $http = $this->http($connection);
            foreach ((array) ($connection->settings['webhooks'] ?? []) as $id) {
                $http->delete('webhooks/'.(string) $id);
            }
        } catch (Throwable $e) {
            report($e); // never stop someone disconnecting
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function quietly(callable $work): mixed
    {
        $was = self::$quiet;
        self::$quiet = true;
        try {
            return $work();
        } finally {
            self::$quiet = $was;
        }
    }

    private function by(ConnectionData $connection): User
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        if (! $by instanceof User || ! $by->isActive()) {
            throw new NeedsReconnect('The person who connected Follow Up Boss no longer has access. Please connect it again.');
        }

        return $by;
    }

    private static function ok(Response $r): void
    {
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('Follow Up Boss no longer accepts the connection. Please connect it again.');
        }
        $r->throw(); // 429 and other errors: the job tries again
        if (! is_scalar($r->json('id'))) {
            throw new \RuntimeException('Follow Up Boss did not return an id.');
        }
    }

    /** Follow Up Boss's users matched to RightAlly people by email (so contacts keep their agent). */
    private function users(PendingRequest $http, ConnectionData $connection): void
    {
        $r = $this->get($http, 'users', ['limit' => 100]);
        foreach ((array) ($r?->json('users', []) ?? []) as $u) {
            $email = is_array($u) ? mb_strtolower((string) ($u['email'] ?? '')) : '';
            $local = $email !== '' ? User::query()->where('email', $email)->first() : null;
            if ($local instanceof User && isset($u['id'])) {
                $this->kit->remember($connection, 'user', (string) $u['id'], $local->id);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $p
     * @return 'created'|'updated'|'skipped'
     */
    private function person(ConnectionData $connection, array $p, User $by): string
    {
        $id = (string) ($p['id'] ?? '');
        if ($id === '') {
            return 'skipped';
        }
        $email = self::primary($p['emails'] ?? null);
        $phone = self::primary($p['phones'] ?? null);
        $known = $this->kit->localId($connection, 'contact', $id);
        $contact = $known !== null ? Contact::query()->find($known) : null;
        $result = 'updated';
        if ($contact === null) {
            $contact = self::existing($email, $phone);
            if ($contact !== null && ! app(ContactVisibility::class)->canSee($by, $contact)) {
                return 'skipped'; // someone else's contact in the brokerage: left alone
            }
            $result = $contact !== null ? 'skipped' : 'created';
        }
        if ($contact === null) {
            $agent = isset($p['assignedUserId']) ? $this->kit->localId($connection, 'user', (string) $p['assignedUserId']) : null;
            try {
                $contact = app(ManageContacts::class)->create(array_filter(['first_name' => (string) ($p['firstName'] ?? ''), 'last_name' => (string) ($p['lastName'] ?? ''),
                    'email' => $email, 'phone' => $phone, 'source' => 'import', 'origin' => $connection->ownerUserId === null ? 'company' : 'personal',
                    'owner_user_id' => $connection->ownerUserId === null && CrmAccess::manager($by) ? ($agent ?? $by->id) : null], fn ($v) => $v !== null), $by);
            } catch (ValidationException) {
                return 'skipped'; // no name, or no email or phone we can use
            }
        }
        $this->kit->remember($connection, 'contact', $id, $contact->id);
        if ($result === 'updated') {
            $names = array_filter(['first_name' => is_string($p['firstName'] ?? null) ? trim($p['firstName']) : null,
                'last_name' => is_string($p['lastName'] ?? null) ? trim($p['lastName']) : null], fn ($v) => $v !== null && $v !== '');
            if ($names !== [] && array_diff_assoc($names, ['first_name' => $contact->first_name, 'last_name' => $contact->last_name]) !== []) {
                app(ManageContacts::class)->update($contact, $names, $by);
            }
        }
        if (is_string($p['stage'] ?? null) && $p['stage'] !== '') { // one stage at a time
            foreach ($contact->tags()->where('name', 'like', 'FUB stage: %')->where('name', '!=', mb_substr('FUB stage: '.$p['stage'], 0, 60))->get() as $old) {
                app(Tags::class)->detach($contact, $old);
            }
        }
        $tags = array_filter([...array_map('strval', array_filter((array) ($p['tags'] ?? []), 'is_scalar')),
            isset($p['stage']) && is_string($p['stage']) && $p['stage'] !== '' ? 'FUB stage: '.$p['stage'] : null]);
        foreach (array_slice($tags, 0, 20) as $tag) {
            app(Tags::class)->attach($contact, mb_substr($tag, 0, 60));
        }
        if ($result === 'created') {
            $contact->forceFill(['source_detail' => mb_substr('Follow Up Boss'.(is_string($p['source'] ?? null) && $p['source'] !== '' ? ': '.$p['source'] : ''), 0, 150)])->save();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $n
     * @return 'created'|'updated'|'skipped'
     */
    private function note(ConnectionData $connection, array $n, User $by): string
    {
        $id = (string) ($n['id'] ?? '');
        $contactId = isset($n['personId']) ? $this->kit->localId($connection, 'contact', (string) $n['personId']) : null;
        $contact = $contactId !== null ? Contact::query()->find($contactId) : null;
        if ($id === '' || $contact === null || $this->kit->localId($connection, 'note', $id) !== null) {
            return 'skipped';
        }
        $body = trim(strip_tags((string) ($n['subject'] ?? '')."\n".(string) ($n['body'] ?? '')));
        if ($body === '') {
            return 'skipped';
        }
        try {
            $when = is_string($n['created'] ?? null) ? CarbonImmutable::parse($n['created'])->utc() : null;
            $activity = app(Timeline::class)->log($contact, ['type' => 'note', 'body' => mb_substr('From Follow Up Boss: '.$body, 0, 9000),
                'occurred_at' => $when !== null && ! $when->isFuture() ? $when->format('Y-m-d H:i:s') : null], $by);
        } catch (ValidationException|InvalidFormatException) {
            return 'skipped';
        }
        $this->kit->remember($connection, 'note', $id, (int) $activity->id);

        return 'created';
    }

    private function http(ConnectionData $connection): PendingRequest
    {
        $keys = $this->kit->secrets('follow_up_boss');

        return Http::withToken($this->kit->accessToken($connection))->acceptJson()->timeout(20)
            ->withHeaders(['X-System' => (string) ($keys['x_system'] ?? ''), 'X-System-Key' => (string) ($keys['x_system_key'] ?? '')])->baseUrl(self::API);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Response|null null when asked to slow down (429)
     */
    private function get(PendingRequest $http, string $path, array $query): ?Response
    {
        $r = $http->get($path, $query);
        if ($r->status() === 429) {
            return null;
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('Follow Up Boss no longer accepts the connection. Please connect it again.');
        }

        return $r->throw();
    }

    private static function primary(mixed $list): ?string
    {
        $items = array_values(array_filter(is_array($list) ? $list : [], 'is_array'));
        usort($items, fn (array $a, array $b): int => (int) ($b['isPrimary'] ?? 0) <=> (int) ($a['isPrimary'] ?? 0));
        $v = trim((string) ($items[0]['value'] ?? ''));

        return $v !== '' ? $v : null;
    }

    private static function existing(?string $email, ?string $phone): ?Contact
    {
        $phone164 = $phone !== null ? ManageContacts::e164($phone) : null;
        if ($email === null && $phone164 === null) {
            return null;
        }

        return Contact::query()->where(fn ($q) => $q->when($email !== null, fn ($w) => $w->orWhere('primary_email', mb_strtolower((string) $email)))
            ->when($phone164 !== null, fn ($w) => $w->orWhere('primary_phone', $phone164)))->orderBy('id')->first();
    }
}
