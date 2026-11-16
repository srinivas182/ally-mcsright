<?php

declare(strict_types=1);

namespace Modules\Sites\Ailly;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Leads\LeadAlert;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Crm\Models\Lead;
use Modules\Sites\Models\Site;
use Modules\Sites\SiteAccess;

/**
 * An agent taking over a live AIlly chat (specs/websites/14). While an agent has it, AIlly stays quiet: the
 * visitor's messages go to the agent (an alert at most once every two minutes) and the agent's replies appear in the
 * widget, which checks for new messages every few seconds while it is open. Handing back lets AIlly answer again.
 *
 * Who may see and take a chat: owners and staff; the agent whose website it is on; the lead's agent.
 */
final class LiveChat
{
    /** A chat counts as live while the visitor wrote in the last ten minutes. */
    public const LIVE_MINUTES = 10;

    public function __construct(private readonly TenantContext $tenants) {}

    public function canSee(User $user, \stdClass $c): bool
    {
        if (SiteAccess::manager($user)) {
            return true;
        }
        if ($user->kind !== UserKind::Agent) {
            return false;
        }
        $site = Site::query()->whereKey((int) $c->site_id)->first();
        if ($site !== null && $site->kind === 'agent' && $site->user_id === $user->id) {
            return true;
        }
        $lead = $c->lead_id !== null ? Lead::query()->whereKey((int) $c->lead_id)->first() : null;

        return $lead !== null && $lead->assigned_user_id === $user->id;
    }

    public function find(string $ulid, User $user): \stdClass
    {
        $c = $this->db()->where('ulid', $ulid)->first();
        abort_unless($c instanceof \stdClass && $this->canSee($user, $c), 404);

        return $c;
    }

    /**
     * The chat for the agent's screen: messages, who has it, whether the visitor is still there.
     *
     * @return array{ulid: string, page: string, outcome: string, score: int|null, live: bool, taken_by: array{id: int, name: string}|null, mine: bool, can_take: bool, messages: list<array{id: int, role: string, body: string, at: string, by: string|null}>}
     */
    public function view(\stdClass $c, User $user): array
    {
        $taker = $c->taken_by !== null ? User::query()->whereKey((int) $c->taken_by)->first() : null;

        return ['ulid' => (string) $c->ulid, 'page' => (string) ($c->page ?? ''), 'outcome' => (string) $c->outcome, 'score' => $c->lead_score !== null ? (int) $c->lead_score : null,
            'live' => $this->isLive($c), 'taken_by' => $taker !== null ? ['id' => $taker->id, 'name' => $taker->name] : null, 'mine' => $taker?->id === $user->id,
            'can_take' => $c->status === 'open' && ($taker === null || SiteAccess::manager($user)), 'messages' => $this->messages((int) $c->id, 0)];
    }

    /**
     * Messages after an id, oldest first (the widget and the agent's screen poll with this).
     *
     * @return list<array{id: int, role: string, body: string, at: string, by: string|null}>
     */
    public function messages(int $conversationId, int $after): array
    {
        $rows = DB::connection(Connections::cell())->table('ailly_messages')->where('conversation_id', $conversationId)->where('id', '>', $after)->orderBy('id')->limit(200)
            ->get(['id', 'role', 'body', 'user_id', 'created_at']);
        $names = User::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique()->all())->get()->mapWithKeys(fn (User $u): array => [$u->id => $u->first_name]);

