<?php

declare(strict_types=1);

namespace Modules\Transactions\Connect;

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
use Modules\Crm\Connect\Importer;

/**
 * SkySlope (specs/connect/12, Sprint C5a-2): switch in a weekend. Sales and listings come over as deals with their
 * agent, parties, dates and commission, through SkySlope's Transaction Management API.
 *
 * Signing in takes two pairs of keys: RightAlly's client ID and secret (HQ, from SkySlope's partner onboarding) and
 * the brokerage's own access key and secret (SkySlope → My Account → Integrations). Each run logs in (an HMAC of
 * the client pair, signed with the brokerage's secret) and gets a two-hour session.
 *
 * - First run: users (to match agents), then every sale, then every listing that never became a sale, 10 a page.
 * - Then every hour: files changed since the last pass (bulk export, modifiedAfter). Everything again once a week.
 * - SkySlope has no webhooks and its API offers no document download: files come over from SkySlope's file export
 *   (Transactions → Bring over files).
 * - A sale made from a listing updates that listing's deal, so a listing and its sale are one deal here.
 */
final class SkySlope implements Connector
{
    public const API = 'https://api.skyslope.com/';

    private const PAGES_PER_RUN = 10;

    public function __construct(private readonly ConnectorKit $kit, private readonly DealImporter $importer, private readonly Importer $people) {}

    public function verify(array $credentials, array $settings): string
    {
        $this->session($credentials, fn (string $field) => ValidationException::withMessages([$field => 'SkySlope didn\'t accept that access key and secret. Copy them again from SkySlope → My Account → Integrations.']));

        return 'SkySlope';
    }

    public function sync(ConnectionData $connection): array
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        if (! $by instanceof User || ! $by->isActive()) {
            throw new NeedsReconnect('The person who connected SkySlope no longer has access. Please connect it again.');
        }
        $http = $this->session($connection->credentials, fn () => new NeedsReconnect('SkySlope no longer accepts the access key. Please connect it again with a new key.'));
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $cursor = is_array($cursor) && is_string($cursor['phase'] ?? null) ? $cursor : ['phase' => 'users', 'page' => 1, 'pass' => CarbonImmutable::now()->toIso8601String()];
        if (($cursor['phase'] === 'changes') && CarbonImmutable::parse((string) ($cursor['full'] ?? $cursor['pass']))->lt(CarbonImmutable::now()->subWeek())) {
            $cursor = ['phase' => 'users', 'page' => 1, 'pass' => CarbonImmutable::now()->toIso8601String()]; // weekly: everything again
        }
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        if ($cursor['phase'] === 'users') {
            foreach (self::list(self::get($http, 'api/users'), 'users') as $u) {
                if (is_string($u['userGuid'] ?? null)) {
                    $this->people->user($connection, $u['userGuid'], (string) ($u['email'] ?? ''));
                }
            }
            $cursor = ['phase' => 'sales', 'page' => 1, 'pass' => $cursor['pass']];
        }

        if ($cursor['phase'] === 'changes') {
            $since = (string) $cursor['since'];
            $next = CarbonImmutable::now()->toIso8601String();
            $r = self::get($http, 'api/files', ['modifiedAfter' => $since, 'type' => 'all']);
            if ($r === null) {
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'SkySlope asked us to slow down; carrying on shortly.', 'more' => false];
            }
            foreach (self::files($r) as $file) {
                $counts[$this->file($connection, $by, $file)]++;
            }

