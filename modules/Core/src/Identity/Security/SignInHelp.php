<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Security;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Notifications\SignInHelpRequested;
use Modules\Core\Tenancy\TenantContext;

/**
 * SX3: "I lost my phone" — help signing in, confirmed by the broker, never by email alone.
 *
 * The person has already typed their password (they are on the two-step page), so a request names a real account
 * and a real password holder. It goes to the people who manage users in the brokerage (owners and staff with that
 * permission), never to the person asking. One of them confirms it is really them — by calling the number they
 * already know, or in person — and resets their sign-in security: passkeys and the authenticator are removed, the
 * person is signed out everywhere and gets a password reset email. Requests expire after a day; every step is in the
 * audit log. An owner with no one else to ask is pointed to RightAlly support.
 */
final class SignInHelp
{
    public const HOURS = 24;

    public function __construct(private readonly TenantContext $tenants, private readonly SignInSecurity $security, private readonly AuditLogger $audit) {}

    /** Ask for help. Returns false when there is no one in the brokerage who can help (owner on their own). */
    public function ask(User $user): bool
    {
        $helpers = $this->helpersFor($user);
        if ($helpers->isEmpty()) {
            return false;
        }
        $db = DB::table('sign_in_help_requests');
        $waiting = $db->clone()->where('user_id', $user->id)->where('status', 'waiting')->where('expires_at', '>', now())->exists();
        if (! $waiting) {
            $db->clone()->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'user_id' => $user->id, 'reason' => 'lost_device',
                'status' => 'waiting', 'expires_at' => CarbonImmutable::now()->addHours(self::HOURS), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($helpers as $helper) {
                $helper->notify(new SignInHelpRequested($user->name, $user->email));
            }
            $this->audit->record('core.auth.sign_in_help.asked', $user, severity: 'notice');
        }

        return true;
    }

    /**
     * Waiting requests for the users page.
     *
     * @return list<array{ulid: string, name: string, email: string, asked_at: string}>
     */
    public function waiting(): array
    {
        return array_values(DB::table('sign_in_help_requests')->where('status', 'waiting')->where('expires_at', '>', now())->orderBy('id')->get()
            ->map(function (object $r): ?array {
                $u = User::query()->find((int) $r->user_id);

                return $u === null ? null : ['ulid' => (string) $r->ulid, 'name' => $u->name, 'email' => $u->email, 'asked_at' => CarbonImmutable::parse((string) $r->created_at)->toIso8601String()];
            })->filter()->all());
    }

    /** The helper confirmed it is really them: reset their sign-in security. */
    public function reset(string $ulid, User $by): User
    {
        [$id, $user] = $this->open($ulid, $by);
        $this->security->resetSecurity($user, $by);
        $this->close($id, 'reset', $by);
        $this->audit->record('core.auth.sign_in_help.reset', $user, metadata: ['by' => $by->id], severity: 'warning');

        return $user;
    }

    public function decline(string $ulid, User $by): User
    {
        [$id, $user] = $this->open($ulid, $by);
        $this->close($id, 'declined', $by);
        $this->audit->record('core.auth.sign_in_help.declined', $user, metadata: ['by' => $by->id], severity: 'notice');

        return $user;
    }

    /** @return Collection<int, User> active people in the brokerage who manage users, other than the person asking */
    private function helpersFor(User $user): Collection
    {
        return User::query()->where('status', UserStatus::Active)->whereKeyNot($user->id)->get()
            ->filter(fn (User $u): bool => Gate::forUser($u)->allows('core.users.manage'))->values();
    }

    /** @return array{0: int, 1: User} the request's id and the person who asked */
    private function open(string $ulid, User $by): array
    {
        $request = DB::table('sign_in_help_requests')->where('ulid', $ulid)->where('status', 'waiting')->where('expires_at', '>', now())->first();
        $user = $request !== null ? User::query()->find((int) $request->user_id) : null;
        if ($request === null || $user === null) {
            throw ValidationException::withMessages(['help' => 'This request has already been answered or has expired.']);
        }
        if ($user->is($by)) {
            throw ValidationException::withMessages(['help' => 'Someone else must confirm this request.']);
        }

        return [(int) $request->id, $user];
    }

    private function close(int $id, string $status, User $by): void
    {
        DB::table('sign_in_help_requests')->where('id', $id)->update(['status' => $status, 'decided_by' => $by->id, 'decided_at' => now(), 'updated_at' => now()]);
    }
}
