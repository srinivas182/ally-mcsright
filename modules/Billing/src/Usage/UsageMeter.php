<?php

declare(strict_types=1);

namespace Modules\Billing\Usage;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Credits\RateCard;
use Modules\Billing\Jobs\TopUpCredits;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\CrossTenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Monthly allowances for texts, call minutes and website map views (specs/billing/03). Each package includes some;
 * use beyond that draws RightAlly credits from the brokerage pool at HQ's price (texts per segment, calls per
 * minute, map views per 10). Owners are told at 80% and 100% of an allowance; HQ is told when a brokerage passes
 * its map allowance.
 */
#[CrossTenantAccess('Emails RightAlly HQ staff when a brokerage passes its website map allowance')]
final class UsageMeter
{
    public const METERS = [
        'texts' => ['column' => 'included_text_segments', 'action' => 'usage.text_segment', 'per' => 1, 'label' => 'texts'],
        'calls' => ['column' => 'included_call_minutes', 'action' => 'usage.call_minute', 'per' => 1, 'label' => 'call minutes'],
        'maps' => ['column' => 'included_map_views', 'action' => 'usage.map_views_10', 'per' => 10, 'label' => 'website map views'],
    ];

    public const ALERTS = [80, 100];

    /** @var array<string, int>|null */
    private ?array $allowances = null;

    public function __construct(private readonly TenantContext $tenants, private readonly CreditLedger $ledger, private readonly RateCard $rates) {}

    /** This month's allowance for a meter, from the brokerage's current package. */
    public function allowance(string $meter): int
    {
        if ($this->allowances === null) {
            $row = DB::connection(Connections::control())->table('billing_tenant_subscriptions')
                ->join('billing_packages', 'billing_packages.id', '=', 'billing_tenant_subscriptions.package_id')
                ->where('billing_tenant_subscriptions.tenant_id', $this->tenants->id())->whereIn('billing_tenant_subscriptions.status', ['active', 'grace'])
                ->orderByDesc('billing_tenant_subscriptions.id')
                ->first(['billing_packages.included_text_segments', 'billing_packages.included_call_minutes', 'billing_packages.included_map_views']);
            $this->allowances = [];
            foreach (self::METERS as $key => $m) {
                $this->allowances[$key] = $row !== null ? (int) $row->{$m['column']} : 0;
            }
        }

        return $this->allowances[$meter] ?? 0;
    }

    /**
     * Throws when sending this much more would need credits the brokerage doesn't have (only while credits are
     * enforced). Used before messages a person sends; system alerts are never held back.
     *
     * @throws InsufficientCredits
     */
    public function ensureAvailable(string $meter, int $units): void
    {
        $credits = $this->creditsFor($meter, $units);
        if ($credits > 0 && $this->rates->enforced() && $this->ledger->poolSummary()['available'] < $credits) {
            throw new InsufficientCredits('Your brokerage has used this month\'s included '.self::METERS[$meter]['label'].' and is out of RightAlly Credits. Add credits to keep sending.');
        }
    }

    /**
     * Counts use. Anything beyond the allowance is charged in credits to the brokerage pool (always, even when the
     * pool is short: the message has already gone out).
     */
    public function record(string $meter, int $units): void
    {
        if ($units === 0 || ! isset(self::METERS[$meter])) {
            return;
        }
        $period = CreditLedger::period();
        $allowance = $this->allowance($meter);
        $db = DB::connection(Connections::cell());
        [$before, $after] = $db->transaction(function () use ($db, $period, $meter, $units, $allowance): array {
            $db->table('usage_meters')->insertOrIgnore(['tenant_id' => $this->tenants->id(), 'period' => $period, 'meter' => $meter, 'used' => 0, 'over' => 0,
                'created_at' => now(), 'updated_at' => now()]);
            $row = $db->table('usage_meters')->where('tenant_id', $this->tenants->id())->where('period', $period)->where('meter', $meter)->lockForUpdate()->first();
            $before = (int) ($row->used ?? 0);
            $after = max(0, $before + $units);
            $db->table('usage_meters')->where('tenant_id', $this->tenants->id())->where('period', $period)->where('meter', $meter)
                ->update(['used' => $after, 'over' => max(0, $after - $allowance), 'updated_at' => now()]);

            return [$before, $after];
        });

        $per = self::METERS[$meter]['per'];
        $chunks = intdiv(max(0, $after - $allowance), $per) - intdiv(max(0, $before - $allowance), $per);
        if ($chunks > 0 && ! ($meter === 'maps' && $this->mapsCovered())) {
            $this->ledger->charge(self::METERS[$meter]['action'], null, 'usage:'.$meter.':'.Str::ulid(), $chunks, overdraw: true);
        }
        $this->alert($meter, $before, $after, $allowance);
    }

