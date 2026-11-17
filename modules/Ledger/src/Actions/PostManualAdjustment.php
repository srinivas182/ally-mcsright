<?php

declare(strict_types=1);

namespace Modules\Ledger\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\AdjustmentWaiting;
use Modules\Ledger\Exceptions\ClosedPeriod;
use Modules\Ledger\Exceptions\InvalidJournal;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Models\LedgerJournal;
use Modules\Ledger\Models\LedgerPeriod;
use Modules\Ledger\Money\Money;

/**
 * Manual journals entered by a person (specs/finance/01, FH1 specs/finance/22). They are never posted by
 * the person who entered them: a second person with "Approve adjustments" approves or declines. A brokerage
 * with only one such person may approve its own, with a written reason, and that is marked everywhere.
 */
final class PostManualAdjustment
{
    public function __construct(private readonly LedgerService $ledger, private readonly TenantContext $tenants, private readonly AuditLogger $audit) {}

    public static function canApprove(User $user): bool
    {
        return $user->isKind(UserKind::Owner) || $user->can('finance.ledger.approve');
    }

    /**
     * Checks the adjustment would post and asks for approval. Idempotent per form submission.
     *
     * @param  list<array{account: string, side: string, amount: string, memo?: string|null}>  $lines
     * @return string the request ULID
     */
    public function request(string $description, CarbonImmutable $effectiveDate, array $lines, string $idempotencyKey, User $by): string
    {
        if (trim($description) === '') {
            throw ValidationException::withMessages(['description' => 'Describe why this adjustment is needed.']);
        }
        $db = DB::connection(Connections::cell());
        $existing = $db->table('ledger_adjustment_requests')->where('tenant_id', $this->tenants->id())->where('idempotency_key', $idempotencyKey)->value('ulid');
        if ($existing !== null) {
            return (string) $existing;
        }
        $clean = [];
        $draft = $this->draft($description, $effectiveDate, $lines, 'check', $by->id, $clean);
        $period = LedgerPeriod::query()->whereDate('starts_on', $effectiveDate->startOfMonth()->toDateString())->first();
        if ($period?->isClosed() === true) {
            throw ValidationException::withMessages(['effective_date' => 'That month is closed. Use a date in an open period.']);
        }
        $total = 0;
        foreach ($draft->lines() as $line) {
            $total += $line['debit']->cents ?? 0;
        }
        $ulid = (string) Str::ulid();
        $db->table('ledger_adjustment_requests')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'description' => trim($description),
            'effective_date' => $effectiveDate->toDateString(), 'lines' => json_encode($clean), 'total_cents' => $total, 'idempotency_key' => $idempotencyKey,
            'requested_by' => $by->id, 'status' => 'waiting', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('finance.adjustment.requested', metadata: ['request' => $ulid, 'total_cents' => $total], severity: 'notice');

        $approvers = $this->approvers($by);
        if ($approvers !== []) {
            Notification::send($approvers, new AdjustmentWaiting(trim($description), $total, (string) $by->name));
        }

        return $ulid;
    }

