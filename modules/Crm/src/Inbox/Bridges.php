<?php

declare(strict_types=1);

namespace Modules\Crm\Inbox;

use Modules\Crm\Models\Contact;

/** Every registered ContactBridge, asked in turn (specs/movewise/05). With none registered, nothing changes. */
final class Bridges
{
    /** @return list<ContactBridge> */
    private function all(): array
    {
        $out = [];
        foreach (app()->tagged('crm.contact_bridges') as $b) {
            if ($b instanceof ContactBridge) {
                $out[] = $b;
            }
        }

        return $out;
    }

    public function smsFrom(Contact $contact): ?string
    {
        foreach ($this->all() as $b) {
            $n = $b->smsFrom($contact);
            if ($n !== null) {
                return $n;
            }
        }

        return null;
    }

    /** @return array{level: 'all'|'bulk', reason: string}|null the strictest hold */
    public function hold(Contact $contact): ?array
    {
        $found = null;
        foreach ($this->all() as $b) {
            $h = $b->hold($contact);
            if ($h !== null && ($found === null || $h['level'] === 'all')) {
                $found = $h;
            }
        }

        return $found;
    }

    /** May this contact get automated, campaign or bulk messages? */
    public function allowsBulk(Contact $contact): bool
    {
        return $this->hold($contact) === null;
    }

    public function keyword(Contact $contact, string $keyword): ?string
    {
        foreach ($this->all() as $b) {
            $r = $b->keyword($contact, $keyword);
            if ($r !== null) {
                return $r;
            }
        }

        return null;
    }
}
