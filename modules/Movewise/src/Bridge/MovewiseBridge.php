<?php

declare(strict_types=1);

namespace Modules\Movewise\Bridge;

use Carbon\CarbonImmutable;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Inbox\ContactBridge;
use Modules\Crm\Models\Contact;
use Modules\Movewise\Models\Connection;

/**
 * Movewise as a CRM contact bridge (specs/movewise/05): texts to a Movewise client go from their relay number;
 * they get personal messages only; nothing at all while they have paused or after they stopped; and their text
 * keywords (PAUSE, RESUME, CHANGE, STOP, YES to a hand-over) work from any message.
 */
final class MovewiseBridge implements ContactBridge
{
    private const STOP = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'REVOKE', 'OPTOUT'];

    public function __construct(private readonly TenantContext $tenants, private readonly Relay $relay) {}

    public function smsFrom(Contact $contact): ?string
    {
        $c = $this->live($contact);

        return $c !== null && $c->status === 'accepted' ? $c->relay_number : null;
    }

    public function hold(Contact $contact): ?array
    {
        $c = $this->latest($contact);
        if ($c === null || $c->handed_over_at !== null) {
            return null;
        }
        if (in_array($c->status, ['stopped', 'changed', 'withdrawn'], true)) {
            return ['level' => 'all', 'reason' => 'They ended contact through Movewise. Don\'t contact them about it again.'];
        }
        if ($c->status !== 'accepted') {
            return null;
        }
        if ($c->paused_until !== null && $c->paused_until->isFuture()) {
            return ['level' => 'all', 'reason' => 'They paused contact through Movewise until '.$c->paused_until->toFormattedDateString().'.'];
        }

        return ['level' => 'bulk', 'reason' => 'A Movewise introduction: personal messages only (no campaigns, plans or automated texts).'];
    }

    public function keyword(Contact $contact, string $keyword): ?string
    {
        $c = $this->live($contact);
        if ($c === null) {
            return null;
        }
        $base = 'https://'.config('movewise.host');

        return match (true) {
            $keyword === 'PAUSE' => $this->then($this->relay->pause($c, 'text'), 'Movewise: paused. Nobody will contact you for '.Relay::PAUSE_DAYS.' days. Reply RESUME to undo, or STOP to end.'),
            $keyword === 'RESUME' && $c->paused_until !== null => $this->then($this->relay->resume($c), 'Movewise: resumed. Your agent can contact you again.'),
            $keyword === 'CHANGE' => 'Movewise: choose another agent here: '.$base.'/connect/'.$this->relay->change($c).' Your current agent won\'t contact you again.',
            $keyword === 'YES' && $c->handover_asked_at !== null => $this->relay->handOver($c),
            in_array($keyword, self::STOP, true) => $this->stopThen($c),
            default => null,
        };
    }

    /** STOP: the introduction ends here; the usual opt-out then runs and the carrier confirms (so no reply of our own). */
    private function stopThen(Connection $c): null
    {
        $this->relay->stop($c, 'Replied STOP');

        return null;
    }

    private function then(Connection $done, string $reply): string
    {
        return $reply;
    }

    /** The introduction that is still bridged for this contact. */
    private function live(Contact $contact): ?Connection
    {
        $c = $this->latest($contact);

        return $c !== null && $c->status === 'accepted' && $c->handed_over_at === null ? $c : null;
    }

    private function latest(Contact $contact): ?Connection
    {
        if (! $this->tenants->check()) {
            return null;
        }

        return Connection::query()->where('tenant_id', $this->tenants->id())->where('contact_id', $contact->id)->where('offered_at', '>=', CarbonImmutable::now()->subYears(2))
            ->orderByDesc('id')->first();
    }
}
