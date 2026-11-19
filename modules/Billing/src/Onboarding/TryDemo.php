<?php

declare(strict_types=1);

namespace Modules\Billing\Onboarding;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Tenancy\Actions\ProvisionTenant;
use Modules\Core\Tenancy\Data\ProvisionTenantData;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Practice\PracticeClients;

/**
 * MK2: "Try it now" — RightAlly with made-up clients, no sign-up.
 *
 * Every visitor gets their own guest agent in a separate demo brokerage ("Sunny Realty (demo)"), with the five
 * made-up practice clients, in the simple view. Guests see only their own made-up clients; nothing can be sent to
 * them (practice people); the demo brokerage has no texting or calling switched on; guests are agents, so they cannot
 * change users, billing or settings. Guests and their clients are deleted after a day (billing:demo-cleanup).
 *
 * Off unless HQ switches it on (DEMO_ENABLED), so it is tried on staging first. AI help in the demo uses RightAlly's
 * own AI costs; the assistant's rate limits apply.
 */
final class TryDemo
{
    public const SLUG = 'rightally-demo';

    public const DOMAIN = 'demo.rightally.invalid';

    public function __construct(private readonly TenantContext $tenants) {}

    public static function enabled(): bool
    {
        return (bool) config('core.demo', false);
    }

    /** A fresh guest with made-up clients; returns the one-time link that signs them in (10 minutes). */
    public function visit(): string
    {
        $tenant = $this->tenant();

        return $this->tenants->runAs($tenant, function () use ($tenant): string {
            $guest = User::query()->create(['first_name' => 'Guest', 'last_name' => 'Agent', 'email' => 'guest-'.Str::lower((string) Str::ulid()).'@'.self::DOMAIN,
                'password' => Hash::make(Str::random(40)), 'kind' => UserKind::Agent, 'status' => UserStatus::Active]);
            $guest->forceFill(['email_verified_at' => now(), 'ui_mode' => 'simple'])->save();
            app(PracticeClients::class)->add($guest);
            $host = $tenant->primaryDomain()?->host;
            $path = URL::temporarySignedRoute('try.enter', CarbonImmutable::now()->addMinutes(10), ['user' => $guest->ulid], false);

            return $host !== null ? 'https://'.$host.$path : url($path);
        });
    }

    /** The guest the link names, if it is a demo guest less than an hour old. */
    public function guest(string $ulid): ?User
    {
        if (! $this->tenants->check() || $this->tenants->current()->slug !== self::SLUG) {
            return null;
        }

        return User::query()->where('ulid', $ulid)->where('email', 'like', '%@'.self::DOMAIN)->where('created_at', '>', now()->subHour())->first();
    }

    /** Daily: delete guests (and their made-up clients) older than a day. */
    public function cleanup(): int
    {
        $tenant = Tenant::query()->where('slug', self::SLUG)->first();
        if ($tenant === null) {
            return 0;
        }

        return $this->tenants->runAs($tenant, function (): int {
            $n = 0;
            foreach (User::query()->where('email', 'like', '%@'.self::DOMAIN)->where('kind', UserKind::Agent)->where('created_at', '<', now()->subDay())->get() as $guest) {
                app(PracticeClients::class)->remove($guest);
                $guest->delete();
                $n++;
            }

            return $n;
        });
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->where('slug', self::SLUG)->first()
            ?? app(ProvisionTenant::class)->handle(new ProvisionTenantData(type: TenantType::Brokerage, name: 'Sunny Realty (demo)', slug: self::SLUG,
                ownerEmail: 'owner@'.self::DOMAIN, ownerName: 'Demo Owner', idempotencyKey: 'rightally-demo'));
    }
}
