<?php

declare(strict_types=1);

namespace Modules\Billing\Usage;

use Illuminate\Support\Facades\DB;
use Modules\Core\Messaging\VoiceCaller;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Counts each brokerage's call minutes against its monthly allowance (specs/billing/03). A call is counted at an
 * estimate when placed; when Twilio reports its real length the difference is settled (CallStatusController).
 * Calls are alerts and connections to new leads, so they are never held back.
 */
final class MeteredVoiceCaller implements VoiceCaller
{
    public function __construct(private readonly VoiceCaller $inner, private readonly TenantContext $tenants) {}

    public function call(string $to, string $message): string
    {
        $sid = $this->inner->call($to, $message);
        $this->count($sid, self::spokenMinutes($message, 2));

        return $sid;
    }

    public function connect(string $to, string $message, string $onPress): string
    {
        $sid = $this->inner->connect($to, $message, $onPress);
        $this->count($sid, self::spokenMinutes($message, 2));

        return $sid;
    }

    /** Minutes to read a message aloud a number of times, at about 150 words a minute, rounded up (Twilio bills whole minutes). */
    public static function spokenMinutes(string $message, int $times): int
    {
        $words = max(1, str_word_count($message));

        return max(1, (int) ceil(($words * $times / 150) + 0.1));
    }

    private function count(string $sid, int $minutes): void
    {
        if (! $this->tenants->check() || $this->tenants->current()->isHq()) {
            return;
        }
        app(UsageMeter::class)->record('calls', $minutes);
        if ($sid !== '') {
            DB::connection(Connections::control())->table('usage_calls')->insertOrIgnore(['call_sid' => mb_substr($sid, 0, 64), 'tenant_id' => $this->tenants->id(),
                'estimated_minutes' => $minutes, 'created_at' => now()]);
        }
    }
}
