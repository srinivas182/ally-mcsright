<?php

declare(strict_types=1);

namespace Modules\Core\Identity;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Events\ImpersonationStarted;
use Modules\Core\Identity\Events\ImpersonationStopped;
use Modules\Core\Identity\Notifications\AccountViewed;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * "Log in as" for support (specs/core/02, 4A-3).
 *
 * - Owners: agents, assistants and staff of their own brokerage (never owners, HQ staff or self).
 * - HQ support: any brokerage user except HQ staff, through a single-use 60-second ticket.
 * - Reason required; 60 minutes at most; banner with Stop; sensitive actions blocked
 *   (ImpersonationGuard); no sign-in side effects on the viewed account; start and stop recorded;
 *   the person is emailed.
 */
final class Impersonation
{
    public const SESSION = 'impersonation';

    public const MINUTES = 60;

    public function __construct(private readonly TenantContext $tenants) {}

    public function startAsOwner(User $owner, User $target, string $reason, Request $request): void
    {
        if (! $owner->isKind(UserKind::Owner) || $request->session()->has(self::SESSION)) {
            throw ValidationException::withMessages(['user' => 'Only owners can do this.']);
        }
        if ($target->is($owner) || $target->isKind(UserKind::Owner, UserKind::HqStaff) || $target->tenant_id !== $owner->tenant_id) {
            throw ValidationException::withMessages(['user' => 'You can view agents, assistants and staff of your brokerage.']);
        }

        $this->begin($target, $request, ['by_id' => $owner->id, 'by_name' => $owner->name, 'by_kind' => 'owner', 'return' => 'owner'], $reason);
    }

    /**
     * HQ console: create a single-use ticket and return the brokerage URL that redeems it.
     */
    public function issueHqTicket(User $hqStaff, Tenant $tenant, string $email, string $reason): string
    {
        $this->guardReason($reason);
        if (! $hqStaff->isKind(UserKind::HqStaff)) {
            throw ValidationException::withMessages(['email' => 'Only HQ support can do this.']);
        }
        $target = $this->tenants->runAs($tenant, fn () => User::query()->where('email', mb_strtolower(trim($email)))->first());
        if (! $target instanceof User || $target->isKind(UserKind::HqStaff) || $target->status !== UserStatus::Active) {
            throw ValidationException::withMessages(['email' => 'No active user with that email in this brokerage.']);
        }

        $ticket = Str::random(48);
        Cache::put('impersonate:'.hash('sha256', $ticket), [
            'tenant' => $tenant->id, 'target' => $target->id, 'by_id' => $hqStaff->id, 'by_name' => $hqStaff->name.' (RightAlly support)', 'reason' => $reason,
        ], 60);
        $domain = $tenant->primaryDomain();
        $host = $domain !== null ? $domain->host : $tenant->slug.'.'.config('core.platform_domain');

        return 'https://'.$host.'/impersonate/accept?ticket='.urlencode($ticket);
    }

    public function acceptHqTicket(string $ticket, Request $request): void
    {
        /** @var array{tenant: int, target: int, by_id: int, by_name: string, reason: string}|null $data */
        $data = Cache::pull('impersonate:'.hash('sha256', $ticket));
        $target = $data !== null && $data['tenant'] === $this->tenants->id() ? User::query()->find($data['target']) : null;
        if (! $target instanceof User || $target->status !== UserStatus::Active) {
            throw ValidationException::withMessages(['email' => 'That support link has expired.']);
        }

        $this->begin($target, $request, ['by_id' => $data['by_id'], 'by_name' => $data['by_name'], 'by_kind' => 'hq', 'return' => 'hq'], $data['reason']);
    }

    /**
     * @return array{by_id: int, by_name: string, by_kind: string, return: string, target_id: int, target_name: string, reason: string, expires_at: int}|null
     */
    public function current(Request $request): ?array
    {
        $data = $request->hasSession() ? $request->session()->get(self::SESSION) : null;

        // Only a complete record counts (never a stray or partial value).
        if (! is_array($data) || ! isset($data['by_id'], $data['target_id'], $data['expires_at'], $data['return'])) {
            return null;
        }

        /** @var array{by_id: int, by_name: string, by_kind: string, return: string, target_id: int, target_name: string, reason: string, expires_at: int} $data */
        return $data;
    }

    /**
     * Ends the session. Owners return to their own account; HQ support is signed out here.
     */
    public function stop(Request $request, string $why = 'stopped'): string
    {
        $data = $this->current($request);
        if ($data === null) {
            return '/';
        }

        $request->session()->forget(self::SESSION);
        ImpersonationStopped::dispatch($this->tenants->id(), $data['by_id'], $data['by_kind'], $data['target_id'], $why);
        Log::notice('Impersonation stopped', ['tenant' => $this->tenants->id(), 'by' => $data['by_id'], 'kind' => $data['by_kind'], 'target' => $data['target_id'], 'why' => $why]);

        if ($data['return'] === 'owner') {
            $owner = User::query()->find($data['by_id']);
            if ($owner instanceof User && $owner->isActive()) {
                $request->session()->put('impersonation_returning', true);
                Auth::login($owner);
                $request->session()->forget('impersonation_returning');

                return '/users';
            }
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();

        return $data['return'] === 'hq' ? 'https://'.config('core.hq_host').'/hq/tenants' : '/login';
    }

    /**
     * @param  array{by_id: int, by_name: string, by_kind: string, return: string}  $by
     */
    private function begin(User $target, Request $request, array $by, string $reason): void
    {
        $this->guardReason($reason);
        if ($target->status !== UserStatus::Active) {
            throw ValidationException::withMessages(['user' => 'You can only view active accounts.']);
        }

        $data = $by + ['target_id' => $target->id, 'target_name' => $target->name, 'reason' => trim($reason),
            'expires_at' => CarbonImmutable::now()->addMinutes(self::MINUTES)->getTimestamp()];

        // Set first, so sign-in listeners know this is not the person signing in themselves.
        $request->session()->put(self::SESSION, $data);
        Auth::login($target);

        ImpersonationStarted::dispatch($this->tenants->id(), $by['by_id'], $by['by_kind'], $target->id, trim($reason));
        Log::notice('Impersonation started', ['tenant' => $this->tenants->id(), 'by' => $by['by_id'], 'kind' => $by['by_kind'], 'target' => $target->id]);
        $target->notify(new AccountViewed($by['by_name'], trim($reason)));
    }

    private function guardReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'Say why you need to view this account (at least 10 characters).']);
        }
    }
}
