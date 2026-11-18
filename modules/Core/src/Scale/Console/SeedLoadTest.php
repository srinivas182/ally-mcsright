<?php

declare(strict_types=1);

namespace Modules\Core\Scale\Console;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Enums\Source;
use Modules\Crm\Models\Contact;
use Modules\Transactions\Models\Deal;

/**
 * PS2 (specs/core/14): fills a load-test brokerage with a big brokerage's worth of made-up data, so the load
 * scripts in tests/load run against realistic sizes. Never in production, and only into a brokerage whose address
 * starts with "loadtest", so a copy of a real brokerage can't be filled by mistake. Every email address ends in
 * .invalid (it can never be delivered) and every phone number is a 555-01xx fiction number.
 */
final class SeedLoadTest extends Command
{
    protected $signature = 'perf:seed {slug : a brokerage whose address starts with "loadtest"} {--agents=200} {--contacts=50000} {--deals=5000}
        {--password= : the password for the load-test agents (agent1@loadtest.invalid and so on)}';

    protected $description = 'Fill a load-test brokerage with made-up agents, contacts and deals (not in production)';

    public function handle(TenantContext $tenants): int
    {
        if (app()->environment('production')) {
            $this->error('Not in production.');

            return self::FAILURE;
        }
        $slug = (string) $this->argument('slug');
        $tenant = Tenant::query()->where('slug', $slug)->first();
        if (! str_starts_with($slug, 'loadtest') || $tenant === null) {
            $this->error('Make a brokerage whose address starts with "loadtest" first.');

            return self::FAILURE;
        }
        $password = (string) $this->option('password');
        if (strlen($password) < 12) {
            $this->error('Give a --password of at least 12 characters for the load-test agents.');

            return self::FAILURE;
        }
        $agentCount = max(1, (int) $this->option('agents'));
        $contactCount = max(0, (int) $this->option('contacts'));
        $dealCount = max(0, (int) $this->option('deals'));

        $tenants->runAs($tenant, function () use ($agentCount, $contactCount, $dealCount, $password): void {
            $hash = Hash::make($password);
            $ids = [];
            for ($i = 1; $i <= $agentCount; $i++) {
                $ids[] = User::query()->firstOrCreate(['email' => "agent{$i}@loadtest.invalid"], ['kind' => UserKind::Agent, 'status' => UserStatus::Active,
                    'first_name' => 'Agent', 'last_name' => (string) $i, 'password' => $hash, 'email_verified_at' => now()])->id;
            }
            $this->info(count($ids).' agents.');
            $made = 0;
            while ($made < $contactCount) {
                DB::connection(Connections::cell())->transaction(function () use (&$made, $contactCount, $ids): void {
                    for ($n = 0; $n < 500 && $made < $contactCount; $n++, $made++) {
                        $owner = $ids[$made % count($ids)];
                        Contact::query()->create(['type' => 'person', 'first_name' => 'Test', 'last_name' => 'Person '.($made + 1),
                            'emails' => [['address' => 'person'.($made + 1).'@loadtest.invalid', 'label' => 'personal', 'primary' => true]],
                            'phones' => [['e164' => '+1512555'.str_pad((string) (100 + ($made % 100)), 4, '0', STR_PAD_LEFT), 'label' => 'mobile', 'primary' => true, 'can_text' => false]],
                            'origin' => Origin::Personal, 'source' => [Source::Website, Source::Zillow, Source::Sphere, Source::Referral][$made % 4],
                            'lifecycle' => [Lifecycle::Lead, Lifecycle::Lead, Lifecycle::Sphere][$made % 3], 'owner_user_id' => $owner, 'created_by' => $owner]);
                    }
                });
                $this->output->write("\r{$made} contacts");
            }
            $this->newLine();
            $today = CarbonImmutable::today();
            for ($d = 1; $d <= $dealCount; $d++) {
                $closed = $d % 3 === 0;
                Deal::query()->create(['type' => $d % 2 === 0 ? 'buyer' : 'listing', 'status' => $closed ? 'closed' : ($d % 3 === 1 ? 'pre_contract' : 'under_contract'),
                    'agent_user_id' => $ids[$d % count($ids)], 'street' => $d.' Test St', 'city' => 'Austin', 'state_code' => 'TX', 'price_cents' => 35_000_000 + ($d % 50) * 1_000_000,
                    'gci_cents' => $closed ? 1_050_000 : 0, 'closed_on' => $closed ? $today->subDays($d % 365)->toDateString() : null, 'created_by' => $ids[$d % count($ids)]]);
            }
            $this->info("{$dealCount} deals.");
        });

        return self::SUCCESS;
    }
}
