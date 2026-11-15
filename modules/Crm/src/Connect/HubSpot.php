<?php

declare(strict_types=1);

namespace Modules\Crm\Connect;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;

/**
 * HubSpot (specs/connect/08): contacts, lead status, owner and notes, with a private app access token (HubSpot →
 * Settings → Integrations → Private Apps; scopes crm.objects.contacts.read, crm.objects.owners.read). Same import
 * rules as every CRM (Importer).
 *
 * First pass: owners, then contacts (100 a page, `after` paging), then notes with their contacts. After that, each
 * run searches only contacts and notes modified since the last run.
 */
final class HubSpot implements Connector
{
    public const API = 'https://api.hubapi.com/crm/v3/';

    private const PAGES_PER_RUN = 5;

    private const PROPS = 'firstname,lastname,email,phone,mobilephone,hs_lead_status,lifecyclestage,hubspot_owner_id,hs_analytics_source';

    public function __construct(private readonly ConnectorKit $kit, private readonly Importer $importer) {}

    public function verify(array $credentials, array $settings): string
    {
        $r = self::http((string) ($credentials['api_key'] ?? ''))->get('objects/contacts', ['limit' => 1]);
        if (! $r->successful()) {
            throw ValidationException::withMessages(['api_key' => 'HubSpot didn\'t accept that token. Make a private app with "crm.objects.contacts.read" and copy its access token.']);
        }

        return 'HubSpot';
    }

