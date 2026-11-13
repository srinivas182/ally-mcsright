<?php

declare(strict_types=1);

namespace Modules\Roster;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;

/**
 * Compliance dashboard (specs/roster/01, addition 6): per active agent, what is expired, what expires in
 * 30/60/90 days, what is missing (licence, E&O when required, contractor agreement, W-9), and licences not
 * yet verified.
 */
final class Compliance
{
    /**
     * @return array{agents: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function dashboard(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $db = DB::connection(Connections::cell());
        $eoRequired = app(Roster::class)->settings()['eo_required'];
        $counts = ['expired' => 0, 'within_30' => 0, 'within_60' => 0, 'within_90' => 0, 'missing' => 0, 'unverified' => 0];
        $agents = [];
        foreach (User::query()->where('kind', UserKind::Agent)->where('status', UserStatus::Active)->orderBy('first_name')->get() as $agent) {
            $creds = $db->table('roster_credentials')->where('user_id', $agent->id)->where('status', 'current')->get();
            $docs = $db->table('roster_documents')->where('user_id', $agent->id)->pluck('kind')->all();
            $issues = [];
            foreach ($creds as $c) {
                if ($c->expires_on === null) {
                    continue;
                }
                $days = (int) $today->diffInDays(CarbonImmutable::parse((string) $c->expires_on), false);
                $label = $this->label((string) $c->kind, $c->state_code, $c->issuer);
                if ($days < 0) {
                    $issues[] = ['level' => 'expired', 'text' => "{$label} expired ".CarbonImmutable::parse((string) $c->expires_on)->toFormattedDateString()];
                    $counts['expired']++;
                } elseif ($days <= 90) {
                    $bucket = $days <= 30 ? 'within_30' : ($days <= 60 ? 'within_60' : 'within_90');
                    $issues[] = ['level' => $bucket, 'text' => "{$label} expires in {$days} days"];
                    $counts[$bucket]++;
                }
                if ($c->kind === 'licence' && $c->verified_at === null) {
                    $issues[] = ['level' => 'unverified', 'text' => "{$label} not verified"];
                    $counts['unverified']++;
                }
            }
            $missing = [];
            if (! $creds->contains('kind', 'licence')) {
                $missing[] = 'licence';
            }
            if ($eoRequired && ! $creds->contains('kind', 'eo')) {
                $missing[] = 'E&O';
            }
            if (! in_array('agreement', $docs, true)) {
                $missing[] = 'contractor agreement';
            }
            if (! in_array('w9', $docs, true) && ! $db->table('pay_w9s')->where('user_id', $agent->id)->where('status', 'current')->exists()) {
                $missing[] = 'W-9';
            }
            if ($missing !== []) {
                $issues[] = ['level' => 'missing', 'text' => 'Missing: '.implode(', ', $missing)];
                $counts['missing']++;
            }
            $agents[] = ['ulid' => $agent->ulid, 'name' => $agent->name, 'issues' => $issues];
        }

        return ['agents' => $agents, 'counts' => $counts];
    }

    private function label(string $kind, ?string $state, ?string $issuer): string
    {
        return match ($kind) {
            'licence' => ($state ?? '').' licence', 'eo' => 'E&O', 'association' => (string) $issuer, default => ($issuer ?? 'MLS').' membership',
        };
    }
}
