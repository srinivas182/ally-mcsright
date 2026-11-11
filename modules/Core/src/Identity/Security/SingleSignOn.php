<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Security;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Models\UserIdentity;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

/**
 * Sign in with Google or Microsoft through one central hand-off host (specs/core/02, 4A-2).
 *
 * Brokerage host → sealed 10-minute state (tenant, intent, nonce bound to this browser's session)
 * → central host → provider → central callback → single-use 60-second ticket → brokerage host.
 *
 * Matching rules (defence against "nOAuth"-style email spoofing):
 * - an already linked identity matches by the provider's permanent subject ID;
 * - Google may match a user by email the first time, only when Google says it is verified;
 * - Microsoft is never matched by email: users link it once from their security settings.
 * Two-step verification and trusted devices still apply.
 */
final class SingleSignOn
{
    public const PROVIDERS = ['google', 'microsoft'];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return list<string> providers configured for this deployment
     */
    public function configured(): array
    {
        return array_values(array_filter(self::PROVIDERS, fn (string $p): bool => (string) config("services.{$p}.client_id") !== ''));
    }

    /**
     * Step 1 (brokerage host): seal the state and send the browser to the central host.
     */
    public function start(string $provider, string $intent, Request $request, ?User $user = null): string
    {
        $this->guardProvider($provider);
        $nonce = Str::random(32);
        $request->session()->put('sso.nonce', $nonce);

        $state = Crypt::encryptString((string) json_encode([
            't' => $this->tenants->id(), 'h' => $request->getHost(), 'p' => $provider, 'i' => $intent === 'link' ? 'link' : 'login',
            'u' => $user?->id, 'n' => $nonce, 'e' => CarbonImmutable::now()->addMinutes(10)->getTimestamp(),
        ]));

        return 'https://'.config('core.sso_host').'/oauth/'.$provider.'/redirect?state='.urlencode($state);
    }

    /**
     * Step 2 (central host): check the state and go to the provider.
     */
    public function toProvider(string $provider, string $state): RedirectResponse
    {
        $this->decode($state, $provider);

        return $this->driver($provider)->stateless()->redirectUrl($this->callbackUrl($provider))->with(['state' => $state])->redirect();
    }

    /**
     * Step 3 (central host): read the provider's answer, hand a single-use ticket back.
     */
    public function callback(string $provider, string $state): string
    {
        $data = $this->decode($state, $provider);
        $remote = $this->driver($provider)->stateless()->redirectUrl($this->callbackUrl($provider))->user();
        $raw = $remote instanceof AbstractUser ? $remote->getRaw() : [];

        $ticket = Str::random(48);
        Cache::put('sso:'.hash('sha256', $ticket), [
            'tenant' => $data['t'], 'intent' => $data['i'], 'user' => $data['u'], 'nonce' => $data['n'], 'provider' => $provider,
            'subject' => (string) $remote->getId(), 'email' => mb_strtolower((string) $remote->getEmail()),
            'verified' => $provider === 'google' && (($raw['email_verified'] ?? false) === true || ($raw['verified_email'] ?? false) === true),
        ], 60);

        return 'https://'.$data['h'].'/auth/complete?ticket='.urlencode($ticket);
    }

    /**
     * Step 4 (brokerage host): redeem the ticket. Returns the user to sign in (login) or links.
     */
    public function complete(string $ticket, Request $request): User
    {
        /** @var array{tenant: int, intent: string, user: int|null, nonce: string, provider: string, subject: string, email: string, verified: bool}|null $data */
        $data = Cache::pull('sso:'.hash('sha256', $ticket));
        $nonce = $request->session()->pull('sso.nonce');
        if ($data === null || $data['tenant'] !== $this->tenants->id() || ! is_string($nonce) || ! hash_equals($nonce, $data['nonce'])) {
            throw ValidationException::withMessages(['email' => 'That sign-in link has expired. Please try again.']);
        }

        if ($data['intent'] === 'link') {
            $user = $request->user();
            if (! $user instanceof User || $user->id !== $data['user']) {
                throw ValidationException::withMessages(['email' => 'Please sign in first, then connect your account.']);
            }
            $this->link($user, $data['provider'], $data['subject'], $data['email']);

            return $user;
        }

        $identity = UserIdentity::query()->where('provider', $data['provider'])->where('subject', $data['subject'])->first();
        $user = $identity !== null ? User::query()->find($identity->user_id) : null;

        if ($user === null && $data['provider'] === 'google' && $data['verified'] && $data['email'] !== '') {
            $user = User::query()->where('email', $data['email'])->first();
            if ($user instanceof User) {
                $this->link($user, 'google', $data['subject'], $data['email']);
            }
        }

        if (! $user instanceof User || $user->status !== UserStatus::Active) {
            $hint = $data['provider'] === 'microsoft'
                ? 'Connect Microsoft from your security settings first (sign in with your password once).'
                : 'There is no active account for this Google email here. Ask your broker for an invitation.';

            throw ValidationException::withMessages(['email' => $hint]);
        }

        $identity?->update(['last_used_at' => CarbonImmutable::now()]);

        return $user;
    }

    public function link(User $user, string $provider, string $subject, string $email): void
    {
        $existing = UserIdentity::query()->where('provider', $provider)->where('subject', $subject)->first();
        if ($existing !== null && $existing->user_id !== $user->id) {
            throw ValidationException::withMessages(['email' => 'That account is already connected to someone else here.']);
        }

        UserIdentity::query()->updateOrCreate(['provider' => $provider, 'subject' => $subject], ['user_id' => $user->id, 'email' => $email, 'last_used_at' => CarbonImmutable::now()]);
    }

    public function unlink(User $user, string $provider): void
    {
        UserIdentity::query()->where('user_id', $user->id)->where('provider', $provider)->delete();
    }

    /**
     * @return array{t: int, h: string, p: string, i: string, u: int|null, n: string, e: int}
     */
    private function decode(string $state, string $provider): array
    {
        try {
            $data = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['email' => 'That sign-in link is not valid.']);
        }

        if (! is_array($data) || ($data['p'] ?? null) !== $provider || (int) ($data['e'] ?? 0) < CarbonImmutable::now()->getTimestamp()) {
            throw ValidationException::withMessages(['email' => 'That sign-in link has expired. Please try again.']);
        }

        /** @var array{t: int, h: string, p: string, i: string, u: int|null, n: string, e: int} $data */
        return $data;
    }

    private function driver(string $provider): AbstractProvider
    {
        $driver = Socialite::driver($provider);
        if (! $driver instanceof AbstractProvider) {
            throw ValidationException::withMessages(['email' => 'That sign-in option is not available.']);
        }

        return $driver;
    }

    private function callbackUrl(string $provider): string
    {
        return 'https://'.config('core.sso_host').'/oauth/'.$provider.'/callback';
    }

    private function guardProvider(string $provider): void
    {
        if (! in_array($provider, $this->configured(), true)) {
            throw ValidationException::withMessages(['email' => 'That sign-in option is not available.']);
        }
    }
}
