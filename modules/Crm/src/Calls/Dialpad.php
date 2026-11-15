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
 * Dialpad (specs/connect/15, C7b): API key (Bearer). Calls that ended since the last run are read every hour (GET
 * /api/v2/call, started after the last call, times in milliseconds) and logged on matching contacts.
 */
final class Dialpad implements Connector
{
    public const API = 'https://dialpad.com/api/v2/';

    public function verify(array $credentials, array $settings): string
    {
        $r = self::http($credentials)->get('company');
        if (! $r->successful()) {
            throw ValidationException::withMessages(['api_key' => 'Dialpad didn\'t accept that API key. An admin makes one under Admin settings → My company → Authentication → API keys.']);
        }

        return 'Dialpad'.(is_string($r->json('name')) ? ' ('.$r->json('name').')' : '');
    }

    public function sync(ConnectionData $connection): array
    {
        $from = $connection->cursor !== null && ctype_digit($connection->cursor) ? (int) $connection->cursor : CarbonImmutable::now()->subDay()->getTimestampMs();
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $latest = $from;
        $cursor = null;
        $http = self::http($connection->credentials);
        for ($page = 0; $page < 5; $page++) {
            $r = $http->get('call', array_filter(['started_after' => $from, 'cursor' => $cursor]));
            if (in_array($r->status(), [401, 403], true)) {
                throw new NeedsReconnect('Dialpad no longer accepts the API key. Make a new one and connect again.');
            }
            $r->throw();
            foreach ((array) $r->json('items', []) as $call) {
                if (! is_array($call) || ($call['date_ended'] ?? null) === null) {
                    continue;
                }
                $started = (int) ($call['date_started'] ?? 0);
                $latest = max($latest, $started + 1);
                $rec = $call['recording_details'][0]['url'] ?? ($call['recording_url'][0] ?? null);
                $counts[app(CallLog::class)->log($connection, ['id' => (string) ($call['call_id'] ?? ''), 'kind' => 'call', 'direction' => ($call['direction'] ?? '') === 'inbound' ? 'in' : 'out',
                    'number' => (string) ($call['external_number'] ?? ''), 'at' => CarbonImmutable::createFromTimestampMs($started)->toIso8601String(),
                    'seconds' => (int) round(((int) ($call['duration'] ?? 0)) / 1000), 'answered' => (int) ($call['duration'] ?? 0) > 0,
                    'agent_email' => is_string($call['target']['email'] ?? null) ? $call['target']['email'] : null, 'recording' => is_string($rec) ? $rec : null], 'Dialpad')]++;
            }
            $cursor = $r->json('cursor');
            if (! is_string($cursor) || $cursor === '') {
                break;
            }
        }

        return $counts + ['cursor' => (string) $latest, 'note' => null];
    }

    /** @param  array<string, string>  $credentials */
    private static function http(array $credentials): PendingRequest
    {
        return Http::withToken((string) ($credentials['api_key'] ?? ''))->acceptJson()->timeout(20)->baseUrl(self::API);
    }
}