    /**
     * Approves (posting the journal) or declines a waiting adjustment.
     */
    public function decide(string $ulid, User $by, bool $approve, ?string $note = null): ?LedgerJournal
    {
        $db = DB::connection(Connections::cell());

        return $db->transaction(function () use ($db, $ulid, $by, $approve, $note): ?LedgerJournal {
            $r = $db->table('ledger_adjustment_requests')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->lockForUpdate()->first();
            if ($r === null || $r->status !== 'waiting') {
                throw ValidationException::withMessages(['adjustment' => 'This adjustment has already been decided.']);
            }
            $own = (int) $r->requested_by === $by->id;
            if ($own && ! $approve) {
                $db->table('ledger_adjustment_requests')->where('id', $r->id)->update(['status' => 'withdrawn', 'decided_by' => $by->id, 'decided_at' => now(), 'updated_at' => now()]);
                $this->audit->record('finance.adjustment.withdrawn', metadata: ['request' => $ulid]);

                return null;
            }
            if (! self::canApprove($by)) {
                throw ValidationException::withMessages(['adjustment' => 'You need the "Approve adjustments" permission.']);
            }
            $note = $note !== null ? trim($note) : null;
            if ($own) {
                if ($this->approvers($by) !== []) {
                    throw ValidationException::withMessages(['adjustment' => 'Someone else must approve an adjustment you entered.']);
                }
                if ($note === null || mb_strlen($note) < 10) {
                    throw ValidationException::withMessages(['note' => 'You are the only approver. Write why this adjustment is right (at least 10 characters).']);
                }
            }
            if (! $approve) {
                if ($note === null || $note === '') {
                    throw ValidationException::withMessages(['note' => 'Say why you are declining it.']);
                }
                $db->table('ledger_adjustment_requests')->where('id', $r->id)->update(['status' => 'declined', 'decided_by' => $by->id, 'decided_at' => now(),
                    'decision_note' => mb_substr($note, 0, 255), 'updated_at' => now()]);
                $this->audit->record('finance.adjustment.declined', metadata: ['request' => $ulid], severity: 'notice');

                return null;
            }
            /** @var list<array{account: string, side: string, amount: string, memo?: string|null}> $lines */
            $lines = (array) json_decode((string) $r->lines, true);
            $clean = [];
            $draft = $this->draft((string) $r->description, CarbonImmutable::parse((string) $r->effective_date), $lines, 'manual:'.$r->idempotency_key, (int) $r->requested_by, $clean);
            try {
                $journal = $this->ledger->post($draft);
            } catch (InvalidJournal $e) {
                throw ValidationException::withMessages(['adjustment' => $e->getMessage()]);
            } catch (ClosedPeriod) {
                throw ValidationException::withMessages(['adjustment' => 'That month has been closed since this was entered. Decline it and enter it again in an open month.']);
            }
            $db->table('ledger_adjustment_requests')->where('id', $r->id)->update(['status' => 'approved', 'decided_by' => $by->id, 'decided_at' => now(),
                'decision_note' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null, 'self_approved' => $own, 'journal_id' => $journal->id, 'updated_at' => now()]);
            $this->audit->record('finance.adjustment.approved', metadata: ['request' => $ulid, 'journal' => $journal->ulid, 'self_approved' => $own], severity: $own ? 'warning' : 'notice');

            return $journal;
        });
    }

    /**
     * People other than $by who can approve adjustments.
     *
     * @return list<User>
     */
    public function approvers(User $by): array
    {
        return array_values(User::query()->where('status', UserStatus::Active)->whereKeyNot($by->id)->get()
            ->filter(fn (User $u): bool => self::canApprove($u))->all());
    }

    /**
     * @param  list<array{account: string, side: string, amount: string, memo?: string|null}>  $lines
     * @param  list<array{account: string, side: string, amount: string, memo: string|null}>  $clean
     */
    private function draft(string $description, CarbonImmutable $effectiveDate, array $lines, string $key, ?int $by, array &$clean): JournalDraft
    {
        $draft = JournalDraft::make('manual.adjustment', $key)->effectiveOn($effectiveDate)->describe(trim($description))->by($by);
        foreach ($lines as $i => $line) {
            $account = LedgerAccount::query()->where('ulid', $line['account'])->first()
                ?? throw ValidationException::withMessages(["lines.{$i}.account" => 'Choose an account.']);
            try {
                $amount = Money::fromDecimal($line['amount']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["lines.{$i}.amount" => 'Enter an amount like 1250.00.']);
            }
            $memo = $line['memo'] ?? null;
            $line['side'] === 'debit' ? $draft->debit($account, $amount, $memo) : $draft->credit($account, $amount, $memo);
            $clean[] = ['account' => $account->ulid, 'side' => $line['side'] === 'debit' ? 'debit' : 'credit', 'amount' => $line['amount'], 'memo' => $memo];
        }
        try {
            $draft->validate();
        } catch (InvalidJournal $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return $draft;
    }
}
