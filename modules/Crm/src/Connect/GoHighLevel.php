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
 * GoHighLevel (specs/connect/07): contacts, tags, source, agent and notes from one sub-account, with a Private
 * Integration token (Settings → Integrations → Private Integrations) and the sub-account's Location ID. Same import
 * rules as every CRM (Importer).
 *
 * The first pass walks every contact oldest first (searchAfter paging, 100 a page, three pages a run). After that,
 * each run reads contacts by most recent change and stops at the last run.
 */
final class GoHighLevel implements Connector
{
    public const API = 'https://services.leadconnectorhq.com/';

    private const PAGES_PER_RUN = 3;

    public function __construct(private readonly ConnectorKit $kit, private readonly Importer $importer) {}

    public function verify(array $credentials, array $settings): string
    {
        $location = trim((string) ($settings['location_id'] ?? ''));
        if (! preg_match('/^[A-Za-z0-9]{10,40}$/', $location)) {
            throw ValidationException::withMessages(['location_id' => 'Copy the Location ID from Settings → Business Profile in your GoHighLevel sub-account.']);
        }
        $r = self::search(self::http((string) ($credentials['api_key'] ?? '')), ['locationId' => $location, 'pageLimit' => 1]);
        if (! $r->successful()) {
            throw ValidationException::withMessages(['api_key' => 'GoHighLevel didn\'t accept that token and Location ID. Make a Private Integration token with "View Contacts" in that sub-account.']);
        }

        return 'GoHighLevel';
    }

    public function sync(ConnectionData $connection): array
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        if (! $by instanceof User || ! $by->isActive()) {
            throw new NeedsReconnect('The person who connected GoHighLevel no longer has access. Please connect it again.');
        }
        $http = self::http((string) ($connection->credentials['api_key'] ?? ''));
        $location = (string) ($connection->settings['location_id'] ?? '');
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $cursor = is_array($cursor) && is_string($cursor['phase'] ?? null) ? $cursor : ['phase' => 'all', 'after' => null, 'started' => CarbonImmutable::now()->toIso8601String()];
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        if ($cursor['phase'] === 'all' && $cursor['after'] === null) {
            $this->users($connection, $http, $location);
        }
        for ($i = 0; $i < self::PAGES_PER_RUN; $i++) {
            $live = $cursor['phase'] === 'changes';
            $body = ['locationId' => $location, 'pageLimit' => 100, 'sort' => [['field' => $live ? 'dateUpdated' : 'dateAdded', 'direction' => $live ? 'desc' : 'asc']]]
                + (is_array($cursor['after'] ?? null) ? ['searchAfter' => $cursor['after']] : []);
            $r = self::search($http, $body);
            if ($r->status() === 429) {
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'GoHighLevel asked us to slow down; carrying on shortly.', 'more' => true];
            }
            self::check($r);
            $contacts = array_values(array_filter((array) $r->json('contacts', []), 'is_array'));
            $older = false;
            foreach ($contacts as $c) {
                if ($live && is_string($c['dateUpdated'] ?? null) && is_string($cursor['since'] ?? null)
                    && CarbonImmutable::parse($c['dateUpdated'])->lte(CarbonImmutable::parse($cursor['since']))) {
                    $older = true;
                    break;
                }
                $result = $this->importer->person($connection, $by, self::person($c), 'GoHighLevel', 'GoHighLevel stage');
                $counts[$result]++;
                $id = is_string($c['id'] ?? null) ? $c['id'] : '';
                if ($id !== '' && $this->kit->localId($connection, 'contact', $id) !== null) {
                    $this->notes($connection, $http, $by, $id, $counts);
                }
            }
            $last = $contacts !== [] ? end($contacts) : null;
            $after = is_array($last) && is_array($last['searchAfter'] ?? null) ? $last['searchAfter'] : null;
            if ($older || $contacts === [] || count($contacts) < 100 || $after === null) {
                $next = ['phase' => 'changes', 'after' => null, 'since' => CarbonImmutable::parse((string) $cursor['started'])->subMinute()->toIso8601String(),
                    'started' => CarbonImmutable::now()->toIso8601String()];

                return $counts + ['cursor' => (string) json_encode($next), 'note' => $live ? null : 'Everything is over from GoHighLevel.', 'more' => false];
            }
            $cursor['after'] = $after;
        }

        return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Bringing contacts over from GoHighLevel…', 'more' => true];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array{id: string, first_name: string, last_name: string, email: string|null, phone: string|null, tags: list<string>, stage: string|null, source: string|null, agent_id: string|null}
     */
    private static function person(array $c): array
    {
        $s = fn (string $k): ?string => is_scalar($c[$k] ?? null) && trim((string) $c[$k]) !== '' ? trim((string) $c[$k]) : null;

        return ['id' => $s('id') ?? '', 'first_name' => $s('firstName') ?? '', 'last_name' => $s('lastName') ?? '', 'email' => $s('email'), 'phone' => $s('phone'),
            'tags' => array_values(array_filter((array) ($c['tags'] ?? []), fn ($t): bool => is_string($t) && trim($t) !== '')), 'stage' => null,
            'source' => $s('source'), 'agent_id' => $s('assignedTo')];
    }

    /** @param  array{created: int, updated: int, skipped: int}  $counts */
    private function notes(ConnectionData $connection, PendingRequest $http, User $by, string $contactId, array &$counts): void
    {
        $r = $http->withHeaders(['Version' => '2021-07-28'])->get('contacts/'.$contactId.'/notes');
        if (! $r->successful()) {
            return;
        }
        foreach (array_values(array_filter((array) $r->json('notes', []), 'is_array')) as $n) {
            $created = $this->importer->note($connection, $by, ['id' => is_scalar($n['id'] ?? null) ? (string) $n['id'] : '', 'person_id' => $contactId,
                'body' => (string) ($n['body'] ?? ''), 'created' => is_string($n['dateAdded'] ?? null) ? $n['dateAdded'] : null], 'GoHighLevel');
            if ($created === 'created') {
                $counts['created']++;
            }
        }
    }

    /** Sub-account users matched to RightAlly people by email; best effort (the token may not have "View Users"). */
    private function users(ConnectionData $connection, PendingRequest $http, string $location): void
    {
        $r = $http->withHeaders(['Version' => '2021-07-28'])->get('users/', ['locationId' => $location]);
        foreach (array_values(array_filter((array) ($r->successful() ? $r->json('users', []) : []), 'is_array')) as $u) {
            if (is_string($u['id'] ?? null)) {
                $this->importer->user($connection, $u['id'], (string) ($u['email'] ?? ''));
            }
        }
    }

    /** @param  array<string, mixed>  $body */
    private static function search(PendingRequest $http, array $body): Response
    {
        return $http->withHeaders(['Version' => 'v3'])->post('contacts/search', $body);
    }

    private static function check(Response $r): void
    {
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('GoHighLevel no longer accepts the token. Please connect it again with a new Private Integration token.');
        }
        $r->throw();
    }

    private static function http(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(20)->baseUrl(self::API);
    }
}
