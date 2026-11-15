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
 * Sierra Interactive (specs/connect/07): leads, tags, status, agent and notes, with the site's own API key
 * (Sierra → gear icon → Integrations → Sierra Interactive). Same import rules as every CRM (Importer).
 *
 * The first pass reads every lead, 100 at a time (three pages a run, each lead with its notes). After that, each
 * run asks Sierra only for leads changed since the last run (leadUpdateDateFrom).
 */
final class Sierra implements Connector
{
    public const API = 'https://api.sierrainteractivedev.com/';

    private const PAGES_PER_RUN = 3;

    public function __construct(private readonly ConnectorKit $kit, private readonly Importer $importer) {}

    public function verify(array $credentials, array $settings): string
    {
        $r = self::http((string) ($credentials['api_key'] ?? ''))->get('leads/find', ['pageSize' => 1]);
        if (! $r->successful() || $r->json('success') === false) {
            throw ValidationException::withMessages(['api_key' => 'Sierra didn\'t accept that API key. Copy it again from the gear icon → Integrations → Sierra Interactive.']);
        }

        return 'Sierra Interactive';
    }

    public function sync(ConnectionData $connection): array
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        if (! $by instanceof User || ! $by->isActive()) {
            throw new NeedsReconnect('The person who connected Sierra no longer has access. Please connect it again.');
        }
        $http = self::http((string) ($connection->credentials['api_key'] ?? ''));
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $cursor = is_array($cursor) && is_int($cursor['page'] ?? null) ? $cursor : ['page' => 1, 'since' => null, 'started' => CarbonImmutable::now()->toIso8601String()];
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        for ($i = 0; $i < self::PAGES_PER_RUN; $i++) {
            $query = ['pageSize' => 100, 'pageNumber' => $cursor['page'], 'includeTags' => 'true', 'sortColumn' => 'Id', 'sortOrder' => 'asc']
                + (is_string($cursor['since'] ?? null) ? ['leadUpdateDateFrom' => $cursor['since']] : []);
            $r = self::get($http, 'leads/find', $query);
            if ($r === null) {
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Sierra asked us to slow down; carrying on shortly.', 'more' => true];
            }
            $leads = array_values(array_filter((array) $r->json('data.leads', []), 'is_array'));
            foreach ($leads as $lead) {
                $agent = is_array($lead['assignedTo'] ?? null) ? $lead['assignedTo'] : [];
                if (is_scalar($agent['agentUserId'] ?? null) && is_string($agent['agentUserEmail'] ?? null)) {
                    $this->importer->user($connection, (string) $agent['agentUserId'], $agent['agentUserEmail']);
                }
                $counts[$this->importer->person($connection, $by, self::person($lead), 'Sierra', 'Sierra status')]++;
                $id = is_scalar($lead['id'] ?? null) ? (string) $lead['id'] : '';
                if ($id !== '' && $this->kit->localId($connection, 'contact', $id) !== null) {
                    $this->notes($connection, $http, $by, $id, $counts);
                }
            }
            $pages = (int) ($r->json('data.totalPages') ?? 0);
            if ($leads === [] || $cursor['page'] >= $pages) {
                // Done: next time, only leads changed since this pass began (a minute of overlap; repeats are harmless).
                $next = ['page' => 1, 'since' => CarbonImmutable::parse((string) $cursor['started'])->subMinute()->toIso8601String(), 'started' => CarbonImmutable::now()->toIso8601String()];

                return $counts + ['cursor' => (string) json_encode($next), 'note' => $cursor['since'] === null ? 'Everything is over from Sierra.' : null, 'more' => false];
            }
            $cursor['page']++;
        }

        return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Bringing leads over from Sierra…', 'more' => true];
    }

    /**
     * @param  array<string, mixed>  $lead
     * @return array{id: string, first_name: string, last_name: string, email: string|null, phone: string|null, tags: list<string>, stage: string|null, source: string|null, agent_id: string|null}
     */
    private static function person(array $lead): array
    {
        $s = fn (string $k): ?string => is_scalar($lead[$k] ?? null) && trim((string) $lead[$k]) !== '' ? trim((string) $lead[$k]) : null;
        $agent = is_array($lead['assignedTo'] ?? null) && is_scalar($lead['assignedTo']['agentUserId'] ?? null) ? (string) $lead['assignedTo']['agentUserId'] : null;

        return ['id' => $s('id') ?? '', 'first_name' => $s('firstName') ?? '', 'last_name' => $s('lastName') ?? '', 'email' => $s('email'), 'phone' => $s('phone'),
            'tags' => array_values(array_filter(array_map(fn ($t): string => is_string($t) ? $t : (is_array($t) ? (string) ($t['name'] ?? '') : ''), (array) ($lead['tags'] ?? [])))),
            'stage' => $s('leadStatus'), 'source' => $s('source'), 'agent_id' => $agent];
    }

    /** @param  array{created: int, updated: int, skipped: int}  $counts */
    private function notes(ConnectionData $connection, PendingRequest $http, User $by, string $leadId, array &$counts): void
    {
        $r = self::get($http, 'notes/'.$leadId, ['pageSize' => 100, 'sortOrder' => 'desc']);
        foreach (array_values(array_filter((array) ($r?->json('data', []) ?? []), 'is_array')) as $n) {
            if (($n['isSystemItem'] ?? false) === true) {
                continue;
            }
            $created = $this->importer->note($connection, $by, ['id' => is_scalar($n['id'] ?? null) ? (string) $n['id'] : '', 'person_id' => $leadId,
                'body' => (string) ($n['contents'] ?? ''), 'created' => is_string($n['dateCreated'] ?? null) ? $n['dateCreated'] : null], 'Sierra');
            if ($created === 'created') {
                $counts['created']++;
            }
        }
    }

    private static function http(string $key): PendingRequest
    {
        return Http::withHeaders(['Sierra-ApiKey' => $key, 'Sierra-OriginatingSystemName' => 'RightAlly'])->acceptJson()->timeout(20)->baseUrl(self::API);
    }

    /** @param  array<string, mixed>  $query */
    private static function get(PendingRequest $http, string $path, array $query): ?Response
    {
        $r = $http->get($path, $query);
        if ($r->status() === 429) {
            return null;
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('Sierra no longer accepts the API key. Please connect it again with a new key.');
        }
        if ($r->status() === 404 && str_starts_with($path, 'notes/')) {
            return null;
        }

        return $r->throw();
    }
}
