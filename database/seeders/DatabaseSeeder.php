<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Tenancy\Actions\ProvisionTenant;
use Modules\Core\Tenancy\Data\ProvisionTenantData;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Local demo data: RightAlly HQ with one HQ staff user, and a demo brokerage with an owner and an
 * agent. All demo passwords are "rightally-demo".
 */
class DatabaseSeeder extends Seeder
{
    private const PASSWORD = 'rightally-demo';

    public function run(TenantContext $tenants, ProvisionTenant $provision): void
    {
        Artisan::call('tenants:bootstrap-hq');
        $hq = Tenant::query()->where('type', TenantType::Hq)->sole();

        $tenants->runAs($hq, fn () => User::factory()->hqStaff()->create([
            'first_name' => 'HQ', 'last_name' => 'Admin', 'email' => 'hq@example.com', 'password' => self::PASSWORD,
        ]));

        $demo = $provision->handle(new ProvisionTenantData(
            type: TenantType::Brokerage,
            name: 'Demo Realty',
            slug: 'demo',
            ownerEmail: 'owner@example.com',
            ownerName: 'Olivia Owner',
            idempotencyKey: 'seed-demo',
            stateCode: 'MS',
        ));

        $tenants->runAs($demo, function (): void {
            User::factory()->owner()->create([
                'first_name' => 'Olivia', 'last_name' => 'Owner', 'email' => 'owner@example.com', 'password' => self::PASSWORD,
            ]);
            User::factory()->create([
                'first_name' => 'Andy', 'last_name' => 'Agent', 'email' => 'agent@example.com', 'password' => self::PASSWORD,
            ]);
        });
    }
}