    public function sync(ConnectionData $connection): array
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        if (! $by instanceof User || ! $by->isActive()) {
            throw new NeedsReconnect('The person who connected HubSpot no longer has access. Please connect it again.');
        }
        $http = self::http((string) ($connection->credentials['api_key'] ?? ''));
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $cursor = is_array($cursor) && is_string($cursor['phase'] ?? null) ? $cursor : ['phase' => 'owners', 'started' => CarbonImmutable::now()->toIso8601String()];
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        if ($cursor['phase'] === 'owners') {
            $r = $http->get('owners', ['limit' => 100]);
            foreach (array_values(array_filter((array) ($r->successful() ? $r->json('results', []) : []), 'is_array')) as $o) {
                if (is_scalar($o['id'] ?? null)) {
                    $this->importer->user($connection, (string) $o['id'], (string) ($o['email'] ?? ''));
                }
            }
            $cursor['phase'] = 'contacts';
        }
        for ($i = 0; $i < self::PAGES_PER_RUN && in_array($cursor['phase'], ['contacts', 'notes'], true); $i++) {
            $notes = $cursor['phase'] === 'notes';
            $r = self::get($http, 'objects/'.($notes ? 'notes' : 'contacts'), ['limit' => 100, 'archived' => 'false']
                + ($notes ? ['properties' => 'hs_note_body,hs_timestamp', 'associations' => 'contacts'] : ['properties' => self::PROPS])
                + (is_string($cursor['after'] ?? null) ? ['after' => $cursor['after']] : []));
            if ($r === null) {
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'HubSpot asked us to slow down; carrying on shortly.', 'more' => true];
            }
            $this->rows($connection, $by, (array) $r->json('results', []), $notes, $counts);
            $after = $r->json('paging.next.after');
            $cursor['after'] = is_string($after) && $after !== '' ? $after : null;
            if ($cursor['after'] === null) {
                $cursor = $notes ? ['phase' => 'changes', 'since' => CarbonImmutable::parse((string) $cursor['started'])->subMinute()->toIso8601String()]
                    : ['phase' => 'notes', 'started' => $cursor['started']];
            }
        }
        if (in_array($cursor['phase'], ['contacts', 'notes'], true)) {
            return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Bringing contacts over from HubSpot…', 'more' => true];
        }
        if ($cursor['phase'] === 'changes' && ($cursor['checked'] ?? false) === true) {
            $started = CarbonImmutable::now();
            foreach (['contacts' => 'lastmodifieddate', 'notes' => 'hs_lastmodifieddate'] as $object => $field) {
                $r = self::post($http, 'objects/'.$object.'/search', ['limit' => 100, 'sorts' => [['propertyName' => $field, 'direction' => 'DESCENDING']],
                    'filterGroups' => [['filters' => [['propertyName' => $field, 'operator' => 'GTE', 'value' => (string) CarbonImmutable::parse((string) $cursor['since'])->getTimestampMs()]]]],
                    'properties' => $object === 'notes' ? ['hs_note_body', 'hs_timestamp'] : explode(',', self::PROPS)]);
                if ($r === null) {
                    return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'HubSpot asked us to slow down; carrying on shortly.'];
                }
                $rows = (array) $r->json('results', []);
                if ($object === 'notes') {
                    $rows = $this->withContacts($http, $rows);
                }
                $this->rows($connection, $by, $rows, $object === 'notes', $counts);
            }
            $cursor['since'] = $started->subMinute()->toIso8601String();
        }
        $cursor['checked'] = true;

        return $counts + ['cursor' => (string) json_encode($cursor), 'note' => null, 'more' => false];
    }

    /**
     * @param  array<mixed>  $rows
     * @param  array{created: int, updated: int, skipped: int}  $counts
     */
    private function rows(ConnectionData $connection, User $by, array $rows, bool $notes, array &$counts): void
    {
        foreach (array_values(array_filter($rows, 'is_array')) as $row) {
            $id = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '';
            $p = is_array($row['properties'] ?? null) ? $row['properties'] : [];
            $s = fn (string $k): ?string => is_scalar($p[$k] ?? null) && trim((string) $p[$k]) !== '' ? trim((string) $p[$k]) : null;
            if ($notes) {
                $contact = $row['associations']['contacts']['results'][0]['id'] ?? null;
                $result = $this->importer->note($connection, $by, ['id' => $id, 'person_id' => is_scalar($contact) ? (string) $contact : '',
                    'body' => $s('hs_note_body') ?? '', 'created' => $s('hs_timestamp')], 'HubSpot');
                $counts[$result]++;

                continue;
            }
            $counts[$this->importer->person($connection, $by, ['id' => $id, 'first_name' => $s('firstname') ?? '', 'last_name' => $s('lastname') ?? '',
                'email' => $s('email'), 'phone' => $s('mobilephone') ?? $s('phone'), 'tags' => $s('lifecyclestage') !== null ? ['HubSpot lifecycle: '.$s('lifecyclestage')] : [],
                'stage' => $s('hs_lead_status'), 'source' => $s('hs_analytics_source'), 'agent_id' => $s('hubspot_owner_id')], 'HubSpot', 'HubSpot lead status')]++;
        }
    }

    /**
     * Search results don't carry associations: look up each note's contact (batch).
     *
     * @param  array<mixed>  $notes
     * @return list<array<string, mixed>>
     */
    private function withContacts(PendingRequest $http, array $notes): array
    {
        $notes = array_values(array_filter($notes, fn ($n): bool => is_array($n) && is_scalar($n['id'] ?? null)));
        if ($notes === []) {
            return [];
        }
        $r = $http->post('https://api.hubapi.com/crm/v4/associations/notes/contacts/batch/read', ['inputs' => array_map(fn (array $n): array => ['id' => (string) $n['id']], $notes)]);
        $map = [];
        foreach ((array) ($r->successful() ? $r->json('results', []) : []) as $a) {
            if (is_array($a) && is_scalar($a['from']['id'] ?? null) && is_scalar($a['to'][0]['toObjectId'] ?? null)) {
                $map[(string) $a['from']['id']] = (string) $a['to'][0]['toObjectId'];
            }
        }

        return array_map(fn (array $n): array => $n + ['associations' => ['contacts' => ['results' => isset($map[(string) $n['id']]) ? [['id' => $map[(string) $n['id']]]] : []]]], $notes);
    }

    private static function http(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(20)->baseUrl(self::API);
    }

    /** @param  array<string, mixed>  $query */
    private static function get(PendingRequest $http, string $path, array $query): ?Response
    {
        return self::check($http->get($path, $query));
    }

    /** @param  array<string, mixed>  $body */
    private static function post(PendingRequest $http, string $path, array $body): ?Response
    {
        return self::check($http->post($path, $body));
    }

    private static function check(Response $r): ?Response
    {
        if ($r->status() === 429) {
            return null;
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('HubSpot no longer accepts the token. Please connect it again with a new private app token.');
        }

        return $r->throw();
    }
}
