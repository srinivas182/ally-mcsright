<?php

declare(strict_types=1);

namespace Modules\Listings\Idx;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Billing\BrokeragePayments;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * IDX7: what MLS boards cost a brokerage. The package includes one board (the dearest one it has, so the brokerage
 * always pays the least); each further board adds its monthly fee, added to the brokerage's invoice on the 1st with
 * the other extras. A board removed before the 1st is not charged for that month. Defaults for HQ to review.
 */
final class BoardFees
{
    public const INCLUDED = 1;

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * The fees for a set of boards: which one is included and what the others add each month.
     *
     * @param  list<array{name: string, fee_cents: int, ulid?: string}>  $boards
     * @return array{included: string|null, included_ulid: string|null, extra_cents: int, extras: list<array{name: string, fee_cents: int, ulid?: string}>}
     */
    public static function split(array $boards): array
    {
        usort($boards, fn (array $a, array $b): int => $b['fee_cents'] <=> $a['fee_cents']);
        $included = array_slice($boards, 0, self::INCLUDED);
        $extras = array_slice($boards, self::INCLUDED);

        return ['included' => $included[0]['name'] ?? null, 'included_ulid' => $included[0]['ulid'] ?? null, 'extra_cents' => array_sum(array_column($extras, 'fee_cents')), 'extras' => $extras];
    }

    /**
     * This brokerage's boards that are not cancelled, with their fees.
     *
     * @return array{included: string|null, included_ulid: string|null, extra_cents: int, extras: list<array{name: string, fee_cents: int, ulid?: string}>}
     */
    public function current(): array
    {
        $rows = DB::connection(Connections::control())->table('idx_subscriptions')->join('idx_boards', 'idx_boards.id', '=', 'idx_subscriptions.board_id')
            ->where('idx_subscriptions.tenant_id', $this->tenants->id())->where('idx_subscriptions.status', '!=', 'cancelled')
            ->orderBy('idx_subscriptions.id')->get(['idx_boards.name', 'idx_subscriptions.fee_cents', 'idx_subscriptions.ulid']);

        return self::split(array_values($rows->map(fn (object $r): array => ['name' => (string) $r->name, 'fee_cents' => (int) ($r->fee_cents ?? 0), 'ulid' => (string) $r->ulid])->all()));
    }

    /** On the 1st: add this month's extra boards to the brokerage's invoice, once. Returns the cents billed. */
    public function bill(?CarbonImmutable $month = null): int
    {
        $month ??= CarbonImmutable::now()->startOfMonth();
        $customer = app(BrokeragePayments::class)->current()['customer'] ?? null;
        $key = 'idx-boards:'.$this->tenants->id().':'.$month->format('Y-m');
        $events = DB::connection(Connections::control())->table('billing_gateway_events');
        if ($customer === null || $events->clone()->where('id', $key)->exists()) {
            return 0;
        }
        $total = 0;
        foreach ($this->current()['extras'] as $extra) {
            if ($extra['fee_cents'] > 0) {
                app(PaymentGateway::class)->addInvoiceItem($customer, $extra['fee_cents'], 'MLS board: '.$extra['name'].', '.$month->format('F Y'));
                $total += $extra['fee_cents'];
            }
        }
        $events->clone()->insert(['id' => $key, 'type' => 'idx_boards_run', 'processed_at' => now()]);

        return $total;
    }
}
