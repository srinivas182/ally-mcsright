<?php

declare(strict_types=1);

namespace Modules\Outreach;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\SmartLists;

/**
 * The call queue, a power dialer done safely (MK1, specs/crm/20): the agent picks a list and works through it one
 * person at a time. Every call is placed by the agent's own phone (tap to call), never by an autodialer, so there are
 * no robocalls. Skipped automatically: anyone marked Do Not Call, anyone without a phone, anyone already called today,
 * and anyone for whom it is before 8am or after 9pm where they live (TCPA calling hours). One tap logs the outcome on
 * the timeline; "Don't call me again" marks them Do Not Call for everyone at the brokerage.
 */
final class CallQueue
{
    public const OUTCOMES = ['connected' => 'Talked', 'left_voicemail' => 'Left a voicemail', 'no_answer' => 'No answer', 'wrong_number' => 'Wrong number', 'do_not_call' => 'Asked not to be called'];

    /** Time zones by state for calling hours (the brokerage's own zone when a contact has no state). */
    private const ZONES = [
        'America/New_York' => ['CT', 'DE', 'DC', 'FL', 'GA', 'IN', 'KY', 'ME', 'MD', 'MA', 'MI', 'NH', 'NJ', 'NY', 'NC', 'OH', 'PA', 'RI', 'SC', 'VT', 'VA', 'WV'],
        'America/Chicago' => ['AL', 'AR', 'IL', 'IA', 'KS', 'LA', 'MN', 'MS', 'MO', 'NE', 'ND', 'OK', 'SD', 'TN', 'TX', 'WI'],
        'America/Denver' => ['CO', 'ID', 'MT', 'NM', 'UT', 'WY'], 'America/Phoenix' => ['AZ'],
        'America/Los_Angeles' => ['CA', 'NV', 'OR', 'WA'], 'America/Anchorage' => ['AK'], 'Pacific/Honolulu' => ['HI'],
    ];

    public function __construct(private readonly TenantContext $tenants, private readonly ContactVisibility $visibility, private readonly SmartLists $lists) {}

    /**
     * @return array{ready: list<array{ulid: string, name: string, phone: string, city: string|null, state: string|null, last_activity_at: string|null, local_time: string, notes: list<string>}>, skipped: array{do_not_call: int, no_phone: int, called_today: int, quiet_hours: int}, total: int}
     */
    public function build(User $user, string $listKey, int $limit = 50, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $filters = $this->lists->filtersFor($listKey, $user) ?? throw ValidationException::withMessages(['list' => 'Choose a list.']);
        $contacts = $this->lists->apply($this->visibility->query($user), $filters)->orderByRaw('last_activity_at is null desc')->orderBy('last_activity_at')->limit(500)->get();
        $calledToday = Activity::query()->whereIn('contact_id', $contacts->pluck('id')->all() ?: [0])->where('type', 'call')
            ->where('occurred_at', '>=', $now->setTimezone($this->brokerageZone())->startOfDay()->utc())->pluck('contact_id')->flip();
        $skipped = ['do_not_call' => 0, 'no_phone' => 0, 'called_today' => 0, 'quiet_hours' => 0];
        $ready = [];
        foreach ($contacts as $c) {
            $local = $now->setTimezone($this->zoneFor($c->state));
            $reason = match (true) {
                (bool) $c->do_not_call => 'do_not_call',
                ($c->primary_phone ?? '') === '' => 'no_phone',
                $calledToday->has($c->id) => 'called_today',
                $local->hour < 8 || $local->hour >= 21 => 'quiet_hours',
                default => null,
            };
            if ($reason !== null) {
                $skipped[$reason]++;

                continue;
            }
            if (count($ready) < $limit) {
                $notes = array_values(Activity::query()->where('contact_id', $c->id)->whereIn('type', ['note', 'call'])->orderByDesc('occurred_at')->limit(2)->pluck('body')
                    ->filter()->map(fn ($b): string => mb_substr((string) $b, 0, 160))->all());
                $ready[] = ['ulid' => $c->ulid, 'name' => trim($c->first_name.' '.$c->last_name) ?: 'No name', 'phone' => (string) $c->primary_phone, 'city' => $c->city,
                    'state' => $c->state, 'last_activity_at' => $c->last_activity_at?->toDateString(), 'local_time' => $local->format('g:i A'), 'notes' => $notes];
            }
        }

        return ['ready' => $ready, 'skipped' => $skipped, 'total' => $contacts->count()];
    }

    public function log(User $user, Contact $contact, string $outcome, ?string $note): void
    {
        if (! isset(self::OUTCOMES[$outcome])) {
            throw ValidationException::withMessages(['outcome' => 'How did the call go?']);
        }
        abort_unless($this->visibility->canSee($user, $contact), 403);
        $body = trim((string) $note);
        if ($outcome === 'do_not_call') {
            $contact->forceFill(['do_not_call' => true])->save();
            $body = trim('Asked not to be called again. '.$body);
            app(AuditLogger::class)->record('crm.contact_do_not_call', $contact, metadata: ['from' => 'call_queue'], severity: 'notice');
        }
        app(Timeline::class)->log($contact, ['type' => 'call', 'outcome' => $outcome === 'do_not_call' ? 'connected' : $outcome, 'direction' => 'out',
            'body' => $body !== '' ? mb_substr($body, 0, 2000) : null], $user);
    }

    private function zoneFor(?string $state): string
    {
        $state = strtoupper(trim((string) $state));
        foreach (self::ZONES as $zone => $states) {
            if (in_array($state, $states, true)) {
                return $zone;
            }
        }

        return $this->brokerageZone();
    }

    private function brokerageZone(): string
    {
        return (string) ($this->tenants->current()->timezone ?: 'America/Chicago');
    }
}
