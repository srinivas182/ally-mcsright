<?php

declare(strict_types=1);

namespace Modules\Crm\Booking;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Task;
use Modules\Crm\Webhooks\CrmEvents;

/**
 * Website booking (specs/crm/booking): each agent's weekly hours, open slots (their hours, minus their open calls,
 * showings and appointments in the CRM, minus a minimum notice), and booking a slot as a CRM task. Times are in the
 * brokerage's time zone. Calendar sync with Google or Outlook can be added later behind the same busy times.
 */
final class Availability
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const DEFAULT_HOURS = ['mon' => [['09:00', '18:00']], 'tue' => [['09:00', '18:00']], 'wed' => [['09:00', '18:00']], 'thu' => [['09:00', '18:00']],
        'fri' => [['09:00', '18:00']], 'sat' => [['10:00', '16:00']], 'sun' => []];

    public const KINDS = ['call' => ['type' => 'call', 'title' => 'Call booked from the website', 'minutes' => null],
        'showing' => ['type' => 'showing', 'title' => 'Showing booked from the website', 'minutes' => 60],
        'listing' => ['type' => 'other', 'title' => 'Listing appointment booked from the website', 'minutes' => 60]];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{enabled: bool, hours: array<string, list<array{0: string, 1: string}>>, slot_minutes: int, notice_hours: int}
     */
    public function for(int $userId): array
    {
        $row = DB::connection(Connections::cell())->table('agent_availability')->where('tenant_id', $this->tenants->id())->where('user_id', $userId)->first();
        $hours = $row !== null ? (array) json_decode((string) $row->hours, true) : self::DEFAULT_HOURS;

        // Off until the agent turns it on: nobody is booked on hours they never chose.
        return ['enabled' => (bool) ($row->enabled ?? false), 'hours' => self::cleanHours($hours), 'slot_minutes' => (int) ($row->slot_minutes ?? 30),
            'notice_hours' => (int) ($row->notice_hours ?? 2)];
    }

    /** @param array<string, mixed> $hours */
    public function save(User $agent, bool $enabled, array $hours, int $slotMinutes, int $noticeHours, User $by): void
    {
        if (! in_array($slotMinutes, [15, 30, 45, 60], true) || $noticeHours < 0 || $noticeHours > 72) {
            throw ValidationException::withMessages(['slot_minutes' => 'Choose 15, 30, 45 or 60 minutes, and 0 to 72 hours\' notice.']);
        }
        DB::connection(Connections::cell())->table('agent_availability')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'user_id' => $agent->id],
            ['enabled' => $enabled, 'hours' => json_encode(self::cleanHours($hours)), 'slot_minutes' => $slotMinutes, 'notice_hours' => $noticeHours, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('crm.booking.availability', metadata: ['agent' => $agent->id, 'enabled' => $enabled], severity: 'info');
    }

    /**
     * Open start times for an agent over the next days, soonest first.
     *
     * @return list<CarbonImmutable>
     */
    public function slots(User $agent, string $kind = 'call', int $days = 6, int $max = 8): array
    {
        $a = $this->for($agent->id);
        if (! $a['enabled'] || ! $agent->isActive()) {
            return [];
        }
        $tz = $this->timezone();
        $length = self::KINDS[$kind]['minutes'] ?? $a['slot_minutes'];
        $earliest = CarbonImmutable::now($tz)->addHours($a['notice_hours']);
        // Busy: open calls (one slot long) and showings or appointments (an hour) already on the agent's tasks.
        $busy = Task::query()->where('assigned_to', $agent->id)->whereNull('completed_at')->whereIn('type', ['call', 'showing', 'other'])
            ->whereBetween('due_at', [CarbonImmutable::now()->subHours(2), CarbonImmutable::now()->addDays($days + 1)])->get(['due_at', 'type'])
            ->map(fn (Task $task): array => [CarbonImmutable::parse($task->due_at)->setTimezone($tz), $task->type === 'call' ? $a['slot_minutes'] : 60]);
        $out = [];
        for ($d = 0; $d <= $days && count($out) < $max; $d++) {
            $day = CarbonImmutable::now($tz)->startOfDay()->addDays($d);
            foreach ($a['hours'][self::DAYS[$day->dayOfWeekIso - 1]] ?? [] as [$open, $close]) {
                $t = $day->setTimeFromTimeString($open);
                $end = $day->setTimeFromTimeString($close);
                for (; $t->addMinutes($length)->lte($end) && count($out) < $max; $t = $t->addMinutes($a['slot_minutes'])) {
                    if ($t->lt($earliest)) {
                        continue;
                    }
                    $clash = $busy->contains(fn (array $b): bool => $b[0]->lt($t->addMinutes($length)) && $b[0]->addMinutes($b[1])->gt($t));
                    if (! $clash) {
                        $out[] = $t;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The agents (and owners who sell) who take bookings from the brokerage's website: the chosen team, or everyone
     * who turned on website booking.
     *
     * @param  list<int>  $team
     * @return list<User>
     */
    public function team(array $team): array
    {
        $q = User::query()->whereIn('kind', [UserKind::Agent, UserKind::Owner]);
        $users = $team !== [] ? $q->whereIn('id', $team)->get() : $q->get();

        return array_values($users->filter(fn (User $u): bool => $u->isActive() && $this->for($u->id)['enabled'])->all());
    }

    /**
     * Books a slot as a CRM task for the agent (refused if it was just taken).
     */
    public function book(User $agent, ?Contact $contact, CarbonImmutable $start, string $kind, string $notes): Task
    {
        if (! isset(self::KINDS[$kind])) {
            throw ValidationException::withMessages(['slot' => 'Choose a time.']);
        }
        $open = collect($this->slots($agent, $kind, 14, 200))->contains(fn (CarbonImmutable $s): bool => $s->equalTo($start));
        if (! $open) {
            throw ValidationException::withMessages(['slot' => 'That time was just taken. Please choose another.']);
        }

        $task = Task::query()->create(['contact_id' => $contact?->id, 'assigned_to' => $agent->id, 'type' => self::KINDS[$kind]['type'], 'title' => self::KINDS[$kind]['title'],
            'notes' => mb_substr($notes, 0, 2000), 'priority' => 'high', 'due_at' => $start->utc(), 'remind_at' => $start->utc()->subMinutes(30)]);
        CrmEvents::booking($task, $kind);

        return $task;
    }

    public function timezone(): string
    {
        return (string) ($this->tenants->current()->timezone ?: 'America/Chicago');
    }

    /**
     * @param  array<string, mixed>  $hours
     * @return array<string, list<array{0: string, 1: string}>>
     */
    private static function cleanHours(array $hours): array
    {
        $out = [];
        foreach (self::DAYS as $d) {
            $out[$d] = [];
            foreach (array_slice(is_array($hours[$d] ?? null) ? $hours[$d] : [], 0, 3) as $range) {
                [$from, $to] = is_array($range) ? array_values($range) + [null, null] : [null, null];
                if (is_string($from) && is_string($to) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) === 1 && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $to) === 1 && $from < $to) {
                    $out[$d][] = [$from, $to];
                }
            }
        }

        return $out;
    }
}
