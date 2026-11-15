<?php

declare(strict_types=1);

namespace Modules\Ledger\Books;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\NeedsReconnect;

/**
 * Xero (specs/connect/13, Sprint C6): RightAlly's journals posted as manual journals, after a one-click sign-in.
 * Granular scopes (accounting.manualjournals, accounting.settings), as Xero requires of apps made from March 2026.
 * The organisation is the first one the sign-in allowed (Xero's connections list); each journal is sent with
 * RightAlly's journal id as the Idempotency-Key.
 */
final class Xero implements BooksApp
{
    public const API = 'https://api.xero.com/api.xro/2.0/';

    public const CONNECTIONS = 'https://api.xero.com/connections';

    public function __construct(private readonly ConnectorKit $kit, private readonly BooksSync $books) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'Xero'; // connected with one click (OAuth), not a pasted key
    }

    public function sync(ConnectionData $connection): array
    {
        return $this->books->run($connection, $this, 'Xero');
    }

    public function accounts(ConnectionData $connection): array
    {
        $r = self::check($this->http($connection)->get('Accounts', ['where' => 'Status=="ACTIVE"']));

        return array_values(array_map(fn (array $a): array => ['id' => (string) $a['Code'], 'name' => (string) $a['Code'].' '.((string) ($a['Name'] ?? '')), 'type' => (string) ($a['Type'] ?? '')],
            array_filter((array) $r->json('Accounts', []), fn ($a) => is_array($a) && is_scalar($a['Code'] ?? null) && (string) $a['Code'] !== '')));
    }

    public function post(ConnectionData $connection, array $entry): string
    {
        $lines = array_map(fn (array $l): array => ['LineAmount' => round(($l['debit_cents'] - $l['credit_cents']) / 100, 2), 'AccountCode' => $l['account'],
            'Description' => $l['description']], $entry['lines']);
        $r = $this->http($connection)->withHeaders(['Idempotency-Key' => $entry['ref']])
            ->post('ManualJournals', ['ManualJournals' => [['Narration' => $entry['memo'], 'Date' => $entry['date'], 'Status' => 'POSTED', 'JournalLines' => $lines]]]);
        if ($r->status() === 400) {
            $messages = array_column((array) $r->json('Elements.0.ValidationErrors', []), 'Message');
            throw new BooksRefused($messages !== [] ? implode(' ', array_map('strval', $messages)) : (string) ($r->json('Message') ?? 'Xero refused the journal.'));
        }
        $id = self::check($r)->json('ManualJournals.0.ManualJournalID');
        if (! is_string($id) || $id === '') {
            throw new BooksRefused('Xero did not confirm the journal.');
        }

        return $id;
    }

    private function http(ConnectionData $connection): PendingRequest
    {
        $token = $this->kit->accessToken($connection);
        $tenant = $connection->settings['xero_tenant'] ?? null;
        if (! is_string($tenant) || $tenant === '') {
            $orgs = array_values(array_filter((array) self::check(Http::withToken($token)->acceptJson()->timeout(15)->get(self::CONNECTIONS))->json(),
                fn ($c) => is_array($c) && ($c['tenantType'] ?? null) === 'ORGANISATION' && is_string($c['tenantId'] ?? null)));
            if ($orgs === []) {
                throw new NeedsReconnect('That Xero sign-in has no organisation. Please connect again and choose your organisation.');
            }
            $tenant = (string) $orgs[0]['tenantId'];
            $this->kit->saveSettings($connection, ['xero_tenant' => $tenant, 'xero_org' => (string) ($orgs[0]['tenantName'] ?? '')]);
        }

        return Http::withToken($token)->acceptJson()->asJson()->timeout(30)->withHeaders(['xero-tenant-id' => $tenant])->baseUrl(self::API);
    }

    private static function check(Response $r): Response
    {
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('Xero no longer accepts RightAlly\'s sign-in. Please connect it again.');
        }

        return $r->throw();
    }
}
