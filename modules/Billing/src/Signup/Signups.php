<?php

declare(strict_types=1);

namespace Modules\Billing\Signup;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Affiliates\Affiliates;
use Modules\Billing\BrokeragePayments;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\GoLive\GoLive;
use Modules\Billing\Models\Package;
use Modules\Billing\PaymentSettings;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Actions\ManageInvitations;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Actions\ProvisionTenant;
use Modules\Core\Tenancy\Data\ProvisionTenantData;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\Slug;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Idx\Coverage;
use Modules\Listings\Idx\Subscriptions;
use stdClass;
use Throwable;

/**
 * Self-serve signup (specs/billing/07): a brokerage picks a package at public prices and pays on the payment
 * provider's page; when the payment comes through, its RightAlly is created on its own address, the owner is invited,
 * the subscription starts and the 30-day go-live plan begins. Nothing is created before payment, and a repeated payment
 * event never creates a second brokerage.
 */
final class Signups
{
    public function __construct(private readonly PaymentGateway $gateway, private readonly PaymentSettings $settings, private readonly TenantContext $tenants) {}

    /** Whether online signup is open for brokerages or for solo agents. */
    public function open(string $audience = 'brokerage'): bool
    {
        return $this->gateway->ready() && Package::query()->where('active', true)->where('audience', $audience)->exists();
    }

