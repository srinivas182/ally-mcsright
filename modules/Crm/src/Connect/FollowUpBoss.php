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
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Tags;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\ContactVisibility;
use Modules\Crm\CrmAccess;
use Modules\Crm\Models\Contact;

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
 */
final class FollowUpBoss implements Connector
{
    public const API = 'https://api.followupboss.com/v1/';

    private const PAGES_PER_RUN = 5;

    public function __construct(private readonly ConnectorKit $kit) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'Follow Up Boss'; // connected with one click (OAuth), not a pasted key
    }

    public function sync(ConnectionData $connection): array
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        if (! $by instanceof User || ! $by->isActive()) {
            throw new NeedsReconnect('The person who connected Follow Up Boss no longer has access. Please connect it again.');
        }
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $cursor = is_array($cursor) && is_string($cursor['phase'] ?? null) ? $cursor : ['phase' => 'users'];
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $http = $this->http($connection);

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

        return $counts + ['cursor' => (string) json_encode($cursor), 'note' => $cursor['phase'] === 'done' ? 'Everything is over from Follow Up Boss.' : null,
            'more' => $cursor['phase'] !== 'done'];
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
