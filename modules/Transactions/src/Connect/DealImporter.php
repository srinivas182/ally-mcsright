<?php

declare(strict_types=1);

namespace Modules\Transactions\Connect;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Support\Connections;
use Modules\Crm\Connect\Importer;
use Modules\Transactions\Checklists;
use Modules\Transactions\Documents;
use Modules\Transactions\Models\Deal;
use Throwable;

/**
 * The one set of rules every transaction-app import follows (specs/connect/12), whether the deals come from dotloop,
 * SkySlope or Lone Wolf. "Switch in a weekend": every deal, open or closed, comes over with its parties, dates and
 * files, and keeps coming over until the brokerage disconnects the old app.
 *
 * - Safe to repeat: each deal and file is remembered by the other app's id, so nothing is added twice.
 * - The other app is the source while both are in use: its address, price, dates and status update the deal here.
 *   A deal that has since been closed in RightAlly (commission paid here) is never changed again.
 * - Agent: matched by the other app's user id or email to a RightAlly person. Everything in an agent's own account
 *   is theirs. Deals whose agent is not on RightAlly yet go to the person who connected, and move to the agent
 *   once they join (the next sync).
 * - Open deals get the brokerage's checklist, like a deal started here. Closed and fallen-through deals are history:
 *   no checklist, and closed ones are locked.
 * - Commission: a deal that closes there on or after the connection's "pay from" date is paid here automatically
 *   (SyncedPay, C5a-3); deals closed before it are history, paid in the old system.
 * - Parties are linked to existing CRM contacts by email; no contacts are created (the CRM import does that).
 */
final class DealImporter
{
    public const TYPES = ['listing', 'buyer', 'dual', 'lease'];

    public const STATUSES = ['pre_contract', 'under_contract', 'closed', 'fell_through'];

    public const ROLES = ['buyer', 'seller', 'co_op_agent', 'lender', 'title', 'escrow', 'attorney', 'inspector', 'other'];

    /** @var array<int, string> connection id => the date from which closings are paid here */
    private array $payFrom = [];

    public function __construct(private readonly ConnectorKit $kit, private readonly Checklists $checklists, private readonly Documents $documents, private readonly SyncedPay $pay) {}

