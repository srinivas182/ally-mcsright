<?php

declare(strict_types=1);

namespace Modules\Movewise\Bridge;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Support\Connections as ControlDb;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Movewise\Matching\Matcher;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Connection;
use Modules\Movewise\Models\Person;
use Modules\Movewise\Notifications\IntroductionClosed;

/**
 * The Movewise bridge (specs/movewise/00 §10, 05). Each accepted introduction texts through a relay number from HQ's
 * pool, so the person sees one Movewise number and one thread. While bridged the contact gets personal messages only
 * (no campaigns, plans or automated texts). The person can pause (2 weeks), stop or change agent from My Movewise or
 * by text; consent never moves to another agent. After an agreement or a closing the agent can ask to move to their
 * own number; the person must reply YES, then the relay says goodbye and Movewise steps back.
 */
final class Relay
{
    public const PAUSE_DAYS = 14;

    public function __construct(private readonly TenantContext $tenants) {}

    /** Gives an accepted introduction its relay number: one the person isn't already using with another agent. */
    public function assign(Connection $c, Person $p): ?string
    {
        $busy = Connection::query()->where('person_id', $p->id)->where('id', '!=', $c->id)->where('status', 'accepted')->whereNull('handed_over_at')
            ->whereNotNull('relay_number')->pluck('relay_number')->all();
        $use = DB::connection(ControlDb::control())->table('movewise_connections')->where('status', 'accepted')->whereNull('handed_over_at')->whereNotNull('relay_number')
            ->groupBy('relay_number')->pluck(DB::raw('count(*)'), 'relay_number')->all();
        $numbers = DB::connection(ControlDb::control())->table('movewise_relay_numbers')->where('active', true)->whereNotIn('number', $busy)->pluck('number')->all();
        if ($numbers === []) {
            report(new \RuntimeException('Movewise: no free relay number; the agent will text from their brokerage number.'));

            return null;
        }
        usort($numbers, fn ($a, $b): int => ($use[$a] ?? 0) <=> ($use[$b] ?? 0));
        $number = (string) $numbers[0];
        $c->forceFill(['relay_number' => $number])->save();
        if ($p->phone !== null) {
            app(MessagingSettings::class)->remember($p->phone, $number, $c->tenant_id); // their replies find the agent's inbox
        }

        return $number;
    }

    public function pause(Connection $c, string $how): Connection
    {
        $this->open($c);
        $c->forceFill(['paused_until' => CarbonImmutable::now()->addDays(self::PAUSE_DAYS)])->save();
        $this->tellAgent($c, 'A Movewise client paused contact', 'They asked for no contact for '.self::PAUSE_DAYS.' days, until '.$c->paused_until?->toFormattedDateString()
            .'. Messages to them are held until then.');
        $this->audit($c, 'paused', $how);

        return $c;
    }

    public function resume(Connection $c): Connection
    {
        $this->open($c);
        $c->forceFill(['paused_until' => null])->save();
        $this->audit($c, 'resumed', 'person');

        return $c;
    }

    /** "I'm done; no more contact." Consent ends; the agent is told without reasons the person didn't give. */
    public function stop(Connection $c, string $how): Connection
    {
        if ($c->status !== 'accepted') {
            return $c;
        }
        $c->forceFill(['status' => 'stopped', 'closed_at' => CarbonImmutable::now(), 'paused_until' => null, 'end_reason' => mb_substr($how, 0, 200)])->save();
        $this->tellAgent($c, 'A Movewise client stopped contact', 'They asked for no more contact. Please don\'t contact them again about this.');
        $this->audit($c, 'stopped', $how);

        return $c;
    }

