<?php

declare(strict_types=1);

namespace Modules\Ledger\Accounting\BankFeeds;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Plaid (recommended): Link opens in the page; Test mode is Plaid Sandbox, Live is Production. Transactions only. */
final class PlaidFeed implements FeedProvider
{
    /** @param  array<string, string>  $keys  client_id, secret */
    public function __construct(private readonly array $keys, private readonly bool $test, private readonly string $brokerage) {}

    public function start(string $tenantRef): array
    {
        $r = $this->post('/link/token/create', ['client_name' => mb_substr($this->brokerage, 0, 30), 'user' => ['client_user_id' => $tenantRef], 'products' => ['transactions'],
            'transactions' => ['days_requested' => 730], 'country_codes' => ['US'], 'language' => 'en']);

        return ['provider' => 'plaid', 'link_token' => (string) $r->json('link_token')];
    }

    public function finish(array $input, string $tenantRef): array
    {
        $token = (string) ($input['public_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Plaid did not finish the sign-in.');
        }
        $r = $this->post('/item/public_token/exchange', ['public_token' => $token]);

        return ['access_token' => (string) $r->json('access_token'), 'item_id' => (string) $r->json('item_id')];
    }

    public function accounts(array $access): array
    {
        $r = $this->post('/accounts/get', ['access_token' => $access['access_token']]);

        return array_values(array_map(fn (array $a): array => ['id' => (string) $a['account_id'], 'name' => (string) ($a['official_name'] ?? $a['name'] ?? 'Account'),
            'mask' => isset($a['mask']) ? (string) $a['mask'] : null], array_filter((array) $r->json('accounts', []), fn ($a): bool => is_array($a) && ($a['type'] ?? '') === 'depository')));
    }

    public function transactions(array $access, string $account, ?string $cursor): array
    {
        $added = [];
        $removed = [];
        for ($page = 0; $page < 20; $page++) {
            $r = $this->post('/transactions/sync', array_filter(['access_token' => $access['access_token'], 'cursor' => $cursor, 'count' => 500]));
            foreach ([...(array) $r->json('added', []), ...(array) $r->json('modified', [])] as $t) {
                if (is_array($t) && ($t['account_id'] ?? null) === $account) {
                    // Plaid: positive = money out of the account.
                    $added[] = ['id' => (string) $t['transaction_id'], 'date' => (string) $t['date'], 'cents' => -(int) round((float) $t['amount'] * 100),
                        'name' => (string) ($t['merchant_name'] ?? $t['name'] ?? 'Bank transaction'), 'pending' => (bool) ($t['pending'] ?? false)];
                }
            }
            foreach ((array) $r->json('removed', []) as $t) {
                $removed[] = (string) (is_array($t) ? ($t['transaction_id'] ?? '') : $t);
            }
            $cursor = (string) $r->json('next_cursor');
            if (! $r->json('has_more')) {
                break;
            }
        }

        return ['added' => $added, 'removed' => array_values(array_filter($removed)), 'cursor' => $cursor];
    }

    public function balance(array $access, string $account): ?int
    {
        $r = $this->post('/accounts/balance/get', ['access_token' => $access['access_token'], 'options' => ['account_ids' => [$account]]]);
        $current = $r->json('accounts.0.balances.current');

        return is_numeric($current) ? (int) round((float) $current * 100) : null;
    }

    /** @param  array<string, mixed>  $body */
    private function post(string $path, array $body): Response
    {
        $r = Http::timeout(30)->acceptJson()->post(($this->test ? 'https://sandbox.plaid.com' : 'https://production.plaid.com').$path,
            ['client_id' => $this->keys['client_id'] ?? '', 'secret' => $this->keys['secret'] ?? ''] + $body);
        if ($r->failed()) {
            throw new RuntimeException('Plaid: '.((string) $r->json('error_message') ?: 'error '.$r->status()));
        }

        return $r;
    }
}
