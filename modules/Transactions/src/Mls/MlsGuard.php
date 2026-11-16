<?php

declare(strict_types=1);

namespace Modules\Transactions\Mls;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Listings\MlsStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Deadlines\BrokerAlert;
use Modules\Transactions\Events\DealStatusChanged;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Timeline\StatePacks;

/**
 * The MLS status guard (specs/ai/02, §20). MLSs fine late status changes, so Deal-IQ compares each listing deal with
 * what the MLS feed actually shows: under contract but still Active, closed but still Pending, fell through but still
 * Pending. The agent is reminded the business day before the brokerage's MLS deadline (Deals → Compliance, preset to
 * 2 business days and editable for each MLS's own rule); once overdue it goes to the broker's risk register and the
 * broker is alerted once. It checks again until the MLS shows the right status.
 */
final class MlsGuard
{
    /** What the MLS should show for each deal status (RESO StandardStatus). */
    public const EXPECTED = [
        'under_contract' => ['label' => 'Pending', 'accept' => ['Pending', 'Active Under Contract'], 'setting' => 'mls_pending_days'],
        'closed' => ['label' => 'Closed', 'accept' => ['Closed'], 'setting' => 'mls_closed_days'],
        'fell_through' => ['label' => 'Active or withdrawn', 'accept' => ['Active', 'Coming Soon', 'Withdrawn', 'Canceled', 'Expired', 'Hold'], 'setting' => 'mls_active_days'],
    ];

    public function __construct(private readonly TenantContext $tenants, private readonly MlsStatus $mls) {}

    /** Listens for status changes: the clock for the MLS update starts now. */
    public static function statusChanged(DealStatusChanged $event): void
    {
        DB::connection(Connections::cell())->table('tx_deals')->where('id', $event->dealId)->update(['status_changed_at' => now()]);
    }

    /**
     * @return array{mls_pending_days: int, mls_closed_days: int, mls_active_days: int}
     */
    public function rules(): array
    {
        $r = DB::connection(Connections::cell())->table('tx_deadline_settings')->where('tenant_id', $this->tenants->id())->first();

        return ['mls_pending_days' => (int) ($r->mls_pending_days ?? 2), 'mls_closed_days' => (int) ($r->mls_closed_days ?? 2), 'mls_active_days' => (int) ($r->mls_active_days ?? 2)];
    }

    /**
     * @param  array{mls_pending_days: int, mls_closed_days: int, mls_active_days: int}  $r
     */
    public function saveRules(array $r, User $by): void
    {
        foreach ($r as $v) {
            if ($v < 0 || $v > 10) {
                throw ValidationException::withMessages(['mls' => 'Use 0 to 10 business days, as your MLS rules say.']);
            }
        }
        DB::connection(Connections::cell())->table('tx_deadline_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], $r + ['updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('transactions.mls_rules.saved', metadata: $r, severity: 'notice');
    }

    /**
     * Checks every listing deal against the MLS (hourly).
     *
     * @return array{checked: int, reminded: int, escalated: int, resolved: int}
     */
    public function run(): array
    {
        $db = DB::connection(Connections::cell());
        $rules = $this->rules();
        $today = CarbonImmutable::today();
        $counts = ['checked' => 0, 'reminded' => 0, 'escalated' => 0, 'resolved' => 0];
        $deals = Deal::query()->whereIn('type', ['listing', 'dual', 'new_construction'])->whereNotNull('mls_number')->where('mls_number', '!=', '')
            ->whereIn('status', array_keys(self::EXPECTED))->whereNotNull('status_changed_at')->where('status_changed_at', '>=', $today->subDays(45))->get();
        foreach ($deals as $deal) {
            $want = self::EXPECTED[$deal->status];
            $seen = $this->mls->status($this->tenants->id(), (string) $deal->mls_number);
            if ($seen === null) {
                continue; // not in the brokerage's feed: nothing to compare
            }
            $counts['checked']++;
            $open = $db->table('tx_mls_checks')->where('deal_id', $deal->id)->whereNull('resolved_at')->get();
            if (in_array($seen, $want['accept'], true)) {
                foreach ($open as $o) {
                    $db->table('tx_mls_checks')->where('id', $o->id)->update(['seen' => $seen, 'resolved_at' => now(), 'updated_at' => now()]);
                    $counts['resolved']++;
                }

                continue;
            }
            $changed = CarbonImmutable::parse((string) $deal->getAttribute('status_changed_at'))->startOfDay();
            $due = StatePacks::addBusinessDays($changed, $rules[$want['setting']], $deal->state_code);
            $check = $db->table('tx_mls_checks')->where('deal_id', $deal->id)->where('expected', $want['label'])->first();
            if ($check === null) {
                $db->table('tx_mls_checks')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'expected' => $want['label'], 'seen' => $seen, 'due_on' => $due->toDateString(),
                    'created_at' => now(), 'updated_at' => now()]);
                $check = $db->table('tx_mls_checks')->where('deal_id', $deal->id)->where('expected', $want['label'])->first();
            } else {
                $db->table('tx_mls_checks')->where('id', $check->id)->update(['seen' => $seen, 'resolved_at' => null, 'updated_at' => now()]);
            }
            if ($check === null) {
                continue;
            }
            $agent = User::query()->whereKey($deal->agent_user_id)->first();
            if ($check->reminded_at === null && $agent !== null) {
                $agent->notify(new MlsStatusMail($deal->street, (string) $deal->mls_number, $want['label'], $seen, $due, '/deals/'.$deal->ulid));
                $db->table('tx_mls_checks')->where('id', $check->id)->update(['reminded_at' => now()]);
                $counts['reminded']++;
            }
            if ($today->gt($due) && $check->escalated_at === null) {
                $this->broker($deal, $agent, "MLS still shows {$seen} for {$deal->street} (MLS #{$deal->mls_number}); it should show {$want['label']} since ".$due->format('m/d/Y').'.');
                $db->table('tx_mls_checks')->where('id', $check->id)->update(['escalated_at' => now()]);
                $counts['escalated']++;
            }
        }

        return $counts;
    }

    /**
     * Open MLS updates for the deal page.
     *
     * @return list<array{expected: string, seen: string|null, due_on: string, overdue: bool}>
     */
    public static function forDeal(Deal $deal): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_mls_checks')->where('deal_id', $deal->id)->whereNull('resolved_at')->get()
            ->map(fn ($c): array => ['expected' => (string) $c->expected, 'seen' => $c->seen !== null ? (string) $c->seen : null, 'due_on' => substr((string) $c->due_on, 0, 10),
                'overdue' => substr((string) $c->due_on, 0, 10) < CarbonImmutable::today()->toDateString()])->all());
    }

    private function broker(Deal $deal, ?User $agent, string $message): void
    {
        $brokers = User::query()->whereIn('kind', [UserKind::Owner, UserKind::Staff])->where('status', UserStatus::Active)->get()
            ->filter(fn (User $u): bool => $u->isKind(UserKind::Owner) || $u->can('transactions.review'));
        Notification::send($brokers, new BrokerAlert($deal->street, $agent->name ?? 'An agent', $message, $deal->ulid));
        app(AuditLogger::class)->record('transactions.mls.overdue', $deal, metadata: ['message' => $message], severity: 'warning', label: $deal->street);
    }
}
