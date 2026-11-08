<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Events\InvitationResent;
use Modules\Core\Identity\Events\InvitationRevoked;
use Modules\Core\Identity\Events\UserInvited;
use Modules\Core\Identity\Models\Invitation;
use Modules\Core\Identity\Notifications\InvitationNotification;
use Modules\Core\Tenancy\TenantContext;

/**
 * Invite, resend and revoke (specs/core/02). Links expire after 7 days; resending issues a new
 * token and revokes the old one.
 */
final class ManageInvitations
{
    public const VALID_DAYS = 7;

    public function __construct(private readonly TenantContext $tenants) {}

    public function invite(string $email, string $firstName, string $lastName, UserKind $kind, ?User $invitedBy, ?int $assistedUserId = null): Invitation
    {
        $email = mb_strtolower(trim($email));

        if ($kind === UserKind::HqStaff && ! $this->tenants->current()->isHq()) {
            throw ValidationException::withMessages(['kind' => 'HQ staff can only be invited to RightAlly HQ.']);
        }

        if ($kind !== UserKind::HqStaff && $this->tenants->current()->isHq()) {
            throw ValidationException::withMessages(['kind' => 'Only HQ staff can be invited to RightAlly HQ.']);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Someone with this email already has an account here.']);
        }

        if ($kind === UserKind::Assistant) {
            $agentOk = $assistedUserId !== null && User::query()->whereKey($assistedUserId)
                ->where('kind', UserKind::Agent)->where('status', UserStatus::Active)->exists();

            if (! $agentOk) {
                throw ValidationException::withMessages(['assisted_user_id' => 'Choose the active agent this assistant works for.']);
            }
        } else {
            $assistedUserId = null;
        }

        // One open invitation per email: older ones are revoked.
        Invitation::query()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['revoked_at' => CarbonImmutable::now()]);

        $token = Str::random(40);
        $invitation = Invitation::query()->create([
            'email' => $email,
            'first_name' => trim($firstName),
            'last_name' => trim($lastName),
            'kind' => $kind,
            'role_ids' => [],
            'assisted_user_id' => $assistedUserId,
            'invited_by' => $invitedBy?->id,
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => CarbonImmutable::now()->addDays(self::VALID_DAYS),
        ]);

        $this->send($invitation, $token, $invitedBy);
        UserInvited::dispatch($invitation->tenant_id, $invitation->id, $invitedBy?->id);

        return $invitation;
    }

    public function resend(Invitation $invitation, ?User $by): Invitation
    {
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages(['invitation' => 'This invitation was already accepted.']);
        }

        $token = Str::random(40);
        $invitation->forceFill([
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => CarbonImmutable::now()->addDays(self::VALID_DAYS),
            'revoked_at' => null,
        ])->save();

        $this->send($invitation, $token, $by);
        InvitationResent::dispatch($invitation->tenant_id, $invitation->id, $by?->id);

        return $invitation;
    }

    public function revoke(Invitation $invitation, ?User $by): Invitation
    {
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages(['invitation' => 'This invitation was already accepted.']);
        }

        $invitation->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
        InvitationRevoked::dispatch($invitation->tenant_id, $invitation->id, $by?->id);

        return $invitation;
    }

    /**
     * The open invitation for a raw token in the current tenant, or null.
     */
    public function findOpen(string $token): ?Invitation
    {
        $invitation = Invitation::query()->where('token_hash', Invitation::hashToken($token))->first();

        return $invitation !== null && $invitation->isOpen() ? $invitation : null;
    }

    private function send(Invitation $invitation, string $token, ?User $by): void
    {
        $tenant = $this->tenants->current();
        $host = $tenant->primaryDomain()?->host;
        $url = ($host !== null ? 'https://'.$host : rtrim((string) config('app.url'), '/')).'/invitations/'.$token;

        Notification::route('mail', $invitation->email)->notify(new InvitationNotification(
            $url,
            $tenant->name,
            $by?->name,
            $invitation->expires_at->toFormattedDateString(),
        ));
    }
}