            return $counts + ['cursor' => (string) json_encode(['phase' => 'changes', 'since' => $next, 'full' => $cursor['full'] ?? $cursor['pass']]), 'note' => 'Up to date with SkySlope.', 'more' => false];
        }

        for ($i = 0; $i < self::PAGES_PER_RUN; $i++) {
            $kind = $cursor['phase'] === 'sales' ? 'sales' : 'listings';
            $r = self::get($http, 'api/files/'.$kind, ['PageNumber' => (int) $cursor['page']]);
            if ($r === null) {
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'SkySlope asked us to slow down; carrying on shortly.', 'more' => true];
            }
            $files = self::list($r, $kind);
            foreach ($files as $file) {
                $counts[$this->file($connection, $by, $file)]++;
            }
            if ($files !== []) {
                $cursor['page'] = (int) $cursor['page'] + 1;

                continue;
            }
            if ($kind === 'sales') {
                $cursor = ['phase' => 'listings', 'page' => 1, 'pass' => $cursor['pass']];

                continue;
            }

            // A full pass is over: from now on, files changed since it started.
            return $counts + ['cursor' => (string) json_encode(['phase' => 'changes', 'since' => $cursor['pass'], 'full' => $cursor['pass']]), 'note' => 'Up to date with SkySlope.', 'more' => false];
        }

        return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Bringing deals over from SkySlope…', 'more' => true];
    }

    /**
     * @param  array<string, mixed>  $file  a sale or a listing
     * @return 'created'|'updated'|'skipped'
     */
    private function file(ConnectionData $connection, User $by, array $file): string
    {
        $deal = self::deal($file);
        if ($deal === null) {
            return 'skipped';
        }

        return $this->importer->deal($connection, $by, $deal, 'SkySlope')['result'];
    }

    /**
     * A SkySlope sale or listing in the shared deal shape. A listing that has gone pending or closed is left to its
     * sale (which carries the listing's id, so they stay one deal); null when there is nothing to bring.
     *
     * @param  array<string, mixed>  $f
     * @return array{id: string, type: string, status: string, agent_id: string|null, agent_email: string|null, street: string, city: string|null,
     *     state_code: string, postal_code: string|null, mls_number: string|null, price_cents: int, gci_cents: int, contract_date: string|null,
     *     closing_date: string|null, cancel_reason: string|null, parties: list<array{role: string, name: string, company: string|null, email: string|null, phone: string|null}>,
     *     dates: list<array{label: string, due_on: string}>}|null
     */
    public static function deal(array $f): ?array
    {
        $str = fn (mixed $v): ?string => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        $sale = $str($f['saleGuid'] ?? null);
        $listing = $str($f['listingGuid'] ?? null);
        $status = strtolower((string) ($f['status'] ?? ''));
        if ($sale === null && ($listing === null || in_array($status, ['pending', 'closed'], true))) {
            return null;
        }
        $p = is_array($f['property'] ?? null) ? $f['property'] : [];
        $closed = $str($f['actualClosingDate'] ?? null);
        $state = match ($status) {
            'pending' => 'under_contract',
            'closed' => 'closed',
            'canceledapproved', 'canceledpending', 'expired' => 'fell_through',
            'archived' => $closed !== null ? 'closed' : 'fell_through',
            default => 'pre_contract',
        };

        $parties = [];
        $person = function (mixed $c, string $role) use (&$parties, $str): void {
            if (! is_array($c)) {
                return;
            }
            $name = trim(($str($c['firstName'] ?? null) ?? '').' '.($str($c['lastName'] ?? null) ?? ''));
            $parties[] = ['role' => $role, 'name' => $name !== '' ? $name : ($str($c['company'] ?? null) ?? ''), 'company' => $str($c['company'] ?? null),
                'email' => $str($c['email'] ?? null), 'phone' => $str($c['phoneNumber'] ?? $c['phone'] ?? null)];
        };
        foreach (['buyers' => 'buyer', 'sellers' => 'seller'] as $key => $role) {
            foreach ((array) ($f[$key] ?? []) as $c) {
                $person($c, $role);
            }
        }
        foreach (['titleContact' => 'title', 'escrowContact' => 'escrow', 'lenderContact' => 'lender', 'attorneyContact' => 'attorney', 'otherSideAgentContact' => 'co_op_agent'] as $key => $role) {
            $person($f[$key] ?? null, $role);
        }
        $commission = is_array($f['commission'] ?? null) ? $f['commission'] : [];

        return ['id' => $listing !== null ? 'listing:'.$listing : 'sale:'.$sale, 'type' => $sale === null ? 'listing' : ($listing !== null ? 'listing' : 'buyer'),
            'status' => $state, 'agent_id' => $str($f['agentGuid'] ?? null), 'agent_email' => null,
            'street' => trim(implode(' ', array_filter([$str($p['streetNumber'] ?? null), $str($p['direction'] ?? null), $str($p['streetAddress'] ?? null),
                ($unit = $str($p['unit'] ?? null)) !== null ? '#'.$unit : null]))),
            'city' => $str($p['city'] ?? null), 'state_code' => (string) ($str($p['state'] ?? null) ?? ''), 'postal_code' => $str($p['zip'] ?? null),
            'mls_number' => $str($f['mlsNumber'] ?? $p['mlsNumber'] ?? null),
            'price_cents' => DealImporter::cents($f['salePrice'] ?? $f['listingPrice'] ?? 0), 'gci_cents' => DealImporter::cents($commission['officeGrossCommission'] ?? 0),
            'contract_date' => $str($f['contractAcceptedDate'] ?? null), 'closing_date' => $closed ?? $str($f['escrowClosingDate'] ?? null),
            'cancel_reason' => $state === 'fell_through' ? 'Cancelled or expired in SkySlope' : null, 'parties' => $parties, 'dates' => []];
    }

    /**
     * Logs in with RightAlly's client pair, signed with the brokerage's access secret, and returns a client that
     * sends the session.
     *
     * @param  array<string, string>  $credentials
     * @param  callable(string): \Throwable  $refused
     */
    private function session(array $credentials, callable $refused): PendingRequest
    {
        $hq = $this->kit->secrets('skyslope');
        $clientId = (string) ($hq['client_id'] ?? '');
        $clientSecret = (string) ($hq['client_secret'] ?? '');
        $key = (string) ($credentials['access_key'] ?? '');
        $secret = (string) ($credentials['access_secret'] ?? '');
        $stamp = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
        $hmac = base64_encode(hash_hmac('sha256', $clientId.':'.$clientSecret.':'.$stamp, $secret, true));
        $r = Http::acceptJson()->timeout(20)->withHeaders(['Authorization' => 'SS '.$key.':'.$hmac, 'Timestamp' => $stamp])
            ->post(self::API.'auth/login', ['clientID' => $clientId, 'clientSecret' => $clientSecret]);
        $session = $r->json('Session') ?? $r->json('session');
        if (! $r->successful() || ! is_string($session) || $session === '') {
            throw $refused('access_key');
        }

        return Http::acceptJson()->timeout(30)->withHeaders(['Session' => $session])->baseUrl(self::API);
    }

    /**
     * The list inside SkySlope's linked response ({links, value: {sales: [...]}, warnings}).
     *
     * @return list<array<string, mixed>>
     */
    private static function list(?Response $r, string $key): array
    {
        $value = $r?->json('value') ?? $r?->json('values');
        $items = is_array($value) ? ($value[$key] ?? (array_is_list($value) ? $value : [])) : [];

        return array_values(array_filter((array) $items, 'is_array'));
    }

    /**
     * Sales and listings from the bulk export, whichever way it groups them.
     *
     * @return list<array<string, mixed>>
     */
    private static function files(Response $r): array
    {
        $value = $r->json('value') ?? $r->json('values') ?? [];
        if (is_array($value) && ! array_is_list($value)) {
            $value = [...array_values((array) ($value['sales'] ?? [])), ...array_values((array) ($value['listings'] ?? [])), ...array_values((array) ($value['files'] ?? []))];
        }
        $out = [];
        foreach ((array) $value as $item) {
            if (is_array($item)) { // an item may wrap the file ({sale: {...}} or {listing: {...}})
                $out[] = is_array($item['sale'] ?? null) ? $item['sale'] : (is_array($item['listing'] ?? null) ? $item['listing'] : $item);
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $query */
    private static function get(PendingRequest $http, string $path, array $query = []): ?Response
    {
        $r = $http->get($path, $query);
        if ($r->status() === 429) {
            return null;
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('SkySlope no longer accepts the access key. Please connect it again with a new key.');
        }
        if ($r->status() === 404) {
            return null;
        }

        return $r->throw();
    }
}
