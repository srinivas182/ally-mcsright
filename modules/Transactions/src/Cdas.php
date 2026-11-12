<?php

declare(strict_types=1);

namespace Modules\Transactions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Models\Calculation;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Models\Deal;

/**
 * Commission disbursement authorisations (specs/transactions/01): generated from the deal's commission
 * calculation, versioned (a new version supersedes the old one and its link), approved by someone other
 * than the deal's agent, and sent only to a verified title company through a tracked link the title
 * officer acknowledges. The title-facing CDA shows the gross commission, any outside referral and the
 * remainder payable to the brokerage — never agents' internal splits, never full bank numbers.
 */
final class Cdas
{
    public function __construct(private readonly TenantContext $tenants, private readonly TitleDesk $desk) {}

    public function generate(Deal $deal, User $by): string
    {
        $calc = $deal->calculation_id !== null ? Calculation::query()->find($deal->calculation_id) : null;
        if ($calc === null) {
            throw ValidationException::withMessages(['cda' => 'Put the deal under contract first, so the commission is calculated.']);
        }
        $gci = (int) ($calc->result['gci_cents'] ?? 0);
        $referral = (int) ($calc->result['outside_referral_cents'] ?? 0);
        $out = DB::connection(Connections::cell())->table('tx_referrals')->where('deal_id', $deal->id)->where('direction', 'out')->orderBy('id')->get();
        $payees = [];
        if ($referral > 0) {
            $payees[] = ['name' => $out->isNotEmpty() ? $out->pluck('brokerage')->implode(', ') : 'Referring brokerage', 'role' => 'Outside referral fee', 'amount_cents' => $referral];
        }
        $wire = $this->desk->wire();
        $payees[] = ['name' => (string) $this->tenants->current()->name, 'role' => 'Brokerage (all remaining commission)', 'amount_cents' => $gci - $referral,
            'account' => $wire['last4'] !== null ? "{$wire['bank']} account ending {$wire['last4']}, verified ".substr((string) $wire['verified_at'], 0, 10) : null];
        if (array_sum(array_column($payees, 'amount_cents')) !== $gci) {
            throw ValidationException::withMessages(['cda' => 'The payees do not add up to the commission.']);
        }

        $parties = DB::connection(Connections::cell())->table('tx_parties')->where('deal_id', $deal->id)->whereIn('role', ['buyer', 'seller'])->get();
        $payload = [
            'property' => trim("{$deal->street}, {$deal->city} {$deal->state_code} {$deal->postal_code}"), 'mls' => $deal->mls_number,
            'buyers' => $parties->where('role', 'buyer')->pluck('name')->values()->all(), 'sellers' => $parties->where('role', 'seller')->pluck('name')->values()->all(),
            'price_cents' => $deal->price_cents, 'closing_date' => $deal->closing_date?->toDateString(), 'gci_cents' => $gci, 'payees' => $payees,
            'brokerage' => (string) $this->tenants->current()->name,
            'wire_warning' => 'Pay only to the brokerage\'s verified account on file. Never follow emailed or texted changes to payment instructions — call the brokerage on a number you already know.',
        ];

        $db = DB::connection(Connections::cell());

        return $db->transaction(function () use ($db, $deal, $payload, $by): string {
            $db->table('tx_cdas')->where('deal_id', $deal->id)->whereNot('status', 'superseded')->update(['status' => 'superseded', 'token_hash' => null, 'updated_at' => now()]);
            $ulid = (string) Str::ulid();
            $db->table('tx_cdas')->insert(['ulid' => $ulid, 'tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id,
                'version' => (int) $db->table('tx_cdas')->where('deal_id', $deal->id)->max('version') + 1, 'payload' => json_encode($payload),
                'status' => 'draft', 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
            app(AuditLogger::class)->record('transactions.cda.generated', $deal, metadata: ['gci_cents' => $payload['gci_cents']], severity: 'notice', label: $deal->street);

            return $ulid;
        });
    }

    public function approve(string $ulid, User $by): void
    {
        $cda = $this->find($ulid, ['draft']);
        $deal = Deal::query()->findOrFail($cda['deal_id']);
        if (! ($by->isKind(UserKind::Owner) || $by->can('transactions.cda.approve'))) {
            throw ValidationException::withMessages(['cda' => 'Approving CDAs needs the "Approve CDAs" permission.']);
        }
        if ($deal->agent_user_id === $by->id) {
            throw ValidationException::withMessages(['cda' => 'Someone other than the deal\'s agent must approve its CDA.']);
        }
        DB::connection(Connections::cell())->table('tx_cdas')->where('ulid', $ulid)->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.cda.approved', $deal, metadata: ['cda' => $ulid], severity: 'notice', label: $deal->street);
    }

    public function send(string $ulid, string $titleUlid, User $by, string $baseUrl): void
    {
        $cda = $this->find($ulid, ['approved']);
        $db = DB::connection(Connections::cell());
        $title = $db->table('tx_title_companies')->where('ulid', $titleUlid)->first();
        if ($title === null || $title->verified_at === null) {
            throw ValidationException::withMessages(['title' => 'Send CDAs only to a title company verified by call-back.']);
        }
        $token = Str::random(48);
        $db->table('tx_cdas')->where('ulid', $ulid)->update(['status' => 'sent', 'title_company_id' => $title->id, 'token_hash' => hash('sha256', $token), 'sent_at' => now(), 'updated_at' => now()]);
        Notification::route('mail', (string) $title->email)->notify(new CdaSent((string) $this->tenants->current()->name, $baseUrl.'/cda/'.$token));
        app(AuditLogger::class)->record('transactions.cda.sent', metadata: ['cda' => $ulid, 'title' => $title->name], severity: 'notice');
    }

    /**
     * The title officer's view (public link); records the first view.
     *
     * @return array<string, mixed>|null
     */
    public function byToken(string $token): ?array
    {
        $db = DB::connection(Connections::cell());
        $cda = $db->table('tx_cdas')->where('token_hash', hash('sha256', $token))->whereIn('status', ['sent', 'acknowledged'])->first();
        if ($cda === null) {
            return null;
        }
        if ($cda->viewed_at === null) {
            $db->table('tx_cdas')->where('id', $cda->id)->update(['viewed_at' => now()]);
        }

        return ['version' => (int) $cda->version, 'status' => $cda->status, 'acknowledged_at' => $cda->acknowledged_at] + (array) json_decode((string) $cda->payload, true);
    }

    public function acknowledge(string $token, string $name): bool
    {
        if (mb_strlen(trim($name)) < 3) {
            throw ValidationException::withMessages(['name' => 'Type your full name to acknowledge.']);
        }

        return DB::connection(Connections::cell())->table('tx_cdas')->where('token_hash', hash('sha256', $token))->where('status', 'sent')
            ->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => mb_substr(trim($name), 0, 120), 'updated_at' => now()]) > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forDeal(Deal $deal): array
    {
        return DB::connection(Connections::cell())->table('tx_cdas')->where('deal_id', $deal->id)->orderByDesc('version')->get()
            ->map(fn ($c): array => ['ulid' => $c->ulid, 'version' => (int) $c->version, 'status' => $c->status, 'payload' => json_decode((string) $c->payload, true),
                'sent_at' => $c->sent_at, 'viewed_at' => $c->viewed_at, 'acknowledged_at' => $c->acknowledged_at, 'acknowledged_by' => $c->acknowledged_by])->values()->all();
    }

    /**
     * @param  list<string>  $statuses
     * @return array{deal_id: int, status: string}
     */
    private function find(string $ulid, array $statuses): array
    {
        $cda = DB::connection(Connections::cell())->table('tx_cdas')->where('ulid', $ulid)->first();
        $status = $cda !== null ? (string) $cda->status : 'missing';
        if ($cda === null || ! in_array($status, $statuses, true)) {
            throw ValidationException::withMessages(['cda' => 'This CDA is '.$status.'.']);
        }

        return ['deal_id' => (int) $cda->deal_id, 'status' => $status];
    }
}
