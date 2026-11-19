<?php

declare(strict_types=1);

namespace Modules\Billing\Builder;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\BrokeragePayments;
use Modules\Billing\Credits\Economics;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\Models\Package;
use Modules\Billing\Usage\UsageMeter;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\TenantContext;
use stdClass;

/**
 * PB2: when a brokerage or solo agent needs more than its package, without changing package.
 *
 * - Allowance add-ons: more texts, call minutes or website map views every month.
 * - Module add-ons: one extra module (for example AI voice calls) on a smaller package.
 * - Prices are suggested at cost × (1 + HQ's markup, 70%), rounded to end in 9; below cost + 40% needs a reason.
 * - Bought by the owner, on straight away, billed each month on the subscription's invoice; stopping keeps it until
 *   the end of the month.
 * - If what they pay for add-ons reaches the difference to the next package up, they are told the upgrade is cheaper.
 * - Beyond the package and add-ons, use still goes on RightAlly Credits (as before).
 */
final class AddOns
{
    /** Allowance add-ons: meter => [usage key in the cost list, plain name]. */
    public const ALLOWANCES = ['texts' => ['twilio.sms_segment', 'texts'], 'calls' => ['twilio.call_minute', 'call minutes'], 'maps' => ['mapbox.map_load', 'website map views']];

    public function __construct(private readonly TenantContext $tenants, private readonly PackageQuote $quotes, private readonly Economics $economics,
        private readonly AuditLogger $audit) {}

    /** What an add-on costs RightAlly a month, in cents (a module for the package's people). */
    public function costCents(string $kind, string $key, int $amount, string $audience, int $seats = 5): int
    {
        if ($kind === 'allowance') {
            $item = self::ALLOWANCES[$key][0] ?? null;
            if ($item === null) {
                throw ValidationException::withMessages(['key' => 'Choose texts, call minutes or website map views.']);
            }

            return (int) ceil(($this->economics->catalogCosts()[$item] ?? 0) * max(0, $amount) / 1_000_000);
        }
        if (! isset(ModuleCatalog::for($audience)[$key])) {
            throw ValidationException::withMessages(['key' => 'Choose a module offered to '.($audience === 'agent' ? 'solo agents' : 'brokerages').'.']);
        }
        foreach ($this->quotes->quote($audience, [$key], $seats)['lines'] as $line) {
            if ($line['key'] === $key) {
                return (int) $line['cost_cents'];
            }
        }

        return 0;
    }

    /** The suggested monthly price: cost plus HQ's markup and card fees, ending in 9. */
    public function suggestedCents(int $costCents): int
    {
        $rules = PackageQuote::rules();
        $before = $costCents * (100 + $rules['markup']) / 100;
        $fees = $before * $rules['card_fee_bp'] / 10_000 + $rules['card_fee_fixed_cents'];

        return PackageQuote::roundPrice((int) ceil(($costCents + $fees) * (100 + $rules['markup']) / 100 / 100));
    }

