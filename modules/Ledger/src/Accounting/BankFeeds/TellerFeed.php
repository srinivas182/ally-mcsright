<?php

declare(strict_types=1);

namespace Modules\Ledger\Accounting\BankFeeds;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Teller: Teller Connect opens in the page; every API call is signed with HQ's Teller certificate (mutual TLS) and
 * the enrollment's access token. Test mode is Teller's sandbox. Read-only: accounts, balances, transactions.
 */
final class TellerFeed implements FeedProvider
{
    /** @param  array<string, string>  $keys  application_id, certificate (PEM with key) */
    public function __construct(private readonly array $keys, private readonly bool $test) {}

    public function start(string $tenantRef): array
    {
        return ['provider' => 'teller', 'application_id' => $this->keys['application_id'] ?? '', 'environment' => $this->test ? 'sandbox' : 'production'];
    }

    public function finish(array $input, string $tenantRef): array
    {
        $token = (string) ($input['access_token'] ?? '');
        if (preg_match('/^[A-Za-z0-9_\-]{8,200}$/', $token) !== 1) {
            throw new RuntimeException('Teller did not finish the sign-in.');
        }

        return ['access_token' => $token];
    }

    public function accounts(array $access): array
    {
        $r = $this->ok($this->http($access)->get('https://api.teller.io/accounts'));

        return array_values(array_map(fn (array $a): array => ['id' => (string) $a['id'], 'name' => (string) ($a['name'] ?? 'Account'), 'mask' => isset($a['last_four']) ? (string) $a['last_four'] : null],
            array_filter((array) $r->json(), fn ($a): bool => is_array($a) && ($a['type'] ?? '') === 'depository')));
    }

    public function transactions(array $access, string $account, ?string $cursor): array
    {
        $r = $this->ok($this->http($access)->get('https://api.teller.io/accounts/'.rawurlencode($account).'/transactions', ['count' => 500]));
        $added = [];
        foreach ((array) $r->json() as $t) {
            if (is_array($t)) {
                // Teller: a signed amount; negative is money out.
                $added[] = ['id' => (string) $t['id'], 'date' => (string) $t['date'], 'cents' => (int) round((float) $t['amount'] * 100),
                    'name' => (string) ($t['description'] ?? 'Bank transaction'), 'pending' => ($t['status'] ?? '') === 'pending'];
            }
        }

        return ['added' => $added, 'removed' => [], 'cursor' => null];
    }

    public function balance(array $access, string $account): ?int
    {
        $r = $this->ok($this->http($access)->get('https://api.teller.io/accounts/'.rawurlencode($account).'/balances'));
        $b = $r->json('ledger');

        return is_numeric($b) ? (int) round((float) $b * 100) : null;
    }

    /** @param  array<string, string>  $access */
    private function http(array $access): PendingRequest
    {
        $pem = $this->keys['certificate'] ?? '';
        $file = sys_get_temp_dir().'/teller-'.hash('sha256', $pem).'.pem';
        if (! is_file($file)) {
            file_put_contents($file, $pem);
            chmod($file, 0600);
        }

        return Http::timeout(30)->acceptJson()->withBasicAuth($access['access_token'], '')->withOptions(['cert' => $file, 'ssl_key' => $file]);
    }

    private function ok(Response $r): Response
    {
        if ($r->failed()) {
            throw new RuntimeException('Teller: error '.$r->status());
        }

        return $r;
    }
}
