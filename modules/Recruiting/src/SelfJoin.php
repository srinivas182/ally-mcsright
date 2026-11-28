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
use Modules\Compensation\JoinChoices;
use Modules\Compensation\RevenueShare\RevenueShareEngine;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Events\UserActivated;
use Modules\Core\Identity\Models\Invitation;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Roster\Brokerage\BrokerageLicences;

/**
 * CM4: joining through an agent's link. The new agent gives a licence that the state's records accept, picks one of
 * the brokerage's packs and pays RightAlly for it; once paid they are placed straight away (no approval) under the
 * agent whose link they used, on the commission plan they picked (AG4: revenue share or a flat fee, as the owner
 * offers) or the brokerage's default plan. When the brokerage pays for its agents, they are placed as soon as the
 * licence checks out.
 */
final class SelfJoin
{
    public function __construct(private readonly TenantContext $tenants) {}

    private const VIDEO = '#^https://(www\.)?(youtube\.com/watch\?v=|youtu\.be/|youtube-nocookie\.com/embed/|vimeo\.com/|player\.vimeo\.com/video/)[\w\-?=&/]+$|^https://[\w.\-]+/[\w\-./]+\.mp4$#i';

    /**
     * The join page's words, video and extra states, with the careers page's words as the starting point.
     *
     * @return array{headline: string, pitch: string, reasons: list<string>, video: string|null, extra_states: list<string>}
     */
    public function page(): array
    {
        $raw = DB::connection(Connections::cell())->table('rec_settings')->value('join_page');
        $p = is_string($raw) ? (array) json_decode($raw, true) : [];
        $careers = app(Careers::class)->settings();

        return [
            'headline' => (string) ($p['headline'] ?? $careers['headline']),
            'pitch' => (string) ($p['pitch'] ?? $careers['intro']),
            'reasons' => array_values(array_map('strval', (array) ($p['reasons'] ?? $careers['reasons']))),
            'video' => isset($p['video']) && is_string($p['video']) && $p['video'] !== '' ? $p['video'] : null,
            'extra_states' => array_values(array_map('strval', (array) ($p['extra_states'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function savePage(array $in, User $by): void
    {
        $headline = trim((string) ($in['headline'] ?? ''));
        $pitch = trim((string) ($in['pitch'] ?? ''));
        $video = trim((string) ($in['video'] ?? ''));
        $reasons = array_values(array_filter(array_map(fn ($l): string => mb_substr(trim((string) $l), 0, 140), preg_split('/\R/', (string) ($in['reasons'] ?? '')) ?: []), fn (string $l): bool => $l !== ''));
        $states = array_values(array_unique(array_filter(array_map(fn ($s): string => strtoupper(trim((string) $s)), (array) ($in['extra_states'] ?? [])), fn (string $s): bool => preg_match('/^[A-Z]{2}$/', $s) === 1)));
        if ($headline === '' || mb_strlen($headline) > 90) {
            throw ValidationException::withMessages(['headline' => 'A headline of up to 90 characters.']);
        }
        if (mb_strlen($pitch) > 600) {
            throw ValidationException::withMessages(['pitch' => 'Up to 600 characters.']);
        }
        if ($video !== '' && preg_match(self::VIDEO, $video) !== 1) {
            throw ValidationException::withMessages(['video' => 'Use a YouTube or Vimeo link, or an https link to an MP4 file.']);
        }
        DB::connection(Connections::cell())->table('rec_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], [
            'join_page' => json_encode(['headline' => $headline, 'pitch' => $pitch, 'reasons' => array_slice($reasons, 0, 6), 'video' => $video !== '' ? $video : null,
                'extra_states' => $states]), 'updated_at' => now(),
        ]);
        app(AuditLogger::class)->record('recruiting.join_page.saved', metadata: ['states' => $states, 'video' => $video !== ''], label: $by->name);
    }

    /**
     * The states a new agent can join from: where the brokerage holds a good licence, plus the states the owner added
     * (they work there through a managing broker). Every state while none is set.
     *
     * @param  array<string, string>  $all  code => name
     * @return array<string, string>
     */
    public function states(array $all): array
    {
        $codes = [...app(BrokerageLicences::class)->goodStates(), ...$this->page()['extra_states']];
        // FX2: agents can only hang their licence with a brokerage licensed in that state, so never every state. With no
        // licence on file yet, the brokerage's own state (from sign-up); with none at all, joining is not open yet.
        if ($codes === []) {
            $home = $this->tenants->current()->state_code;
            $codes = is_string($home) && $home !== '' ? [strtoupper($home)] : [];
        }

        return array_intersect_key($all, array_flip($codes));
    }

    /** The video's player address (YouTube and Vimeo play privacy-friendly; MP4 files as they are). */
    public static function player(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/|youtube-nocookie\.com/embed/)([\w\-]{6,20})#', $url, $m) === 1) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1];
        }
        if (preg_match('#vimeo\.com/(?:video/)?(\d{5,12})#', $url, $m) === 1) {
            return 'https://player.vimeo.com/video/'.$m[1].'?dnt=1';
        }

        return $url;
    }

    /**
     * FX3: who a new agent with no sponsor joins under: the company's root account (the owner unless HQ set another),
     * shown as "Referred by" on the join page.
     */
    public function rootReferrer(): ?User
    {
        $id = class_exists(RevenueShareEngine::class) ? app(RevenueShareEngine::class)->rootUserId()
            : User::query()->where('kind', UserKind::Owner)->orderBy('id')->value('id');
        $user = $id !== null ? User::query()->find($id) : null;

        return $user instanceof User ? $user : null;
    }

    /** FX3: a referral code typed on the form, or pasted as the agent's whole join link. */
    public static function codeFromInput(?string $input): ?string
    {
        $input = strtolower(trim((string) $input));
        if ($input === '') {
            return null;
        }
        $input = rtrim((string) preg_replace('#[?\#].*$#', '', $input), '/');

        return basename($input);
    }

    /** The agent's own join code (made the first time it is needed). */
    public function codeFor(User $user): string
    {
        $code = (string) ($user->getAttribute('join_code') ?? '');
        if ($code !== '') {
            return $code;
        }
        // AG1: a code people can read and remember, from the agent's name ("mariachen"); a number is added when taken.
        $base = substr((string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($user->first_name.$user->last_name))), 0, 10);
        $base = strlen($base) >= 6 ? $base : str_pad($base, 6, (string) random_int(0, 9));
        $code = $base;
        for ($n = 2; User::query()->where('join_code', $code)->exists(); $n++) {
            $code = $n < 100 ? substr($base, 0, 12 - strlen((string) $n)).$n : Str::lower(Str::random(8));
        }
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
     * AG4: the commission plans a new agent can pick (none: they join on the default plan).
     *
     * @return list<array{ulid: string, name: string, description: string|null, lines: list<string>, default: bool}>
     */
    public function plans(): array
    {
        return class_exists(JoinChoices::class) ? app(JoinChoices::class)->offered() : [];
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
     * @param  array{first_name: string, last_name: string, email: string, phone: string, password: string, licence_state: string, licence_number: string, pack?: string|null, plan?: string|null}  $data
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

        $choices = $this->plans();
        $commission = null;
        if ($choices !== []) {
            $picked = (string) ($data['plan'] ?? '');
            if ($picked === '' && count($choices) === 1) {
                $picked = $choices[0]['ulid'];
            }
            $commission = in_array($picked, array_column($choices, 'ulid'), true) ? app(JoinChoices::class)->find($picked) : null;
            if ($commission === null) {
                throw ValidationException::withMessages(['plan' => 'Choose how you want to be paid.']);
            }
        }

        $user = DB::transaction(function () use ($data, $email, $sponsor, $licence, $plan, $commission): User {
            $user = new User(['kind' => UserKind::Agent, 'first_name' => trim($data['first_name']), 'last_name' => trim($data['last_name']), 'email' => $email,
                'phone' => trim($data['phone']), 'password' => $data['password'], 'status' => UserStatus::Invited]);
            $user->save();
            $db = DB::connection(Connections::cell());
            $db->table('recruiting_join_applications')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'user_id' => $user->id,
                'sponsor_user_id' => $sponsor?->id, 'agent_plan_id' => $plan?->id, 'comp_plan_id' => $commission?->id, 'licence_state' => $licence['state'], 'licence_number' => $licence['number'],
                'licence_status' => $licence['status'], 'status' => 'payment', 'created_at' => now(), 'updated_at' => now()]);
            $db->table('roster_credentials')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'user_id' => $user->id, 'kind' => 'licence',
                'state_code' => $licence['state'], 'number' => $licence['number'], 'expires_on' => $licence['expires_on'], 'status' => 'current',
                'verified_at' => $licence['status'] === 'active' ? now() : null, 'verification_method' => $licence['status'] === 'active' ? 'state_data' : null,
                'verification_note' => 'Checked against the state\'s records when they joined online ('.$licence['status'].').', 'created_at' => now(), 'updated_at' => now()]);

            return $user;
        });
        app(AuditLogger::class)->record('recruiting.join.applied', $user, metadata: ['sponsor' => $sponsor?->name, 'licence' => $licence['state'].' '.$licence['status'], 'plan' => $commission?->name], label: $user->name);

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

    /** Placed: the account is active, under the sponsor, on the plan they picked (or the default plan). Runs once. */
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
        $planName = $app->comp_plan_id !== null && class_exists(JoinChoices::class) ? app(JoinChoices::class)->place($user->id, (int) $app->comp_plan_id) : null;
        UserActivated::dispatch($this->tenants->id(), $user->id, $app->sponsor_user_id !== null ? (int) $app->sponsor_user_id : null);
        app(AuditLogger::class)->record('recruiting.join.placed', $user, metadata: ['plan' => $planName], label: $user->name);
    }
}