    /**
     * HQ: create or change an add-on.
     *
     * @param  array{kind: string, key: string, amount?: int, name: string, audience: string, price_cents?: int|null, reason?: string|null, active?: bool}  $d
     */
    public function save(?string $ulid, array $d, User $by): string
    {
        $audience = in_array($d['audience'], ['brokerage', 'agent', 'both'], true) ? $d['audience'] : 'both';
        $amount = $d['kind'] === 'allowance' ? max(1, (int) ($d['amount'] ?? 0)) : 0;
        $cost = $this->costCents($d['kind'], $d['key'], $amount, $audience === 'agent' ? 'agent' : 'brokerage', $audience === 'agent' ? 1 : 5);
        $price = isset($d['price_cents']) ? max(0, (int) $d['price_cents']) : $this->suggestedCents($cost);
        $min = PackageQuote::rules()['min_markup'];
        $below = $cost > 0 && $price * 100 < $cost * (100 + $min);
        if ($below && trim((string) ($d['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['price' => 'This price is below cost plus '.$min.'%. Write why, or raise the price.']);
        }
        $values = ['kind' => $d['kind'], 'key' => $d['key'], 'amount' => $amount, 'name' => mb_substr(trim($d['name']), 0, 80), 'price_cents' => $price, 'audience' => $audience,
            'price_reason' => $below ? mb_substr(trim((string) ($d['reason'] ?? '')), 0, 300) : null, 'active' => $d['active'] ?? true, 'updated_by' => $by->id, 'updated_at' => now()];
        $db = DB::connection(Connections::control())->table('billing_addons');
        if ($ulid !== null && $db->clone()->where('ulid', $ulid)->exists()) {
            $db->clone()->where('ulid', $ulid)->update($values);
        } else {
            $ulid = (string) Str::ulid();
            $db->clone()->insert($values + ['ulid' => $ulid, 'created_at' => now()]);
        }
        $this->audit->record('billing.addon.saved', metadata: ['addon' => $ulid, 'price_cents' => $price, 'cost_cents' => $cost], severity: 'notice');

        return $ulid;
    }

    /**
     * HQ's list, with today's cost for each.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values(DB::connection(Connections::control())->table('billing_addons')->orderBy('kind')->orderBy('price_cents')->get()->map(function (object $a): array {
            $cost = $this->costCents((string) $a->kind, (string) $a->key, (int) $a->amount, $a->audience === 'agent' ? 'agent' : 'brokerage', $a->audience === 'agent' ? 1 : 5);

            return ['ulid' => (string) $a->ulid, 'kind' => (string) $a->kind, 'key' => (string) $a->key, 'amount' => (int) $a->amount, 'name' => (string) $a->name,
                'price_cents' => (int) $a->price_cents, 'audience' => (string) $a->audience, 'active' => (bool) $a->active, 'cost_cents' => $cost,
                'buyers' => DB::connection(Connections::control())->table('tenant_addons')->where('addon_id', $a->id)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->count()];
        })->all());
    }

    /**
     * The owner's choices: add-ons offered to them, which they have, and whether upgrading would be cheaper.
     *
     * @return array{offers: list<array<string, mixed>>, upgrade: array{name: string, monthly_cents: int, saves_cents: int}|null}
     */
    public function forOwner(): array
    {
        $audience = $this->audience();
        $mine = [];
        foreach ($this->active() as $row) {
            $mine[(int) $row->addon_id] = $row->ends_at !== null ? CarbonImmutable::parse((string) $row->ends_at)->toDateString() : null;
        }
        $package = $this->package();
        $offers = DB::connection(Connections::control())->table('billing_addons')->where('active', true)->whereIn('audience', [$audience, 'both'])->orderBy('kind')->orderBy('price_cents')->get()
            ->reject(fn (object $a): bool => $a->kind === 'module' && $package !== null && in_array((string) $a->key, $package->getAttribute('modules') ?? [], true))
            ->map(fn (object $a): array => ['ulid' => (string) $a->ulid, 'name' => (string) $a->name, 'kind' => (string) $a->kind, 'price_cents' => (int) $a->price_cents,
                'about' => $a->kind === 'module' ? (ModuleCatalog::MODULES[(string) $a->key]['about'] ?? '') : number_format((int) $a->amount).' more '.(self::ALLOWANCES[(string) $a->key][1] ?? '').' every month',
                'have' => array_key_exists((int) $a->id, $mine), 'ends_at' => $mine[(int) $a->id] ?? null])
            ->values()->all();

        return ['offers' => array_values($offers), 'upgrade' => $this->upgrade()];
    }

    /** The owner buys an add-on: on straight away, billed with the subscription each month. */
    public function buy(User $owner, string $ulid): void
    {
        $this->owner($owner);
        if ((app(BrokeragePayments::class)->current()['customer'] ?? null) === null) {
            throw ValidationException::withMessages(['addon' => 'Choose a package and add a card first (Billing), then add extras.']);
        }
        $a = DB::connection(Connections::control())->table('billing_addons')->where('ulid', $ulid)->where('active', true)->whereIn('audience', [$this->audience(), 'both'])->first();
        if ($a === null) {
            throw ValidationException::withMessages(['addon' => 'That extra is not available.']);
        }
        if ($this->active()->contains(fn (stdClass $r): bool => (int) $r->addon_id === (int) $a->id)) {
            throw ValidationException::withMessages(['addon' => 'You already have this extra.']);
        }
        DB::connection(Connections::control())->table('tenant_addons')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'addon_id' => $a->id,
            'price_cents' => (int) $a->price_cents, 'started_at' => now(), 'ends_at' => null, 'added_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        app(UsageMeter::class)->forget();
        $this->audit->record('billing.addon.bought', $owner, metadata: ['addon' => $ulid, 'price_cents' => (int) $a->price_cents], severity: 'notice');
    }

    /** Stop an add-on: it stays on until the end of this month. */
    public function stop(User $owner, string $ulid): void
    {
        $this->owner($owner);
        $a = DB::connection(Connections::control())->table('billing_addons')->where('ulid', $ulid)->first();
        if ($a !== null) {
            DB::connection(Connections::control())->table('tenant_addons')->where('tenant_id', $this->tenants->id())->where('addon_id', $a->id)->whereNull('ends_at')
                ->update(['ends_at' => CarbonImmutable::now()->endOfMonth(), 'updated_at' => now()]);
            $this->audit->record('billing.addon.stopped', $owner, metadata: ['addon' => $ulid], severity: 'notice');
        }
    }

    /** Extra allowance this month from add-ons, for one meter (texts, calls, maps). */
    public function extraAllowance(string $meter): int
    {
        $ids = $this->active()->pluck('addon_id');

        return $ids->isEmpty() ? 0 : (int) DB::connection(Connections::control())->table('billing_addons')->whereIn('id', $ids)->where('kind', 'allowance')->where('key', $meter)->sum('amount');
    }

    /**
     * Plan features switched on by module add-ons.
     *
     * @return list<string>
     */
    public function extraFeatures(): array
    {
        $ids = $this->active()->pluck('addon_id');
        $features = [];
        foreach (DB::connection(Connections::control())->table('billing_addons')->whereIn('id', $ids)->where('kind', 'module')->pluck('key') as $key) {
            array_push($features, ...(ModuleCatalog::MODULES[(string) $key]['features'] ?? []));
        }

        return array_values(array_unique($features));
    }

    /** Monthly: add this month's add-ons to the brokerage's invoice, once. Returns the cents billed. */
    public function bill(?CarbonImmutable $month = null): int
    {
        $month ??= CarbonImmutable::now()->startOfMonth();
        $customer = app(BrokeragePayments::class)->current()['customer'] ?? null;
        $key = 'addons:'.$this->tenants->id().':'.$month->format('Y-m');
        $events = DB::connection(Connections::control())->table('billing_gateway_events');
        if ($customer === null || $events->clone()->where('id', $key)->exists()) {
            return 0;
        }
        $total = 0;
        foreach ($this->active($month) as $row) {
            /** @var stdClass $row */
            $name = (string) DB::connection(Connections::control())->table('billing_addons')->where('id', $row->addon_id)->value('name');
            app(PaymentGateway::class)->addInvoiceItem($customer, (int) $row->price_cents, 'RightAlly extra: '.$name.', '.$month->format('F Y'));
            $total += (int) $row->price_cents;
        }
        $events->clone()->insert(['id' => $key, 'type' => 'addon_run', 'processed_at' => now()]);

        return $total;
    }

    /** @return Collection<int, stdClass> this brokerage's add-ons on during the month (default: now) */
    private function active(?CarbonImmutable $month = null): Collection
    {
        $at = $month ?? CarbonImmutable::now();

        return DB::connection(Connections::control())->table('tenant_addons')->where('tenant_id', $this->tenants->id())->where('started_at', '<=', $month !== null ? $at->endOfMonth() : $at)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at))->get();
    }

    /** @return array{name: string, monthly_cents: int, saves_cents: int}|null the next package up, when it costs less than package + add-ons */
    private function upgrade(): ?array
    {
        $package = $this->package();
        if ($package === null) {
            return null;
        }
        $extras = (int) $this->active()->sum('price_cents');
        $next = Package::query()->where('active', true)->where('audience', $this->audience())->where('monthly_cents', '>', $package->monthly_cents)->orderBy('monthly_cents')->first();
        if ($next === null || $extras <= 0 || $package->monthly_cents + $extras < $next->monthly_cents) {
            return null;
        }

        return ['name' => $next->name, 'monthly_cents' => $next->monthly_cents, 'saves_cents' => $package->monthly_cents + $extras - $next->monthly_cents];
    }

    private function package(): ?Package
    {
        $id = $this->tenants->current()->getAttribute('billing_package_id');

        return $id !== null ? Package::query()->find((int) $id) : null;
    }

    private function audience(): string
    {
        return $this->tenants->current()->type === TenantType::Solo ? 'agent' : 'brokerage';
    }

    private function owner(User $user): void
    {
        if (! $user->isKind(UserKind::Owner)) {
            throw ValidationException::withMessages(['addon' => 'Only the owner can change extras.']);
        }
    }
}
