<?php

declare(strict_types=1);

namespace Modules\Crm\Connect;

use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Models\Contact;

/**
 * Sends new RightAlly contacts and notes, and name and agent changes, to Follow Up Boss (specs/connect/05), to each connection that covers the
 * contact: the brokerage's connection covers company contacts; an agent's own connection covers only their personal
 * contacts. Nothing that came from Follow Up Boss is sent back to it. Each send is a queued job, after the save.
 */
final class FollowUpBossOutbox
{
    public static function contactCreated(int $contactId): void
    {
        self::send($contactId, 'contact', $contactId);
    }

    /** A linked contact's name or agent changed in RightAlly. */
    public static function contactChanged(int $contactId): void
    {
        self::send($contactId, 'contact_update', $contactId);
    }

    public static function noteLogged(int $contactId, int $activityId): void
    {
        self::send($contactId, 'note', $activityId);
    }

    private static function send(int $contactId, string $entity, int $localId): void
    {
        if (FollowUpBoss::$quiet) {
            return; // a change Follow Up Boss just told us about
        }
        try {
            $contact = Contact::query()->find($contactId);
            if ($contact === null) {
                return;
            }
            foreach (app(ConnectorKit::class)->connections('follow_up_boss') as $c) {
                if (self::covers($c, $contact)) {
                    PushToFollowUpBoss::dispatch($c->id, $entity, $localId);
                }
            }
        } catch (\Throwable $e) {
            report($e); // never break the save that caused it
        }
    }

    private static function covers(ConnectionData $c, Contact $contact): bool
    {
        return $c->ownerUserId === null
            ? $contact->origin === Origin::Company
            : $contact->origin === Origin::Personal && $contact->owner_user_id === $c->ownerUserId;
    }
}
