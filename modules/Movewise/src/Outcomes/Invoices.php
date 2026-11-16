<?php

declare(strict_types=1);

namespace Modules\Movewise\Outcomes;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections as ControlDb;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Connection;
use Modules\Movewise\Models\Partner;
use Modules\Movewise\Models\State;
use Modules\Movewise\Notifications\IntroductionClosed;

/**
 * Referral invoices and partner settlements (specs/movewise/00 §9, 06). A share-at-close referral is paid broker to
 * broker: the state's managing broker partner invoices the agent's brokerage. Movewise records the invoice, reminds,
 * and settles HQ's share with each partner monthly. No money moves through RightAlly here.
 */
final class Invoices
{
    public const DUE_DAYS = 30;

    public function raise(Connection $c, int $commissionCents): ?object
    {
        $agent = Agent::query()->findOrFail($c->agent_id);
        $partner = State::query()->findOrFail($agent->state)->partner;
        if ($partner === null || $c->share_pct === null) {
            report(new \RuntimeException('Movewise: no partner or share for referral invoice on '.$c->ulid));

            return null;
        }
        $db = DB::connection(ControlDb::control())->table('movewise_invoices');
        if ($db->where('connection_id', $c->id)->exists()) {
            return $db->where('connection_id', $c->id)->first();
        }
        $amount = (int) round($commissionCents * $c->share_pct / 100);
        $id = $db->insertGetId(['ulid' => (string) Str::ulid(), 'number' => 'MW-'.CarbonImmutable::now()->format('ymd').'-'.Str::upper(Str::random(4)), 'connection_id' => $c->id,
            'tenant_id' => $c->tenant_id, 'partner_id' => $partner->id, 'commission_cents' => $commissionCents, 'share_pct' => $c->share_pct, 'amount_cents' => $amount,
            'status' => 'issued', 'due_on' => CarbonImmutable::now()->addDays(self::DUE_DAYS)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $inv = DB::connection(ControlDb::control())->table('movewise_invoices')->where('id', $id)->first() ?? throw new \RuntimeException('Invoice not saved');
        $tenant = Tenant::query()->findOrFail($c->tenant_id);
        app(TenantContext::class)->runAs($tenant, function () use ($inv, $partner, $tenant): void {
            $host = $tenant->primaryDomain()?->host;
            foreach (User::query()->where('kind', UserKind::Owner)->get() as $owner) {
                $owner->notify(new IntroductionClosed('Movewise referral invoice '.$inv->number, 'A Movewise referral closed. '.$partner->name.' (licence '.$partner->licence_number
                    .') invoices '.$tenant->name.' $'.number_format($inv->amount_cents / 100, 2).' ('.$inv->share_pct.'% of $'.number_format($inv->commission_cents / 100, 2)
                    .'), due '.$inv->due_on.', broker to broker. Contact '.($partner->email ?? $partner->phone ?? 'the partner').' for payment details.',
                    ($host !== null ? 'https://'.$host : rtrim((string) config('app.url'), '/')).'/movewise'));
            }
        });
        app(AuditLogger::class)->record('movewise.invoice.raised', $c, metadata: ['number' => $inv->number, 'amount_cents' => $amount], tenantId: $c->tenant_id);

        return $inv;
    }

    public function mark(string $ulid, string $status, User $hq, ?string $note): void
    {
        abort_unless(in_array($status, ['paid', 'void'], true), 422);
        $db = DB::connection(ControlDb::control())->table('movewise_invoices');
        $inv = $db->where('ulid', $ulid)->first() ?? abort(404);
        if ($inv->status !== 'issued') {
            throw ValidationException::withMessages(['invoice' => 'This invoice is already '.$inv->status.'.']);
        }
        if ($status === 'void' && trim((string) $note) === '') {
            throw ValidationException::withMessages(['invoice' => 'Say why the invoice is void.']);
        }
        DB::connection(ControlDb::control())->table('movewise_invoices')->where('id', $inv->id)->update(['status' => $status, 'paid_at' => $status === 'paid' ? now() : null,
            'note' => $note !== null ? mb_substr($note, 0, 300) : null, 'updated_at' => now()]);
        app(AuditLogger::class)->record('movewise.invoice.'.$status, metadata: ['invoice' => $inv->number, 'by' => $hq->id], tenantId: (int) $inv->tenant_id);
    }

    /** Monthly: what each partner collected in the month, and HQ's share of it. Re-running a month updates it until it is settled. */
    public function settle(string $period): int
    {
        $start = CarbonImmutable::parse($period.'-01')->startOfMonth();
        $n = 0;
        foreach (Partner::query()->get() as $p) {
            $paid = DB::connection(ControlDb::control())->table('movewise_invoices')->where('partner_id', $p->id)->where('status', 'paid')
                ->whereBetween('paid_at', [$start, $start->endOfMonth()]);
            $count = (clone $paid)->count();
            if ($count === 0) {
                continue;
            }
            $collected = (int) (clone $paid)->sum('amount_cents');
            $db = DB::connection(ControlDb::control())->table('movewise_settlements');
            if ($db->where('partner_id', $p->id)->where('period', $period)->where('status', 'settled')->exists()) {
                continue;
            }
            $db->updateOrInsert(['partner_id' => $p->id, 'period' => $period], ['invoices' => $count, 'collected_cents' => $collected, 'hq_pct' => $p->hq_share_pct,
                'hq_cents' => (int) round($collected * $p->hq_share_pct / 100), 'status' => 'open', 'updated_at' => now(), 'created_at' => now()]);
            $n++;
        }

        return $n;
    }
}
