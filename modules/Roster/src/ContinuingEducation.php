<?php

declare(strict_types=1);

namespace Modules\Roster;

use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Continuing education (specs/roster/01, addition 5). Requirements differ by state and change, so each
 * brokerage sets its states' rules (hours per renewal cycle, cycle length, required topics); RightAlly tracks
 * each agent's courses in the cycle ending at their licence renewal date and reminds them before it.
 */
final class ContinuingEducation
{
    public const STEPS = [90, 60, 30];

    public function __construct(private readonly TenantContext $tenants, private readonly Roster $roster) {}

    /**
     * @param  list<array{topic: string, hours: string}>  $topics
     */
    public function saveRule(string $state, string $hours, int $cycleMonths, array $topics, User $by): void
    {
        if (! Roster::manages($by)) {
            throw ValidationException::withMessages(['ce' => 'Setting CE rules needs the roster permission.']);
        }
        $state = strtoupper(trim($state));
        if (preg_match('/^[A-Z]{2}$/', $state) !== 1 || ! is_numeric($hours) || BigDecimal::of($hours)->isNegativeOrZero() || $cycleMonths < 6 || $cycleMonths > 60) {
            throw ValidationException::withMessages(['ce' => 'Give the state, the hours required and the renewal cycle in months (6–60).']);
        }
        $clean = [];
        foreach ($topics as $t) {
            if (trim($t['topic']) !== '' && is_numeric($t['hours']) && BigDecimal::of($t['hours'])->isPositive()) {
                $clean[] = ['topic' => mb_substr(trim($t['topic']), 0, 60), 'hours' => (string) BigDecimal::of($t['hours'])->toScale(1)];
            }
        }
        DB::connection(Connections::cell())->table('roster_ce_rules')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'state_code' => $state],
            ['hours_required' => (string) BigDecimal::of($hours)->toScale(1), 'cycle_months' => $cycleMonths, 'topics' => json_encode($clean), 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('roster.ce.rule', metadata: ['state' => $state, 'hours' => $hours, 'cycle_months' => $cycleMonths, 'topics' => $clean], severity: 'notice');
    }

    /** An agent logs their own course (or staff for them), with the certificate. */
    public function log(User $agent, string $state, string $title, ?string $provider, ?string $topic, string $hours, CarbonImmutable $completedOn, ?UploadedFile $certificate, User $by): string
    {
        if ($by->id !== $agent->id && ! Roster::manages($by)) {
            throw ValidationException::withMessages(['ce' => 'You can only log your own courses.']);
        }
        if (preg_match('/^[A-Z]{2}$/', strtoupper($state)) !== 1 || trim($title) === '' || ! is_numeric($hours) || BigDecimal::of($hours)->isNegativeOrZero()
            || BigDecimal::of($hours)->isGreaterThan(100) || $completedOn->isFuture()) {
            throw ValidationException::withMessages(['ce' => 'Give the state, course title, hours (up to 100) and a completion date that is not in the future.']);
        }
        $doc = $certificate !== null ? $this->roster->uploadAs($agent, 'other', $certificate, $by) : null;
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('roster_ce_courses')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'user_id' => $agent->id,
            'state_code' => strtoupper($state), 'title' => mb_substr(trim($title), 0, 160), 'provider' => $provider !== null ? mb_substr(trim($provider), 0, 120) : null,
            'topic' => $topic !== null && trim($topic) !== '' ? mb_substr(trim($topic), 0, 60) : null, 'hours' => (string) BigDecimal::of($hours)->toScale(1),
            'completed_on' => $completedOn->toDateString(), 'document_id' => $doc !== null ? DB::connection(Connections::cell())->table('roster_documents')->where('ulid', $doc)->value('id') : null,
            'logged_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('roster.ce.logged', $agent, metadata: ['state' => $state, 'hours' => $hours, 'title' => $title], label: $agent->name);

        return $ulid;
    }

    public function remove(string $courseUlid, User $by): void
    {
        if (! Roster::manages($by)) {
            throw ValidationException::withMessages(['ce' => 'Only the brokerage can remove a logged course.']);
        }
        DB::connection(Connections::cell())->table('roster_ce_courses')->where('ulid', $courseUlid)->delete();
        app(AuditLogger::class)->record('roster.ce.removed', metadata: ['course' => $courseUlid], severity: 'notice');
    }

