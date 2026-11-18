<?php

declare(strict_types=1);

namespace Modules\Core\Approvals;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Access\Permissions;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Approval rules for sensitive changes (FL1, specs/core/03 and 16). Modules register the actions that can need
 * approval; the brokerage owner sets how many people must say yes (0 to 3). When an action needs approval, asking
 * creates a request; approvers are the owners and people with "Approve sensitive changes". The person who asked
 * cannot approve their own request. When enough people approve, the action runs; one "no" declines it. Requests
 * expire after 7 days.
 *
 * (Automations have their own approval step, specs/core/06; this is for settings changes.)
 */
final class Approvals
{
    public const DAYS = 7;

    public const MAX = 3;

    public function __construct(private readonly TenantContext $tenants, private readonly ApprovalActions $registry) {}

    /**
     * @param  Closure(array<string, mixed>, User): string  $run  does the change once approved; returns a plain-words result
     */
    public function register(string $action, string $label, Closure $run, int $default = 0): void
    {
        $this->registry->actions[$action] = ['label' => $label, 'default' => max(0, min(self::MAX, $default)), 'run' => $run];
    }

    /** How many approvals the action needs in this brokerage (0 = none). */
    public function needed(string $action): int
    {
        $def = $this->registry->actions[$action] ?? null;
        if ($def === null || ! $this->tenants->check()) {
            return 0;
        }
        $rule = DB::connection(Connections::cell())->table('approval_rules')->where('tenant_id', $this->tenants->id())->where('action', $action)->value('needed');

        return $rule !== null ? (int) $rule : $def['default'];
    }

    /**
     * Do the action now when no approval is needed, otherwise ask for approval.
     *
     * @param  array<string, mixed>  $payload
     * @return array{done: bool, message: string}
     */
    public function doOrAsk(string $action, string $about, array $payload, User $by): array
    {
        $def = $this->registry->actions[$action] ?? throw ValidationException::withMessages(['action' => 'Unknown action.']);
        $needed = $this->needed($action);
        if ($needed === 0) {
            return ['done' => true, 'message' => ($def['run'])($payload, $by)];
        }
        $this->ask($action, $about, $payload, $needed, $by);

        return ['done' => false, 'message' => 'Sent for approval. It happens once '.$needed.' '.($needed === 1 ? 'person approves' : 'people approve').'.'];
    }

    public function setRule(string $action, int $needed, User $by): void
    {
        if (! isset($this->registry->actions[$action])) {
            throw ValidationException::withMessages(['action' => 'Unknown action.']);
        }
        if ($needed < 0 || $needed > self::MAX) {
            throw ValidationException::withMessages(['needed' => 'Choose from 0 (no approval) to '.self::MAX.' people.']);
        }
        DB::connection(Connections::cell())->table('approval_rules')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'action' => $action],
            ['needed' => $needed, 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('core.approvals.rule_set', metadata: ['action' => $action, 'needed' => $needed], severity: 'warning');
    }

