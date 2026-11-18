<?php

declare(strict_types=1);

namespace Modules\Core\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Approvals\Approvals;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Actions\ManageInvitations;
use Modules\Core\Identity\Actions\ManageUserStatus;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Models\Invitation;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Agents add their own assistants (AG1, specs/grow/03). An assistant works the agent's own contacts and deals (never
 * anyone else's), with a preset for what they may open:
 * - Full: everything the agent uses, except the agent's money (pay, plan, revenue share);
 * - Leads: contacts, tasks, inbox and follow-up; no deals, marketing or money;
 * - Marketing: Ally Studio, social posts, ads, postcards and the website; no deals, inbox or money;
 * - Admin: deals and documents, tasks and contacts; no marketing or money.
 * The brokerage can require approval before an agent adds an assistant (Settings → Approvals); otherwise the
 * invitation goes out at once and the owner can see every assistant on the People page.
 */
final class AgentAssistants
{
    public const PRESETS = [
        'full' => 'Full: everything I use, except my money',
        'leads' => 'Leads: contacts, tasks, inbox and follow-up',
        'marketing' => 'Marketing: Studio, social posts, ads and website',
        'admin' => 'Admin: deals, documents, tasks and contacts',
    ];

    /** Pages an assistant may never open, by preset (a path and everything under it). */
    private const BLOCKED = [
        'full' => ['/my-money', '/my-plan', '/revenue-share', '/plans', '/settings/billing'],
        'leads' => ['/my-money', '/my-plan', '/revenue-share', '/plans', '/settings/billing', '/deals', '/studio', '/social', '/ads', '/postcards', '/settings/website'],
        'marketing' => ['/my-money', '/my-plan', '/revenue-share', '/plans', '/settings/billing', '/deals', '/inbox', '/outreach'],
        'admin' => ['/my-money', '/my-plan', '/revenue-share', '/plans', '/settings/billing', '/studio', '/social', '/ads', '/postcards', '/outreach', '/settings/website'],
    ];

    public const MAX = 3;

    /** @var array<int, string> */
    private array $memo = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /** Whether this person may open the page (everyone but assistants always may). */
    public function allowsPath(User $user, string $path): bool
    {
        if ($user->kind !== UserKind::Assistant) {
            return true;
        }
        $path = '/'.ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        foreach (self::BLOCKED[$this->presetOf($user)] as $p) {
            if ($path === $p || str_starts_with($path, $p.'/')) {
                return false;
            }
        }

        return true;
    }

    public function presetOf(User $user): string
    {
        if (isset($this->memo[$user->id])) {
            return $this->memo[$user->id];
        }
        $preset = DB::connection(Connections::cell())->table('assistant_access')->where('tenant_id', $user->tenant_id)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('email', mb_strtolower((string) $user->email)))->value('preset');

        return $this->memo[$user->id] = isset(self::BLOCKED[(string) $preset]) ? (string) $preset : 'full';
    }

    /**
     * The agent invites an assistant (or asks for approval first when the brokerage requires it).
     *
     * @return array{done: bool, message: string}
     */
    public function invite(User $agent, string $email, string $first, string $last, string $preset): array
    {
        $this->guardAgent($agent);
        if (! isset(self::PRESETS[$preset])) {
            throw ValidationException::withMessages(['preset' => 'Choose what they may do.']);
        }
        if (User::query()->where('assisted_user_id', $agent->id)->where('status', UserStatus::Active)->count() >= self::MAX) {
            throw ValidationException::withMessages(['email' => 'You can have up to '.self::MAX.' assistants. Remove one first.']);
        }
        $payload = ['agent' => $agent->id, 'email' => mb_strtolower(trim($email)), 'first' => trim($first), 'last' => trim($last), 'preset' => $preset];

        return app(Approvals::class)->doOrAsk('agents.add_assistant', $agent->name.' adds '.trim($first.' '.$last).' as their assistant', $payload, $agent);
    }

    /**
     * Runs the invitation (directly, or once approved).
     *
     * @param  array<string, mixed>  $p
     */
    public function send(array $p): string
    {
        $agent = User::query()->findOrFail((int) $p['agent']);
        DB::connection(Connections::cell())->table('assistant_access')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'email' => (string) $p['email']],
            ['agent_user_id' => $agent->id, 'preset' => (string) $p['preset'], 'updated_at' => now(), 'created_at' => now()]);
        app(ManageInvitations::class)->invite((string) $p['email'], (string) $p['first'], (string) $p['last'], UserKind::Assistant, $agent, $agent->id);
        app(AuditLogger::class)->record('core.assistant.invited', metadata: ['agent' => $agent->id, 'preset' => $p['preset']]);

        return 'Invitation sent to '.$p['email'].'.';
    }

    public function setPreset(User $agent, User $assistant, string $preset): void
    {
        $this->guardOwn($agent, $assistant);
        if (! isset(self::PRESETS[$preset])) {
            throw ValidationException::withMessages(['preset' => 'Choose what they may do.']);
        }
        DB::connection(Connections::cell())->table('assistant_access')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'email' => mb_strtolower((string) $assistant->email)],
            ['agent_user_id' => $agent->id, 'user_id' => $assistant->id, 'preset' => $preset, 'updated_at' => now(), 'created_at' => now()]);
        unset($this->memo[$assistant->id]);
        app(AuditLogger::class)->record('core.assistant.preset', $assistant, metadata: ['preset' => $preset], label: $assistant->name);
    }

    public function remove(User $agent, User $assistant): void
    {
        $this->guardOwn($agent, $assistant);
        app(ManageUserStatus::class)->deactivate($assistant, $agent);
        app(AuditLogger::class)->record('core.assistant.removed', $assistant, label: $assistant->name, severity: 'warning');
    }

    /** @return list<array{ulid: string, name: string, email: string, status: string, preset: string}> */
    public function of(User $agent): array
    {
        $presets = DB::connection(Connections::cell())->table('assistant_access')->where('tenant_id', $this->tenants->id())->where('agent_user_id', $agent->id)->pluck('preset', 'email');

        return array_values(User::query()->where('assisted_user_id', $agent->id)->whereIn('status', [UserStatus::Active, UserStatus::Invited])->orderBy('first_name')->get()
            ->map(fn (User $u): array => ['ulid' => (string) $u->ulid, 'name' => $u->name, 'email' => (string) $u->email, 'status' => $u->status->value,
                'preset' => (string) ($presets[mb_strtolower((string) $u->email)] ?? 'full')])->all());
    }

    /** @return list<array{email: string, name: string}> invitations not yet accepted */
    public function waiting(User $agent): array
    {
        return array_values(Invitation::query()->where('assisted_user_id', $agent->id)->whereNull('accepted_at')->whereNull('revoked_at')->get()
            ->map(fn ($i): array => ['email' => (string) $i->email, 'name' => trim($i->first_name.' '.$i->last_name)])->all());
    }

    private function guardAgent(User $agent): void
    {
        if ($agent->kind !== UserKind::Agent || ! $agent->isActive()) {
            throw ValidationException::withMessages(['email' => 'Only agents add their own assistants.']);
        }
    }

    private function guardOwn(User $agent, User $assistant): void
    {
        if ($assistant->kind !== UserKind::Assistant || $assistant->assisted_user_id !== $agent->id) {
            throw ValidationException::withMessages(['assistant' => 'That person is not your assistant.']);
        }
    }
}
