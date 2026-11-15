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
 * Lone Wolf (TransactionDesk / brokerWOLF; specs/connect/12, Sprint C5a-2): switch in a weekend. Deals come over with
 * their agent, client and business contacts, other-side agents, dates and price, through Lone Wolf's partner
 * Deals and Roster APIs.
 *
 * Access is for Lone Wolf partners only: Lone Wolf issues RightAlly's client ID (HQ), and the brokerage creates a
 * separate, read-only Lone Wolf user just for RightAlly and enters its email and password once (encrypted,
 * write-only). Each run logs in for a 24-hour token.
 *
 * - First run: the roster (to match agents), then every deal, 20 a run with their contacts.
 * - Then every hour: deals changed since the last pass (modifiedTimestamp). Everything again once a week.
 * - Lone Wolf's codes for status and deal class aren't published, so the deal's state is read from its dates
 *   (finalised or closed → closed, firm or offer → under contract) and only a status that says cancelled, collapsed,
 *   void or terminated counts as fallen through. Confirmed in Lone Wolf's partner sandbox before go-live.
 * - No webhooks and no documents in the Deals API: files come over from Lone Wolf's file export.
 */
final class LoneWolf implements Connector
{
    public const LOGIN = 'https://authentication.api.lwolf.com/v1/login';

    public const DEALS = 'https://deals.api.lwolf.com/v1/';

    public const ROSTER = 'https://roster.api.lwolf.com/v1/';

    private const DEALS_PER_RUN = 20;

    public function __construct(private readonly ConnectorKit $kit, private readonly DealImporter $importer, private readonly Importer $people) {}

    public function verify(array $credentials, array $settings): string
    {
        if ($this->token($credentials) === null) {
            throw ValidationException::withMessages(['password' => 'Lone Wolf didn\'t accept that email and password. Check the RightAlly user in Lone Wolf and try again.']);
        }

        return 'Lone Wolf ('.mb_substr((string) ($credentials['email'] ?? ''), 0, 80).')';
    }

    public function sync(ConnectionData $connection): array
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        if (! $by instanceof User || ! $by->isActive()) {
            throw new NeedsReconnect('The person who connected Lone Wolf no longer has access. Please connect it again.');
        }
        $token = $this->token($connection->credentials) ?? throw new NeedsReconnect('Lone Wolf no longer accepts the RightAlly user\'s password. Please connect it again.');
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $cursor = is_array($cursor) && is_string($cursor['pass'] ?? null) ? $cursor : ['skip' => 0, 'since' => null, 'pass' => CarbonImmutable::now()->toIso8601String(), 'roster' => true];
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        if (($cursor['roster'] ?? false) === true) {
            $r = self::get(self::http($token, self::ROSTER), 'members');
            foreach (array_values(array_filter((array) ($r?->json() ?? []), 'is_array')) as $m) {
                if (is_string($m['id'] ?? null)) {
                    $this->people->user($connection, $m['id'], (string) ($m['primaryEmailAddress'] ?? ''));
                }
            }
            $cursor['roster'] = false;
        }

        $deals = self::http($token, self::DEALS);
        $query = ['$top' => self::DEALS_PER_RUN, '$skip' => (int) $cursor['skip'], '$orderby' => 'modifiedTimestamp asc'];
        if (is_string($cursor['since'] ?? null)) {
            $query['$filter'] = 'modifiedTimestamp gt '.$cursor['since'];
        }
        $r = self::get($deals, 'deals', $query);
        if ($r === null) {
            return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Lone Wolf asked us to slow down; carrying on shortly.', 'more' => true];
        }
        $list = array_values(array_filter((array) ($r->json('value') ?? $r->json()), 'is_array'));
        foreach ($list as $deal) {
            $counts[$this->one($connection, $by, $deals, $deal)]++;
        }
        if (count($list) === self::DEALS_PER_RUN) {
            $cursor['skip'] = (int) $cursor['skip'] + count($list);

            return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Bringing deals over from Lone Wolf…', 'more' => true];
        }
        // A pass is over: next, deals changed since it started; once a week everything again, roster included.
        $full = is_string($cursor['full'] ?? null) && $cursor['since'] !== null ? $cursor['full'] : (string) $cursor['pass'];
        $weekly = CarbonImmutable::parse($full)->lt(CarbonImmutable::now()->subWeek());
        $next = ['skip' => 0, 'since' => $weekly ? null : (string) $cursor['pass'], 'pass' => CarbonImmutable::now()->toIso8601String(), 'full' => $full, 'roster' => $weekly];

