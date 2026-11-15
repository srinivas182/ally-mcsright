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
 * BoldTrail (kvCORE) (specs/connect/06): contacts, their tags, status and agent, through kvCORE Public API V2 with
 * the person's own "Contacts" API token (BoldTrail → Lead Engine → Lead Dropbox → My API Tokens). Same import rules
 * as every CRM (Importer).
 *
 * Contacts are read 100 at a time (five pages a run). After a full pass, everything is read again daily to pick
 * up new and changed contacts; it is safe to repeat. Notes are not read yet (see the spec).
 */
final class BoldTrail implements Connector
{
    public const API = 'https://api.kvcore.com/v2/public/';

    private const PAGES_PER_RUN = 5;

    public function __construct(private readonly ConnectorKit $kit, private readonly Importer $importer) {}

    public function verify(array $credentials, array $settings): string
    {
        $r = self::http((string) ($credentials['api_key'] ?? ''))->get('contacts', ['limit' => 1, 'page' => 1]);
        if (! $r->successful()) {
            throw ValidationException::withMessages(['api_key' => 'BoldTrail didn\'t accept that token. Make a new "Contacts" token in Lead Engine → Lead Dropbox → My API Tokens.']);
        }

        return 'BoldTrail';
    }

    public function sync(ConnectionData $connection): array
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        if (! $by instanceof User || ! $by->isActive()) {
            throw new NeedsReconnect('The person who connected BoldTrail no longer has access. Please connect it again.');
        }
        $http = self::http((string) ($connection->credentials['api_key'] ?? ''));
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $cursor = is_array($cursor) && is_int($cursor['page'] ?? null) ? $cursor : ['page' => 1];
        if (isset($cursor['done_at']) && is_string($cursor['done_at'])) {
            if (CarbonImmutable::parse($cursor['done_at'])->gt(CarbonImmutable::now()->subDay())) {
                return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'cursor' => (string) json_encode($cursor), 'note' => null];
            }
            $cursor = ['page' => 1]; // daily: read everything again for new and changed contacts
        }
        if ($cursor['page'] === 1) {
            $this->users($connection, $http);
        }
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        for ($i = 0; $i < self::PAGES_PER_RUN; $i++) {
            $r = self::get($http, 'contacts', ['limit' => 100, 'page' => $cursor['page']]);
            if ($r === null) {
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'BoldTrail asked us to slow down; carrying on shortly.', 'more' => true];
            }
            $rows = array_values(array_filter((array) $r->json('data', []), 'is_array'));
            foreach ($rows as $row) {
                $counts[$this->importer->person($connection, $by, self::person($row), 'BoldTrail', 'BoldTrail status')]++;
            }
            $last = $r->json('last_page') ?? $r->json('meta.last_page');
            $more = $rows !== [] && (is_numeric($last) ? $cursor['page'] < (int) $last : count($rows) >= 100);
            if (! $more) {
                return $counts + ['cursor' => (string) json_encode(['page' => 1, 'done_at' => CarbonImmutable::now()->toIso8601String()]),
                    'note' => 'Everything is over from BoldTrail.', 'more' => false];
            }
            $cursor['page']++;
        }

        return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Bringing contacts over from BoldTrail…', 'more' => true];
    }

    /**
     * kvCORE field names vary between accounts and API versions, so each value is read from the names it may use.
     *
     * @param  array<string, mixed>  $c
     * @return array{id: string, first_name: string, last_name: string, email: string|null, phone: string|null, tags: list<string>, stage: string|null, source: string|null, agent_id: string|null}
     */
    private static function person(array $c): array
    {
        $pick = function (array $keys) use ($c): ?string {
            foreach ($keys as $k) {
                if (is_scalar($c[$k] ?? null) && trim((string) $c[$k]) !== '') {
                    return trim((string) $c[$k]);
                }
            }

            return null;
        };
        $tags = [];
        foreach (['hashtags', 'tags'] as $k) {
            foreach ((array) ($c[$k] ?? []) as $t) {
                $name = is_array($t) ? (string) ($t['name'] ?? $t['hashtag'] ?? '') : (is_string($t) ? $t : '');
                if (trim($name) !== '') {
                    $tags[] = ltrim(trim($name), '#');
                }
            }
        }
        $status = $pick(['status_name', 'status']);

        return ['id' => $pick(['id', 'contact_id']) ?? '', 'first_name' => $pick(['first_name', 'firstname']) ?? '', 'last_name' => $pick(['last_name', 'lastname']) ?? '',
            'email' => $pick(['email', 'primary_email']), 'phone' => $pick(['cell_phone_1', 'cell_phone', 'phone', 'home_phone', 'work_phone']),
            'tags' => array_values(array_unique($tags)), 'stage' => $status !== null && ! ctype_digit($status) ? $status : null, 'source' => $pick(['source', 'lead_source']),
            'agent_id' => $pick(['assigned_agent_id', 'agent_id', 'user_id'])];
    }

    /** kvCORE users matched to RightAlly people by email (so contacts keep their agent); best effort. */
    private function users(ConnectionData $connection, PendingRequest $http): void
    {
        $r = $http->get('users', ['limit' => 100]);
        if (! $r->successful()) {
            return; // a "Contacts" token may not see users: contacts then stay with the person who connected
        }
        foreach (array_values(array_filter((array) $r->json('data', []), 'is_array')) as $u) {
            if (is_scalar($u['id'] ?? null)) {
                $this->importer->user($connection, (string) $u['id'], (string) ($u['email'] ?? ''));
            }
        }
    }

    private static function http(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(20)->baseUrl(self::API);
    }

    /** @param  array<string, mixed>  $query */
    private static function get(PendingRequest $http, string $path, array $query): ?Response
    {
        $r = $http->get($path, $query);
        if ($r->status() === 429) {
            return null;
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('BoldTrail no longer accepts the token. Please connect it again with a new token.');
        }

        return $r->throw();
    }
}
