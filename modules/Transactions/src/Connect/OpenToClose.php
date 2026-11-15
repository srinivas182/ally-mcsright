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

/**
 * Open to Close (specs/connect/12, Sprint C5b): every property (transaction) comes over as a deal with its agent,
 * parties and dates, through Open to Close's API with the brokerage's own API token (Apps & API dashboard). Same
 * rules as every transaction app, including close there, paid here.
 *
 * - Properties are read 50 at a time, newest change first (sort=-updated_at), with their contacts. Until the first
 *   full pass is done everything is read; then each hourly run reads until it reaches properties already up to
 *   date, and everything again once a week.
 * - Open to Close returns property fields either flat or as a list of field values (key, value); both are read.
 */
final class OpenToClose implements Connector
{
    public const API = 'https://api.opentoclose.com/v1/';

    private const PER_PAGE = 50;

    private const PAGES_PER_RUN = 4;

    public function __construct(private readonly ConnectorKit $kit, private readonly DealImporter $importer) {}

    public function verify(array $credentials, array $settings): string
    {
        $r = self::http((string) ($credentials['api_token'] ?? ''))->get('properties', ['limit' => 1]);
        if (! $r->successful()) {
            throw ValidationException::withMessages(['api_token' => 'Open to Close didn\'t accept that API token. Copy it again from Open to Close → Apps & API.']);
        }

        return 'Open to Close';
    }

    public function sync(ConnectionData $connection): array
    {
        $by = User::query()->find($this->kit->connectedBy($connection));
        if (! $by instanceof User || ! $by->isActive()) {
            throw new NeedsReconnect('The person who connected Open to Close no longer has access. Please connect it again.');
        }
        $http = self::http((string) ($connection->credentials['api_token'] ?? ''));
        $cursor = json_decode((string) ($connection->cursor ?? ''), true);
        $cursor = is_array($cursor) && is_string($cursor['pass'] ?? null) ? $cursor : ['offset' => 0, 'pass' => CarbonImmutable::now()->toIso8601String(), 'until' => null];
        if (is_string($cursor['full'] ?? null) && CarbonImmutable::parse($cursor['full'])->lt(CarbonImmutable::now()->subWeek()) && (int) $cursor['offset'] === 0) {
            $cursor['until'] = null; // weekly: everything again
        }
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        for ($page = 0; $page < self::PAGES_PER_RUN; $page++) {
            $r = self::get($http, 'properties', ['limit' => self::PER_PAGE, 'offset' => (int) $cursor['offset'], 'sort' => '-updated_at']);
            if ($r === null) {
                return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Open to Close asked us to slow down; carrying on shortly.', 'more' => true];
            }
            $list = array_values(array_filter((array) ($r->json('data') ?? $r->json()), 'is_array'));
            $reachedKnown = false;
            foreach ($list as $property) {
                $p = self::flatten($property);
                $updated = is_string($p['updated_at'] ?? null) ? $p['updated_at'] : null;
                if (is_string($cursor['until'] ?? null) && $updated !== null && $updated < $cursor['until']) {
                    $reachedKnown = true; // older changes were read on an earlier pass

                    break;
                }
                $counts[$this->one($connection, $by, $http, $p)]++;
            }
            if ($reachedKnown || count($list) < self::PER_PAGE) {
                $next = ['offset' => 0, 'pass' => CarbonImmutable::now()->toIso8601String(), 'until' => $cursor['pass'],
                    'full' => $cursor['until'] === null ? $cursor['pass'] : ($cursor['full'] ?? $cursor['pass'])];

                return $counts + ['cursor' => (string) json_encode($next), 'note' => 'Up to date with Open to Close.', 'more' => false];
            }
            $cursor['offset'] = (int) $cursor['offset'] + count($list);
        }

        return $counts + ['cursor' => (string) json_encode($cursor), 'note' => 'Bringing deals over from Open to Close…', 'more' => true];
    }

    /**
     * @param  array<string, mixed>  $p  flattened property
     * @return 'created'|'updated'|'skipped'
     */
    private function one(ConnectionData $connection, User $by, PendingRequest $http, array $p): string
    {
        $id = is_scalar($p['id'] ?? null) ? (string) $p['id'] : '';
        if ($id === '') {
            return 'skipped';
        }
        $people = [];
        $links = self::get($http, "properties/{$id}/contacts");
        foreach (array_values(array_filter((array) ($links?->json('data') ?? $links?->json() ?? []), 'is_array')) as $link) {
            $contact = is_array($link['contact'] ?? null) ? $link['contact'] : null;
            if ($contact === null && is_scalar($link['contact_id'] ?? null)) {
                $c = self::get($http, 'contacts/'.$link['contact_id']);
                $contact = is_array($c?->json('data')) ? $c->json('data') : (is_array($c?->json()) ? $c->json() : null);
            }
            if (is_array($contact)) {
                $people[] = ['role' => (string) ($link['role'] ?? $contact['role'] ?? ''), 'contact' => self::flatten($contact)];
            }
        }

        return $this->importer->deal($connection, $by, self::deal($p, $people), 'Open to Close')['result'];
    }

