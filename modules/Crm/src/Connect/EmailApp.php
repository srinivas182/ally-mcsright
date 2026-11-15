<?php

declare(strict_types=1);

namespace Modules\Crm\Connect;

use Modules\Core\Connect\ConnectionData;

/** An email marketing app (specs/connect/15, C7a): its audiences, adding or updating people, and unsubscribes. */
interface EmailApp
{
    /** @return list<array{id: string, name: string, members: int|null}> */
    public function audiences(ConnectionData $connection): array;

    /**
     * Adds or updates one person in the audience, with their tags (where the app takes them).
     *
     * @param  array{email: string, first_name: string, last_name: string, tags: list<string>}  $person
     */
    public function upsert(ConnectionData $connection, string $audience, array $person): void;

    /** Marks them unsubscribed in the app (they opted out of email here). */
    public function unsubscribe(ConnectionData $connection, string $audience, string $email): void;

    /**
     * Emails that unsubscribed (or were cleaned) in the app since the given time.
     *
     * @return list<string>
     */
    public function unsubscribedSince(ConnectionData $connection, string $audience, ?string $since): array;

    /** The app's display name, for consent records and messages. */
    public function name(): string;
}
