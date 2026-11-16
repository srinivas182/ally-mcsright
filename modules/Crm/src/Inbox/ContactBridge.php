<?php

declare(strict_types=1);

namespace Modules\Crm\Inbox;

use Modules\Crm\Models\Contact;

/**
 * A service that stands between an agent and a contact (specs/movewise/05: the Movewise relay). Registered with the
 * tag "crm.contact_bridges". The CRM asks it which number to text from, whether the contact is held back from
 * automated or bulk messages (or from all messages while the person has paused or stopped), and lets it answer its
 * own text keywords (PAUSE, CHANGE).
 */
interface ContactBridge
{
    /** The number to text this contact from, instead of the brokerage's own number; null when not bridged. */
    public function smsFrom(Contact $contact): ?string;

    /**
     * @return array{level: 'all'|'bulk', reason: string}|null 'bulk': only personal messages may go; 'all': nothing may go
     */
    public function hold(Contact $contact): ?array;

    /** A text keyword from this contact. Returns the reply to send back when the bridge handled it. */
    public function keyword(Contact $contact, string $keyword): ?string;
}
