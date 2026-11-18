<?php

declare(strict_types=1);

namespace Modules\Core\Scale\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Tenancy\Actions\ProvisionTenant;
use Modules\Core\Tenancy\Data\ProvisionTenantData;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Models\Site;
use Modules\Sites\SiteSetup;

/**
 * E2E1 (specs/core/15): the brokerage the nightly end-to-end journeys run in: "e2e", with an owner (two-step sign-in
 * with a known test secret), an agent, and a published website. Refuses production. Safe to run again.
 */
final class SeedEndToEnd extends Command
{
    /** The owner's two-step secret (base32); the journeys work out the six-digit code from it, like an app would. */
    public const TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected $signature = 'e2e:seed {--password= : the password for e2e-owner@example.test and e2e-agent@example.test}';

    protected $description = 'Make the brokerage the nightly end-to-end journeys run in (not in production)';

    public function handle(ProvisionTenant $provision, TenantContext $tenants): int
    {
        if (app()->environment('production')) {
            $this->error('Not in production.');

            return self::FAILURE;
        }
        $password = (string) $this->option('password');
        if (strlen($password) < 12) {
            $this->error('Give a --password of at least 12 characters.');

            return self::FAILURE;
        }
        $tenant = $provision->handle(new ProvisionTenantData(TenantType::Brokerage, 'E2E Realty', 'e2e', 'e2e-owner@example.test', 'Erin Owner', 'e2e-seed', 'TX'));
        $tenants->runAs($tenant, function () use ($password): void {
            $hash = Hash::make($password);
            $owner = User::query()->updateOrCreate(['email' => 'e2e-owner@example.test'], ['kind' => UserKind::Owner, 'status' => UserStatus::Active, 'first_name' => 'Erin',
                'last_name' => 'Owner', 'password' => $hash]);
            $owner->forceFill(['email_verified_at' => now(), 'two_factor_secret' => encrypt(self::TOTP_SECRET), 'two_factor_recovery_codes' => encrypt((string) json_encode(['e2e-recovery-1'])),
                'two_factor_confirmed_at' => now()])->save();
            User::query()->updateOrCreate(['email' => 'e2e-agent@example.test'], ['kind' => UserKind::Agent, 'status' => UserStatus::Active, 'first_name' => 'Avery',
                'last_name' => 'Agent', 'password' => $hash])->forceFill(['email_verified_at' => now()])->save();
            if (! Site::query()->where('kind', 'brokerage')->exists()) {
                $sites = app(SiteSetup::class);
                $sites->publish($sites->create('modern', ['name' => 'E2E Realty'], $owner), true, $owner);
            }
        });
        $this->info('Ready: '.$tenant->slug);

        return self::SUCCESS;
    }
}
