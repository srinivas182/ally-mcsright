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
 * Brokermint (specs/connect/12, Sprint C5b): every transaction comes over as a deal with its agent, parties, dates
 * and commission, through Brokermint's API (v2) with the brokerage's API token, which Brokermint support issues on
 * request. Same rules as every transaction app, including close there, paid here.
 *
 * - Users first (to match agents by email), then transactions, 200 a page, with their contacts. Contacts are read
 *   only for transactions that changed since the last pass. Everything is read every hour (Brokermint has no
 *   "changed since" filter), so the list stays exact.
 * - Brokermint sends dates as "YYYY-MM-DD" or as Unix time in milliseconds; both are read.
 */
final class Brokermint implements Connector
{
    public const API = 'https://my.brokermint.com/api/v2/';

    private const PER_PAGE = 200;

    private const PAGES_PER_RUN = 5;

    public function __construct(private readonly ConnectorKit $kit, private readonly DealImporter $importer, private readonly Importer $people) {}

    public function verify(array $credentials, array $settings): string
    {
        $r = self::http((string) ($credentials['api_key'] ?? ''))->get('transactions', ['count' => 1]);
        if (! $r->successful()) {
            throw ValidationException::withMessages(['api_key' => 'Brokermint didn\'t accept that API token. Check it with Brokermint support and try again.']);
        }

        return 'Brokermint';
    }

    public function sync(ConnectionData $connection): array
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        if (! $by instanceof User || ! $by->isActive()) {
            throw new NeedsReconnect('The person who connected Brokermint no longer has access. Please connect it again.');
        }
        $http = self::http((string) ($connection->credentials['api_key'] ?? ''));
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $cursor = is_array($cursor) && is_string($cursor['pass'] ?? null) ? $cursor : ['offset' => 0, 'pass' => CarbonImmutable::now()->toIso8601String(), 'since' => null, 'first' => null];
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        if ((int) $cursor['offset'] === 0) {
            foreach (array_values(array_filter((array) (self::get($http, 'users')?->json() ?? []), 'is_array')) as $u) {
                if (is_scalar($u['id'] ?? null)) {
                    $this->people->user($connection, (string) $u['id'], (string) ($u['email'] ?? ''));
                }
            }
        }
        for ($page = 0; $page < self::PAGES_PER_RUN; $page++) {
            $r = self::get($http, 'transactions', ['count' => self::PER_PAGE, 'offset' => (int) $cursor['offset']]);
            if ($r === null) {
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Brokermint asked us to slow down; carrying on shortly.', 'more' => true];
            }
            $list = array_values(array_filter((array) ($r->json('transactions') ?? $r->json()), 'is_array'));
            $first = is_scalar($list[0]['id'] ?? null) ? (string) $list[0]['id'] : null;
            $repeat = (int) $cursor['offset'] > 0 && $first !== null && $first === $cursor['first']; // an API that ignores the offset: stop
            if (! $repeat) {
                foreach ($list as $t) {
                    $counts[$this->one($connection, $by, $http, $t, is_string($cursor['since'] ?? null) ? $cursor['since'] : null)]++;
                }
            }
            if ($repeat || count($list) < self::PER_PAGE) {
                $next = ['offset' => 0, 'pass' => CarbonImmutable::now()->toIso8601String(), 'since' => $cursor['pass'], 'first' => null];

                return $counts + ['cursor' => (string) json_encode($next), 'note' => 'Up to date with Brokermint.', 'more' => false];
            }
            $cursor['offset'] = (int) $cursor['offset'] + count($list);
            $cursor['first'] = $first;
        }

