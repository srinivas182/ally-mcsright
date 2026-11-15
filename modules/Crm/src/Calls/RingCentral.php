<?php

declare(strict_types=1);

namespace Modules\Crm\Calls;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;

/**
 * RingCentral (specs/connect/15, C7b): one-click sign-in (OAuth). Every hour, the call log and text messages since
 * the last run are read (the whole account for a brokerage connection, which needs a RingCentral admin; the
 * person's own extension for an agent's) and logged on matching contacts.
 */
final class RingCentral implements Connector
{
    public const API = 'https://platform.ringcentral.com/restapi/v1.0/';

    public function __construct(private readonly ConnectorKit $kit) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'RingCentral';
    }

    public function sync(ConnectionData $connection): array
    {
        $since = $connection->cursor !== null ? CarbonImmutable::parse($connection->cursor) : CarbonImmutable::now()->subDay();
        $now = CarbonImmutable::now();
        $http = Http::withToken($this->kit->accessToken($connection))->acceptJson()->timeout(20)->baseUrl(self::API);
        $scope = $connection->ownerUserId === null ? 'account/~/' : 'account/~/extension/~/';
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $calls = self::check($http->get($scope.'call-log', ['dateFrom' => $since->utc()->format('Y-m-d\TH:i:s.v\Z'), 'view' => 'Simple', 'perPage' => 250]));
        foreach ((array) $calls->json('records', []) as $c) {
            if (! is_array($c)) {
                continue;
            }
            $in = ($c['direction'] ?? '') === 'Inbound';
            $counts[app(CallLog::class)->log($connection, ['id' => (string) ($c['id'] ?? ''), 'kind' => 'call', 'direction' => $in ? 'in' : 'out',
                'number' => (string) ($in ? ($c['from']['phoneNumber'] ?? '') : ($c['to']['phoneNumber'] ?? '')), 'at' => (string) ($c['startTime'] ?? $now->toIso8601String()),
                'seconds' => (int) ($c['duration'] ?? 0), 'answered' => in_array($c['result'] ?? '', ['Accepted', 'Call connected'], true), 'voicemail' => ($c['result'] ?? '') === 'Voicemail',
                'agent_email' => null, 'recording' => is_string($c['recording']['contentUri'] ?? null) ? $c['recording']['contentUri'] : null], 'RingCentral')]++;
        }
        $texts = $http->get('account/~/extension/~/message-store', ['messageType' => 'SMS', 'dateFrom' => $since->utc()->format('Y-m-d\TH:i:s.v\Z'), 'perPage' => 250]);
        foreach ((array) ($texts->successful() ? $texts->json('records', []) : []) as $m) {
            if (! is_array($m)) {
                continue;
            }
            $in = ($m['direction'] ?? '') === 'Inbound';
            $counts[app(CallLog::class)->log($connection, ['id' => (string) ($m['id'] ?? ''), 'kind' => 'text', 'direction' => $in ? 'in' : 'out',
                'number' => (string) ($in ? ($m['from']['phoneNumber'] ?? '') : ($m['to'][0]['phoneNumber'] ?? '')), 'at' => (string) ($m['creationTime'] ?? $now->toIso8601String()),
                'text' => (string) ($m['subject'] ?? '')], 'RingCentral')]++;
        }

        return $counts + ['cursor' => $now->subMinutes(5)->toIso8601String(), 'note' => null];
    }

    private static function check(Response $r): Response
    {
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('RingCentral needs you to sign in again'.($r->status() === 403 ? ' (a brokerage connection needs a RingCentral admin, with Read Call Log and Read Messages).' : '.'));
        }

        return $r->throw();
    }
}
