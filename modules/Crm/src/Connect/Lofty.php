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
 * Lofty (formerly Chime) (specs/connect/06): people, tags, stage, agent and notes, through Lofty's Open API with the
 * person's own API key (Lofty → Settings → Integrations → API). Same import rules as every CRM (Importer).
 *
 * Leads are read oldest first, 100 at a time (two pages a run, each with its notes), so new leads in Lofty simply
 * come after the last one read: each hourly run picks them up. Once a week everything is read again, to catch
 * changes to people already brought over.
 */
final class Lofty implements Connector
{
    public const API = 'https://api.lofty.com/v1.0/';

    private const PAGES_PER_RUN = 2;

    public function __construct(private readonly ConnectorKit $kit, private readonly Importer $importer) {}

    public function verify(array $credentials, array $settings): string
    {
        $r = self::http((string) ($credentials['api_key'] ?? ''))->get('members', ['limit' => 1]);
        if (! $r->successful()) {
            throw ValidationException::withMessages(['api_key' => 'Lofty didn\'t accept that API key. Copy it again from Lofty → Settings → Integrations → API.']);
        }

        return 'Lofty';
    }

    public function sync(ConnectionData $connection): array
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        if (! $by instanceof User || ! $by->isActive()) {
            throw new NeedsReconnect('The person who connected Lofty no longer has access. Please connect it again.');
        }
        $http = self::http((string) ($connection->credentials['api_key'] ?? ''));
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $cursor = is_array($cursor) && is_int($cursor['offset'] ?? null) ? $cursor : ['offset' => 0, 'full_at' => CarbonImmutable::now()->toIso8601String()];
        if (is_string($cursor['full_at'] ?? null) && CarbonImmutable::parse($cursor['full_at'])->lt(CarbonImmutable::now()->subWeek())) {
            $cursor = ['offset' => 0, 'full_at' => CarbonImmutable::now()->toIso8601String()]; // weekly: read everything again for changes
        }
        if ($cursor['offset'] === 0) {
            $this->members($connection, $http);
        }
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        for ($page = 0; $page < self::PAGES_PER_RUN; $page++) {
            $r = self::get($http, 'leads', ['limit' => 100, 'offset' => $cursor['offset'], 'sort' => 'CreateTime', 'desc' => 'false']);
            if ($r === null) {
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Lofty asked us to slow down; carrying on shortly.', 'more' => true];
            }
            $leads = array_values(array_filter((array) $r->json('leads', []), 'is_array'));
            foreach ($leads as $lead) {
                $result = $this->importer->person($connection, $by, self::person($lead), 'Lofty', 'Lofty stage');
                $counts[$result]++;
                $id = is_scalar($lead['leadId'] ?? null) ? (string) $lead['leadId'] : '';
                if ($id !== '' && $this->kit->localId($connection, 'contact', $id) !== null) {
                    $this->notes($connection, $http, $by, $id, $counts);
                }
            }
            $cursor['offset'] += count($leads);
            if (count($leads) < 100) {
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Up to date with Lofty.', 'more' => false];
            }
        }

        return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Bringing people over from Lofty…', 'more' => true];
    }

    /**
     * @param  array<string, mixed>  $lead
     * @return array{id: string, first_name: string, last_name: string, email: string|null, phone: string|null, tags: list<string>, stage: string|null, source: string|null, agent_id: string|null}
     */
    private static function person(array $lead): array
    {
        $first = fn (mixed $list): ?string => is_array($list) && is_string($list[0] ?? null) && trim($list[0]) !== '' ? trim($list[0]) : null;

        return ['id' => is_scalar($lead['leadId'] ?? null) ? (string) $lead['leadId'] : '', 'first_name' => is_string($lead['firstName'] ?? null) ? $lead['firstName'] : '',
            'last_name' => is_string($lead['lastName'] ?? null) ? $lead['lastName'] : '', 'email' => $first($lead['emails'] ?? null), 'phone' => $first($lead['phones'] ?? null),
            'tags' => array_values(array_filter(array_map(fn ($t): string => is_array($t) ? (string) ($t['tagName'] ?? '') : (is_string($t) ? $t : ''), (array) ($lead['tags'] ?? [])))),
            'stage' => is_string($lead['stage'] ?? null) ? $lead['stage'] : null, 'source' => is_string($lead['source'] ?? null) ? $lead['source'] : null,
            'agent_id' => is_scalar($lead['assignedUserId'] ?? null) && (string) $lead['assignedUserId'] !== '0' ? (string) $lead['assignedUserId'] : null];
    }

    /** @param  array{created: int, updated: int, skipped: int}  $counts */
    private function notes(ConnectionData $connection, PendingRequest $http, User $by, string $leadId, array &$counts): void
    {
        $r = self::get($http, 'notes', ['leadId' => $leadId]);
        foreach (array_values(array_filter((array) ($r?->json('notes', []) ?? []), 'is_array')) as $n) {
            if (($n['systemNote'] ?? false) === true || ($n['deleteFlag'] ?? false) === true) {
                continue;
            }
            $created = $this->importer->note($connection, $by, ['id' => is_scalar($n['noteId'] ?? null) ? (string) $n['noteId'] : '', 'person_id' => $leadId,
                'body' => (string) ($n['content'] ?? ''), 'created' => is_string($n['createTime'] ?? null) ? $n['createTime'] : null], 'Lofty');
            if ($created === 'created') {
                $counts['created']++;
            }
        }
    }

    private function members(ConnectionData $connection, PendingRequest $http): void
    {
        for ($offset = 0; $offset < 1000; $offset += 100) {
            $r = self::get($http, 'members', ['limit' => 100, 'offset' => $offset]);
            $members = array_values(array_filter((array) ($r?->json('members', []) ?? []), 'is_array'));
            foreach ($members as $m) {
                if (is_scalar($m['memberUserId'] ?? null)) {
                    $this->importer->user($connection, (string) $m['memberUserId'], (string) ($m['email'] ?? ''));
                }
            }
            if (count($members) < 100) {
                return;
            }
        }
    }

    private static function http(string $key): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'token '.$key])->acceptJson()->timeout(20)->baseUrl(self::API);
    }

    /** @param  array<string, mixed>  $query */
    private static function get(PendingRequest $http, string $path, array $query): ?Response
    {
        $r = $http->get($path, $query);
        if ($r->status() === 429) {
            return null;
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('Lofty no longer accepts the API key. Please connect it again with a new key.');
        }
        if ($r->status() === 404 && $path === 'notes') {
            return null; // the lead was removed in Lofty
        }

        return $r->throw();
    }
}