    /**
     * @param  array{id: string, type: string, status: string, agent_id: string|null, agent_email: string|null, street: string, city: string|null,
     *     state_code: string, postal_code: string|null, mls_number: string|null, price_cents: int, gci_cents: int, contract_date: string|null,
     *     closing_date: string|null, cancel_reason: string|null, parties: list<array{role: string, name: string, company: string|null, email: string|null, phone: string|null}>,
     *     dates: list<array{label: string, due_on: string}>}  $d
     * @param  string  $app  the other app's display name, e.g. "dotloop"
     * @return array{result: 'created'|'updated'|'skipped', deal: Deal|null}
     */
    public function deal(ConnectionData $connection, User $by, array $d, string $app): array
    {
        $state = strtoupper(trim($d['state_code']));
        if ($d['id'] === '' || trim($d['street']) === '' || preg_match('/^[A-Z]{2}$/', $state) !== 1
            || ! in_array($d['type'], self::TYPES, true) || ! in_array($d['status'], self::STATUSES, true)) {
            return ['result' => 'skipped', 'deal' => null]; // not a property deal, or no address we can file it under
        }
        $agentId = $this->agent($connection, $by, $d['agent_id'], $d['agent_email']);
        $known = $this->kit->localId($connection, 'deal', $d['id']);
        $deal = $known !== null ? Deal::query()->find($known) : null;
        if ($deal !== null && $deal->imported_from === null && $deal->status === 'closed') {
            return ['result' => 'skipped', 'deal' => $deal];
        }
        $closingDate = self::date($d['closing_date']);
        $fields = ['type' => $d['type'], 'status' => $d['status'], 'agent_user_id' => $agentId, 'street' => mb_substr(trim($d['street']), 0, 160),
            'city' => self::text($d['city'], 80), 'state_code' => $state, 'postal_code' => self::text($d['postal_code'], 10), 'mls_number' => self::text($d['mls_number'], 30),
            'price_cents' => max(0, $d['price_cents']), 'gci_cents' => max(0, $d['gci_cents']), 'contract_date' => self::date($d['contract_date']), 'closing_date' => $closingDate,
            'closed_on' => $d['status'] === 'closed' ? ($closingDate ?? CarbonImmutable::now()->toDateString()) : null,
            'cancel_reason' => $d['status'] === 'fell_through' ? (self::text($d['cancel_reason'], 300) ?? 'Cancelled in '.$app) : null];

        $created = $deal === null;
        $deal = DB::connection(Connections::cell())->transaction(function () use ($deal, $fields, $connection, $d, $by, $app): Deal {
            if ($deal === null) {
                $deal = Deal::query()->create($fields + ['created_by' => $by->id, 'imported_from' => mb_substr($app, 0, 20),
                    'locked_at' => $fields['status'] === 'closed' ? CarbonImmutable::now() : null]);
                $this->kit->remember($connection, 'deal', $d['id'], $deal->id);
                if (in_array($fields['status'], ['pre_contract', 'under_contract'], true)) {
                    $this->checklists->apply($deal);
                }
            } else {
                $deal->fill($fields);
                if ($fields['status'] === 'closed' && $deal->locked_at === null) {
                    $deal->locked_at = CarbonImmutable::now();
                }
                $deal->save();
            }
            $this->parties($deal, $d['parties']);
            $this->dates($deal, $d['dates']);

            return $deal;
        });
        if ($created) {
            app(AuditLogger::class)->record('transactions.deal.imported', $deal, metadata: ['from' => $app, 'status' => $deal->status], label: $deal->street);
        }
        $this->pay->afterSync($deal, $this->payFrom($connection), $app);

        return ['result' => $created ? 'created' : 'updated', 'deal' => $deal];
    }

    /**
     * The date from which deals that close in the other app are paid here (specs/connect/12, C5a-3): the day the app
     * was connected, unless the broker set another. Closings before it are history, paid in the old system.
     */
    public function payFrom(ConnectionData $connection): string
    {
        if (! isset($this->payFrom[$connection->id])) {
            $set = $connection->settings['pay_from'] ?? null;
            if (! is_string($set) || DealImporter::date($set) === null) {
                $set = CarbonImmutable::today()->toDateString();
                $this->kit->saveSettings($connection, ['pay_from' => $set]);
            }
            $this->payFrom[$connection->id] = $set;
        }

        return $this->payFrom[$connection->id];
    }

    /**
     * Brings one file into a deal, once (by the other app's id). The download only happens for files not seen yet.
     *
     * @param  Closure(): ?string  $download  the file's bytes, or null when the other app won't give them
     */
    public function document(ConnectionData $connection, Deal $deal, string $externalId, string $filename, Closure $download): bool
    {
        if ($externalId === '' || $this->kit->localId($connection, 'document', $externalId) !== null) {
            return false;
        }
        try {
            $bytes = $download();
        } catch (Throwable) {
            return false; // tried again on the next full read
        }
        $doc = $bytes !== null && $bytes !== '' ? $this->documents->imported($deal, $filename, $bytes) : null;
        // Remembered even when skipped (not a PDF or image, or the same file already there), so it isn't downloaded again.
        $this->kit->remember($connection, 'document', $externalId, $doc !== null ? $doc->id : 0);

        return $doc !== null;
    }