        return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Bringing deals over from Brokermint…', 'more' => true];
    }

    /**
     * @param  array<string, mixed>  $t
     * @return 'created'|'updated'|'skipped'
     */
    private function one(ConnectionData $connection, User $by, PendingRequest $http, array $t, ?string $since): string
    {
        $id = is_scalar($t['id'] ?? null) ? (string) $t['id'] : '';
        if ($id === '') {
            return 'skipped';
        }
        $updated = DealImporter::date(self::when($t['updated_at'] ?? null)) !== null ? CarbonImmutable::parse((string) self::when($t['updated_at'] ?? null)) : null;
        $known = $this->kit->localId($connection, 'deal', $id) !== null;
        if ($known && $since !== null && $updated !== null && $updated->lt(CarbonImmutable::parse($since))) {
            return 'skipped'; // not changed since the last pass
        }
        $contacts = array_values(array_filter((array) (self::get($http, "transactions/{$id}/contacts")?->json() ?? []), 'is_array'));

        return $this->importer->deal($connection, $by, self::deal($t, $contacts), 'Brokermint')['result'];
    }

    /**
     * A Brokermint transaction in the shared deal shape.
     *
     * @param  array<string, mixed>  $t
     * @param  list<array<string, mixed>>  $contacts
     * @return array{id: string, type: string, status: string, agent_id: string|null, agent_email: string|null, street: string, city: string|null,
     *     state_code: string, postal_code: string|null, mls_number: string|null, price_cents: int, gci_cents: int, contract_date: string|null,
     *     closing_date: string|null, cancel_reason: string|null, parties: list<array{role: string, name: string, company: string|null, email: string|null, phone: string|null}>,
     *     dates: list<array{label: string, due_on: string}>}
     */
    public static function deal(array $t, array $contacts): array
    {
        $str = fn (mixed $v): ?string => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        $kind = strtolower(($str($t['representing'] ?? null) ?? '').' '.($str($t['transaction_type'] ?? null) ?? ''));
        $type = match (true) {
            str_contains($kind, 'lease') || str_contains($kind, 'rent') || str_contains($kind, 'tenant') || str_contains($kind, 'landlord') => 'lease',
            str_contains($kind, 'both') || str_contains($kind, 'dual') => 'dual',
            str_contains($kind, 'seller') || str_contains($kind, 'list') => 'listing',
            default => 'buyer',
        };
        $statusText = strtolower((string) ($str($t['status'] ?? null) ?? ''));
        $status = match (true) {
            preg_match('/cancel|terminat|withdrawn|expired|fell|void/', $statusText) === 1 => 'fell_through',
            preg_match('/closed|sold|complete/', $statusText) === 1 => 'closed',
            preg_match('/pending|contract|escrow/', $statusText) === 1 => 'under_contract',
            default => 'pre_contract',
        };
        $agentEmail = null;
        $parties = [];
        foreach ($contacts as $c) {
            $role = strtolower((string) ($str($c['role'] ?? $c['contact_type'] ?? null) ?? ''));
            $email = $str($c['email'] ?? null);
            $name = trim(($str($c['first_name'] ?? null) ?? '').' '.($str($c['last_name'] ?? null) ?? '')) ?: ($str($c['name'] ?? $c['company'] ?? null) ?? '');
            $ourSide = match ($type) {
                'listing' => str_contains($role, 'listing'),
                'dual' => true,
                default => str_contains($role, 'buyer') || str_contains($role, 'tenant'),
            };
            if ($agentEmail === null && str_contains($role, 'agent') && $ourSide) {
                $agentEmail = $email;

                continue;
            }
            $parties[] = ['role' => match (true) {
                str_contains($role, 'agent') || str_contains($role, 'isa') => 'co_op_agent',
                str_contains($role, 'buyer') || str_contains($role, 'tenant') => 'buyer',
                str_contains($role, 'seller') || str_contains($role, 'landlord') => 'seller',
                str_contains($role, 'lend') || str_contains($role, 'loan') => 'lender',
                str_contains($role, 'title') => 'title',
                str_contains($role, 'escrow') => 'escrow',
                str_contains($role, 'attorney') || str_contains($role, 'lawyer') => 'attorney',
                str_contains($role, 'inspect') => 'inspector',
                default => 'other',
            }, 'name' => $name, 'company' => $str($c['company'] ?? null), 'email' => $email, 'phone' => $str($c['phone'] ?? $c['cell_phone'] ?? null)];
        }

        return ['id' => is_scalar($t['id'] ?? null) ? (string) $t['id'] : '', 'type' => $type, 'status' => $status, 'agent_id' => null, 'agent_email' => $agentEmail,
            'street' => (string) ($str($t['address'] ?? null) ?? ''), 'city' => $str($t['city'] ?? null), 'state_code' => (string) ($str($t['state'] ?? null) ?? ''),
            'postal_code' => $str($t['zip'] ?? null), 'mls_number' => $str($t['mls_number'] ?? null), 'price_cents' => DealImporter::cents($t['price'] ?? 0),
            'gci_cents' => DealImporter::cents($t['total_gross_commission'] ?? $t['gross_commission'] ?? 0),
            'contract_date' => self::when($t['acceptance_date'] ?? null), 'closing_date' => self::when($t['closing_date'] ?? null),
            'cancel_reason' => $status === 'fell_through' ? 'Cancelled in Brokermint' : null, 'parties' => $parties, 'dates' => []];
    }

    /** A Brokermint date ("YYYY-MM-DD", ISO, or Unix milliseconds) as a string the importer reads, or null. */
    private static function when(mixed $value): ?string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value) && strlen($value) >= 12)) {
            return CarbonImmutable::createFromTimestampMs((int) $value, 'UTC')->toIso8601String();
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function http(string $key): PendingRequest
    {
        return Http::acceptJson()->timeout(30)->baseUrl(self::API)->withQueryParameters(['api_key' => $key]);
    }

    /** @param  array<string, mixed>  $query */
    private static function get(PendingRequest $http, string $path, array $query = []): ?Response
    {
        $r = $http->get($path, $query);
        if ($r->status() === 429) {
            return null;
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('Brokermint no longer accepts the API token. Please connect it again.');
        }
        if ($r->status() === 404) {
            return null;
        }

        return $r->throw();
    }
}
