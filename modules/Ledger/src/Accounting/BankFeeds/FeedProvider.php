<?php

declare(strict_types=1);

namespace Modules\Ledger\Accounting\BankFeeds;

/**
 * One read-only bank-feed provider (BK1): Plaid, MX or Teller. Only accounts, balances and transactions are ever
 * asked for; nothing that could move money (no payment, transfer or account-number products).
 */
interface FeedProvider
{
    /**
     * What the page needs to open the provider's own bank sign-in (the person signs in to their bank there, never here).
     *
     * @return array<string, string>
     */
    public function start(string $tenantRef): array;

    /**
     * Finishes the sign-in with what the provider's widget returned; returns what to keep (encrypted).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function finish(array $input, string $tenantRef): array;

    /**
     * @param  array<string, string>  $access
     * @return list<array{id: string, name: string, mask: string|null}>
     */
    public function accounts(array $access): array;

    /**
     * New, changed and removed transactions since the cursor.
     *
     * @param  array<string, string>  $access
     * @return array{added: list<array{id: string, date: string, cents: int, name: string, pending: bool}>, removed: list<string>, cursor: string|null}
     */
    public function transactions(array $access, string $account, ?string $cursor): array;

    /**
     * The account's current (ledger) balance in cents.
     *
     * @param  array<string, string>  $access
     */
    public function balance(array $access, string $account): ?int;
}
