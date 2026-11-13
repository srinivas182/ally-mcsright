<?php

declare(strict_types=1);

namespace Modules\Payouts\Rails;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * HQ's side of payout rails: which rails are offered, and RightAlly's platform credentials where the
 * partner works that way (Dwolla). Stripe uses HQ › Payments; Modern Treasury needs none (brokerages
 * bring their own organisation). Credentials are encrypted and never shown again.
 */
final class RailPlatform
{
    public const RAILS = ['dwolla', 'modern_treasury', 'stripe'];

    /**
     * @return array<string, array{enabled: bool, mode: string, keys_set: bool}>
     */
    public function summary(): array
    {
        $rows = DB::connection(Connections::control())->table('pay_rail_platform')->get()->keyBy('rail');
        $out = [];
        foreach (self::RAILS as $rail) {
            $r = $rows[$rail] ?? null;
            $out[$rail] = ['enabled' => (bool) ($r->enabled ?? false), 'mode' => (string) ($r->mode ?? 'test'),
                'keys_set' => $r !== null && ($r->mode === 'live' ? $r->live_keys : $r->test_keys) !== null];
        }

        return $out;
    }

    public function enabled(string $rail): bool
    {
        return $rail === 'bank_file' || ($this->summary()[$rail]['enabled'] ?? false);
    }

    public function setEnabled(string $rail, bool $enabled, User $by): void
    {
        $this->guard($rail);
        DB::connection(Connections::control())->table('pay_rail_platform')->updateOrInsert(['rail' => $rail], ['enabled' => $enabled, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('payouts.rail.'.($enabled ? 'enabled' : 'disabled'), metadata: ['rail' => $rail], severity: 'warning');
    }

    /**
     * @param  array<string, string>  $keys
     */
    public function saveKeys(string $rail, string $mode, array $keys, User $by): void
    {
        $this->guard($rail);
        if (! in_array($mode, ['test', 'live'], true) || in_array('', array_map('trim', $keys), true)) {
            throw ValidationException::withMessages(['keys' => 'Fill in every key for the chosen mode.']);
        }
        DB::connection(Connections::control())->table('pay_rail_platform')->updateOrInsert(['rail' => $rail],
            [$mode.'_keys' => Crypt::encryptString((string) json_encode($keys)), 'mode' => $mode, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('payouts.rail.keys_saved', metadata: ['rail' => $rail, 'mode' => $mode], severity: 'critical');
    }

    /**
     * @return array<string, string>|null
     */
    public function keys(string $rail): ?array
    {
        $row = DB::connection(Connections::control())->table('pay_rail_platform')->where('rail', $rail)->first();
        $sealed = $row !== null ? ($row->mode === 'live' ? $row->live_keys : $row->test_keys) : null;

        return $sealed !== null ? (array) json_decode(Crypt::decryptString((string) $sealed), true) : null;
    }

    public function mode(string $rail): string
    {
        return (string) (DB::connection(Connections::control())->table('pay_rail_platform')->where('rail', $rail)->value('mode') ?? 'test');
    }

    private function guard(string $rail): void
    {
        if (! in_array($rail, self::RAILS, true)) {
            throw ValidationException::withMessages(['rail' => 'Unknown payout rail.']);
        }
    }
}