    /**
     * An Open to Close property in the shared deal shape.
     *
     * @param  array<string, mixed>  $p  flattened property
     * @param  list<array{role: string, contact: array<string, mixed>}>  $people
     * @return array{id: string, type: string, status: string, agent_id: string|null, agent_email: string|null, street: string, city: string|null,
     *     state_code: string, postal_code: string|null, mls_number: string|null, price_cents: int, gci_cents: int, contract_date: string|null,
     *     closing_date: string|null, cancel_reason: string|null, parties: list<array{role: string, name: string, company: string|null, email: string|null, phone: string|null}>,
     *     dates: list<array{label: string, due_on: string}>}
     */
    public static function deal(array $p, array $people): array
    {
        $str = function (string ...$keys) use ($p): ?string {
            foreach ($keys as $k) {
                if (is_scalar($p[$k] ?? null) && trim((string) $p[$k]) !== '') {
                    return trim((string) $p[$k]);
                }
            }

            return null;
        };
        $client = strtolower((string) ($str('contract_client_type', 'client_type') ?? ''));
        $statusText = strtolower((string) ($str('contract_status', 'status') ?? ''));
        $type = match (true) {
            str_contains($client, 'lease') || str_contains($client, 'tenant') || str_contains($client, 'landlord') || str_contains($client, 'rent') => 'lease',
            str_contains($client, 'dual') || str_contains($client, 'both') => 'dual',
            str_contains($client, 'sell') || str_contains($client, 'list') => 'listing',
            default => 'buyer',
        };
        $status = match (true) {
            preg_match('/cancel|terminat|withdrawn|expired|fell|dead/', $statusText) === 1 => 'fell_through',
            preg_match('/closed|sold|complete|funded/', $statusText) === 1 => 'closed',
            preg_match('/pending|contract|escrow/', $statusText) === 1 => 'under_contract',
            default => 'pre_contract',
        };
        $agentEmail = null;
        $parties = [];
        foreach ($people as $person) {
            $role = strtolower($person['role']);
            $c = $person['contact'];
            $name = trim(((string) ($c['first_name'] ?? '')).' '.((string) ($c['last_name'] ?? ''))) ?: (string) ($c['name'] ?? $c['company'] ?? '');
            $email = is_string($c['email'] ?? null) ? $c['email'] : null;
            $ourSide = match ($type) {
                'listing' => str_contains($role, 'listing') || str_contains($role, 'seller'),
                'dual' => true,
                default => str_contains($role, 'buy') || str_contains($role, 'tenant'),
            };
            if ($agentEmail === null && str_contains($role, 'agent') && $ourSide) {
                $agentEmail = $email;

                continue;
            }
            $parties[] = ['role' => match (true) {
                str_contains($role, 'buyer') || str_contains($role, 'tenant') => 'buyer',
                str_contains($role, 'seller') || str_contains($role, 'landlord') => 'seller',
                str_contains($role, 'agent') => 'co_op_agent',
                str_contains($role, 'lend') || str_contains($role, 'loan') || str_contains($role, 'mortgage') => 'lender',
                str_contains($role, 'title') => 'title',
                str_contains($role, 'escrow') => 'escrow',
                str_contains($role, 'attorney') || str_contains($role, 'lawyer') => 'attorney',
                str_contains($role, 'inspect') => 'inspector',
                default => 'other',
            }, 'name' => $name, 'company' => is_string($c['company'] ?? null) ? $c['company'] : null, 'email' => $email,
                'phone' => is_scalar($c['phone'] ?? $c['phone_number'] ?? null) ? (string) ($c['phone'] ?? $c['phone_number']) : null];
        }
        $dates = [];
        foreach ($p as $key => $value) {
            if (is_scalar($value) && preg_match('/_(date|deadline)$/', $key) === 1
                && ! in_array($key, ['contract_date', 'closing_date', 'list_date', 'created_date', 'updated_date'], true) && DealImporter::date((string) $value) !== null) {
                $dates[] = ['label' => ucfirst(str_replace('_', ' ', $key)), 'due_on' => (string) $value];
            }
        }

        return ['id' => is_scalar($p['id'] ?? null) ? (string) $p['id'] : '', 'type' => $type, 'status' => $status, 'agent_id' => null, 'agent_email' => $agentEmail,
            'street' => (string) ($str('address', 'property_address', 'street') ?? ''), 'city' => $str('city', 'property_city'),
            'state_code' => (string) ($str('state', 'property_state') ?? ''), 'postal_code' => $str('zip_code', 'zip', 'property_zip'), 'mls_number' => $str('mls_number'),
            'price_cents' => DealImporter::cents($str('sale_price', 'purchase_amount', 'offer_price', 'listing_price') ?? 0),
            'gci_cents' => DealImporter::cents($str('total_commission', 'gross_commission', 'gci') ?? 0),
            'contract_date' => $str('contract_date', 'contract_acceptance_date'), 'closing_date' => $str('closing_date'),
            'cancel_reason' => $status === 'fell_through' ? 'Cancelled in Open to Close' : null, 'parties' => $parties, 'dates' => $dates];
    }

    /**
     * Property fields as one flat map, whether Open to Close sends them flat or as field values ([{key, value}]).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function flatten(array $record): array
    {
        $out = $record;
        foreach ((array) ($record['field_values'] ?? []) as $k => $v) {
            if (is_array($v) && is_string($v['key'] ?? null)) {
                $out[$v['key']] ??= $v['value'] ?? null;
            } elseif (is_string($k)) {
                $out[$k] ??= $v;
            }
        }
        unset($out['field_values']);

        return $out;
    }

    private static function http(string $token): PendingRequest
    {
        return Http::acceptJson()->timeout(30)->baseUrl(self::API)->withQueryParameters(['api_token' => $token]);
    }

    /** @param  array<string, mixed>  $query */
    private static function get(PendingRequest $http, string $path, array $query = []): ?Response
    {
        $r = $http->get($path, $query);
        if ($r->status() === 429) {
            return null;
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('Open to Close no longer accepts the API token. Please connect it again with a new token.');
        }
        if ($r->status() === 404) {
            return null;
        }

        return $r->throw();
    }
}
