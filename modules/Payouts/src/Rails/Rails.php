<?php

declare(strict_types=1);

namespace Modules\Payouts\Rails;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Connections;

/** Finds the rail a brokerage uses and its (encrypted) settings. */
final class Rails
{
    /** @var array<string, PayoutRail> */
    private array $overrides = [];

    public function use(PayoutRail $rail): void
    {
        $this->overrides[$rail->key()] = $rail;
    }

    public function get(string $key): PayoutRail
    {
        return $this->overrides[$key] ?? match ($key) {
            'dwolla' => app(DwollaRail::class),
            'modern_treasury' => app(ModernTreasuryRail::class),
            'stripe' => app(StripeTreasuryRail::class),
            default => throw ValidationException::withMessages(['rail' => 'Unknown payout rail.']),
        };
    }

    /**
     * @return array{rail: string, status: string, same_day_default: bool, config: array<string, mixed>}
     */
    public function settings(): array
    {
        $row = DB::connection(Connections::cell())->table('pay_rail_settings')->first();

        return ['rail' => (string) ($row->rail ?? 'bank_file'), 'status' => (string) ($row->status ?? 'ready'), 'same_day_default' => (bool) ($row->same_day_default ?? false),
            'config' => $row !== null && $row->config_sealed !== null ? (array) json_decode(Crypt::decryptString((string) $row->config_sealed), true) : []];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function save(int $tenantId, string $rail, array $config, bool $sameDayDefault, string $status): void
    {
        DB::connection(Connections::cell())->table('pay_rail_settings')->updateOrInsert(['tenant_id' => $tenantId], [
            'rail' => $rail, 'status' => $status, 'same_day_default' => $sameDayDefault,
            'config_sealed' => Crypt::encryptString((string) json_encode($config)), 'updated_at' => now(), 'created_at' => now(),
        ]);
    }
}