    private function agent(ConnectionData $connection, User $by, ?string $externalId, ?string $email): int
    {
        if ($connection->ownerUserId !== null) {
            return $connection->ownerUserId; // an agent's own account: everything in it is theirs
        }
        if ($externalId !== null && $externalId !== '' && ($local = $this->kit->localId($connection, 'user', $externalId)) !== null) {
            return $local;
        }
        if ($email !== null && trim($email) !== '') {
            $user = User::query()->where('email', mb_strtolower(trim($email)))->first();
            if ($user instanceof User) {
                if ($externalId !== null && $externalId !== '') {
                    app(Importer::class)->user($connection, $externalId, (string) $user->email);
                }

                return $user->id;
            }
        }

        return $by->id;
    }

    /** @param  list<array{role: string, name: string, company: string|null, email: string|null, phone: string|null}>  $parties */
    private function parties(Deal $deal, array $parties): void
    {
        $db = DB::connection(Connections::cell());
        foreach (array_slice($parties, 0, 30) as $p) {
            $name = mb_substr(trim($p['name']), 0, 160);
            $email = $p['email'] !== null && filter_var(trim($p['email']), FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower(trim($p['email'])) : null;
            if ($name === '' && $email === null) {
                continue;
            }
            $role = in_array($p['role'], self::ROLES, true) ? $p['role'] : 'other';
            $existing = $db->table('tx_parties')->where('deal_id', $deal->id)->where('role', $role)
                ->where(fn ($q) => $email !== null ? $q->where('email', $email) : $q->where('name', $name))->first();
            $contact = in_array($role, ['buyer', 'seller'], true) ? Importer::existing($email, $p['phone']) : null;
            $row = ['name' => $name !== '' ? $name : (string) $email, 'company' => self::text($p['company'], 160), 'email' => $email,
                'phone' => self::text($p['phone'], 30), 'contact_id' => $contact?->id, 'updated_at' => now()];
            if ($existing === null) {
                $db->table('tx_parties')->insert($row + ['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'role' => $role, 'created_at' => now()]);
            } else {
                $db->table('tx_parties')->where('id', $existing->id)->update(array_filter($row, fn ($v) => $v !== null));
            }
            if ($contact !== null && $deal->contact_id === null && ($role === 'buyer') === ($deal->type !== 'listing')) {
                $deal->forceFill(['contact_id' => $contact->id])->save();
            }
        }
    }

    /** @param  list<array{label: string, due_on: string}>  $dates */
    private function dates(Deal $deal, array $dates): void
    {
        $db = DB::connection(Connections::cell());
        foreach (array_slice($dates, 0, 30) as $date) {
            $label = mb_substr(trim($date['label']), 0, 80);
            $due = self::date($date['due_on']);
            if ($label === '' || $due === null) {
                continue;
            }
            $existing = $db->table('tx_dates')->where('deal_id', $deal->id)->where('label', $label)->first();
            if ($existing === null) {
                $db->table('tx_dates')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'label' => $label, 'due_on' => $due, 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($existing->done_at === null && (string) $existing->due_on !== $due) {
                $db->table('tx_dates')->where('id', $existing->id)->update(['due_on' => $due, 'updated_at' => now()]);
            }
        }
    }

    /** Y-m-d from the formats transaction apps use (2026-03-01, 03/01/2026, or a full timestamp), or null. */
    public static function date(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach (['!m/d/Y', '!Y-m-d', '!n/j/Y'] as $format) {
            try {
                $d = CarbonImmutable::createFromFormat($format, $value);
            } catch (Throwable) {
                continue;
            }
            if ($d instanceof CarbonImmutable && $d->format(ltrim($format, '!')) === $value) {
                return $d->toDateString();
            }
        }
        try {
            $d = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $d->year >= 1990 && $d->year <= 2100 ? $d->toDateString() : null;
    }

    /** Whole cents from "$425,000.00", "425000" or a number, or 0. */
    public static function cents(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return max(0, (int) round($value * 100));
        }
        $clean = preg_replace('/[^0-9.]/', '', (string) (is_scalar($value) ? $value : ''));

        return is_numeric($clean) ? max(0, (int) round((float) $clean * 100)) : 0;
    }

    private static function text(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
