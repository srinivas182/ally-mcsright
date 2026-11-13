<?php

declare(strict_types=1);

namespace Modules\Roster;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Transactions\DealGuard;
use Modules\Transactions\Models\Deal;

/**
 * Licence guard (specs/roster/01, addition 1): a deal goes under contract only when the agent holds an
 * active licence in the deal's state and, when the brokerage requires it, active E&O. An owner can allow one
 * deal with a reason (audited).
 */
final class LicenceGuard implements DealGuard
{
    public function check(Deal $deal): ?string
    {
        $settings = app(Roster::class)->settings();
        if (! $settings['guard_licence'] || DB::connection(Connections::cell())->table('roster_overrides')->where('deal_id', $deal->id)->exists()) {
            return null;
        }
        $today = CarbonImmutable::today()->toDateString();
        $current = DB::connection(Connections::cell())->table('roster_credentials')->where('user_id', $deal->agent_user_id)->where('status', 'current');
        $name = User::query()->find($deal->agent_user_id)->name ?? 'The agent';
        if (! (clone $current)->where('kind', 'licence')->where('state_code', $deal->state_code)->where('expires_on', '>=', $today)->exists()) {
            return "{$name} has no active {$deal->state_code} licence on file. Add or renew it in the roster (or an owner can allow this deal with a reason).";
        }
        if ($settings['eo_required'] && ! (clone $current)->where('kind', 'eo')->where('expires_on', '>=', $today)->exists()) {
            return "{$name}'s E&O insurance is missing or expired. Add the renewal in the roster (or an owner can allow this deal with a reason).";
        }

        return null;
    }

    public function override(Deal $deal, string $reason, User $by): void
    {
        if (! $by->isKind(UserKind::Owner)) {
            throw ValidationException::withMessages(['override' => 'Only an owner can allow a deal past the licence guard.']);
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['override' => 'Give the reason (at least 10 characters).']);
        }
        DB::connection(Connections::cell())->table('roster_overrides')->updateOrInsert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id],
            ['reason' => mb_substr(trim($reason), 0, 300), 'by' => $by->id, 'created_at' => now()]);
        app(AuditLogger::class)->record('roster.guard.override', $deal, metadata: ['reason' => $reason], severity: 'warning', label: $deal->street);
    }
}