        return $counts + ['cursor' => (string) json_encode($next), 'note' => 'Up to date with Lone Wolf.', 'more' => false];
    }

    /**
     * @param  array<string, mixed>  $deal
     * @return 'created'|'updated'|'skipped'
     */
    private function one(ConnectionData $connection, User $by, PendingRequest $http, array $deal): string
    {
        $id = is_string($deal['id'] ?? null) ? $deal['id'] : '';
        if ($id === '') {
            return 'skipped';
        }
        $clients = array_values(array_filter((array) (self::get($http, "deals/{$id}/client-contacts")?->json() ?? []), 'is_array'));
        $business = array_values(array_filter((array) (self::get($http, "deals/{$id}/business-contacts")?->json() ?? []), 'is_array'));
        $agent = null;
        foreach (array_values(array_filter((array) ($deal['tiers'] ?? []), 'is_array')) as $tier) {
            if (is_string($tier['id'] ?? null)) {
                foreach (array_values(array_filter((array) (self::get($http, 'tiers/'.$tier['id'].'/commissions')?->json() ?? []), 'is_array')) as $c) {
                    if (is_string($c['agentId'] ?? null)) {
                        $agent = $c['agentId'];
                        break 2;
                    }
                }
            }
        }

        return $this->importer->deal($connection, $by, self::deal($deal, $clients, $business, $agent), 'Lone Wolf')['result'];
    }

    /**
     * A Lone Wolf deal in the shared deal shape.
     *
     * @param  array<string, mixed>  $d
     * @param  list<array<string, mixed>>  $clients
     * @param  list<array<string, mixed>>  $business
     * @return array{id: string, type: string, status: string, agent_id: string|null, agent_email: string|null, street: string, city: string|null,
     *     state_code: string, postal_code: string|null, mls_number: string|null, price_cents: int, gci_cents: int, contract_date: string|null,
     *     closing_date: string|null, cancel_reason: string|null, parties: list<array{role: string, name: string, company: string|null, email: string|null, phone: string|null}>,
     *     dates: list<array{label: string, due_on: string}>}
     */
    public static function deal(array $d, array $clients, array $business, ?string $agentId): array
    {
        $str = fn (mixed $v): ?string => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        $class = is_array($d['classification'] ?? null) ? $d['classification'] : [];
        $className = strtolower(($str($class['name'] ?? null) ?? '').' '.($str($d['classCode'] ?? null) ?? ''));
        $type = match (true) {
            str_contains($className, 'lease') || str_contains($className, 'rent') => 'lease',
            (int) ($class['endCount'] ?? 0) === 2 || str_contains($className, 'double') || str_contains($className, 'both') => 'dual',
            str_contains($className, 'list') => 'listing',
            default => 'buyer',
        };
        $today = CarbonImmutable::now()->toDateString();
        $closeDate = DealImporter::date($str($d['closeDate'] ?? null));
        $statusText = strtolower((string) ($str($d['statusCode'] ?? null) ?? ''));
        $status = match (true) {
            preg_match('/cancel|collaps|void|terminat|fell/', $statusText) === 1 => 'fell_through',
            $str($d['finalizeDate'] ?? null) !== null || ($closeDate !== null && $closeDate <= $today && (float) ($d['closePrice'] ?? 0) > 0) => 'closed',
            $str($d['firmDate'] ?? null) !== null || $str($d['offerDate'] ?? null) !== null => 'under_contract',
            default => 'pre_contract',
        };

        $parties = [];
        $person = function (array $c, string $role) use (&$parties, $str): void {
            $name = trim(implode(' ', array_filter([$str($c['firstName'] ?? null), $str($c['lastName'] ?? null)])));
            $email = null;
            foreach ((array) ($c['emailAddresses'] ?? []) as $e) {
                $email ??= is_array($e) ? $str($e['address'] ?? null) : null;
            }
            $phone = null;
            foreach ((array) ($c['phoneNumbers'] ?? []) as $p) {
                $phone ??= is_array($p) ? $str($p['number'] ?? null) : null;
            }
            $parties[] = ['role' => $role, 'name' => $name !== '' ? $name : ($str($c['companyName'] ?? null) ?? ''), 'company' => $str($c['companyName'] ?? null), 'email' => $email, 'phone' => $phone];
        };
        foreach ($clients as $c) {
            $kind = strtolower(($str($c['type'] ?? null) ?? '').' '.($str($c['endCode'] ?? null) ?? ''));
            $person($c, match (true) {
                preg_match('/buy|purchas|tenant|lessee/', $kind) === 1 => 'buyer',
                preg_match('/sell|vendor|landlord|lessor|owner/', $kind) === 1 => 'seller',
                default => $type === 'listing' ? 'seller' : 'buyer',
            });
        }
        foreach ($business as $c) {
            $kind = strtolower(($str($c['contactType'] ?? null) ?? '').' '.($str($c['companyName'] ?? null) ?? ''));
            $person($c, match (true) {
                preg_match('/lawyer|attorney|solicitor|legal/', $kind) === 1 => 'attorney',
                preg_match('/lend|mortgage|bank|loan/', $kind) === 1 => 'lender',
                str_contains($kind, 'escrow') => 'escrow',
                str_contains($kind, 'title') => 'title',
                str_contains($kind, 'inspect') => 'inspector',
                default => 'other',
            });
        }
        foreach (array_values(array_filter((array) ($d['externalAgents'] ?? []), 'is_array')) as $a) {
            $person($a, 'co_op_agent');
        }
        $dates = [];
        foreach (['entryDate' => 'Entered in Lone Wolf', 'firmDate' => 'Firm (conditions removed)', 'finalizeDate' => 'Finalised in Lone Wolf'] as $key => $label) {
            if (($v = $str($d[$key] ?? null)) !== null) {
                $dates[] = ['label' => $label, 'due_on' => $v];
            }
        }

        return ['id' => is_string($d['id'] ?? null) ? $d['id'] : '', 'type' => $type, 'status' => $status, 'agent_id' => $agentId, 'agent_email' => null,
            'street' => trim(implode(' ', array_filter([$str($d['addressStreetNumber'] ?? null), $str($d['addressStreetDirection'] ?? null), $str($d['addressStreetName'] ?? null),
                ($unit = $str($d['addressUnitNumber'] ?? null)) !== null ? '#'.$unit : null]))),
            'city' => $str($d['addressCity'] ?? null), 'state_code' => (string) ($str($d['addressProvinceCode'] ?? null) ?? ''), 'postal_code' => $str($d['addressPostalCode'] ?? null),
            'mls_number' => $str($d['mlsNumber'] ?? null), 'price_cents' => DealImporter::cents($d['closePrice'] ?? 0), 'gci_cents' => 0,
            'contract_date' => $str($d['offerDate'] ?? null), 'closing_date' => $closeDate, 'cancel_reason' => $status === 'fell_through' ? 'Cancelled in Lone Wolf' : null,
            'parties' => $parties, 'dates' => $dates];
    }

    /** @param  array<string, string>  $credentials */
    private function token(array $credentials): ?string
    {
        $r = Http::acceptJson()->timeout(20)->post(self::LOGIN, ['emailAddress' => (string) ($credentials['email'] ?? ''), 'password' => (string) ($credentials['password'] ?? ''),
            'clientId' => (string) ($this->kit->secrets('lone_wolf')['client_id'] ?? '')]);
        $token = $r->json('token');

        return $r->successful() && is_string($token) && $token !== '' ? $token : null;
    }

    private static function http(string $token, string $base): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(30)->baseUrl($base);
    }

    /** @param  array<string, mixed>  $query */
    private static function get(PendingRequest $http, string $path, array $query = []): ?Response
    {
        $r = $http->get($path, $query);
        if ($r->status() === 429) {
            return null;
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('Lone Wolf no longer accepts the RightAlly user. Please connect it again.');
        }
        if ($r->status() === 404) {
            return null;
        }

        return $r->throw();
    }
}