    /**
     * Change agent: this introduction ends (no reasons given to the agent), and the person sees three agents again,
     * without this one. Returns the new set's ulid.
     */
    public function change(Connection $c): string
    {
        $this->open($c);
        $context = (array) json_decode((string) DB::connection(ControlDb::control())->table('movewise_shows')->where('id', $c->show_id)->value('context'), true);
        $c->forceFill(['status' => 'changed', 'closed_at' => CarbonImmutable::now(), 'paused_until' => null, 'end_reason' => 'Changed agent'])->save();
        $this->tellAgent($c, 'A Movewise client chose another agent', 'They chose to work with someone else. Please don\'t contact them again about this.');
        $this->audit($c, 'changed', 'person');
        $tried = Connection::query()->where('person_id', $c->person_id)->where('lead_type', $c->lead_type)->whereIn('status', ['changed', 'stopped', 'expired', 'declined'])
            ->pluck('agent_id')->map(fn ($i): int => (int) $i)->all();

        /** @var array{state: string, zip: string|null, city: string|null, price: int|null, lead_type: string, language: string, listing?: string|null} $context */
        return app(Matcher::class)->three(Person::query()->findOrFail($c->person_id), $context, array_values(array_unique($tried)))['ulid'];
    }

    /** The agent asks to move the person to their own number, after an agreement or a closing. The person must say YES. */
    public function askHandover(Connection $c, User $agent, bool $signedOrClosed): Connection
    {
        $this->open($c);
        if (! $signedOrClosed) {
            throw ValidationException::withMessages(['handover' => 'Moving to your own number is for after a signed agreement or a closing.']);
        }
        if ($c->relay_number === null) {
            throw ValidationException::withMessages(['handover' => 'This introduction already uses your own number.']);
        }
        $person = Person::query()->findOrFail($c->person_id);
        $name = (string) (Agent::query()->findOrFail($c->agent_id)->profile['display_name'] ?? 'Your agent');
        app(SmsSender::class)->deliver($c->relay_number, (string) $person->phone, 'Movewise: '.$name.' would like to text you from their own number from now on. Reply YES to agree. If you don\'t, nothing changes.');
        $c->forceFill(['handover_asked_at' => CarbonImmutable::now()])->save();
        $this->audit($c, 'handover_asked', 'agent '.$agent->id);

        return $c;
    }

    /** The person said YES: the relay says goodbye and Movewise steps back. */
    public function handOver(Connection $c): string
    {
        $c->forceFill(['handed_over_at' => CarbonImmutable::now()])->save();
        $this->tellAgent($c, 'You can text your Movewise client directly', 'They agreed. Texts now go from your own number, and Movewise has stepped back.');
        $this->audit($c, 'handed_over', 'person');
        $name = (string) (Agent::query()->find($c->agent_id)->profile['display_name'] ?? 'Your agent');

        return 'Thanks. '.$name.' will text you from their own number from now on. Movewise is stepping back; this number won\'t be used again.';
    }

    private function open(Connection $c): void
    {
        if ($c->status !== 'accepted' || $c->handed_over_at !== null) {
            throw ValidationException::withMessages(['introduction' => 'This introduction has already ended.']);
        }
    }

    private function tellAgent(Connection $c, string $subject, string $body): void
    {
        $agent = Agent::query()->find($c->agent_id);
        $tenant = Tenant::query()->find($c->tenant_id);
        if ($agent === null || $tenant === null) {
            return;
        }
        $host = $tenant->primaryDomain()?->host;
        $link = ($host !== null ? 'https://'.$host : rtrim((string) config('app.url'), '/')).'/movewise/introductions/'.$c->ulid;
        $run = function () use ($agent, $subject, $body, $link): void {
            User::query()->find($agent->user_id)?->notify(new IntroductionClosed($subject, $body, $link));
        };
        $this->tenants->check() && $this->tenants->id() === $tenant->id ? $run() : $this->tenants->runAs($tenant, $run);
    }

    private function audit(Connection $c, string $what, string $how): void
    {
        app(AuditLogger::class)->record('movewise.connection.'.$what, $c, metadata: ['how' => $how], tenantId: $c->tenant_id);
    }
}