    /**
     * A website visitor opening the map: allowed within the allowance, when HQ covers it, or while credits last.
     * When the brokerage has run out (and its automatic top-up couldn't help), its map pauses: only for that
     * brokerage, nothing else on the site changes, and every visitor who tried is counted to show the owner
     * what the pause costs them. The map comes back as soon as credits are added.
     */
    public function openMap(): bool
    {
        $used = $this->used('maps');
        $allowance = $this->allowance('maps');
        if ($used >= $allowance && ! $this->mapsCovered() && $this->rates->enforced()) {
            $next = $this->rates->rate(self::METERS['maps']['action'])['credits'];
            if ($this->ledger->poolSummary()['available'] < $next) {
                $this->missed();

                return false;
            }
        }
        $this->record('maps', 1);
        $this->resume();

        return true;
    }

    /**
     * The brokerage's paused map, if any: since when and how many visitors tried to open it.
     *
     * @return array{since: string, missed: int}|null
     */
    public function mapsPaused(): ?array
    {
        $row = DB::connection(Connections::cell())->table('usage_meters')->where('tenant_id', $this->tenants->id())->where('period', CreditLedger::period())
            ->where('meter', 'maps')->whereNotNull('paused_at')->first(['paused_at', 'missed']);

        return $row !== null ? ['since' => (string) $row->paused_at, 'missed' => (int) $row->missed] : null;
    }

    private function missed(): void
    {
        $db = DB::connection(Connections::cell());
        $period = CreditLedger::period();
        $db->table('usage_meters')->insertOrIgnore(['tenant_id' => $this->tenants->id(), 'period' => $period, 'meter' => 'maps', 'used' => 0, 'over' => 0,
            'created_at' => now(), 'updated_at' => now()]);
        $where = fn ($q) => $q->where('tenant_id', $this->tenants->id())->where('period', $period)->where('meter', 'maps');
        $started = $db->table('usage_meters')->where($where)->whereNull('paused_at')->update(['paused_at' => now(), 'missed' => 1, 'updated_at' => now()]);
        if ($started === 0) {
            $db->table('usage_meters')->where($where)->increment('missed');

            return;
        }
        TopUpCredits::dispatch($this->tenants->id(), null); // a saved card may still bring it back
        $owners = User::query()->where('kind', UserKind::Owner)->get()->filter(fn (User $u): bool => $u->isActive());
        Notification::send($owners, new MapsPaused(1, CarbonImmutable::now()->toDateString(), false));
    }

    private function resume(): void
    {
        DB::connection(Connections::cell())->table('usage_meters')->where('tenant_id', $this->tenants->id())->where('period', CreditLedger::period())
            ->where('meter', 'maps')->whereNotNull('paused_at')->update(['paused_at' => null, 'missed' => 0, 'updated_at' => now()]);
    }

    public function used(string $meter, ?string $period = null): int
    {
        return (int) DB::connection(Connections::cell())->table('usage_meters')->where('tenant_id', $this->tenants->id())
            ->where('period', $period ?? CreditLedger::period())->where('meter', $meter)->value('used');
    }

    /**
     * This month's allowances and use, for the menu and the credits page.
     *
     * @return array<string, array{used: int, included: int, label: string}>
     */
    public function summary(): array
    {
        $rows = DB::connection(Connections::cell())->table('usage_meters')->where('tenant_id', $this->tenants->id())->where('period', CreditLedger::period())
            ->pluck('used', 'meter');
        $out = [];
        foreach (self::METERS as $key => $m) {
            $out[$key] = ['used' => (int) ($rows[$key] ?? 0), 'included' => $this->allowance($key), 'label' => $m['label']];
        }

        return $out;
    }

    public function mapsCovered(): bool
    {
        return (bool) DB::connection(Connections::control())->table('usage_tenant_policies')->where('tenant_id', $this->tenants->id())->value('maps_covered');
    }

    /** Credits that $units more would cost, given what is already used this month. */
    private function creditsFor(string $meter, int $units): int
    {
        $allowance = $this->allowance($meter);
        $used = $this->used($meter);
        $per = self::METERS[$meter]['per'];
        $chunks = intdiv(max(0, $used + $units - $allowance), $per) - intdiv(max(0, $used - $allowance), $per);

        return $chunks * $this->rates->rate(self::METERS[$meter]['action'])['credits'];
    }

    private function alert(string $meter, int $before, int $after, int $allowance): void
    {
        if ($allowance <= 0) {
            return;
        }
        foreach (self::ALERTS as $pct) {
            $line = (int) ceil($allowance * $pct / 100);
            if ($before < $line && $after >= $line) {
                $owners = User::query()->where('kind', UserKind::Owner)->get()->filter(fn (User $u): bool => $u->isActive());
                Notification::send($owners, new AllowanceUsed(self::METERS[$meter]['label'], $pct, $after, $allowance,
                    $this->rates->rate(self::METERS[$meter]['action'])['credits'], self::METERS[$meter]['per']));
                if ($meter === 'maps' && $pct === 100) {
                    $this->tellHq();
                }
            }
        }
    }

    private function tellHq(): void
    {
        $tenant = $this->tenants->current();
        $hq = CrossTenant::query(User::class, $this)->where('kind', UserKind::HqStaff)->where('status', 'active')->get();
        Notification::send($hq, new MapAllowanceReached($tenant->name, $tenant->ulid, $this->allowance('maps'), CarbonImmutable::now()->format('F')));
    }
}
