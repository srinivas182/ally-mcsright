<?php

declare(strict_types=1);

namespace Modules\Crm\Support;

use Illuminate\Support\Facades\Cache;
use Modules\Core\Tenancy\TenantCache;
use Modules\Crm\Models\Contact;

/**
 * Sprint 60 (load test): counts over a brokerage's whole database (smart-list counts, database health, database
 * value) each read every contact. With 100,000 contacts a page of them took about two seconds.
 *
 * For a big brokerage (BIG contacts or more) such numbers are kept for TTL seconds, per brokerage and per question.
 * Any contact added, changed or removed in the brokerage starts fresh numbers at once (a per-brokerage version in the
 * key). Smaller brokerages are always counted live, so their numbers are never even a moment old.
 */
final class ContactCounts
{
    /** Contacts from which a brokerage's counts are kept for a while (config crm.counts_from). Default to review. */
    public const BIG = 2000;

    /** Seconds a kept number may be used, at most. Default to review. */
    public const TTL = 300;

    public function __construct(private readonly TenantCache $keys) {}

    /**
     * The number, kept for a while in a big brokerage, otherwise counted now.
     *
     * @template T
     *
     * @param  string  $question  what is counted, including whose view (e.g. "lists:user:12")
     * @param  callable(): T  $count
     * @return T
     */
    public function remember(string $question, callable $count): mixed
    {
        if (! $this->big()) {
            return $count();
        }

        return Cache::remember($this->keys->key('crm.counts.'.$this->version().'.'.$question), self::TTL, fn (): mixed => $count());
    }

    /** A contact changed: every kept number for this brokerage is replaced on next use. */
    public function changed(): void
    {
        Cache::forever($this->keys->key('crm.counts.version'), $this->version() + 1);
    }

    private function version(): int
    {
        return (int) Cache::get($this->keys->key('crm.counts.version'), 0);
    }

    /** Whether the brokerage is big enough to keep numbers (itself looked at once every ten minutes). */
    private function big(): bool
    {
        return (bool) Cache::remember($this->keys->key('crm.counts.big'), 600, function (): bool {
            $from = max(1, (int) config('crm.counts_from', self::BIG));

            return Contact::query()->orderBy('id')->skip($from - 1)->value('id') !== null; // reads that many index entries, not every contact
        });
    }
}
