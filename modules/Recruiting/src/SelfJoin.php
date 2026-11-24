<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\AgentPayments;
use Modules\Billing\Entitlements;
use Modules\Billing\Events\AgentPlanStarted;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Pricing;
use Modules\Billing\Signup\LicenceCheck;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Events\UserActivated;
use Modules\Core\Identity\Models\Invitation;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * CM4: joining through an agent's link. The new agent gives a licence that the state's records accept, picks one of
 * the brokerage's packs and pays RightAlly for it; once paid they are placed straight away (no approval) under the
 * agent whose link they used, on the brokerage's default commission plan. When the brokerage pays for its agents,
 * they are placed as soon as the licence checks out.
 */
final class SelfJoin
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** The agent's own join code (made the first time it is needed). */
    public function codeFor(User $user): string
    {
        $code = (string) ($user->getAttribute('join_code') ?? '');
        if ($code !== '') {
            return $code;
        }
        do {
            $code = Str::lower(Str::random(8));
        } while (User::query()->where('join_code', $code)->exists());
        $user->forceFill(['join_code' => $code])->saveQuietly();

        return $code;
    }

    public function sponsorFor(?string $code): ?User
    {
        if ($code === null || preg_match('/^[a-z0-9]{6,12}$/', $code) !== 1) {
            return null;
        }
        $user = User::query()->where('join_code', $code)->where('status', UserStatus::Active)->first();

        return $user instanceof User && in_array($user->kind, [UserKind::Agent, UserKind::Owner], true) ? $user : null;
    }

    /**
     * The packs a new agent can choose (when agents pay RightAlly for their own plan).
     *
     * @return list<array{ulid: string, name: string, description: string|null, price_cents: int}>
     */
    public function packs(): array
    {
        if (app(Entitlements::class)->model() !== 'agent_pays') {
            return [];
        }
        $pricing = app(Pricing::class);

        return array_values(AgentPlan::query()->where('active', true)->orderBy('sort')->get()->filter(fn (AgentPlan $p): bool => $pricing->offered($p))
            ->map(fn (AgentPlan $p): array => ['ulid' => $p->ulid, 'name' => $p->name, 'description' => $p->description, 'price_cents' => $pricing->priceFor($p)])->all());
    }

    /**
     * Applies: checks the licence, creates the (not yet active) account and returns where to go next: the payment
     * page, or the done page when the brokerage pays.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone: string, password: string, licence_state: string, licence_number: string, pack?: string|null}  $data
     */
    public function apply(array $data, ?User $sponsor, string $stateName, string $returnBase): string
    {
        $email = mb_strtolower(trim($data['email']));
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'There is already an account with this email here. Please sign in instead.']);
        }
        $name = trim($data['first_name'].' '.$data['last_name']);
        $licence = app(LicenceCheck::class)->check($data['licence_state'], $stateName, $data['licence_number'], $name);
        if ($licence['error'] !== null) {
            throw ValidationException::withMessages(['licence_number' => $licence['error']]);
        }
        if (in_array($licence['status'], ['expired', 'inactive'], true)) {
            throw ValidationException::withMessages(['licence_number' => 'This licence is not active. Renew it with the state, then come back to join.']);
        }
        $packs = $this->packs();
        $plan = null;
        if ($packs !== []) {
            $plan = AgentPlan::query()->where('ulid', (string) ($data['pack'] ?? ''))->first();
            if ($plan === null || ! in_array($plan->ulid, array_column($packs, 'ulid'), true)) {
                throw ValidationException::withMessages(['pack' => 'Choose one of the packs.']);
            }
        }

        $user = DB::transaction(function () use ($data, $email, $sponsor, $licence, $plan): User {
            $user = new User(['kind' => UserKind::Agent, 'first_name' => trim($data['first_name']), 'last_name' => trim($data['last_name']), 'email' => $email,
                'phone' => trim($data['phone']), 'password' => $data['password'], 'status' => UserStatus::Invited]);
            $user->save();
            $db = DB::connection(Connections::cell());
            $db->table('recruiting_join_applications')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'user_id' => $user->id,
                'sponsor_user_id' => $sponsor?->id, 'agent_plan_id' => $plan?->id, 'licence_state' => $licence['state'], 'licence_number' => $licence['number'],
                'licence_status' => $licence['status'], 'status' => 'payment', 'created_at' => now(), 'updated_at' => now()]);
            $db->table('roster_credentials')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'user_id' => $user->id, 'kind' => 'licence',
                'state_code' => $licence['state'], 'number' => $licence['number'], 'expires_on' => $licence['expires_on'], 'status' => 'current',
                'verified_at' => $licence['status'] === 'active' ? now() : null, 'verification_method' => $licence['status'] === 'active' ? 'state_data' : null,
                'verification_note' => 'Checked against the state\'s records when they joined online ('.$licence['status'].').', 'created_at' => now(), 'updated_at' => now()]);

            return $user;
        });
        app(AuditLogger::class)->record('recruiting.join.applied', $user, metadata: ['sponsor' => $sponsor?->name, 'licence' => $licence['state'].' '.$licence['status']], label: $user->name);

        if ($plan === null) {
            // The brokerage pays for its agents: nothing to pay, placed now.
            $this->place($user->id);

            return '/join/done';
        }

        return app(AgentPayments::class)->checkout($user, $plan, $returnBase, '/join/done', '/join/'.($sponsor !== null ? $this->codeFor($sponsor) : '').'?cancelled=1');
    }

    public function paid(AgentPlanStarted $event): void
    {
        $this->place($event->userId);
    }

    /** Placed: the account is active, under the sponsor, on the default plan. Runs once. */
    public function place(int $userId): void
    {
        $db = DB::connection(Connections::cell());
        $app = $db->table('recruiting_join_applications')->where('user_id', $userId)->where('status', 'payment')->first();
        $user = User::query()->find($userId);
        if ($app === null || ! $user instanceof User || $user->status !== UserStatus::Invited) {
            return;
        }
        DB::transaction(function () use ($db, $app, $user): void {
            $user->forceFill(['status' => UserStatus::Active, 'email_verified_at' => now()])->save();
            // Attribution reads the sponsor from an accepted invitation, the same as an emailed one.
            Invitation::query()->create(['email' => $user->email, 'first_name' => $user->first_name, 'last_name' => $user->last_name, 'kind' => UserKind::Agent,
                'role_ids' => [], 'invited_by' => $app->sponsor_user_id, 'sponsor_user_id' => $app->sponsor_user_id, 'token_hash' => hash('sha256', Str::random(40)),
                'expires_at' => now(), 'accepted_at' => now()]);
            $db->table('recruiting_join_applications')->where('id', $app->id)->update(['status' => 'joined', 'joined_at' => now(), 'updated_at' => now()]);
        });
        UserActivated::dispatch($this->tenants->id(), $user->id, $app->sponsor_user_id !== null ? (int) $app->sponsor_user_id : null);
        app(AuditLogger::class)->record('recruiting.join.placed', $user, label: $user->name);
    }
}
