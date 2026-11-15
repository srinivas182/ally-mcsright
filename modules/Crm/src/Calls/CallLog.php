<?php

declare(strict_types=1);

namespace Modules\Crm\Calls;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Models\Contact;

/**
 * Calls and texts from phone apps on the contact's timeline (specs/connect/15, C7b): RingCentral, Dialpad, Aircall.
 *
 * - Matched by phone number to contacts the call's agent can see (or, when the agent isn't known, the brokerage's
 *   company contacts). Unknown numbers create nothing: no contacts from phone logs.
 * - Each call or text is logged once (remembered by the app's id). An outbound call or text to an open lead counts
 *   as the agent's response (speed to lead), through the usual timeline event.
 * - Recordings: only a link, and only when the brokerage switched on "Show recording links" after confirming its
 *   recording notice. Nothing is transcribed.
 */
final class CallLog
{
    public function __construct(private readonly ConnectorKit $kit, private readonly Timeline $timeline) {}

    /**
     * @param  array{id: string, kind: 'call'|'text', direction: 'in'|'out', number: string, at: string, seconds?: int|null, answered?: bool, voicemail?: bool, agent_email?: string|null, recording?: string|null, text?: string|null}  $c
     * @return 'created'|'skipped'
     */
    public function log(ConnectionData $connection, array $c, string $app): string
    {
        $key = $c['kind'].':'.$c['id'];
        if ($c['id'] === '' || $this->kit->localId($connection, 'call', $key) !== null) {
            return 'skipped';
        }
        $phone = ManageContacts::e164($c['number']);
        if ($phone === null) {
            return 'skipped';
        }
        $agent = is_string($c['agent_email'] ?? null) && $c['agent_email'] !== '' ? User::query()->where('email', mb_strtolower($c['agent_email']))->first() : null;
        $agent = $agent instanceof User && $agent->isActive() ? $agent : null;
        $by = $agent ?? ($connection->ownerUserId !== null ? User::query()->find($connection->ownerUserId) : User::query()->find($this->kit->connectedBy($connection)));
        if (! $by instanceof User) {
            return 'skipped';
        }
        $q = $agent !== null ? app(ContactVisibility::class)->query($agent) : Contact::query()->where('origin', Origin::Company->value);
        $contacts = $q->where('primary_phone', $phone)->limit(3)->get();
        if ($contacts->isEmpty()) {
            return 'skipped';
        }
        $at = CarbonImmutable::parse($c['at']);
        $at = $at->isFuture() ? CarbonImmutable::now() : $at;
        $seconds = max(0, (int) ($c['seconds'] ?? 0));
        $recording = ($connection->settings['recordings'] ?? false) === true && is_string($c['recording'] ?? null) && str_starts_with($c['recording'], 'https://') ? $c['recording'] : null;
        $first = null;
        foreach ($contacts as $contact) {
            $body = $c['kind'] === 'text'
                ? mb_substr(trim((string) ($c['text'] ?? '')), 0, 2000).' (from '.$app.')'
                : trim(($c['direction'] === 'in' ? 'Incoming' : 'Outgoing').' call via '.$app.($seconds > 0 ? ', '.self::length($seconds) : '').'.'.($recording !== null ? ' Recording: '.$recording : ''));
            $activity = $this->timeline->log($contact, ['type' => $c['kind'], 'direction' => $c['direction'], 'body' => $body, 'occurred_at' => $at->toIso8601String(),
                'duration_minutes' => $c['kind'] === 'call' ? (int) ceil($seconds / 60) : null,
                'outcome' => $c['kind'] === 'call' ? (($c['answered'] ?? $seconds > 0) ? 'connected' : (($c['voicemail'] ?? false) ? 'left_voicemail' : 'no_answer')) : null], $by);
            $first ??= $activity;
        }
        if ($first !== null) {
            $this->kit->remember($connection, 'call', $key, $first->id);
        }

        return 'created';
    }

    private static function length(int $seconds): string
    {
        return $seconds < 60 ? $seconds.' sec' : intdiv($seconds, 60).' min '.($seconds % 60).' sec';
    }
}
