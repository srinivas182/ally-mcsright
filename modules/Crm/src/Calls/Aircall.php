<?php

declare(strict_types=1);

namespace Modules\Crm\Calls;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\NeedsReconnect;

/**
 * Aircall (specs/connect/15, C7b): Public API with an API ID and token (Basic auth). Calls since the last run are
 * read every hour (GET /v1/calls with from=, oldest first) and logged on matching contacts.
 */
final class Aircall implements Connector
{
    public const API = 'https://api.aircall.io/v1/';

    public function verify(array $credentials, array $settings): string
    {
        $r = self::http($credentials)->get('company');
        if (in_array($r->status(), [401, 403], true) || ! $r->successful()) {
            throw ValidationException::withMessages(['api_token' => 'Aircall didn\'t accept that API ID and token. Make them under Integrations → API Keys in Aircall (admin).']);
        }

        return 'Aircall'.(is_string($r->json('company.name')) ? ' ('.$r->json('company.name').')' : '');
    }

    public function sync(ConnectionData $connection): array
    {
        $from = $connection->cursor !== null && ctype_digit($connection->cursor) ? (int) $connection->cursor : CarbonImmutable::now()->subDay()->getTimestamp();
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $latest = $from;
        $http = self::http($connection->credentials);
        for ($page = 1; $page <= 5; $page++) {
            $r = $http->get('calls', ['from' => $from, 'order' => 'asc', 'per_page' => 50, 'page' => $page]);
            if (in_array($r->status(), [401, 403], true)) {
                throw new NeedsReconnect('Aircall no longer accepts the API token. Make a new one and connect again.');
            }
            $r->throw();
            foreach ((array) $r->json('calls', []) as $call) {
                if (! is_array($call) || ($call['status'] ?? null) !== 'done') {
                    continue; // still ringing or in progress: next run
                }
                $started = (int) ($call['started_at'] ?? 0);
                $latest = max($latest, $started + 1);
                $counts[app(CallLog::class)->log($connection, ['id' => (string) ($call['id'] ?? ''), 'kind' => 'call', 'direction' => ($call['direction'] ?? '') === 'inbound' ? 'in' : 'out',
                    'number' => (string) ($call['raw_digits'] ?? ''), 'at' => CarbonImmutable::createFromTimestamp($started)->toIso8601String(), 'seconds' => (int) ($call['duration'] ?? 0),
                    'answered' => ($call['answered_at'] ?? null) !== null, 'voicemail' => is_string($call['voicemail'] ?? null), 'agent_email' => $call['user']['email'] ?? null,
                    'recording' => is_string($call['recording'] ?? null) ? $call['recording'] : null], 'Aircall')]++;
            }
            if (! is_string($r->json('meta.next_page_link'))) {
                break;
            }
        }

        return $counts + ['cursor' => (string) $latest, 'note' => null];
    }

    /** @param  array<string, string>  $credentials */
    private static function http(array $credentials): PendingRequest
    {
        return Http::withBasicAuth((string) ($credentials['api_id'] ?? ''), (string) ($credentials['api_token'] ?? ''))->acceptJson()->timeout(20)->baseUrl(self::API);
    }
}
