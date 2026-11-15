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
 * Pipedrive (specs/connect/08): people, owner and notes, with the person's own API token (Pipedrive → avatar →
 * Personal preferences → API). Same import rules as every CRM (Importer).
 *
 * First pass: users, then people (API v2, cursor paging), then notes (API v1, start paging). After that, each run
 * reads people updated since the last run (`updated_since`), with their notes.
 */
final class Pipedrive implements Connector
{
    public const API = 'https://api.pipedrive.com/';

    private const PAGES_PER_RUN = 5;

    public function __construct(private readonly ConnectorKit $kit, private readonly Importer $importer) {}

    public function verify(array $credentials, array $settings): string
    {
        $r = self::http((string) ($credentials['api_key'] ?? ''))->get('api/v1/users/me');
        if (! $r->successful()) {
            throw ValidationException::withMessages(['api_key' => 'Pipedrive didn\'t accept that API token. Copy it from your avatar → Personal preferences → API.']);
        }
        $company = $r->json('data.company_name');

        return 'Pipedrive'.(is_string($company) && $company !== '' ? ' ('.$company.')' : '');
    }

    public function sync(ConnectionData $connection): array
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        if (! $by instanceof User || ! $by->isActive()) {
            throw new NeedsReconnect('The person who connected Pipedrive no longer has access. Please connect it again.');
        }
        $http = self::http((string) ($connection->credentials['api_key'] ?? ''));
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $cursor = is_array($cursor) && is_string($cursor['phase'] ?? null) ? $cursor : ['phase' => 'users', 'started' => CarbonImmutable::now()->toIso8601String()];
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $slow = function (array $c) use (&$counts): array { // keeps what this run already did
            return $counts + ['cursor' => (string) json_encode($c), 'note' => 'Pipedrive asked us to slow down; carrying on shortly.', 'more' => true];
        };

        if ($cursor['phase'] === 'users') {
            $r = $http->get('api/v1/users');
            foreach (array_values(array_filter((array) ($r->successful() ? $r->json('data', []) : []), 'is_array')) as $u) {
                if (is_scalar($u['id'] ?? null)) {
                    $this->importer->user($connection, (string) $u['id'], (string) ($u['email'] ?? ''));
                }
            }
            $cursor['phase'] = 'people';
        }
        if ($cursor['phase'] === 'changes') {
            return $this->changes($connection, $http, $by, $cursor);
        }
        for ($i = 0; $i < self::PAGES_PER_RUN && $cursor['phase'] !== 'changes'; $i++) {
            if ($cursor['phase'] === 'people') {
                $r = self::get($http, 'api/v2/persons', ['limit' => 100] + (is_string($cursor['cursor'] ?? null) ? ['cursor' => $cursor['cursor']] : []));
                if ($r === null) {
                    return $slow($cursor);
                }
                foreach (array_values(array_filter((array) $r->json('data', []), 'is_array')) as $p) {
                    $counts[$this->importer->person($connection, $by, self::person($p), 'Pipedrive', 'Pipedrive stage')]++;
                }
                $next = $r->json('additional_data.next_cursor');
                $cursor = is_string($next) && $next !== '' ? ['phase' => 'people', 'cursor' => $next, 'started' => $cursor['started']]
                    : ['phase' => 'notes', 'start' => 0, 'started' => $cursor['started']];

                continue;
            }
            $r = self::get($http, 'api/v1/notes', ['limit' => 100, 'start' => (int) ($cursor['start'] ?? 0), 'sort' => 'id ASC']);
            if ($r === null) {
                return $slow($cursor);
            }
            foreach (array_values(array_filter((array) $r->json('data', []), 'is_array')) as $n) {
                $counts[$this->note($connection, $by, $n)]++;
            }
            $next = $r->json('additional_data.pagination.next_start');
            $cursor = $r->json('additional_data.pagination.more_items_in_collection') === true && is_int($next)
                ? ['phase' => 'notes', 'start' => $next, 'started' => $cursor['started']]
                : ['phase' => 'changes', 'since' => self::stamp(CarbonImmutable::parse((string) $cursor['started'])->subMinute())];
        }
        $more = $cursor['phase'] !== 'changes';

