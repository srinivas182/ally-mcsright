<?php

declare(strict_types=1);

namespace Modules\Ledger\Accounting\BankFeeds;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use stdClass;
use Throwable;

/**
 * The live bank feed for the operating account (BK1, B15, specs/finance/20), through the provider HQ chose in
 * Outside services → Live bank feed: Plaid (recommended), MX or Teller.
 *
 * Read-only by design: only accounts, balances and transactions are requested, and nothing in RightAlly can move
 * money through it. The brokerage signs in to its bank in the provider's own window; RightAlly keeps only the
 * provider's access token (encrypted). Transactions come in every morning (and on "Refresh"), and a month's
 * reconciliation can be filled from them instead of a downloaded statement. The ending balance is worked out from the
 * account's current balance and the transactions since the month ended; the person checks it before approving.
 */
final class BankFeeds
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * What the bank page shows about the feed.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $service = app(OutsideServices::class)->active('bank_feed');
        $feed = $this->row();

        return ['available' => $service !== null, 'provider' => $feed->provider ?? $service['provider'] ?? null, 'mode' => $service['mode'] ?? null,
            'status' => $feed->status ?? null, 'account' => $feed !== null && $feed->account_name !== null ? trim($feed->account_name.($feed->mask ? ' ····'.$feed->mask : '')) : null,
            'last_synced_at' => $feed->last_synced_at ?? null, 'error' => $feed->error ?? null,
            'accounts' => $feed !== null && $feed->status === 'choose' ? rescue(fn (): array => $this->provider()->accounts($this->access($feed)), [], true) : []];
    }

    /** @return array<string, string> what the page needs to open the provider's sign-in */
    public function start(User $by): array
    {
        return $this->provider()->start($this->ref());
    }

    /** @param  array<string, mixed>  $input */
    public function finish(array $input, User $by): void
    {
        $service = $this->service();
        try {
            $access = $this->provider()->finish($input, $this->ref());
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['feed' => 'The bank connection did not finish. Please try again.']);
        }
        $db = DB::connection(Connections::cell());
        $db->table('acct_bank_feeds')->where('tenant_id', $this->tenants->id())->delete();
        $db->table('acct_bank_feeds')->insert(['tenant_id' => $this->tenants->id(), 'provider' => $service['provider'], 'mode' => $service['mode'],
            'access' => Crypt::encryptString((string) json_encode($access)), 'status' => 'choose', 'connected_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('finance.bank_feed_connected', metadata: ['provider' => $service['provider'], 'mode' => $service['mode']], severity: 'warning');
    }

    /** The person picks which account is the operating account; the first pull runs straight away. */
    public function choose(string $accountId, User $by): void
    {
        $feed = $this->row() ?? throw ValidationException::withMessages(['account' => 'Connect the bank first.']);
        $account = collect($this->provider()->accounts($this->access($feed)))->firstWhere('id', $accountId)
            ?? throw ValidationException::withMessages(['account' => 'Choose one of the accounts listed.']);
        DB::connection(Connections::cell())->table('acct_bank_feeds')->where('id', $feed->id)->update(['account_id' => $account['id'], 'account_name' => mb_substr($account['name'], 0, 120),
            'mask' => $account['mask'] !== null ? mb_substr($account['mask'], -4) : null, 'status' => 'active', 'cursor' => null, 'updated_at' => now()]);
        app(AuditLogger::class)->record('finance.bank_feed_account', metadata: ['account' => $account['name']], severity: 'notice');
        $this->sync();
    }

    /** Brings new transactions in. Returns how many were added or changed. */
    public function sync(): int
    {
        $feed = $this->row();
        if ($feed === null || $feed->status === 'choose' || $feed->account_id === null) {
            return 0;
        }
        $db = DB::connection(Connections::cell());
        try {
            $provider = $this->provider();
            $access = $this->access($feed);
            $r = $provider->transactions($access, (string) $feed->account_id, $feed->cursor !== null ? (string) $feed->cursor : null);
            $balance = $provider->balance($access, (string) $feed->account_id);
        } catch (Throwable $e) {
            report($e);
            $db->table('acct_bank_feeds')->where('id', $feed->id)->update(['status' => 'error', 'error' => 'The bank did not answer. RightAlly tries again tomorrow, or press Refresh.',
                'updated_at' => now()]);

            return 0;
        }
        foreach ($r['added'] as $t) {
            $db->table('acct_bank_feed_txns')->updateOrInsert(['feed_id' => $feed->id, 'external_id' => mb_substr($t['id'], 0, 120)], ['tenant_id' => $this->tenants->id(),
                'posted_on' => $t['date'], 'amount_cents' => $t['cents'], 'description' => mb_substr($t['name'], 0, 300), 'pending' => $t['pending'], 'updated_at' => now(), 'created_at' => now()]);
        }
        if ($r['removed'] !== []) {
            $db->table('acct_bank_feed_txns')->where('feed_id', $feed->id)->whereIn('external_id', $r['removed'])->delete();
        }
        $db->table('acct_bank_feeds')->where('id', $feed->id)->update(['cursor' => $r['cursor'], 'balance_cents' => $balance, 'balance_at' => $balance !== null ? now() : $feed->balance_at,
            'status' => 'active', 'error' => null, 'last_synced_at' => now(), 'updated_at' => now()]);

        return count($r['added']);
    }

    /**
     * A month's statement from the feed: posted (not pending) transactions in the month, and the ending balance
     * worked out from today's balance less everything posted since the month ended.
     *
     * @return array{lines: list<array{posted_on: string, description: string, amount_cents: int, fitid: string|null}>, ending_cents: int|null}
     */
    public function statement(string $month): array
    {
        $feed = $this->row();
        if ($feed === null || $feed->status !== 'active') {
            throw ValidationException::withMessages(['feed' => 'Connect the live bank feed first.']);
        }
        $from = CarbonImmutable::parse($month.'-01');
        $to = $from->endOfMonth();
        $db = DB::connection(Connections::cell());
        $q = fn () => $db->table('acct_bank_feed_txns')->where('feed_id', $feed->id)->where('pending', false);
        $lines = array_values($q()->whereBetween('posted_on', [$from->toDateString(), $to->toDateString()])->orderBy('posted_on')->orderBy('id')->get()
            ->map(fn ($t): array => ['posted_on' => substr((string) $t->posted_on, 0, 10), 'description' => (string) $t->description, 'amount_cents' => (int) $t->amount_cents,
                'fitid' => mb_substr((string) $t->external_id, 0, 80)])->all());
        $since = (int) $q()->where('posted_on', '>', $to->toDateString())->sum('amount_cents');

        return ['lines' => $lines, 'ending_cents' => $feed->balance_cents !== null ? (int) $feed->balance_cents - $since : null];
    }

    public function disconnect(User $by): void
    {
        $db = DB::connection(Connections::cell());
        $feed = $this->row();
        if ($feed !== null) {
            $db->table('acct_bank_feed_txns')->where('feed_id', $feed->id)->delete();
            $db->table('acct_bank_feeds')->where('id', $feed->id)->delete();
            app(AuditLogger::class)->record('finance.bank_feed_disconnected', metadata: ['provider' => $feed->provider], severity: 'warning');
        }
    }

    private function row(): ?stdClass
    {
        return DB::connection(Connections::cell())->table('acct_bank_feeds')->where('tenant_id', $this->tenants->id())->first();
    }

    /** @return array{provider: string, mode: string, keys: array<string, string>, test_contacts: list<string>, level: string} */
    private function service(): array
    {
        return app(OutsideServices::class)->active('bank_feed') ?? throw ValidationException::withMessages(['feed' => 'The live bank feed is not switched on yet (RightAlly HQ turns it on).']);
    }

    private function provider(): FeedProvider
    {
        $s = $this->service();
        $test = $s['mode'] !== 'live';

        return match ($s['provider']) {
            'plaid' => new PlaidFeed($s['keys'], $test, (string) $this->tenants->current()->name),
            'mx' => new MxFeed($s['keys'], $test),
            'teller' => new TellerFeed($s['keys'], $test),
            default => throw ValidationException::withMessages(['feed' => 'Unknown bank-feed provider.']),
        };
    }

    /** @return array<string, string> */
    private function access(stdClass $feed): array
    {
        return (array) json_decode(Crypt::decryptString((string) $feed->access), true);
    }

    /** A stable, non-identifying reference for the brokerage at the provider. */
    private function ref(): string
    {
        return 'ra-'.substr(hash('sha256', config('app.key').'|'.$this->tenants->current()->ulid), 0, 24);
    }
}
