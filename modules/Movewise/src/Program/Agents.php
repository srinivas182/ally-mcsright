<?php

declare(strict_types=1);

namespace Modules\Movewise\Program;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Studio\BrandKits;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Package;
use Modules\Movewise\Models\SeatRequest;
use Modules\Roster\Verification\LicenceVerifier;

/**
 * Agents in the Movewise program (specs/movewise/00 §6.1, 03). An agent goes live in a state only when all five
 * checks pass: an active licence in that state (state records, re-checked monthly), their brokerage confirmed, HQ has
 * looked at the state's public discipline record, HQ approved the profile card, and the program terms are signed.
 * Anything failing later (a lapsed licence) pauses the agent and their packages at once.
 */
final class Agents
{
    public const TERMS_VERSION = '2026-11';

    public const SPECIALTIES = ['first_time' => 'First-time buyers', 'relocation' => 'Relocation', 'luxury' => 'Luxury homes', 'new_build' => 'New construction',
        'investment' => 'Investment property', 'land' => 'Land and acreage', 'military' => 'Military and VA', 'downsizing' => 'Downsizing', 'condo' => 'Condos and townhomes'];

    public const RECHECK_DAYS = 30;

    public function __construct(private readonly LicenceVerifier $verifier, private readonly TenantContext $tenants) {}

    /**
     * Start or update the agent's application in a state (runs in the agent's tenant).
     *
     * @param  array<string, mixed>  $in
     */
    public function apply(User $user, array $in): Agent
    {
        abort_unless($user->isKind(UserKind::Owner, UserKind::Agent), 403);
        $tenant = $this->tenants->current();
        $state = strtoupper((string) ($in['state'] ?? ''));
        if (! array_key_exists($state, (array) config('movewise.launch_states'))) {
            throw ValidationException::withMessages(['state' => 'Choose one of Movewise\'s states.']);
        }
        $licence = strtoupper(trim((string) ($in['licence_number'] ?? '')));
        if (preg_match('/^[A-Z0-9-]{3,40}$/', $licence) !== 1) {
            throw ValidationException::withMessages(['licence_number' => 'Enter your licence number as the state shows it.']);
        }
        $seat = SeatRequest::query()->where('tenant_id', $tenant->id)->where('status', 'approved')->where('email', mb_strtolower($user->email))->first();
        $agent = Agent::query()->firstOrNew(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'state' => $state]);
        $changedLicence = $agent->exists && $agent->licence_number !== $licence;
        $agent->fill([
            'licence_number' => $licence,
            'brokerage_name' => $seat !== null ? $seat->brokerage_name : ($tenant->type === TenantType::Brokerage ? $tenant->name : mb_substr(trim((string) ($in['brokerage_name'] ?? $tenant->name)), 0, 160)),
            'profile' => $this->profile($user, $in, $agent->exists ? $agent->profile : []),
            'seat' => $seat !== null,
        ]);
        if (! $agent->exists || $changedLicence) {
            $agent->forceFill(['licence_status' => 'unchecked', 'licence_checked_at' => null]);
        }
        if ($agent->profile_status === 'approved' && $agent->isDirty('profile')) {
            $agent->profile_status = 'submitted'; // a changed card is reviewed again
        }
        // The owner of a brokerage confirms their own agents; an owner applying for themselves is confirmed.
        if ($agent->brokerage_confirmed_at === null && $user->isKind(UserKind::Owner) && $tenant->type === TenantType::Brokerage) {
            $agent->forceFill(['brokerage_confirmed_at' => CarbonImmutable::now(), 'brokerage_confirmed_by' => $user->id]);
        }
        $agent->save();
        if ($agent->licence_status === 'unchecked') {
            $this->checkLicence($agent, $user);
        }
        app(AuditLogger::class)->record('movewise.agent.applied', $agent, metadata: ['state' => $state], label: $user->name);

