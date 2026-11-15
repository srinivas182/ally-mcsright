<?php

declare(strict_types=1);

namespace Modules\Ledger\Books;

use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;

/**
 * An accounting app RightAlly posts the brokerage's journals to (specs/connect/13): QuickBooks Online or Xero.
 * The shared rules (which journals, account mapping, order, retries) live in BooksSync; a driver only lists the
 * books' accounts and posts one entry.
 */
interface BooksApp extends Connector
{
    /**
     * The books' active accounts, for mapping RightAlly's accounts to them.
     *
     * @return list<array{id: string, name: string, type: string}>
     */
    public function accounts(ConnectionData $connection): array;

    /**
     * Posts one balanced journal entry; safe to repeat with the same reference (the books' own idempotency key).
     *
     * @param  array{ref: string, number: string, date: string, memo: string, lines: list<array{account: string, debit_cents: int, credit_cents: int, description: string}>}  $entry
     * @return string the books' id for the entry
     *
     * @throws BooksRefused when the books refuse it (closed period, archived account): shown to the broker, tried again later
     */
    public function post(ConnectionData $connection, array $entry): string;
}
