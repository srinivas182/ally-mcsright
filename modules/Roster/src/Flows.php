<?php

declare(strict_types=1);

namespace Modules\Roster;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Actions\ManageUserStatus;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\LedgerService;

/**
 * Onboarding and offboarding (specs/roster/01, additions 3 and 4): checklists with agent tasks that tick
 * themselves off when the thing is done, and broker tasks staff mark; progress for both. Completing an
 * offboarding deactivates the account (which records the revenue share leaving date).
 */
final class Flows
{
    /** code => [title, who, automatic] */
    public const ONBOARDING = [
        'agreement' => ['Sign the independent contractor agreement', 'agent', true],
        'policy' => ['Read and sign the policy manual', 'agent', true],
        'w9' => ['Fill in the W-9', 'agent', true],
        'bank' => ['Add the bank account for commission', 'agent', true],
        'licence' => ['Licence on file', 'agent', true],
        'eo' => ['E&O insurance on file', 'agent', true],
        'mls' => ['Add to the MLS under the brokerage', 'broker', false],
        'association' => ['Association membership transferred', 'broker', false],
        'website' => ['Agent profile on the brokerage website', 'broker', false],
        'cards' => ['Business cards and signs ordered', 'broker', false],
    ];

    public const OFFBOARDING = [
        'licence_release' => ['Release the licence with the state', 'broker', false],
        'mls_removed' => ['Remove from the MLS and lockbox access', 'broker', false],
        'listings' => ['Reassign or release active listings and deals', 'broker', false],
        'statement' => ['Send the final statement', 'broker', false],
        'settled' => ['Balances settled (nothing owed either way)', 'broker', true],
    ];

    public function __construct(private readonly TenantContext $tenants, private readonly Agreements $agreements) {}