        return array_values(array_map(fn (\stdClass $m): array => ['id' => (int) $m->id, 'role' => (string) $m->role, 'body' => (string) $m->body, 'at' => (string) $m->created_at,
            'by' => $m->user_id !== null ? (string) ($names[(int) $m->user_id] ?? 'Agent') : null], $rows->all()));
    }

    public function isLive(\stdClass $c): bool
    {
        $last = DB::connection(Connections::cell())->table('ailly_messages')->where('conversation_id', $c->id)->where('role', 'visitor')->max('created_at');

        return $c->status === 'open' && $last !== null && CarbonImmutable::parse((string) $last)->gt(CarbonImmutable::now()->subMinutes(self::LIVE_MINUTES));
    }

    public function takeOver(\stdClass $c, User $agent): void
    {
        if ($c->status !== 'open') {
            throw ValidationException::withMessages(['chat' => 'This chat has ended.']);
        }
        if ($c->taken_by !== null && (int) $c->taken_by !== $agent->id && ! SiteAccess::manager($agent)) {
            throw ValidationException::withMessages(['chat' => 'Another agent is already in this chat.']);
        }
        $this->db()->where('id', $c->id)->update(['taken_by' => $agent->id, 'taken_at' => now(), 'updated_at' => now()]);
        $this->note((int) $c->id, $agent->first_name.' from the team joined the chat.');
        app(AuditLogger::class)->record('sites.ailly.taken_over', metadata: ['conversation' => $c->ulid, 'agent' => $agent->id], severity: 'info');
    }

    public function say(\stdClass $c, User $agent, string $body): void
    {
        if ((int) ($c->taken_by ?? 0) !== $agent->id) {
            throw ValidationException::withMessages(['body' => 'Take over the chat first.']);
        }
        $body = mb_substr(trim(strip_tags($body)), 0, 1000);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write a message.']);
        }
        DB::connection(Connections::cell())->table('ailly_messages')->insert(['tenant_id' => $this->tenants->id(), 'conversation_id' => $c->id, 'role' => 'agent',
            'user_id' => $agent->id, 'body' => $body, 'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u')]);
        $this->db()->where('id', $c->id)->update(['updated_at' => now()]);
    }

    public function handBack(\stdClass $c, User $agent): void
    {
        if ($c->taken_by === null) {
            return;
        }
        $this->db()->where('id', $c->id)->update(['taken_by' => null, 'taken_at' => null, 'updated_at' => now()]);
        $this->note((int) $c->id, AillySettings::persona(Site::query()->whereKey((int) $c->site_id)->first(), Assistant::brokerageSite())['name'].' is back to help. An agent can join again any time.');
        app(AuditLogger::class)->record('sites.ailly.handed_back', metadata: ['conversation' => $c->ulid, 'agent' => $agent->id], severity: 'info');
    }

    /**
     * The visitor wrote while an agent has the chat: tell that agent (at most every two minutes).
     */
    public function visitorWrote(\stdClass $c, string $text): void
    {
        $agent = User::query()->whereKey((int) $c->taken_by)->first();
        if ($agent === null || ($c->agent_alerted_at !== null && CarbonImmutable::parse((string) $c->agent_alerted_at)->gt(CarbonImmutable::now()->subMinutes(2)))) {
            return;
        }
        $this->db()->where('id', $c->id)->update(['agent_alerted_at' => now()]);
        $agent->notify(new LeadAlert('New message in your website chat', '"'.mb_substr($text, 0, 300).'"', self::link((string) $c->ulid), 'Reply'));
    }

    /** The agent's screen for a chat, as a link for alerts. */
    public static function link(string $ulid): string
    {
        return app(SpeedToLead::class)->link('/ailly/chats/'.$ulid);
    }

    /**
     * Live chats this user can see (visitor wrote in the last ten minutes), newest first.
     *
     * @return list<array{ulid: string, page: string, outcome: string, score: int|null, taken_by: string|null, last: string}>
     */
    public function live(User $user): array
    {
        $since = CarbonImmutable::now()->subMinutes(self::LIVE_MINUTES);
        $ids = DB::connection(Connections::cell())->table('ailly_messages')->where('tenant_id', $this->tenants->id())->where('role', 'visitor')->where('created_at', '>=', $since)
            ->distinct()->pluck('conversation_id');
        $rows = $this->db()->whereIn('id', $ids)->where('status', 'open')->orderByDesc('updated_at')->limit(50)->get();
        $names = User::query()->whereIn('id', $rows->pluck('taken_by')->filter()->unique()->all())->get()->mapWithKeys(fn (User $u): array => [$u->id => $u->first_name]);

        return array_map(fn (\stdClass $c): array => ['ulid' => (string) $c->ulid, 'page' => (string) ($c->page ?? ''), 'outcome' => (string) $c->outcome,
            'score' => $c->lead_score !== null ? (int) $c->lead_score : null, 'taken_by' => $c->taken_by !== null ? (string) ($names[(int) $c->taken_by] ?? 'An agent') : null,
            'last' => (string) $c->updated_at], array_values(array_filter($rows->all(), fn (\stdClass $c): bool => $this->canSee($user, $c))));
    }

    private function note(int $conversationId, string $text): void
    {
        DB::connection(Connections::cell())->table('ailly_messages')->insert(['tenant_id' => $this->tenants->id(), 'conversation_id' => $conversationId, 'role' => 'note',
            'body' => $text, 'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u')]);
    }

    private function db(): Builder
    {
        return DB::connection(Connections::cell())->table('ailly_conversations')->where('tenant_id', $this->tenants->id());
    }
}