        return $counts + ['cursor' => (string) json_encode($cursor), 'note' => $more ? 'Bringing people over from Pipedrive…' : 'Everything is over from Pipedrive.', 'more' => $more];
    }

    /**
     * People updated since the last run (and their notes); then the next run counts from this one.
     *
     * @param  array<string, mixed>  $cursor
     * @return array{created: int, updated: int, skipped: int, cursor: string|null, note: string|null, more?: bool}
     */
    private function changes(ConnectionData $connection, PendingRequest $http, User $by, array $cursor): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $started = CarbonImmutable::now();
        $page = null;
        for ($i = 0; $i < self::PAGES_PER_RUN; $i++) {
            $r = self::get($http, 'api/v2/persons', ['limit' => 100, 'updated_since' => (string) ($cursor['since'] ?? self::stamp($started->subDay()))]
                + ($page !== null ? ['cursor' => $page] : []));
            if ($r === null) {
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Pipedrive asked us to slow down; carrying on shortly.'];
            }
            foreach (array_values(array_filter((array) $r->json('data', []), 'is_array')) as $p) {
                $counts[$this->importer->person($connection, $by, self::person($p), 'Pipedrive', 'Pipedrive stage')]++;
                if (is_scalar($p['id'] ?? null) && $this->kit->localId($connection, 'contact', (string) $p['id']) !== null) {
                    $notes = self::get($http, 'api/v1/notes', ['person_id' => (string) $p['id'], 'limit' => 100]);
                    foreach (array_values(array_filter((array) ($notes?->json('data', []) ?? []), 'is_array')) as $n) {
                        if ($this->note($connection, $by, $n) === 'created') {
                            $counts['created']++;
                        }
                    }
                }
            }
            $next = $r->json('additional_data.next_cursor');
            if (! is_string($next) || $next === '') {
                break;
            }
            $page = $next;
        }

        return $counts + ['cursor' => (string) json_encode(['phase' => 'changes', 'since' => self::stamp($started->subMinute())]), 'note' => null];
    }

    private static function stamp(CarbonImmutable $t): string
    {
        return $t->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param  array<string, mixed>  $n
     * @return 'created'|'skipped'
     */
    private function note(ConnectionData $connection, User $by, array $n): string
    {
        return $this->importer->note($connection, $by, ['id' => is_scalar($n['id'] ?? null) ? (string) $n['id'] : '',
            'person_id' => is_scalar($n['person_id'] ?? null) ? (string) $n['person_id'] : '', 'body' => (string) ($n['content'] ?? ''),
            'created' => is_string($n['add_time'] ?? null) ? $n['add_time'] : null], 'Pipedrive');
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{id: string, first_name: string, last_name: string, email: string|null, phone: string|null, tags: list<string>, stage: string|null, source: string|null, agent_id: string|null}
     */
    private static function person(array $p): array
    {
        $primary = function (mixed $list): ?string {
            $items = array_values(array_filter(is_array($list) ? $list : [], 'is_array'));
            usort($items, fn (array $a, array $b): int => (int) ($b['primary'] ?? 0) <=> (int) ($a['primary'] ?? 0));
            $v = trim((string) ($items[0]['value'] ?? ''));

            return $v !== '' ? $v : null;
        };
        $first = is_string($p['first_name'] ?? null) ? $p['first_name'] : '';
        $last = is_string($p['last_name'] ?? null) ? $p['last_name'] : '';
        if ($first === '' && $last === '' && is_string($p['name'] ?? null)) {
            [$first, $last] = array_pad(explode(' ', trim($p['name']), 2), 2, '');
        }

        return ['id' => is_scalar($p['id'] ?? null) ? (string) $p['id'] : '', 'first_name' => $first, 'last_name' => $last, 'email' => $primary($p['emails'] ?? null),
            'phone' => $primary($p['phones'] ?? null), 'tags' => [], 'stage' => null, 'source' => null,
            'agent_id' => is_scalar($p['owner_id'] ?? null) ? (string) $p['owner_id'] : null];
    }

    private static function http(string $token): PendingRequest
    {
        return Http::withHeaders(['x-api-token' => $token])->acceptJson()->timeout(20)->baseUrl(self::API);
    }

    /** @param  array<string, mixed>  $query */
    private static function get(PendingRequest $http, string $path, array $query): ?Response
    {
        $r = $http->get($path, $query);
        if ($r->status() === 429) {
            return null;
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('Pipedrive no longer accepts the API token. Please connect it again with a new one.');
        }

        return $r->throw();
    }
}
