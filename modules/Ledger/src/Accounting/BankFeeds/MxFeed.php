<?php

declare(strict_types=1);

namespace Modules\Ledger\Accounting\BankFeeds;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** MX: the Connect widget opens in the page; Test mode is MX's integration environment. Aggregation only. */
final class MxFeed implements FeedProvider
{
    /** @param  array<string, string>  $keys  client_id, api_key */
    public function __construct(private readonly array $keys, private readonly bool $test) {}

    public function start(string $tenantRef): array
    {
        $r = $this->http()->post($this->base().'/users', ['user' => ['id' => $tenantRef]]);
        $guid = (string) $r->json('user.guid');
        if ($guid === '') { // the user exists already: find it
            $guid = (string) $this->ok($this->http()->get($this->base().'/users', ['id' => $tenantRef]))->json('users.0.guid');
        }
        $w = $this->ok($this->http()->post($this->base().'/users/'.rawurlencode($guid).'/widget_urls', ['widget_url' => ['widget_type' => 'connect_widget', 'mode' => 'aggregation']]));

        return ['provider' => 'mx', 'widget_url' => (string) $w->json('widget_url.url'), 'user_guid' => $guid];
    }

    public function finish(array $input, string $tenantRef): array
    {
        $guid = (string) ($input['user_guid'] ?? '');
        if (preg_match('/^USR-[A-Za-z0-9-]+$/', $guid) !== 1) {
            throw new RuntimeException('MX did not finish the sign-in.');
        }

        return ['user_guid' => $guid];
    }

    public function accounts(array $access): array
    {
        $r = $this->ok($this->http()->get($this->base().'/users/'.rawurlencode($access['user_guid']).'/accounts', ['records_per_page' => 100]));

        return array_values(array_map(fn (array $a): array => ['id' => (string) $a['guid'], 'name' => (string) ($a['name'] ?? 'Account'),
            'mask' => isset($a['account_number']) ? substr((string) $a['account_number'], -4) : null],
            array_filter((array) $r->json('accounts', []), fn ($a): bool => is_array($a) && in_array($a['type'] ?? '', ['CHECKING', 'SAVINGS'], true))));
    }

    public function transactions(array $access, string $account, ?string $cursor): array
    {
        $from = $cursor !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cursor) === 1 ? $cursor : now()->subMonths(24)->toDateString();
        $added = [];
        for ($page = 1; $page <= 20; $page++) {
            $r = $this->ok($this->http()->get($this->base().'/users/'.rawurlencode($access['user_guid']).'/accounts/'.rawurlencode($account).'/transactions',
                ['from_date' => $from, 'page' => $page, 'records_per_page' => 250]));
            foreach ((array) $r->json('transactions', []) as $t) {
                if (is_array($t)) {
                    $cents = (int) round(abs((float) $t['amount']) * 100);
                    $added[] = ['id' => (string) $t['guid'], 'date' => substr((string) ($t['posted_at'] ?? $t['date'] ?? $t['transacted_at']), 0, 10),
                        'cents' => ($t['type'] ?? '') === 'DEBIT' ? -$cents : $cents, 'name' => (string) ($t['description'] ?? 'Bank transaction'), 'pending' => ($t['status'] ?? '') === 'PENDING'];
                }
            }
            if ($page >= (int) $r->json('pagination.total_pages', 1)) {
                break;
            }
        }

        // Ask again from a week back next time, so late-posting items are picked up (the same id is kept once).
        return ['added' => $added, 'removed' => [], 'cursor' => now()->subDays(7)->toDateString()];
    }

    public function balance(array $access, string $account): ?int
    {
        $r = $this->ok($this->http()->get($this->base().'/users/'.rawurlencode($access['user_guid']).'/accounts/'.rawurlencode($account)));
        $b = $r->json('account.balance');

        return is_numeric($b) ? (int) round((float) $b * 100) : null;
    }

    private function base(): string
    {
        return $this->test ? 'https://int-api.mx.com' : 'https://api.mx.com';
    }

    private function http(): PendingRequest
    {
        return Http::timeout(30)->withBasicAuth($this->keys['client_id'] ?? '', $this->keys['api_key'] ?? '')->accept('application/vnd.mx.api.v1+json');
    }

    private function ok(Response $r): Response
    {
        if ($r->failed()) {
            throw new RuntimeException('MX: error '.$r->status());
        }

        return $r;
    }
}
