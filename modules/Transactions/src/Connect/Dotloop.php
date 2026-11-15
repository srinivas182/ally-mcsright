<?php

declare(strict_types=1);

namespace Modules\Transactions\Connect;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Core\Connect\ReceivesWebhooks;
use Modules\Transactions\Models\Deal;
use Throwable;

/**
 * dotloop (specs/connect/12, Sprint C5a-1): switch in a weekend. Every loop in every profile the person can see
 * comes over as a deal, with its parties, contract dates and files, through dotloop's Public API v2 after a
 * one-click sign-in.
 *
 * - First run: every loop, oldest change first, a few loops a run (each with its files) until all are over.
 * - Then: dotloop tells us within seconds when a loop changes (signed webhooks), and every hour we also read the
 *   loops changed since the last pass, as a safety net. Once a week everything is read again.
 * - Read only. Nothing is written back to dotloop.
 */
final class Dotloop implements ReceivesWebhooks
{
    public const API = 'https://api-gateway.dotloop.com/public/v2/';

    private const LOOPS_PER_RUN = 25;

    private const EVENTS = ['LOOP_CREATED', 'LOOP_UPDATED', 'LOOP_MERGED'];

    public function __construct(private readonly ConnectorKit $kit, private readonly DealImporter $importer) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'dotloop'; // connected with one click (OAuth), not a pasted key
    }

    public function sync(ConnectionData $connection): array
    {
        $by = $this->by($connection);
        $http = $this->http($connection);
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $cursor = is_array($cursor) && is_string($cursor['pass'] ?? null) ? $cursor : ['profiles' => null, 'p' => 0, 'batch' => 1, 'since' => null, 'pass' => CarbonImmutable::now()->toIso8601String()];
        if ($cursor['profiles'] === null) {
            $profiles = array_values(array_filter((array) (self::get($http, 'profile')?->json('data', []) ?? []), 'is_array'));
            $cursor['profiles'] = array_values(array_filter(array_map(fn (array $p): int => (int) ($p['id'] ?? 0), $profiles)));
        }
        /** @var list<int> $ids */
        $ids = array_values(array_map('intval', (array) $cursor['profiles']));
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $done = 0;
        while ($done < self::LOOPS_PER_RUN && (int) $cursor['p'] < count($ids)) {
            $profile = $ids[(int) $cursor['p']];
            $filter = is_string($cursor['since'] ?? null) ? 'updated_min='.$cursor['since'] : null;
            $r = self::get($http, "profile/{$profile}/loop", array_filter(['batch_size' => self::LOOPS_PER_RUN, 'batch_number' => (int) $cursor['batch'],
                'sort' => 'updated:asc', 'include_details' => 'true', 'filter' => $filter]));
            if ($r === null) {
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'dotloop asked us to slow down; carrying on shortly.', 'more' => true];
            }
            $loops = array_values(array_filter((array) $r->json('data', []), 'is_array'));
            foreach ($loops as $loop) {
                $counts[$this->loop($connection, $http, $by, $profile, $loop)]++;
                $done++;
            }
            if (count($loops) < self::LOOPS_PER_RUN) {
                $cursor['p'] = (int) $cursor['p'] + 1;
                $cursor['batch'] = 1;
            } else {
                $cursor['batch'] = (int) $cursor['batch'] + 1;
            }
        }
        if ((int) $cursor['p'] < count($ids)) {
            return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Bringing deals over from dotloop…', 'more' => true];
        }
        // A full pass is over: from now on only loops changed since this pass started, and live updates.
        $this->subscribe($connection, $http, $ids);
        // Once a week everything is read again, so deals move to agents who have joined RightAlly since.
        $full = is_string($cursor['full'] ?? null) && ($cursor['since'] ?? null) !== null ? $cursor['full'] : (string) $cursor['pass'];
        $weekly = CarbonImmutable::parse($full)->lt(CarbonImmutable::now()->subWeek());
        $next = ['profiles' => null, 'p' => 0, 'batch' => 1, 'since' => $weekly ? null : (string) $cursor['pass'], 'pass' => CarbonImmutable::now()->toIso8601String(), 'full' => $full];

        return $counts + ['cursor' => (string) json_encode($next), 'note' => 'Up to date with dotloop.', 'more' => false];
    }

    public function verifyWebhook(string $raw, array $headers): bool
    {
        $signature = $headers['x-dotloop-webhook-signature'] ?? '';
        $timestamp = $headers['x-dotloop-webhook-timestamp'] ?? '';
        if ($signature === '' || ! ctype_digit($timestamp) || abs(CarbonImmutable::now()->getTimestamp() - (int) $timestamp) > 86400) {
            return false; // dotloop retries for up to a day; anything older is a replay
        }

        return hash_equals(hash_hmac('sha1', $timestamp.'.'.$raw, self::signingKey()), strtolower($signature));
    }

    public function webhook(ConnectionData $connection, array $payload): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $profile = is_scalar($payload['profileId'] ?? null) ? (int) $payload['profileId'] : 0;
        $event = is_array($payload['event'] ?? null) ? $payload['event'] : [];
        $loopId = is_scalar($event['id'] ?? null) ? (string) $event['id'] : '';
        if ($profile <= 0 || $loopId === '' || ! in_array($payload['eventType'] ?? null, self::EVENTS, true)) {
            return $counts;
        }
        $http = $this->http($connection);
        $r = self::get($http, "profile/{$profile}/loop/{$loopId}"); // a merged loop redirects to the loop it became
        $loop = $r?->json('data');
        if (is_array($loop)) {
            $counts[$this->loop($connection, $http, $this->by($connection), $profile, $loop)]++;
        }

        return $counts;
    }

    public function disconnected(ConnectionData $connection): void
    {
        try {
            $http = $this->http($connection);
            foreach ((array) ($connection->settings['subscriptions'] ?? []) as $id) {
                $http->delete('subscription/'.(string) $id);
            }
            $keys = $this->kit->secrets('dotloop');
            Http::withBasicAuth((string) ($keys['client_id'] ?? ''), (string) ($keys['client_secret'] ?? ''))->timeout(5)
                ->post('https://auth.dotloop.com/oauth/token/revoke?'.http_build_query(['token' => $this->kit->accessToken($connection)]));
        } catch (Throwable) {
            // the connection is removed either way; dotloop drops subscriptions whose address answers 410
        }
    }

    /**
     * One loop: its details become the deal, then its people and its files.
     *
     * @param  array<string, mixed>  $loop
     * @return 'created'|'updated'|'skipped'
     */
    private function loop(ConnectionData $connection, PendingRequest $http, User $by, int $profile, array $loop): string
    {
        $id = is_scalar($loop['id'] ?? null) ? (string) $loop['id'] : '';
        $type = self::type((string) ($loop['transactionType'] ?? ''));
        if ($id === '' || $type === null) {
            return 'skipped';
        }
        $details = is_array($loop['details'] ?? null) ? $loop['details'] : (self::get($http, "profile/{$profile}/loop/{$id}/detail")?->json('data') ?? []);
        $details = is_array($details) ? $details : [];
        $participants = array_values(array_filter((array) (self::get($http, "profile/{$profile}/loop/{$id}/participant")?->json('data', []) ?? []), 'is_array'));
        $deal = self::deal($id, $type, (string) ($loop['status'] ?? ''), $details, $participants);
        $result = $this->importer->deal($connection, $by, $deal, 'dotloop');
        if ($result['deal'] instanceof Deal && $result['result'] !== 'skipped') {
            $this->files($connection, $http, $profile, $id, $result['deal']);
        }

        return $result['result'];
    }

    private function files(ConnectionData $connection, PendingRequest $http, int $profile, string $loopId, Deal $deal): void
    {
        $folders = array_values(array_filter((array) (self::get($http, "profile/{$profile}/loop/{$loopId}/folder", ['include_documents' => 'true'])?->json('data', []) ?? []), 'is_array'));
        foreach ($folders as $folder) {
            $folderId = is_scalar($folder['id'] ?? null) ? (string) $folder['id'] : '';
            foreach (array_values(array_filter((array) ($folder['documents'] ?? []), 'is_array')) as $doc) {
                $docId = is_scalar($doc['id'] ?? null) ? (string) $doc['id'] : '';
                if ($folderId === '' || $docId === '') {
                    continue;
                }
                $this->importer->document($connection, $deal, 'dotloop:'.$docId, (string) ($doc['name'] ?? 'document.pdf'), function () use ($http, $profile, $loopId, $folderId, $docId): ?string {
                    $r = (clone $http)->replaceHeaders(['Accept' => 'application/pdf'])->timeout(60)->get("profile/{$profile}/loop/{$loopId}/folder/{$folderId}/document/{$docId}");

                    return $r->successful() ? $r->body() : null;
                });
            }
        }
    }

    /** Registers live updates for each profile once (signed with RightAlly's dotloop key). */
    /** @param  list<int>  $profiles */
    private function subscribe(ConnectionData $connection, PendingRequest $http, array $profiles): void
    {
        $have = array_map('strval', (array) ($connection->settings['subscribed_profiles'] ?? []));
        $subs = array_map('strval', (array) ($connection->settings['subscriptions'] ?? []));
        $url = $this->kit->webhookUrl($connection);
        foreach ($profiles as $profile) {
            if (in_array((string) $profile, $have, true)) {
                continue;
            }
            $r = $http->post('subscription', ['targetType' => 'PROFILE', 'targetId' => $profile, 'url' => $url, 'eventTypes' => self::EVENTS,
                'signingKey' => self::signingKey(), 'externalId' => $connection->ulid]);
            if ($r->successful() && is_scalar($r->json('data.id'))) {
                $subs[] = (string) $r->json('data.id');
                $have[] = (string) $profile;
            }
        }
        $this->kit->saveSettings($connection, ['subscriptions' => $subs, 'subscribed_profiles' => $have, 'interval_minutes' => 60]);
    }

    /**
     * Turns a loop into the shared deal shape.
     *
     * @param  array<string, mixed>  $details
     * @param  list<array<string, mixed>>  $participants
     * @return array{id: string, type: string, status: string, agent_id: string|null, agent_email: string|null, street: string, city: string|null,
     *     state_code: string, postal_code: string|null, mls_number: string|null, price_cents: int, gci_cents: int, contract_date: string|null,
     *     closing_date: string|null, cancel_reason: string|null, parties: list<array{role: string, name: string, company: string|null, email: string|null, phone: string|null}>,
     *     dates: list<array{label: string, due_on: string}>}
     */
    public static function deal(string $id, string $type, string $status, array $details, array $participants): array
    {
        $section = fn (string $name): array => is_array($details[$name] ?? null) ? $details[$name] : [];
        $field = function (array $s, string ...$keys): ?string {
            foreach ($keys as $k) {
                if (is_scalar($s[$k] ?? null) && trim((string) $s[$k]) !== '') {
                    return trim((string) $s[$k]);
                }
            }

            return null;
        };
        $address = $section('Property Address');
        $money = $section('Financials');
        $contract = $section('Contract Dates');
        $street = trim(implode(' ', array_filter([$field($address, 'Street Number'), $field($address, 'Street Name'),
            ($unit = $field($address, 'Unit Number')) !== null ? '#'.$unit : null])));
        $closing = $field($contract, 'Closing Date');
        $price = DealImporter::cents($field($money, 'Purchase/Sale Price', 'Current Price', 'Original Price') ?? '');
        $state = self::status($status, $closing, $price);

        $dates = [];
        foreach ($contract as $label => $value) {
            if (is_string($label) && is_scalar($value) && ! in_array($label, ['Contract Agreement Date', 'Closing Date'], true) && DealImporter::date((string) $value) !== null) {
                $dates[] = ['label' => $label, 'due_on' => (string) $value];
            }
        }

        $agentEmail = null;
        $parties = [];
        foreach ($participants as $p) {
            $role = (string) ($p['role'] ?? '');
            $email = is_string($p['email'] ?? null) ? $p['email'] : null;
            if ($agentEmail === null && $role === ($type === 'listing' ? 'LISTING_AGENT' : 'BUYING_AGENT')) {
                $agentEmail = $email;
            }
            $ours = match ($role) {
                'BUYER', 'TENANT' => 'buyer',
                'SELLER', 'LANDLORD' => 'seller',
                'LISTING_AGENT', 'BUYING_AGENT', 'TENANT_AGENT' => 'co_op_agent',
                'LOAN_OFFICER', 'LOAN_PROCESSOR' => 'lender',
                'ESCROW_TITLE_REP' => 'title',
                'BUYER_ATTORNEY', 'SELLER_ATTORNEY' => 'attorney',
                'HOME_INSPECTOR', 'INSPECTOR' => 'inspector',
                default => null,
            };
            if ($ours === null || ($ours === 'co_op_agent' && $email !== null && $email === $agentEmail)) {
                continue; // our own agent is the deal's agent, not a party; admins and vendors are left out
            }
            $phone = null;
            foreach (['Phone', 'Cell Phone', 'Mobile Phone', 'Business Phone', 'phone'] as $k) {
                if (is_scalar($p[$k] ?? null) && trim((string) $p[$k]) !== '') {
                    $phone = (string) $p[$k];
                    break;
                }
            }
            $parties[] = ['role' => $ours, 'name' => is_string($p['fullName'] ?? null) ? $p['fullName'] : '', 'email' => $email, 'phone' => $phone,
                'company' => is_scalar($p['Company Name'] ?? null) ? (string) $p['Company Name'] : null];
        }

        return ['id' => $id, 'type' => $type, 'status' => $state, 'agent_id' => null, 'agent_email' => $agentEmail, 'street' => $street,
            'city' => $field($address, 'City'), 'state_code' => (string) ($field($address, 'State/Prov', 'State') ?? ''), 'postal_code' => $field($address, 'Zip/Postal Code', 'Zip'),
            'mls_number' => $field($address, 'MLS Number') ?? $field($section('Listing Information'), 'MLS Number'), 'price_cents' => $price,
            'gci_cents' => DealImporter::cents($field($money, 'Sale Commission Total', 'Commission Total') ?? ''),
            'contract_date' => $field($contract, 'Contract Agreement Date', 'Offer Date'), 'closing_date' => $closing,
            'cancel_reason' => $state === 'fell_through' ? 'Archived in dotloop before closing' : null, 'parties' => $parties, 'dates' => $dates];
    }

    private static function type(string $transactionType): ?string
    {
        return match ($transactionType) {
            'LISTING_FOR_SALE' => 'listing',
            'PURCHASE_OFFER' => 'buyer',
            'LISTING_FOR_LEASE', 'LEASE_OFFER' => 'lease',
            default => null, // "other" loops (not a property deal)
        };
    }

    /** dotloop's status as ours. An archived loop counts as closed when it has a price and a closing date in the past. */
    private static function status(string $status, ?string $closing, int $price): string
    {
        $closingDate = DealImporter::date($closing);

        return match ($status) {
            'UNDER_CONTRACT' => 'under_contract',
            'SOLD', 'LEASED' => 'closed',
            'ARCHIVED' => $price > 0 && $closingDate !== null && $closingDate <= CarbonImmutable::now()->toDateString() ? 'closed' : 'fell_through',
            default => 'pre_contract',
        };
    }

    /** RightAlly's own key for signing dotloop's webhooks (derived from the app key, never stored or shown). */
    private static function signingKey(): string
    {
        return hash_hmac('sha256', 'connect:dotloop:webhooks', (string) config('app.key'));
    }

    private function by(ConnectionData $connection): User
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        if (! $by instanceof User || ! $by->isActive()) {
            throw new NeedsReconnect('The person who connected dotloop no longer has access. Please connect it again.');
        }

        return $by;
    }

    private function http(ConnectionData $connection): PendingRequest
    {
        return Http::withToken($this->kit->accessToken($connection))->acceptJson()->timeout(30)->baseUrl(self::API);
    }

    /** @param  array<string, mixed>  $query */
    private static function get(PendingRequest $http, string $path, array $query = []): ?Response
    {
        $r = $http->get($path, $query);
        if ($r->status() === 429) {
            return null;
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('dotloop no longer accepts RightAlly\'s sign-in. Please connect it again.');
        }
        if ($r->status() === 404) {
            return null; // removed in dotloop
        }

        return $r->throw();
    }
}