    /**
     * Progress per current licence that has a rule: hours and topics in the cycle ending at the renewal date.
     *
     * @return list<array{state: string, renewal: string, cycle_start: string, required: string, done: string, topics: list<array{topic: string, required: string, done: string}>, complete: bool, courses: list<array<string, mixed>>}>
     */
    public function progress(User $agent): array
    {
        $db = DB::connection(Connections::cell());
        $out = [];
        foreach ($db->table('roster_credentials')->where('user_id', $agent->id)->where('kind', 'licence')->where('status', 'current')->whereNotNull('expires_on')->get() as $licence) {
            $rule = $db->table('roster_ce_rules')->where('state_code', $licence->state_code)->first();
            if ($rule === null) {
                continue;
            }
            $renewal = CarbonImmutable::parse((string) $licence->expires_on);
            $start = $renewal->subMonthsNoOverflow((int) $rule->cycle_months);
            $courses = $db->table('roster_ce_courses')->where('user_id', $agent->id)->where('state_code', $licence->state_code)
                ->whereBetween('completed_on', [$start->toDateString(), $renewal->toDateString()])->orderBy('completed_on')->get();
            $done = $courses->reduce(fn (BigDecimal $sum, $c): BigDecimal => $sum->plus((string) $c->hours), BigDecimal::zero());
            $topics = [];
            $topicsMet = true;
            foreach ((array) json_decode((string) $rule->topics, true) as $t) {
                $hours = $courses->filter(fn ($c) => $c->topic !== null && mb_strtolower((string) $c->topic) === mb_strtolower((string) $t['topic']))
                    ->reduce(fn (BigDecimal $sum, $c): BigDecimal => $sum->plus((string) $c->hours), BigDecimal::zero());
                $topics[] = ['topic' => (string) $t['topic'], 'required' => (string) $t['hours'], 'done' => (string) $hours->toScale(1)];
                $topicsMet = $topicsMet && $hours->isGreaterThanOrEqualTo((string) $t['hours']);
            }
            $out[] = ['state' => (string) $licence->state_code, 'renewal' => $renewal->toDateString(), 'cycle_start' => $start->toDateString(),
                'required' => (string) BigDecimal::of((string) $rule->hours_required)->toScale(1), 'done' => (string) $done->toScale(1), 'topics' => $topics,
                'complete' => $done->isGreaterThanOrEqualTo((string) $rule->hours_required) && $topicsMet,
                'courses' => array_values($courses->map(fn ($c): array => ['ulid' => $c->ulid, 'title' => $c->title, 'provider' => $c->provider, 'topic' => $c->topic,
                    'hours' => (string) BigDecimal::of((string) $c->hours)->toScale(1), 'completed_on' => (string) $c->completed_on])->all())];
        }

        return $out;
    }

    /** Daily: agents whose CE is incomplete are reminded 90, 60 and 30 days before renewal (owners copied). */
    public function remind(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $db = DB::connection(Connections::cell());
        $sent = 0;
        foreach ($db->table('roster_credentials')->where('kind', 'licence')->where('status', 'current')->whereNotNull('expires_on')
            ->whereBetween('expires_on', [$today->toDateString(), $today->addDays(90)->toDateString()])->get() as $licence) {
            $agent = User::query()->find((int) $licence->user_id);
            if ($agent === null || $agent->status !== UserStatus::Active) {
                continue;
            }
            $p = collect($this->progress($agent))->firstWhere('state', $licence->state_code);
            if ($p === null || $p['complete']) {
                continue;
            }
            $days = (int) $today->diffInDays(CarbonImmutable::parse((string) $licence->expires_on), false);
            $step = null;
            foreach (self::STEPS as $s) {
                if ($days <= $s) {
                    $step = $s;
                }
            }
            if ($step === null || ($licence->ce_reminded_days !== null && (int) $licence->ce_reminded_days <= $step)) {
                continue;
            }
            $note = new CeIncomplete($agent->name, (string) $licence->state_code, $p['done'], $p['required'], CarbonImmutable::parse((string) $licence->expires_on));
            $agent->notify($note);
            Notification::send(User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->get(), $note);
            $db->table('roster_credentials')->where('id', $licence->id)->update(['ce_reminded_days' => $step]);
            $sent++;
        }

        return $sent;
    }
}
