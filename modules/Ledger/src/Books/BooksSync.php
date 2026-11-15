<?php

declare(strict_types=1);

namespace Modules\Ledger\Books;

use Carbon\CarbonImmutable;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Models\LedgerJournal;
use Modules\Ledger\Models\LedgerLine;

/**
 * Posting RightAlly's books to QuickBooks Online or Xero (specs/connect/13, Sprint C6). One way: RightAlly's ledger
 * is the source; every journal posted from the "post from" date (the day the books were connected, unless the
 * owner picks another) becomes one journal entry in the books, in order, once.
 *
 * - Accounts: each of RightAlly's top-level accounts (commissions receivable, agent commissions payable, company
 *   dollar…) is mapped once to an account in the books. Agent and deal sub-accounts post to their parent's account,
 *   with the agent or deal named on the line.
 * - Order and safety: journals go in the order they were posted. If one can't go (an account not mapped yet, or the
 *   books refuse it, e.g. a closed period), posting stops there with the reason shown, and carries on from that
 *   journal on the next run. Each entry carries RightAlly's journal id as the books' idempotency key, and is
 *   remembered, so nothing is posted twice.
 * - Corrections are journals too (reversals), so the books always match RightAlly.
 */
final class BooksSync
{
    private const PER_RUN = 50;

    public function __construct(private readonly ConnectorKit $kit) {}

    /** @return array{created: int, updated: int, skipped: int, cursor: string|null, note: string|null, more?: bool} */
    public function run(ConnectionData $connection, BooksApp $books, string $app): array
    {
        $from = $this->postFrom($connection);
        $map = [];
        foreach ((array) ($connection->settings['map'] ?? []) as $code => $theirs) { // codes like "1100" come back as integer keys
            if (is_scalar($theirs) && (string) $theirs !== '') {
                $map[(string) $code] = (string) $theirs;
            }
        }
        $after = (int) ($connection->cursor ?? 0);
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $journals = LedgerJournal::query()->where('id', '>', $after)->whereDate('effective_date', '>=', $from)->orderBy('id')->limit(self::PER_RUN + 1)->get();
        $more = $journals->count() > self::PER_RUN;
        foreach ($journals->take(self::PER_RUN) as $journal) {
            if ($this->kit->externalId($connection, 'journal', $journal->id) !== null) {
                $after = $journal->id; // already in the books

                continue;
            }
            $entry = self::entry($journal, $map);
            if (is_array($entry['missing'])) {
                $this->kit->saveSettings($connection, ['waiting' => ['reason' => 'map', 'accounts' => $entry['missing'], 'journal' => $journal->ulid]]);

                return $counts + ['cursor' => (string) $after, 'more' => false,
                    'note' => 'Waiting: map these accounts to your '.$app.' accounts under Finance → Books: '.implode(', ', $entry['missing']).'.'];
            }
            try {
                $id = $books->post($connection, $entry['entry']);
            } catch (BooksRefused $e) {
                $this->kit->saveSettings($connection, ['waiting' => ['reason' => 'refused', 'message' => mb_substr($e->getMessage(), 0, 300), 'journal' => $journal->ulid]]);

                return $counts + ['cursor' => (string) $after, 'more' => false,
                    'note' => mb_substr($app.' refused journal '.$entry['entry']['number'].': '.$e->getMessage().' Fix it there, and it is posted on the next run.', 0, 300)];
            }
            $this->kit->remember($connection, 'journal', $id, $journal->id);
            $after = $journal->id;
            $counts['created']++;
        }
        $this->kit->saveSettings($connection, ['waiting' => null]);

        return $counts + ['cursor' => (string) $after, 'more' => $more, 'note' => $more ? 'Posting journals to '.$app.'…' : 'Your '.$app.' books are up to date.'];
    }

    /**
     * The date from which journals go to the books: the day they were connected, unless the owner picked another.
     */
    public function postFrom(ConnectionData $connection): string
    {
        $set = $connection->settings['post_from'] ?? null;
        if (is_string($set) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $set) === 1) {
            return $set;
        }
        $today = CarbonImmutable::today()->toDateString();
        $this->kit->saveSettings($connection, ['post_from' => $today]);

        return $today;
    }

    /**
     * One RightAlly journal as a books entry, or the top-level accounts that still need mapping.
     *
     * @param  array<array-key, string>  $map  RightAlly account code => books account
     * @return array{entry: array{ref: string, number: string, date: string, memo: string, lines: list<array{account: string, debit_cents: int, credit_cents: int, description: string}>}, missing: list<string>|null}
     */
    public static function entry(LedgerJournal $journal, array $map): array
    {
        $lines = [];
        $missing = [];
        $accounts = LedgerAccount::query()->whereIn('id', LedgerLine::query()->where('journal_id', $journal->id)->pluck('account_id'))->get()->keyBy('id');
        foreach (LedgerLine::query()->where('journal_id', $journal->id)->orderBy('id')->get() as $line) {
            $account = $accounts->get($line->account_id);
            if (! $account instanceof LedgerAccount) {
                continue;
            }
            $top = explode('-', $account->code)[0];
            if (! isset($map[$top])) {
                $name = $account->parent_id !== null ? LedgerAccount::query()->whereKey($account->parent_id)->value('name') : $account->name;
                $missing[$top] = $top.' '.(is_string($name) ? $name : $account->name);

                continue;
            }
            $lines[] = ['account' => $map[$top], 'debit_cents' => $line->debit_cents, 'credit_cents' => $line->credit_cents,
                'description' => mb_substr(trim(($account->parent_id !== null ? $account->name : '').($line->memo !== null && $line->memo !== '' ? ' — '.$line->memo : '')) ?: (string) $journal->description, 0, 400)];
        }
        $number = 'RA-'.strtoupper(substr($journal->ulid, -10));

        return ['entry' => ['ref' => $journal->ulid, 'number' => $number, 'date' => $journal->effective_date->toDateString(),
            'memo' => mb_substr(trim((string) $journal->description).' (RightAlly '.$number.', '.$journal->journal_type.')', 0, 400), 'lines' => $lines],
            'missing' => $missing === [] ? null : array_values($missing)];
    }
}