    /** Approve or decline. Returns what happened, in plain words. */
    public function vote(string $ulid, User $by, bool $approve, ?string $note = null): string
    {
        $db = DB::connection(Connections::cell());
        $req = $db->table('approval_requests')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first()
            ?? throw ValidationException::withMessages(['request' => 'That request no longer exists.']);
        if ($req->status !== 'waiting' || CarbonImmutable::parse((string) $req->expires_at)->isPast()) {
            throw ValidationException::withMessages(['request' => 'This request is already closed.']);
        }
        if ((int) $req->requested_by === $by->id) {
            throw ValidationException::withMessages(['request' => 'Someone else must approve a change you asked for.']);
        }
        if (! $this->isApprover($by)) {
            throw ValidationException::withMessages(['request' => 'You cannot approve this. Ask your broker.']);
        }
        $db->table('approval_votes')->updateOrInsert(['request_id' => $req->id, 'user_id' => $by->id],
            ['tenant_id' => $this->tenants->id(), 'decision' => $approve ? 'approve' : 'decline', 'note' => $note !== null ? mb_substr(trim($note), 0, 300) : null,
                'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('core.approvals.vote', metadata: ['request' => $ulid, 'action' => $req->action, 'approve' => $approve], severity: 'warning');
        if (! $approve) {
            $this->close((int) $req->id, 'declined', 'Declined by '.$by->name.'.');

            return 'Declined. Nothing was changed.';
        }
        $yes = $db->table('approval_votes')->where('request_id', $req->id)->where('decision', 'approve')->count();
        if ($yes < (int) $req->needed) {
            return 'Approved. Waiting for '.((int) $req->needed - $yes).' more.';
        }
        $requester = User::query()->find($req->requested_by);
        $def = $this->registry->actions[(string) $req->action] ?? null;
        try {
            if ($def === null || ! $requester instanceof User) {
                throw new \RuntimeException('cannot run');
            }
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $req->payload, true) ?: [];
            $result = ($def['run'])($payload, $requester);
            $this->close((int) $req->id, 'approved', $result);

            return 'Approved and done: '.$result;
        } catch (ValidationException $e) {
            $why = (string) collect($e->errors())->flatten()->first();
            $this->close((int) $req->id, 'failed', $why);

            return 'Approved, but it could not be done: '.$why;
        } catch (Throwable) {
            $this->close((int) $req->id, 'failed', 'Something went wrong. Ask again.');

            return 'Approved, but it could not be done. Ask again.';
        }
    }

    /** Closes requests older than 7 days. */
    public function expire(): int
    {
        return DB::connection(Connections::cell())->table('approval_requests')->where('tenant_id', $this->tenants->id())->where('status', 'waiting')->where('expires_at', '<', now())
            ->update(['status' => 'expired', 'result' => 'No answer within '.self::DAYS.' days.', 'updated_at' => now()]);
    }

    public function isApprover(User $user): bool
    {
        return $user->kind === UserKind::Owner || ($user->tenant_id === $this->tenants->idOrNull() && app(Permissions::class)->allows($user, 'core.approvals.give'));
    }

    /**
     * For the settings screen: rules, waiting requests and the last 30 closed ones.
     *
     * @return array{rules: list<array{action: string, label: string, needed: int}>, waiting: list<array<string, mixed>>, closed: list<array<string, mixed>>}
     */
    public function summary(User $viewer): array
    {
        $db = DB::connection(Connections::cell());
        $tenant = $this->tenants->id();
        $rules = [];
        foreach ($this->registry->actions as $key => $def) {
            $rules[] = ['action' => $key, 'label' => $def['label'], 'needed' => $this->needed($key)];
        }
        $rows = $db->table('approval_requests')->where('tenant_id', $tenant)->orderByDesc('id')->limit(60)->get();
        $votes = $db->table('approval_votes')->whereIn('request_id', $rows->pluck('id'))->get()->groupBy('request_id');
        $names = User::query()->whereIn('id', $rows->pluck('requested_by')->merge($votes->flatten()->pluck('user_id'))->unique())->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn (User $u): array => [$u->id => $u->name]);
        $shape = fn (object $r): array => ['ulid' => $r->ulid, 'about' => $r->about, 'action' => $this->registry->actions[(string) $r->action]['label'] ?? $r->action,
            'needed' => (int) $r->needed, 'status' => $r->status, 'result' => $r->result, 'asked_by' => $names[$r->requested_by] ?? 'Someone',
            'asked_at' => (string) $r->created_at, 'mine' => (int) $r->requested_by === $viewer->id,
            'votes' => ($votes->get($r->id) ?? collect())->map(fn (object $v): array => ['name' => $names[$v->user_id] ?? 'Someone', 'decision' => $v->decision])->values()->all()];

        return ['rules' => $rules,
            'waiting' => $rows->where('status', 'waiting')->filter(fn (object $r): bool => CarbonImmutable::parse((string) $r->expires_at)->isFuture())->map($shape)->values()->all(),
            'closed' => $rows->reject(fn (object $r): bool => $r->status === 'waiting' && CarbonImmutable::parse((string) $r->expires_at)->isFuture())->take(30)->map($shape)->values()->all()];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ask(string $action, string $about, array $payload, int $needed, User $by): void
    {
        $db = DB::connection(Connections::cell())->table('approval_requests');
        $same = $db->clone()->where('tenant_id', $this->tenants->id())->where('action', $action)->where('status', 'waiting')->where('expires_at', '>', now())
            ->where('payload', json_encode($payload))->exists();
        if ($same) {
            throw ValidationException::withMessages(['request' => 'This is already waiting for approval.']);
        }
        $ulid = (string) Str::ulid();
        $db->clone()->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'action' => $action, 'about' => mb_substr($about, 0, 200), 'payload' => json_encode($payload),
            'needed' => $needed, 'requested_by' => $by->id, 'status' => 'waiting', 'expires_at' => now()->addDays(self::DAYS), 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('core.approvals.asked', metadata: ['request' => $ulid, 'action' => $action, 'needed' => $needed], severity: 'warning');
        $approvers = User::query()->where('status', UserStatus::Active)->whereIn('kind', [UserKind::Owner, UserKind::Staff])->where('id', '!=', $by->id)->get()->filter(fn (User $u): bool => $this->isApprover($u));
        Notification::send($approvers, new ApprovalNeededMail($about, $by->name));
    }

    private function close(int $id, string $status, string $result): void
    {
        DB::connection(Connections::cell())->table('approval_requests')->where('id', $id)->update(['status' => $status, 'result' => mb_substr($result, 0, 300), 'updated_at' => now()]);
    }
}
