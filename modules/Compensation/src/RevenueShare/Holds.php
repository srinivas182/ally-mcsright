<?php

declare(strict_types=1);

namespace Modules\Compensation\RevenueShare;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\Rules\RevenueShareRules;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * Abuse controls (specs/compensation/02, addition 14): payments that look like self-sponsoring or
 * gaming are held until the brokerage reviews them. Signals: the same phone number, sign-ins from the
 * same device, a sponsor change shortly before the deal, and very small deals.
 */
final class Holds
{
    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function signals(int $sourceAgentId, int $payee, array $row, RevenueShareRules $rules, CarbonImmutable $on): array
    {
        $db = DB::connection(Connections::cell());
        $reasons = [];
        $phones = User::query()->whereIn('id', [$sourceAgentId, $payee])->pluck('phone')
            ->map(fn ($p) => preg_replace('/\D/', '', (string) $p))->filter(fn ($p) => strlen((string) $p) >= 7);
        if ($phones->count() === 2 && $phones->unique()->count() === 1) {
            $reasons[] = 'same_phone';
        }
        $shared = $db->table('known_devices as a')->join('known_devices as b', 'a.fingerprint', '=', 'b.fingerprint')
            ->where('a.user_id', $sourceAgentId)->where('b.user_id', $payee)->exists();
        if ($shared) {
            $reasons[] = 'shared_device';
        }
        $sponsorships = $db->table('rs_sponsorships')->where('agent_user_id', $sourceAgentId)->orderByDesc('effective_from')->limit(2)->get();
        $latest = $sponsorships->first();
        if ($sponsorships->count() > 1 && $latest !== null && CarbonImmutable::parse((string) $latest->effective_from)->gte($on->subDays($rules->recentSponsorChangeDays))) {
            $reasons[] = 'recent_sponsor_change';
        }
        if ((int) $row['share_cents'] < $rules->tinyDealGciCents) {
            $reasons[] = 'tiny_deal';
        }

        return $reasons;
    }

    /**
     * @param  list<string>  $reasons
     */
    public function hold(int $entryId, int $tenantId, array $reasons): void
    {
        DB::connection(Connections::cell())->table('rs_holds')->insert(['tenant_id' => $tenantId, 'entry_id' => $entryId, 'reasons' => json_encode($reasons),
            'status' => 'held', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function release(int $holdId, User $by, string $note): void
    {
        $db = DB::connection(Connections::cell());
        [$hold, $entry] = $this->open($holdId);
        $calc = Calculation::query()->findOrFail($entry['calculation_id']);
        if ($calc->status !== 'final') {
            throw ValidationException::withMessages(['hold' => 'This deal was reversed, so the payment cannot be released.']);
        }
        $db->transaction(function () use ($db, $hold, $entry, $calc, $by, $note): void {
            $journal = app(RevenueShareEngine::class)->postRelease($calc, $entry['id'], (int) $entry['recipient_user_id'], $entry['amount_cents']);
            $db->table('rs_entries')->where('id', $entry['id'])->update(['kind' => 'share', 'journal_id' => $journal]);
            $db->table('rs_holds')->where('id', $hold)->update(['status' => 'released', 'reviewed_by' => $by->id, 'note' => $note, 'reviewed_at' => now(), 'updated_at' => now()]);
        });
        app(AuditLogger::class)->record('compensation.hold.released', metadata: ['entry' => $entry['id'], 'cents' => $entry['amount_cents'], 'note' => $note], severity: 'warning');
    }

    public function reject(int $holdId, User $by, string $note): void
    {
        if (mb_strlen(trim($note)) < 10) {
            throw ValidationException::withMessages(['note' => 'Record why the payment is rejected (at least 10 characters).']);
        }
        $db = DB::connection(Connections::cell());
        [$hold, $entry] = $this->open($holdId);
        $db->table('rs_entries')->where('id', $entry['id'])->update(['kind' => 'missed', 'reason' => 'rejected', 'missed_user_id' => $entry['recipient_user_id'], 'recipient_user_id' => null]);
        $db->table('rs_holds')->where('id', $hold)->update(['status' => 'rejected', 'reviewed_by' => $by->id, 'note' => trim($note), 'reviewed_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('compensation.hold.rejected', metadata: ['entry' => $entry['id'], 'note' => $note], severity: 'warning');
    }

    /**
     * @return array{0: int, 1: array{id: int, calculation_id: int, recipient_user_id: int|null, amount_cents: int}}
     */
    private function open(int $holdId): array
    {
        $db = DB::connection(Connections::cell());
        $hold = $db->table('rs_holds')->where('id', $holdId)->where('status', 'held')->first();
        $entry = $hold !== null ? $db->table('rs_entries')->where('id', $hold->entry_id)->where('kind', 'held')->first() : null;
        if ($hold === null || $entry === null) {
            throw ValidationException::withMessages(['hold' => 'This payment is no longer held.']);
        }
        $e = (array) $entry;

        return [$holdId, ['id' => (int) $e['id'], 'calculation_id' => (int) $e['calculation_id'],
            'recipient_user_id' => $e['recipient_user_id'] !== null ? (int) $e['recipient_user_id'] : null, 'amount_cents' => (int) $e['amount_cents']]];
    }
}
