<?php

declare(strict_types=1);

namespace Modules\Crm\Connect;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;

/**
 * Salesforce, and Propertybase (which runs on Salesforce) (specs/connect/09): contacts and open leads, with their
 * owner, status and source, through the org's own API (one-click sign-in with RightAlly's connected app). Same import
 * rules as every CRM (Importer).
 *
 * First pass: users, then contacts, then open (unconverted) leads, by SOQL, 200 a page. After that, each run asks
 * only for records modified since the last run (LastModifiedDate).
 */
final class Salesforce implements Connector
{
    private const VERSION = 'v62.0';

    private const PAGES_PER_RUN = 5;

    private const OBJECTS = [
        'Contact' => 'SELECT Id, FirstName, LastName, Email, MobilePhone, Phone, LeadSource, OwnerId, LastModifiedDate FROM Contact',
        'Lead' => 'SELECT Id, FirstName, LastName, Email, MobilePhone, Phone, LeadSource, Status, OwnerId, LastModifiedDate FROM Lead WHERE IsConverted = false',
    ];

    public function __construct(private readonly ConnectorKit $kit, private readonly Importer $importer) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'Salesforce'; // connected with one click (OAuth), not a pasted key
    }

    public function sync(ConnectionData $connection): array
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        if (! $by instanceof User || ! $by->isActive()) {
            throw new NeedsReconnect('The person who connected Salesforce no longer has access. Please connect it again.');
        }
        $base = (string) ($connection->credentials['instance_url'] ?? '');
        if (! str_starts_with($base, 'https://')) {
            throw new NeedsReconnect('Please connect Salesforce again.');
        }
        $http = Http::withToken($this->kit->accessToken($connection))->acceptJson()->timeout(30)->baseUrl(rtrim($base, '/').'/services/data/'.self::VERSION.'/');
        $app = $connection->appKey === 'propertybase' ? 'Propertybase' : 'Salesforce';
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $cursor = is_array($cursor) && is_string($cursor['phase'] ?? null) ? $cursor : ['phase' => 'users', 'started' => CarbonImmutable::now()->toIso8601String()];
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        if ($cursor['phase'] === 'users') {
            $r = self::get($http, 'query', ['q' => 'SELECT Id, Email FROM User WHERE IsActive = true']);
            foreach (array_values(array_filter((array) ($r?->json('records', []) ?? []), 'is_array')) as $u) {
                if (is_string($u['Id'] ?? null)) {
                    $this->importer->user($connection, $u['Id'], (string) ($u['Email'] ?? ''));
                }
            }
            $cursor = ['phase' => 'Contact', 'started' => $cursor['started']];
        }
        $since = is_string($cursor['since'] ?? null) ? $cursor['since'] : null;
        for ($i = 0; $i < self::PAGES_PER_RUN && $cursor['phase'] !== 'done'; $i++) {
            $object = $cursor['phase'] === 'Lead' ? 'Lead' : 'Contact';
            if (is_string($cursor['next'] ?? null)) {
                $r = self::get($http, ltrim(substr($cursor['next'], strlen('/services/data/'.self::VERSION.'/')), '/'), []);
            } else {
                $soql = self::OBJECTS[$object].($since !== null ? (str_contains(self::OBJECTS[$object], 'WHERE') ? ' AND' : ' WHERE').' LastModifiedDate > '.$since : '').' ORDER BY Id';
                $r = self::get($http, 'query', ['q' => $soql]);
            }
            if ($r === null) {
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Salesforce asked us to slow down; carrying on shortly.', 'more' => $since === null];
            }
            foreach (array_values(array_filter((array) $r->json('records', []), 'is_array')) as $rec) {
                $counts[$this->importer->person($connection, $by, self::person($rec), $app, $app.' status')]++;
            }
            $next = $r->json('nextRecordsUrl');
            $cursor['next'] = is_string($next) && $next !== '' ? $next : null;
            if ($cursor['next'] === null) {
                $cursor['phase'] = $object === 'Contact' ? 'Lead' : 'done';
            }
        }
        if ($cursor['phase'] !== 'done') {
            return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Bringing contacts over from '.$app.'…', 'more' => true];
        }
        // Next time, only records changed since this pass began (a minute of overlap; repeats are harmless).
        $next = ['phase' => 'Contact', 'since' => CarbonImmutable::parse((string) $cursor['started'])->subMinute()->utc()->format('Y-m-d\TH:i:s\Z'),
            'started' => CarbonImmutable::now()->toIso8601String()];

        return $counts + ['cursor' => (string) json_encode($next), 'note' => $since === null ? 'Everything is over from '.$app.'.' : null, 'more' => false];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{id: string, first_name: string, last_name: string, email: string|null, phone: string|null, tags: list<string>, stage: string|null, source: string|null, agent_id: string|null}
     */
    private static function person(array $r): array
    {
        $s = fn (string $k): ?string => is_scalar($r[$k] ?? null) && trim((string) $r[$k]) !== '' ? trim((string) $r[$k]) : null;
        $type = is_array($r['attributes'] ?? null) && is_string($r['attributes']['type'] ?? null) ? $r['attributes']['type'] : 'Contact';

        return ['id' => $s('Id') ?? '', 'first_name' => $s('FirstName') ?? '', 'last_name' => $s('LastName') ?? '', 'email' => $s('Email'),
            'phone' => $s('MobilePhone') ?? $s('Phone'), 'tags' => $type === 'Lead' ? ['Salesforce lead'] : [], 'stage' => $s('Status'), 'source' => $s('LeadSource'),
            'agent_id' => $s('OwnerId')];
    }

    /** @param  array<string, mixed>  $query */
    private static function get(PendingRequest $http, string $path, array $query): ?Response
    {
        $r = $http->get($path, $query);
        if ($r->status() === 429 || ($r->status() === 403 && str_contains((string) $r->body(), 'REQUEST_LIMIT_EXCEEDED'))) {
            return null;
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('Salesforce no longer accepts the connection. Please connect it again.');
        }

        return $r->throw();
    }
}