    public function start(User $agent, string $kind, User $by, ?CarbonImmutable $leavingOn = null, ?string $reason = null): string
    {
        if (! Roster::manages($by)) {
            throw ValidationException::withMessages(['flow' => 'Starting onboarding or offboarding needs the roster permission.']);
        }
        $db = DB::connection(Connections::cell());
        if ($db->table('roster_flows')->where('user_id', $agent->id)->where('kind', $kind)->where('status', 'open')->exists()) {
            throw ValidationException::withMessages(['flow' => 'This is already in progress.']);
        }
        if ($kind === 'offboarding' && ($leavingOn === null || $reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages(['flow' => 'Give the leaving date and the reason.']);
        }
        $ulid = (string) Str::ulid();
        $id = $db->table('roster_flows')->insertGetId(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'user_id' => $agent->id, 'kind' => $kind, 'status' => 'open',
            'leaving_on' => $leavingOn?->toDateString(), 'reason' => $reason !== null ? mb_substr(trim($reason), 0, 200) : null, 'started_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        $sort = 0;
        foreach ($kind === 'onboarding' ? self::ONBOARDING : self::OFFBOARDING as $code => [$title, $who, $auto]) {
            $db->table('roster_tasks')->insert(['tenant_id' => $this->tenants->id(), 'flow_id' => $id, 'code' => $code, 'title' => $title, 'who' => $who, 'automatic' => $auto, 'sort' => $sort++]);
        }
        app(AuditLogger::class)->record('roster.'.$kind.'.started', $agent, metadata: ['leaving_on' => $leavingOn?->toDateString(), 'reason' => $reason], severity: 'notice', label: $agent->name);

        return $ulid;
    }

    public function mark(int $taskId, bool $done, User $by): void
    {
        if (! Roster::manages($by)) {
            throw ValidationException::withMessages(['task' => 'Only the brokerage marks these tasks.']);
        }
        $task = DB::connection(Connections::cell())->table('roster_tasks')->where('id', $taskId)->first();
        if ($task === null || $task->automatic) {
            throw ValidationException::withMessages(['task' => 'This task completes itself.']);
        }
        DB::connection(Connections::cell())->table('roster_tasks')->where('id', $taskId)->update(['done_at' => $done ? now() : null, 'done_by' => $done ? $by->id : null]);
    }

    /**
     * The open (or latest) flow of a kind with each task's state; automatic tasks are re-checked.
     *
     * @return array{ulid: string, status: string, leaving_on: string|null, reason: string|null, tasks: list<array<string, mixed>>, progress: int}|null
     */
    public function view(User $agent, string $kind): ?array
    {
        $db = DB::connection(Connections::cell());
        $flow = $db->table('roster_flows')->where('user_id', $agent->id)->where('kind', $kind)->orderByDesc('id')->first();
        if ($flow === null) {
            return null;
        }
        $tasks = [];
        foreach ($db->table('roster_tasks')->where('flow_id', $flow->id)->orderBy('sort')->get() as $t) {
            $done = $t->automatic ? $this->automaticDone($agent, (string) $t->code) : $t->done_at !== null;
            if ($t->automatic && $flow->status === 'open' && $done !== ($t->done_at !== null)) {
                $db->table('roster_tasks')->where('id', $t->id)->update(['done_at' => $done ? now() : null]);
            }
            $tasks[] = ['id' => (int) $t->id, 'code' => $t->code, 'title' => $t->title, 'who' => $t->who, 'automatic' => (bool) $t->automatic, 'done' => $done];
        }
        $progress = $tasks === [] ? 0 : (int) round(100 * count(array_filter($tasks, fn ($t) => $t['done'])) / count($tasks));

        return ['ulid' => (string) $flow->ulid, 'status' => (string) $flow->status, 'leaving_on' => $flow->leaving_on, 'reason' => $flow->reason, 'tasks' => $tasks, 'progress' => $progress];
    }

    /** Completes a flow when every task is done; offboarding then deactivates the account (owner). */
    public function complete(User $agent, string $kind, User $by): void
    {
        $view = $this->view($agent, $kind);
        if ($view === null || $view['status'] !== 'open') {
            throw ValidationException::withMessages(['flow' => 'Nothing is in progress.']);
        }
        if ($view['progress'] < 100) {
            throw ValidationException::withMessages(['flow' => 'Finish every task first.']);
        }
        if ($kind === 'offboarding') {
            if (! $by->isKind(UserKind::Owner)) {
                throw ValidationException::withMessages(['flow' => 'An owner completes offboarding, which deactivates the account.']);
            }
            app(ManageUserStatus::class)->deactivate($agent, $by);
        }
        DB::connection(Connections::cell())->table('roster_flows')->where('ulid', $view['ulid'])->update(['status' => 'complete', 'completed_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('roster.'.$kind.'.completed', $agent, severity: 'notice', label: $agent->name);
    }

    private function automaticDone(User $agent, string $code): bool
    {
        $db = DB::connection(Connections::cell());
        $today = CarbonImmutable::today()->toDateString();

        return match ($code) {
            'agreement', 'policy' => $this->agreements->signed($agent, $code),
            'w9' => $db->table('pay_w9s')->where('user_id', $agent->id)->where('status', 'current')->exists(),
            'bank' => $db->table('pay_bank_accounts')->where('user_id', $agent->id)->where('status', 'active')->exists(),
            'licence' => $db->table('roster_credentials')->where('user_id', $agent->id)->where('kind', 'licence')->where('status', 'current')->where('expires_on', '>=', $today)->exists(),
            'eo' => $db->table('roster_credentials')->where('user_id', $agent->id)->where('kind', 'eo')->where('status', 'current')->where('expires_on', '>=', $today)->exists(),
            'settled' => $this->settled($agent),
            default => false,
        };
    }

    private function settled(User $agent): bool
    {
        $accounts = app(ChartOfAccounts::class);
        $accounts->seed();
        $ledger = app(LedgerService::class);
        foreach (['2000', '2100', '1200'] as $code) {
            if ($ledger->balance($accounts->subAccount($code, 'user', $agent->id, 'Agent'))->cents !== 0) {
                return false;
            }
        }

        return true;
    }
}