        return $this->refresh($agent);
    }

    /** Send the card to HQ for review. */
    public function submit(Agent $agent): Agent
    {
        $missing = array_values(array_filter([
            trim((string) ($agent->profile['display_name'] ?? '')) === '' ? 'Your name as clients know it' : null,
            mb_strlen(trim((string) ($agent->profile['bio'] ?? ''))) < 80 ? 'A short introduction (at least 80 characters)' : null,
            ($agent->profile['photo'] ?? null) === null ? 'A headshot (add one in Studio → Brand kit)' : null,
        ]));
        if ($missing !== []) {
            throw ValidationException::withMessages(['profile' => 'Before sending your card: '.implode('; ', $missing).'.']);
        }
        $agent->forceFill(['profile_status' => 'submitted', 'review_note' => null])->save();

        return $agent;
    }

    public function signTerms(Agent $agent, bool $agree, ?string $ip): Agent
    {
        if (! $agree) {
            throw ValidationException::withMessages(['agree' => 'Tick to agree to the Movewise program terms.']);
        }
        $agent->forceFill(['terms_version' => self::TERMS_VERSION, 'terms_signed_at' => CarbonImmutable::now(), 'terms_signed_ip' => $ip])->save();
        app(AuditLogger::class)->record('movewise.agent.terms_signed', $agent, metadata: ['version' => self::TERMS_VERSION, 'ip' => $ip], tenantId: $agent->tenant_id);

        return $this->refresh($agent);
    }

    /** The brokerage's owner confirms one of their agents may take Movewise introductions. */
    public function confirmBrokerage(Agent $agent, User $owner, bool $yes): Agent
    {
        abort_unless($owner->isKind(UserKind::Owner) && $this->tenants->id() === $agent->tenant_id, 403);
        $agent->forceFill($yes ? ['brokerage_confirmed_at' => CarbonImmutable::now(), 'brokerage_confirmed_by' => $owner->id]
            : ['brokerage_confirmed_at' => null, 'brokerage_confirmed_by' => null])->save();
        app(AuditLogger::class)->record($yes ? 'movewise.agent.brokerage_confirmed' : 'movewise.agent.brokerage_withdrawn', $agent, label: (string) ($agent->profile['display_name'] ?? ''));

        return $this->refresh($agent);
    }

    /**
     * HQ's review: the profile card, the state's public discipline record and, when state records can't be checked
     * automatically, the licence by hand. A seat agent's brokerage is confirmed by HQ too (it isn't on RightAlly).
     *
     * @param  array<string, mixed>  $in
     */
    public function review(Agent $agent, array $in, User $hq): Agent
    {
        $approve = (bool) ($in['approve'] ?? false);
        $note = trim(mb_substr((string) ($in['note'] ?? ''), 0, 500));
        if (! $approve && $note === '') {
            throw ValidationException::withMessages(['note' => 'Say what the agent needs to change.']);
        }
        $now = CarbonImmutable::now();
        $agent->forceFill(['profile_status' => $approve ? 'approved' : 'changes', 'review_note' => $note !== '' ? $note : null,
            'discipline_checked_at' => ($in['discipline_clear'] ?? false) ? $now : $agent->discipline_checked_at]);
        if (($in['licence_verified'] ?? false) && in_array($agent->licence_status, ['manual', 'unchecked'], true)) {
            $agent->forceFill(['licence_status' => 'active', 'licence_checked_at' => $now]);
        }
        if ($agent->seat && ($in['brokerage_confirmed'] ?? false)) {
            $agent->forceFill(['brokerage_confirmed_at' => $now, 'brokerage_confirmed_by' => $hq->id]);
        }
        $agent->save();
        app(AuditLogger::class)->record('movewise.agent.reviewed', $agent, metadata: ['approved' => $approve, 'note' => $note, 'by' => $hq->id], tenantId: $agent->tenant_id);

        return $this->refresh($agent);
    }

    /** Look the licence up in state records. Without a records service, HQ checks it by hand. */
    public function checkLicence(Agent $agent, ?User $user = null): string
    {
        if (! $this->verifier->configured()) {
            $agent->forceFill(['licence_status' => $agent->licence_status === 'active' ? 'active' : 'manual'])->save();

            return $agent->licence_status;
        }
        $user ??= $this->tenants->runAs(Tenant::query()->findOrFail($agent->tenant_id), fn (): ?User => User::query()->find($agent->user_id));
        $r = $this->verifier->lookup($agent->state, $agent->licence_number, (string) ($user->last_name ?? ''));
        $status = match (true) {
            $r['status'] === 'not_found' => 'not_found',
            $r['status'] !== 'active' => 'inactive',
            $r['last_name'] !== null && $user !== null && mb_strtolower((string) $r['last_name']) !== mb_strtolower((string) $user->last_name) => 'mismatch',
            default => 'active',
        };
        $agent->forceFill(['licence_status' => $status, 'licence_checked_at' => CarbonImmutable::now(), 'licence_expires_on' => $r['expires_on']])->save();

        return $status;
    }

    /** The monthly re-check of every live or applying agent's licence. A licence that isn't active pauses them. */
    public function recheckDue(): int
    {
        if (! $this->verifier->configured()) {
            return 0;
        }
        $n = 0;
        foreach (Agent::query()->whereIn('status', ['active', 'paused', 'applying'])->where(fn ($q) => $q->whereNull('licence_checked_at')
            ->orWhere('licence_checked_at', '<', CarbonImmutable::now()->subDays(self::RECHECK_DAYS)))->orderBy('id')->limit(2000)->get() as $agent) {
            $this->checkLicence($agent);
            $this->refresh($agent);
            $n++;
        }

        return $n;
    }

    /** @return list<string> what still stands between the agent and going live, in plain English */
    public function missing(Agent $agent): array
    {
        return array_values(array_filter([
            match ($agent->licence_status) {
                'active' => null,
                'manual', 'unchecked' => 'Licence check (HQ checks it with the state)',
                'mismatch' => 'Your licence number is under a different name in state records',
                'not_found' => 'Your licence number wasn\'t found in state records',
                default => 'Your licence isn\'t active in state records',
            },
            $agent->brokerage_confirmed_at === null ? ($agent->seat ? 'Your brokerage confirmed (HQ contacts them)' : 'Your broker confirms you in RightAlly') : null,
            $agent->discipline_checked_at === null ? 'HQ checks the state\'s public discipline record' : null,
            match ($agent->profile_status) {
                'approved' => null,
                'submitted' => 'HQ reviews your profile card',
                'changes' => 'Update your profile card: '.($agent->review_note ?? ''),
                default => 'Finish and send your profile card',
            },
            $agent->terms_signed_at === null || $agent->terms_version !== self::TERMS_VERSION ? 'Sign the Movewise program terms' : null,
        ]));
    }

    /** Recalculate whether the agent is live. Paused for a reason other than a failed check, or removed, stays so. */
    public function refresh(Agent $agent): Agent
    {
        $ready = $this->missing($agent) === [];
        if ($agent->status === 'removed') {
            return $agent;
        }
        if ($ready && ($agent->status === 'applying' || ($agent->status === 'paused' && $agent->pause_reason === 'checks'))) {
            $agent->forceFill(['status' => 'active', 'pause_reason' => null])->save();
        } elseif (! $ready && $agent->status === 'active') {
            $agent->forceFill(['status' => 'paused', 'pause_reason' => 'checks'])->save();
            Package::query()->where('agent_id', $agent->id)->where('status', 'active')->update(['status' => 'paused', 'pause_reason' => 'The agent\'s checks need attention']);
            app(AuditLogger::class)->record('movewise.agent.paused', $agent, metadata: ['missing' => $this->missing($agent)], severity: 'warning', tenantId: $agent->tenant_id);
        }

        return $agent;
    }

    /**
     * @param  array<string, mixed>  $in
     * @param  array<string, mixed>  $old
     * @return array{display_name: string, bio: string, languages: list<string>, years: int, specialties: list<string>, photo: string|null, phone: string|null}
     */
    private function profile(User $user, array $in, array $old): array
    {
        $bio = trim(strip_tags(mb_substr((string) ($in['bio'] ?? $old['bio'] ?? ''), 0, 600)));
        if ($bio !== '' && FairHousing::screen($bio, 'ads', 'blocked', 'Movewise profile') !== []) {
            throw ValidationException::withMessages(['bio' => 'Your introduction has wording Fair Housing rules don\'t allow. Describe yourself and your service, not the people you want to serve.']);
        }
        $kit = app(BrandKits::class)->forUser($user);

        return [
            'display_name' => mb_substr(trim(strip_tags((string) ($in['display_name'] ?? $old['display_name'] ?? trim($user->first_name.' '.$user->last_name)))), 0, 80),
            'bio' => $bio,
            'languages' => array_values(array_intersect(['en', 'es'], array_map('strval', (array) ($in['languages'] ?? $old['languages'] ?? ['en'])))) ?: ['en'],
            'years' => max(0, min(60, (int) ($in['years'] ?? $old['years'] ?? 0))),
            'specialties' => array_slice(array_values(array_intersect(array_keys(self::SPECIALTIES), array_map('strval', (array) ($in['specialties'] ?? $old['specialties'] ?? [])))), 0, 4),
            'photo' => is_string($kit['headshot'] ?? null) ? $kit['headshot'] : (is_string($old['photo'] ?? null) ? $old['photo'] : null),
            'phone' => null, // never shown: people reach agents only through the relay (MW5)
        ];
    }
}