    /**
     * Saves the signup and returns the payment page's address.
     *
     * A solo agent's signup (type "solo") creates a RightAlly of their own; its name is the agent's or team's name.
     *
     * @param  array{brokerage: string, slug: string, owner_name: string, owner_email: string, state?: string|null, agents?: int|null, package: string, interval: string, health_check_id?: int|null, ref?: string|null, boards?: list<string>}  $in
     */
    public function begin(array $in, string $returnBase, string $type = 'brokerage'): string
    {
        $type = $type === 'solo' ? 'solo' : 'brokerage';
        $audience = $type === 'solo' ? 'agent' : 'brokerage';
        $package = Package::query()->where('ulid', $in['package'])->where('active', true)->where('audience', $audience)->first();
        $slug = mb_strtolower(trim($in['slug']));
        if ($package === null) {
            throw ValidationException::withMessages(['package' => 'Choose one of the packages.']);
        }
        if (($problem = self::slugProblem($slug)) !== null) {
            throw ValidationException::withMessages(['slug' => $problem]);
        }
        if (! $this->open($audience)) {
            throw ValidationException::withMessages(['package' => 'Online signup is not open yet. Ask for a call and we will set you up.']);
        }
        $ulid = (string) Str::ulid();
        $interval = $in['interval'] === 'year' ? 'year' : 'month';
        $price = $interval === 'year' ? $package->yearly_cents : $package->monthly_cents;
        DB::connection(Connections::control())->table('signups')->insert([
            'ulid' => $ulid, 'type' => $type, 'brokerage' => mb_substr(trim($in['brokerage']), 0, 150), 'slug' => $slug, 'owner_name' => mb_substr(trim($in['owner_name']), 0, 120),
            'owner_email' => mb_strtolower(trim($in['owner_email'])), 'state' => isset($in['state']) && $in['state'] !== '' ? strtoupper($in['state']) : null,
            'agents' => $in['agents'] ?? null, 'package_id' => $package->id, 'interval' => $interval, 'health_check_id' => $in['health_check_id'] ?? null,
            'idx_boards' => self::chosenBoards($in['boards'] ?? []), 'status' => 'pending', 'referral_code' => isset($in['ref']) && preg_match('/^[a-z0-9]{3,24}$/', (string) $in['ref']) === 1 ? (string) $in['ref'] : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->gateway->subscriptionCheckout(mb_strtolower(trim($in['owner_email'])), "RightAlly {$package->name} — ".trim($in['brokerage']), $price, $interval,
            $returnBase.'/start/welcome/'.$ulid, $returnBase.($type === 'solo' ? '/agents' : '/start').'?cancelled=1',
            ['kind' => 'signup', 'signup' => $ulid, 'package_id' => (string) $package->id, 'interval' => $interval, 'price_cents' => (string) $price],
            $this->settings->automaticTax());
    }

    /**
     * The payment provider confirmed the first payment: the brokerage goes live. Safe to repeat.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $meta
     */
    public function paid(array $object, array $meta): void
    {
        $db = DB::connection(Connections::control());
        $signup = $db->table('signups')->where('ulid', (string) ($meta['signup'] ?? ''))->first();
        if ($signup === null || $signup->status === 'live') {
            return;
        }
        try {
            $tenant = app(ProvisionTenant::class)->handle(new ProvisionTenantData(type: $signup->type === 'solo' ? TenantType::Solo : TenantType::Brokerage, name: (string) $signup->brokerage, slug: (string) $signup->slug,
                ownerEmail: (string) $signup->owner_email, ownerName: (string) $signup->owner_name, idempotencyKey: 'signup:'.$signup->ulid, stateCode: $signup->state));
        } catch (Throwable $e) {
            // Paid, but the address was taken in the meantime (or another problem): HQ sets it up by hand and tells the broker.
            $db->table('signups')->where('id', $signup->id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'updated_at' => now()]);
            report($e);
            $hq = Tenant::query()->where('type', TenantType::Hq)->first();
            if ($hq !== null) {
                $staff = $this->tenants->runAs($hq, fn () => User::query()->where('kind', UserKind::HqStaff)->where('status', UserStatus::Active)->get());
                Notification::send($staff, new SignupProblemMail((string) $signup->brokerage, (string) $signup->owner_email, mb_substr($e->getMessage(), 0, 200)));
            }

            return;
        }
        app(BrokeragePayments::class)->apply('checkout.session.completed', $object, ['tenant_id' => (string) $tenant->id] + $meta);
        $this->tenants->runAs($tenant, function () use ($signup): void {
            [$first, $last] = array_pad(explode(' ', trim((string) $signup->owner_name), 2), 2, '');
            app(ManageInvitations::class)->invite((string) $signup->owner_email, $first, $last, UserKind::Owner, null);
        });
        app(GoLive::class)->start($tenant);
        // IDX7: the MLS boards chosen at sign-up start on demo listings straight away.
        foreach ((array) json_decode((string) ($signup->idx_boards ?? '[]'), true) as $board) {
            try {
                app(Subscriptions::class)->add($tenant->id, (string) $board, null);
            } catch (Throwable $e) {
                report($e); // a board closed in the meantime: the brokerage can add another from Settings › MLS
            }
        }
        $db->table('signups')->where('id', $signup->id)->update(['status' => 'live', 'tenant_id' => $tenant->id, 'updated_at' => now()]);
        // RF1 (specs/billing/08): a signup that came through a referral link.
        app(Affiliates::class)->attribute($signup->referral_code ?? null, $tenant, (string) $signup->owner_email);
        app(Affiliates::class)->paid($tenant->id, (int) ($object['amount_total'] ?? 0));
        app(AuditLogger::class)->record('billing.signup.live', metadata: ['brokerage' => $signup->brokerage, 'package_id' => (int) $signup->package_id, 'interval' => $signup->interval],
            severity: 'notice', tenantId: $tenant->id);
    }

    /**
     * IDX7: only boards that are offered now (open state, public, priced); never more than 20.
     *
     * @param  array<int, mixed>  $boards
     */
    private static function chosenBoards(array $boards): ?string
    {
        if ($boards === [] || ! class_exists(Coverage::class)) {
            return null;
        }
        $coverage = app(Coverage::class);
        $offered = array_column($coverage->offered($coverage->enabledStates()), 'ulid');
        $chosen = array_slice(array_intersect(array_unique(array_map('strval', $boards)), $offered), 0, 20);

        return $chosen !== [] ? (string) json_encode($chosen) : null;
    }

    /** The brokerage a signup created, for the subscription's later payment events. */
    public static function tenantFor(string $ulid): ?int
    {
        $id = DB::connection(Connections::control())->table('signups')->where('ulid', $ulid)->value('tenant_id');

        return $id !== null ? (int) $id : null;
    }

    public static function find(string $ulid): ?stdClass
    {
        return DB::connection(Connections::control())->table('signups')->where('ulid', $ulid)->first();
    }

    /** Why an address can't be used, or null if it is free. */
    public static function slugProblem(string $slug): ?string
    {
        if (($problem = Slug::problem($slug)) !== null) {
            return $problem;
        }
        $taken = Tenant::withTrashed()->where('slug', $slug)->exists() || TenantDomain::query()->where('host', Host::platformHostFor($slug))->exists()
            || DB::connection(Connections::control())->table('signups')->where('slug', $slug)->where('status', 'pending')->where('created_at', '>', now()->subHour())->exists();

        return $taken ? 'This address is already in use. Please choose another.' : null;
    }

    /** A suggested address from the brokerage's name. */
    public static function suggest(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/-+/', '-', (string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name))), '-'), 0, 40);
    }
}
