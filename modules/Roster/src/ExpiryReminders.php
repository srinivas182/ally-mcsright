<?php

declare(strict_types=1);

namespace Modules\Roster;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;

/** Daily: licences, E&O and memberships expiring in 60, 30 or 7 days, or today/expired — agent and owners told once per step. */
final class ExpiryReminders
{
    public const STEPS = [60, 30, 7, 0];

    public function run(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $db = DB::connection(Connections::cell());
        $sent = 0;
        foreach ($db->table('roster_credentials')->where('status', 'current')->whereNotNull('expires_on')->where('expires_on', '<=', $today->addDays(60)->toDateString())->get() as $c) {
            $days = (int) $today->diffInDays(CarbonImmutable::parse((string) $c->expires_on), false);
            $step = null;
            foreach (self::STEPS as $s) {
                if ($days <= $s) {
                    $step = $s;
                }
            }
            if ($step === null || ($c->reminded_days !== null && (int) $c->reminded_days <= $step)) {
                continue;
            }
            $agent = User::query()->find((int) $c->user_id);
            if ($agent === null || $agent->status !== UserStatus::Active) {
                continue;
            }
            $what = match ((string) $c->kind) {
                'licence' => "{$c->state_code} real estate licence", 'eo' => 'E&O insurance', default => (string) $c->issuer.' membership'
            };
            $note = new CredentialExpiring($agent->name, $what, CarbonImmutable::parse((string) $c->expires_on), $days);
            $agent->notify($note);
            Notification::send(User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->get(), $note);
            $db->table('roster_credentials')->where('id', $c->id)->update(['reminded_days' => $step]);
            $sent++;
        }

        return $sent;
    }
}
