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
use Modules\Core\Identity\Models\Invitation;
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
use Modules\Roster\Brokerage\BrokerageLicences;
use Modules\Sites\SiteSetup;
use Modules\Sites\Support\Designs;
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

    /** SU2: the steps, in order (1 to 4), and what each is called on HQ's list. */
    public const STEPS = [1 => 'You and your boards', 2 => 'Package', 3 => 'Website design', 4 => 'Review and pay'];

    /**
     * SU2 step 1: who they are, their address and their MLS boards. Starts a draft (replacing any earlier unfinished
     * signup with the same email) or updates the one they are filling in.
     *
     * @param  array{brokerage: string, slug: string, owner_name: string, owner_email: string, state?: string|null, agents?: int|null, boards?: list<string>, licences?: list<array<string, mixed>>, phone?: string, health_check_id?: int|null, ref?: string|null}  $in
     */
    public function saveAbout(?string $draft, array $in, string $type = 'brokerage'): string
    {
        $type = $type === 'solo' ? 'solo' : 'brokerage';
        $db = DB::connection(Connections::control());
        $email = mb_strtolower(trim($in['owner_email']));
        $slug = mb_strtolower(trim($in['slug']));
        $row = $draft !== null ? $db->table('signups')->where('ulid', $draft)->whereIn('status', ['draft', 'pending'])->first() : null;
        if (($problem = self::slugProblem($slug, $email, $row?->ulid)) !== null) {
            throw ValidationException::withMessages(['slug' => $problem]);
        }
        $values = ['type' => $type, 'brokerage' => mb_substr(trim($in['brokerage']), 0, 150), 'slug' => $slug, 'owner_name' => mb_substr(trim($in['owner_name']), 0, 120),
            'owner_email' => $email, 'state' => isset($in['state']) && $in['state'] !== '' ? strtoupper($in['state']) : null, 'agents' => $in['agents'] ?? null,
            'idx_boards' => self::chosenBoards($in['boards'] ?? []), 'updated_at' => now()]
            + (isset($in['licences']) ? ['licences' => json_encode($in['licences'])] : [])
            + (isset($in['phone']) && $in['phone'] !== '' ? ['phone' => mb_substr($in['phone'], 0, 30)] : []);
        if ($row !== null) {
            $db->table('signups')->where('id', $row->id)->update($values + ['status' => 'draft', 'step' => max(2, (int) $row->step)]);

            return (string) $row->ulid;
        }
        $db->table('signups')->where('owner_email', $email)->whereIn('status', ['draft', 'pending'])->update(['status' => 'replaced', 'updated_at' => now()]);
        $ulid = (string) Str::ulid();
        $db->table('signups')->insert($values + ['ulid' => $ulid, 'status' => 'draft', 'step' => 2, 'health_check_id' => $in['health_check_id'] ?? null,
            'referral_code' => isset($in['ref']) && preg_match('/^[a-z0-9]{3,24}$/', (string) $in['ref']) === 1 ? (string) $in['ref'] : null, 'created_at' => now()]);

        return $ulid;
    }

    /** SU2 step 2: the package and how often they pay. */
    public function savePackage(string $draft, string $package, string $interval): void
    {
        $row = $this->draft($draft);
        $audience = $row->type === 'solo' ? 'agent' : 'brokerage';
        $p = Package::query()->where('ulid', $package)->where('active', true)->where('audience', $audience)->first()
            ?? throw ValidationException::withMessages(['package' => 'Choose one of the packages.']);
        DB::connection(Connections::control())->table('signups')->where('id', $row->id)->update(['package_id' => $p->id, 'interval' => $interval === 'year' ? 'year' : 'month',
            'status' => 'draft', 'step' => max(3, (int) $row->step), 'updated_at' => now()]);
    }

    /** LV1: the licences given at sign-up as the website shows them ("TX 0123456"): active ones (and ones HQ is checking). */
    public static function licenceLine(stdClass $signup): string
    {
        return collect((array) json_decode((string) ($signup->licences ?? '[]'), true))->filter(fn ($l): bool => is_array($l) && in_array($l['status'] ?? '', ['active', 'unchecked'], true))
            ->map(fn (array $l): string => $l['state'].' '.$l['number'])->implode(', ');
    }

    /** LP1: no MLS board chosen, so the website is a landing page (no listings) until a board is added. */
    public static function landingOnly(stdClass $signup): bool
    {
        return array_filter((array) json_decode((string) ($signup->idx_boards ?? '[]'), true), 'is_string') === [];
    }

    /** SU2 step 3: the website design their RightAlly website starts in. */
    public function saveDesign(string $draft, string $template, bool $matchBoards = true): void
    {
        $row = $this->draft($draft);
        // LP1: with an MLS board, a full website design; without one, a landing page design.
        $landing = self::landingOnly($row);
        if (! Designs::exists($template) || ($matchBoards && Designs::isLanding($template) !== $landing)) {
            throw ValidationException::withMessages(['template' => 'Choose a design.']);
        }
        DB::connection(Connections::control())->table('signups')->where('id', $row->id)->update(['template' => $template, 'status' => 'draft',
            'step' => max(4, (int) $row->step), 'updated_at' => now()]);
    }

    /** SU2 step 4: everything is chosen; returns the payment page's address. */
    public function checkout(string $draft, string $returnBase): string
    {
        $row = $this->draft($draft);
        $package = Package::query()->whereKey((int) $row->package_id)->where('active', true)->first();
        if ($package === null || $row->interval === null) {
            throw ValidationException::withMessages(['package' => 'Choose one of the packages.']);
        }
        if (($problem = self::slugProblem((string) $row->slug, (string) $row->owner_email, (string) $row->ulid)) !== null) {
            throw ValidationException::withMessages(['slug' => $problem]);
        }
        if (! $this->open($row->type === 'solo' ? 'agent' : 'brokerage')) {
            throw ValidationException::withMessages(['package' => 'Online signup is not open yet. Ask for a call and we will set you up.']);
        }
        $price = $row->interval === 'year' ? $package->yearly_cents : $package->monthly_cents;
        DB::connection(Connections::control())->table('signups')->where('id', $row->id)->update(['status' => 'pending', 'updated_at' => now()]);

        return $this->gateway->subscriptionCheckout((string) $row->owner_email, "RightAlly {$package->name} — ".$row->brokerage, $price, (string) $row->interval,
            $returnBase.'/start/welcome/'.$row->ulid, $returnBase.($row->type === 'solo' ? '/agents' : '/start').'?cancelled=1',
            ['kind' => 'signup', 'signup' => (string) $row->ulid, 'package_id' => (string) $package->id, 'interval' => (string) $row->interval, 'price_cents' => (string) $price],
            $this->settings->automaticTax());
    }

    /** A signup still being filled in (or waiting at the payment page). */
    private function draft(string $ulid): stdClass
    {
        return DB::connection(Connections::control())->table('signups')->where('ulid', $ulid)->whereIn('status', ['draft', 'pending'])->first()
            ?? throw ValidationException::withMessages(['signup' => 'This signup has expired. Please start again.']);
    }

    /**
     * Saves the signup and returns the payment page's address.
     *
     * A solo agent's signup (type "solo") creates a RightAlly of their own; its name is the agent's or team's name.
     *
     * @param  array{brokerage: string, slug: string, owner_name: string, owner_email: string, state?: string|null, agents?: int|null, package: string, interval: string, health_check_id?: int|null, ref?: string|null, boards?: list<string>, template?: string}  $in
     */
    public function begin(array $in, string $returnBase, string $type = 'brokerage'): string
    {
        $draft = $this->saveAbout(null, $in, $type);
        $this->savePackage($draft, $in['package'], $in['interval']);
        $this->saveDesign($draft, $in['template'] ?? 'modern', false);

        return $this->checkout($draft, $returnBase);
    }

    /**
     * EM1: HQ corrects the owner's email on a sign-up (typed wrong, or the email never arrived). Before payment only the
     * sign-up changes. After payment the sign-in email goes to the new address (the link sent to the old one stops
     * working), as long as the owner has not signed in yet; once they have, they change it themselves in their profile.
     * Returns 'changed', 'sent', 'signed-in' or 'taken'.
     */
    public function changeEmail(stdClass $signup, string $email): string
    {
        $email = mb_strtolower(trim($email));
        $old = mb_strtolower((string) $signup->owner_email);
        $db = DB::connection(Connections::control());
        if ($signup->status !== 'live') {
            $db->table('signups')->where('id', $signup->id)->update(['owner_email' => $email, 'updated_at' => now()]);

            return 'changed';
        }
        $tenant = Tenant::query()->where('slug', (string) $signup->slug)->first();
        if ($tenant === null) {
            return 'taken';
        }
        $result = $this->tenants->runAs($tenant, function () use ($old, $email, $signup): string {
            if (User::query()->whereRaw('lower(email) = ?', [$old])->exists()) {
                return 'signed-in';
            }
            if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                return 'taken';
            }
            $open = Invitation::query()->whereRaw('lower(email) = ?', [$old])->whereNull('accepted_at')->latest('id')->first();
            if ($open !== null && $open->revoked_at === null) {
                app(ManageInvitations::class)->revoke($open, null);
            }
            [$first, $last] = array_pad(explode(' ', trim((string) $signup->owner_name), 2), 2, '');
            app(ManageInvitations::class)->invite($email, $first, $last, UserKind::Owner, null);

            return 'sent';
        });
        if ($result === 'sent') {
            $db->table('signups')->where('id', $signup->id)->update(['owner_email' => $email, 'updated_at' => now()]);
        }

        return $result;
    }

    /**
     * QW1: sends the owner of a paid sign-up their sign-in email again (a fresh link; the old one stops working).
     * 'sent', 'signed-in' when they already set a password (they use "Forgot your password?"), or 'none' when the
     * brokerage is not set up yet.
     */
    public function resendWelcome(stdClass $signup): string
    {
        $tenant = $signup->status === 'live' ? Tenant::query()->where('slug', (string) $signup->slug)->first() : null;
        if ($tenant === null) {
            return 'none';
        }

        return $this->tenants->runAs($tenant, function () use ($signup): string {
            $email = mb_strtolower((string) $signup->owner_email);
            $open = Invitation::query()->whereRaw('lower(email) = ?', [$email])->whereNull('accepted_at')->latest('id')->first();
            if ($open !== null) {
                app(ManageInvitations::class)->resend($open, null);

                return 'sent';
            }

            return User::query()->whereRaw('lower(email) = ?', [$email])->exists() ? 'signed-in' : 'none';
        });
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
        app(GoLive::class)->start($tenant);
        // LV2: the licences become the brokerage's own (Settings › Licences); without a good one the website waits.
        $licences = array_values(array_filter((array) json_decode((string) ($signup->licences ?? '[]'), true), 'is_array'));
        $holdWebsite = $licences !== [] && array_filter($licences, [LicenceCheck::class, 'allowsBoards']) === [];
        if ($licences !== [] && class_exists(BrokerageLicences::class)) {
            $this->tenants->runAs($tenant, function () use ($licences, $signup): void {
                foreach ($licences as $l) {
                    app(BrokerageLicences::class)->save((string) $l['state'], (string) $l['number'], (string) $signup->owner_name, (string) $l['status'], isset($l['expires_on']) ? (string) $l['expires_on'] : null);
                }
            });
        }
        // SU2: the website goes live at once in the design chosen while signing up (sample listings until a board is approved).
        if (class_exists(SiteSetup::class)) {
            try {
                $this->tenants->runAs($tenant, fn () => app(SiteSetup::class)->launch((string) ($signup->template ?? 'modern'),
                    ['name' => (string) $signup->brokerage, 'email' => (string) $signup->owner_email, 'licence' => self::licenceLine($signup), 'phone' => (string) ($signup->phone ?? ''),
                        'area' => self::marketArea((string) ($signup->idx_boards ?? '[]'), $signup->state),
                        'state' => self::marketState((string) ($signup->idx_boards ?? '[]'), $signup->state)], $holdWebsite ? 'draft' : 'live')); // TP2: its Explore cities; LV2: "coming soon" without a good licence
            } catch (Throwable $e) {
                report($e); // the owner can still create it from Settings › Website
            }
        }
        // IDX7: the MLS boards chosen at sign-up start on demo listings straight away.
        foreach ((array) json_decode((string) ($signup->idx_boards ?? '[]'), true) as $board) {
            try {
                app(Subscriptions::class)->add($tenant->id, (string) $board, null);
            } catch (Throwable $e) {
                report($e); // a board closed in the meantime: the brokerage can add another from Settings › MLS
            }
        }
        // WS1: the welcome email goes last, so it can give the website's address as well as the back office's.
        $this->tenants->runAs($tenant, function () use ($signup): void {
            [$first, $last] = array_pad(explode(' ', trim((string) $signup->owner_name), 2), 2, '');
            app(ManageInvitations::class)->invite((string) $signup->owner_email, $first, $last, UserKind::Owner, null);
        });
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

    /**
     * Why an address can't be used, or null if it is free. An address someone is paying for right now is held for an
     * hour, except for that same person (by email), so starting again after a cancelled payment keeps their address.
     */
    public static function slugProblem(string $slug, ?string $email = null, ?string $own = null): ?string
    {
        if (($problem = Slug::problem($slug)) !== null) {
            return $problem;
        }
        if ($slug === 'demo') { // TP4: the design demo website's address
            return 'This address is reserved. Please choose another.';
        }
        $held = DB::connection(Connections::control())->table('signups')->where('slug', $slug)->whereIn('status', ['draft', 'pending'])->where('updated_at', '>', now()->subHour());
        if ($own !== null) {
            $held->where('ulid', '!=', $own);
        }
        if ($email !== null && trim($email) !== '') {
            $held->where('owner_email', '!=', mb_strtolower(trim($email)));
        }
        $taken = Tenant::withTrashed()->where('slug', $slug)->exists() || TenantDomain::query()->whereIn('host', [Host::platformHostFor($slug), self::websiteHost($slug)])->exists() || $held->exists();

        return $taken ? 'This address is already in use. Please choose another.' : null;
    }

    /** DM1: the brokerage's public website address (name.rightally.app), the one it tells clients. */
    public static function websiteHost(string $slug): string
    {
        return Host::normalise($slug.'.'.(string) config('sites.platform_domain'));
    }

    /** SU1: a free address close to the one asked for (name-2, name-3 …), or null. */
    public static function freeNear(string $slug, ?string $email = null): ?string
    {
        $base = mb_substr(trim((string) preg_replace('/-\d+$/', '', $slug), '-'), 0, 36);
        if ($base === '' || Slug::problem($base) !== null && Slug::problem($base.'-2') !== null) {
            return null;
        }
        for ($n = 2; $n <= 9; $n++) {
            if (self::slugProblem($base.'-'.$n, $email) === null) {
                return $base.'-'.$n;
            }
        }

        return null;
    }

    /** A suggested address from the brokerage's name. */
    public static function suggest(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/-+/', '-', (string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name))), '-'), 0, 40);
    }

    /** TP2: the state a new website's listings come from: the first chosen board's, else the office state. */
    public static function marketState(string $boardsJson, ?string $officeState): ?string
    {
        $ids = array_values(array_filter((array) json_decode($boardsJson, true), 'is_string'));
        $state = $ids !== [] ? DB::connection(Connections::control())->table('idx_boards')->where('ulid', $ids[0])->value('state') : null;

        return is_string($state) && $state !== '' ? $state : $officeState;
    }

    /**
     * SU3: the area a new website talks about ("Find your place in Greater Houston"): where the first MLS board they
     * chose covers (its region, or its state), or the office state when they chose no board.
     */
    public static function marketArea(string $boardsJson, ?string $officeState): string
    {
        $db = DB::connection(Connections::control());
        $ids = array_values(array_filter((array) json_decode($boardsJson, true), 'is_string'));
        try {
            if ($ids !== []) {
                $board = $db->table('idx_boards')->where('ulid', $ids[0])->first(['region', 'state']);
                if ($board !== null) {
                    $region = trim((string) ($board->region ?? ''));

                    return $region !== '' ? $region : (string) ($db->table('idx_states')->where('code', (string) $board->state)->value('name') ?? '');
                }
            }

            return (string) ($db->table('idx_states')->where('code', (string) ($officeState ?? ''))->value('name') ?? '');
        } catch (Throwable) {
            return '';
        }
    }
}
