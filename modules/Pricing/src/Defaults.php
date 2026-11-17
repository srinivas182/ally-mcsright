<?php

declare(strict_types=1);

namespace Modules\Pricing;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * The figures behind net sheets and buyer estimates (CM1): config/pricing.php by state, with the brokerage's own
 * changes on top. All are starting points marked "review": the title company and lender give the real figures.
 */
final class Defaults
{
    /** Settings the brokerage may change, with their limits. */
    public const EDITABLE = [
        'listing_commission_pct' => [0, 10], 'buyer_agent_pct' => [0, 10], 'seller_closing_pct' => [0, 10], 'buyer_closing_pct' => [0, 10],
        'property_tax_pct' => [0, 5], 'insurance_pct' => [0, 5], 'interest_rate' => [0, 20],
    ];

    public function __construct(private readonly TenantContext $tenants) {}

    public function state(): string
    {
        return strtoupper((string) ($this->tenants->current()->state_code ?? '')) ?: 'TX';
    }

    /**
     * @return array<string, float>
     */
    public function values(?string $state = null): array
    {
        $state = strtoupper($state ?? $this->state());
        $byState = fn (string $key): float => (float) (config("pricing.{$key}.{$state}") ?? config("pricing.{$key}.default"));
        $base = ['listing_commission_pct' => (float) config('pricing.listing_commission_pct'), 'buyer_agent_pct' => (float) config('pricing.buyer_agent_pct'),
            'seller_closing_pct' => $byState('seller_closing_pct'), 'buyer_closing_pct' => $byState('buyer_closing_pct'), 'property_tax_pct' => $byState('property_tax_pct'),
            'insurance_pct' => $byState('insurance_pct'), 'interest_rate' => (float) config('pricing.interest_rate')];
        $mine = DB::connection(Connections::cell())->table('pricing_settings')->where('tenant_id', $this->tenants->id())->value('values');
        foreach ((array) (is_string($mine) ? json_decode($mine, true) : []) as $k => $v) {
            if (array_key_exists((string) $k, self::EDITABLE) && is_numeric($v)) {
                $base[(string) $k] = (float) $v;
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function save(array $in): void
    {
        $out = [];
        foreach (self::EDITABLE as $k => [$min, $max]) {
            if (! array_key_exists($k, $in) || $in[$k] === null || $in[$k] === '') {
                continue;
            }
            if (! is_numeric($in[$k]) || (float) $in[$k] < $min || (float) $in[$k] > $max) {
                throw ValidationException::withMessages([$k => "Between {$min} and {$max}."]);
            }
            $out[$k] = round((float) $in[$k], 3);
        }
        DB::connection(Connections::cell())->table('pricing_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()],
            ['values' => json_encode($out), 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('pricing.defaults.saved', metadata: $out);
    }
}
