<?php

declare(strict_types=1);

namespace Modules\Compensation\RevenueShare;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Events\UserActivated;
use Modules\Core\Identity\Events\UserDeactivated;
use Modules\Core\Identity\Models\Invitation;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Attribution lock and disputes (specs/compensation/02, addition 12): the sponsor is fixed when an
 * invitation is accepted (the sponsor chosen when inviting, or the inviting agent); later claims are
 * disputes the brokerage decides, changing the sponsor only from a chosen date.
 */
final class Attribution
{
    public function __construct(private readonly Sponsorships $sponsorships, private readonly Members $members, private readonly TenantContext $tenants) {}

    public function onActivated(UserActivated $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);
        if ($tenant === null) {
            return;
        }
        $this->tenants->runAs($tenant, function () use ($event): void {
            $user = User::query()->find($event->userId);
            if (! $user instanceof User || ! $user->isKind(UserKind::Agent)) {
                return;
            }
            $today = CarbonImmutable::now();
            $this->members->joined($user->id, $today);
            if (DB::connection(Connections::cell())->table('rs_sponsorships')->where('agent_user_id', $user->id)->exists()) {
                return;
            }
            $invitation = Invitation::query()->where('email', $user->email)->whereNotNull('accepted_at')->orderByDesc('id')->first();
            $inviter = $event->byUserId !== null ? User::query()->find($event->byUserId) : null;
            $sponsorId = ($invitation !== null ? $invitation->sponsor_user_id : null) ?? ($inviter instanceof User && $inviter->isKind(UserKind::Agent) ? $inviter->id : null);
            $sponsor = $sponsorId !== null ? User::query()->find($sponsorId) : null;
            if ($sponsor instanceof User && $sponsor->id !== $user->id) {
                $this->sponsorships->set($user, $sponsor, $today, $inviter instanceof User ? $inviter : null, 'Set when the invitation was accepted');
            }
        });
    }

    public function onDeactivated(UserDeactivated $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);
        if ($tenant !== null) {
            $this->tenants->runAs($tenant, fn () => $this->members->left($event->userId, CarbonImmutable::now()));
        }
    }

    public function claim(User $agent, User $claimant, string $evidence): string
    {
        $current = $this->sponsorships->sponsorOf($agent->id, CarbonImmutable::now());
        if ($claimant->id === $agent->id || $claimant->id === $current) {
            throw ValidationException::withMessages(['claim' => 'Only someone other than the agent and their current sponsor can claim sponsorship.']);
        }
        if (mb_strlen(trim($evidence)) < 20) {
            throw ValidationException::withMessages(['evidence' => 'Explain how you recruited them (at least 20 characters).']);
        }
        $db = DB::connection(Connections::cell());
        if ($db->table('rs_disputes')->where('agent_user_id', $agent->id)->where('status', 'open')->exists()) {
            throw ValidationException::withMessages(['claim' => 'There is already an open sponsorship dispute for this agent.']);
        }
        $ulid = (string) Str::ulid();
        $db->table('rs_disputes')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'agent_user_id' => $agent->id, 'claimant_user_id' => $claimant->id,
            'current_sponsor_user_id' => $current, 'evidence' => trim($evidence), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('compensation.sponsor.disputed', $agent, metadata: ['claimant' => $claimant->name], severity: 'warning', label: $agent->name);

        return $ulid;
    }

    public function decide(string $disputeUlid, bool $upheld, CarbonImmutable $effectiveFrom, string $note, User $by): void
    {
        $db = DB::connection(Connections::cell());
        $dispute = $db->table('rs_disputes')->where('ulid', $disputeUlid)->where('status', 'open')->first();
        if ($dispute === null) {
            throw ValidationException::withMessages(['dispute' => 'This dispute is already decided.']);
        }
        if (mb_strlen(trim($note)) < 10) {
            throw ValidationException::withMessages(['note' => 'Record the reason for the decision (at least 10 characters).']);
        }
        $agent = User::query()->findOrFail((int) $dispute->agent_user_id);
        if ($upheld) {
            $this->sponsorships->set($agent, User::query()->findOrFail((int) $dispute->claimant_user_id), $effectiveFrom, $by, 'Sponsorship dispute upheld: '.trim($note));
        }
        $db->table('rs_disputes')->where('id', $dispute->id)->update(['status' => $upheld ? 'upheld' : 'rejected', 'decided_by' => $by->id,
            'decision_note' => trim($note), 'effective_from' => $upheld ? $effectiveFrom->toDateString() : null, 'decided_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('compensation.sponsor.dispute_decided', $agent, metadata: ['upheld' => $upheld, 'note' => $note], severity: 'warning', label: $agent->name);
    }
}
