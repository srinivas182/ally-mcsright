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
 * QuickBooks Online (specs/connect/13, Sprint C6): RightAlly's journals posted as journal entries, after a one-click
 * sign-in to the company (Intuit names the company, the "realm", on the return address). Accounting API v3, minor
 * version 75; each entry is sent with RightAlly's journal id as Intuit's requestid, so a retry never posts twice.
 */
final class QuickBooks implements BooksApp
{
    public const API = 'https://quickbooks.api.intuit.com/v3/company/';

    public const MINOR = '75';

    public function __construct(private readonly ConnectorKit $kit, private readonly BooksSync $books) {}

    public function verify(array $credentials, array $settings): string
    {
        return 'QuickBooks Online'; // connected with one click (OAuth), not a pasted key
    }

    public function sync(ConnectionData $connection): array
    {
        return $this->books->run($connection, $this, 'QuickBooks');
    }

    public function accounts(ConnectionData $connection): array
    {
        $r = self::check($this->http($connection)->get('query', ['query' => 'select Id, Name, AccountType, FullyQualifiedName from Account where Active = true maxresults 1000', 'minorversion' => self::MINOR]));

        return array_values(array_map(fn (array $a): array => ['id' => (string) $a['Id'], 'name' => (string) ($a['FullyQualifiedName'] ?? $a['Name'] ?? ''), 'type' => (string) ($a['AccountType'] ?? '')],
            array_filter((array) $r->json('QueryResponse.Account', []), fn ($a) => is_array($a) && is_scalar($a['Id'] ?? null))));
    }

    public function post(ConnectionData $connection, array $entry): string
    {
        $lines = [];
        foreach ($entry['lines'] as $l) {
            $debit = $l['debit_cents'] > 0;
            $lines[] = ['DetailType' => 'JournalEntryLineDetail', 'Amount' => round(($debit ? $l['debit_cents'] : $l['credit_cents']) / 100, 2), 'Description' => $l['description'],
                'JournalEntryLineDetail' => ['PostingType' => $debit ? 'Debit' : 'Credit', 'AccountRef' => ['value' => $l['account']]]];
        }
        $r = $this->http($connection)->post('journalentry?'.http_build_query(['minorversion' => self::MINOR, 'requestid' => $entry['ref']]),
            ['DocNumber' => $entry['number'], 'TxnDate' => $entry['date'], 'PrivateNote' => $entry['memo'], 'Line' => $lines]);
        if ($r->status() === 400) {
            $fault = $r->json('Fault.Error.0');
            throw new BooksRefused(is_array($fault) ? trim(((string) ($fault['Message'] ?? '')).' '.((string) ($fault['Detail'] ?? ''))) : 'QuickBooks refused the entry.');
        }
        $id = self::check($r)->json('JournalEntry.Id');
        if (! is_scalar($id)) {
            throw new BooksRefused('QuickBooks did not confirm the entry.');
        }

        return (string) $id;
    }

    private function http(ConnectionData $connection): PendingRequest
    {
        $realm = (string) ($connection->credentials['realm_id'] ?? '');
        if (preg_match('/^\d{1,24}$/', $realm) !== 1) {
            throw new NeedsReconnect('Please connect QuickBooks again and choose your company.');
        }

        return Http::withToken($this->kit->accessToken($connection))->acceptJson()->asJson()->timeout(30)->baseUrl(self::API.$realm.'/');
    }

    private static function check(Response $r): Response
    {
        if (in_array($r->status(), [401, 403], true)) {
            throw new NeedsReconnect('QuickBooks no longer accepts RightAlly\'s sign-in. Please connect it again.');
        }

        return $r->throw();
    }
}
