<?php

declare(strict_types=1);

namespace Modules\Billing\Credits;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\PaymentSettings;
use Modules\Core\Access\Can;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Buying RightAlly credits (specs/billing/04). Owners and staff buy packs for the brokerage pool; agents buy for
 * themselves when the brokerage allows it. Payment is a one-off checkout on RightAlly's payment account; credits are
 * added when the payment provider confirms it, and whatever is left 12 months later expires.
 */
final class CreditPurchases
{
    public const MONTHS_VALID = 12;

    public function __construct(private readonly TenantContext $tenants, private readonly PaymentGateway $gateway, private readonly PaymentSettings $settings,
        private readonly CreditLedger $ledger) {}

    /**
     * The packs this person may buy.
     *
     * @return list<array{ulid: string, name: string, credits: int, price_cents: int}>
     */
    public function packsFor(User $user): array
    {
        $manager = self::isManager($user);
        if (! $manager && ! $this->ledger->policy()['agents_can_buy']) {
            return [];
        }

        return array_values(DB::connection(Connections::control())->table('credit_packs')->where('active', true)
            ->whereIn('audience', $manager ? ['brokerage', 'both'] : ['agent', 'both'])->orderBy('sort')->orderBy('price_cents')->get()
            ->map(fn (object $p): array => ['ulid' => (string) $p->ulid, 'name' => (string) $p->name, 'credits' => (int) $p->credits, 'price_cents' => (int) $p->price_cents])->all());
    }

    /** Starts a purchase and returns the payment page to send the buyer to. */
    public function start(User $buyer, string $packUlid, string $returnBase): string
    {
        $pack = collect($this->packsFor($buyer))->firstWhere('ulid', $packUlid)
            ?? throw ValidationException::withMessages(['pack' => 'Choose one of the credit packs on this page.']);
        if (! $this->gateway->ready()) {
            throw ValidationException::withMessages(['pack' => 'Buying credits is not available yet. Please contact RightAlly.']);
        }
        $forPool = self::isManager($buyer);
        $ulid = (string) Str::ulid();
        $packId = (int) DB::connection(Connections::control())->table('credit_packs')->where('ulid', $packUlid)->value('id');
        DB::connection(Connections::cell())->table('credit_purchases')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'account_user_id' => $forPool ? null : $buyer->id,
            'buyer_id' => $buyer->id, 'pack_id' => $packId, 'pack_name' => $pack['name'], 'credits' => $pack['credits'], 'price_cents' => $pack['price_cents'],
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $description = 'RightAlly credits: '.$pack['name'].' ('.number_format($pack['credits']).' credits)'.($forPool ? ' for '.$this->tenants->current()->name : '');

        return $this->gateway->oneTimeCheckout($buyer->email, $description, $pack['price_cents'],
            $returnBase.'/settings/credits?purchase='.$ulid.'&checkout=success', $returnBase.'/settings/credits?checkout=cancelled',
            ['kind' => 'credit_pack', 'tenant_id' => (string) $this->tenants->id(), 'purchase' => $ulid], $this->settings->automaticTax(),
            app(AutoTopUp::class)->customer($forPool ? null : $buyer->id), saveCard: true);
    }

    /** One click: buys a pack on the card saved at an earlier checkout. */
    public function buyWithSavedCard(User $buyer, string $packUlid): string
    {
        collect($this->packsFor($buyer))->firstWhere('ulid', $packUlid) ?? throw ValidationException::withMessages(['pack' => 'Choose one of the credit packs on this page.']);
        $packId = (int) DB::connection(Connections::control())->table('credit_packs')->where('ulid', $packUlid)->value('id');

        return app(AutoTopUp::class)->charge(self::isManager($buyer) ? null : $buyer->id, $packId, 'saved', $buyer);
    }

    /**
     * The payment provider confirmed payment: add the credits (once).
     *
     * @param  array<string, mixed>  $session
     */
    public function paid(string $purchaseUlid, array $session): void
    {
        $db = DB::connection(Connections::cell());
        $p = $db->table('credit_purchases')->where('tenant_id', $this->tenants->id())->where('ulid', $purchaseUlid)->first();
        $customer = (string) ($session['customer'] ?? '');
        if ($p !== null && $customer !== '' && $p->source === 'checkout') {
            app(AutoTopUp::class)->rememberCard($p->account_user_id !== null ? (int) $p->account_user_id : null, $customer);
        }
        if ($p === null || ! in_array($p->status, ['pending', 'failed'], true)) {
            return; // a late success for a top-up first reported as failed still adds the credits
        }
        $now = CarbonImmutable::now();
        $claimed = $db->table('credit_purchases')->where('id', $p->id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'paid', 'paid_at' => $now,
            'expires_at' => $now->addMonthsNoOverflow(self::MONTHS_VALID), 'remaining' => (int) $p->credits,
            'gateway_session' => mb_substr((string) ($session['id'] ?? ''), 0, 120), 'gateway_payment' => mb_substr((string) ($session['payment_intent'] ?? ''), 0, 120),
            'total_cents' => (int) ($session['amount_total'] ?? $p->price_cents), 'tax_cents' => (int) ($session['total_details']['amount_tax'] ?? 0), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        $buyer = User::query()->find($p->buyer_id);
        $this->ledger->add($p->account_user_id !== null ? (int) $p->account_user_id : null, (int) $p->credits, 'purchase', 'Bought '.$p->pack_name,
            $buyer instanceof User ? $buyer : null, 'purchase:'.$purchaseUlid);
    }

    /** A saved-card payment the provider reports as failed after all (it was still processing). */
    public function failed(string $purchaseUlid, string $error): void
    {
        $db = DB::connection(Connections::cell());
        $p = $db->table('credit_purchases')->where('tenant_id', $this->tenants->id())->where('ulid', $purchaseUlid)->where('status', 'pending')->where('source', '!=', 'checkout')->first();
        if ($p === null) {
            return;
        }
        $db->table('credit_purchases')->where('id', $p->id)->update(['status' => 'failed', 'updated_at' => now()]);
        app(AutoTopUp::class)->failed($p->account_user_id !== null ? (int) $p->account_user_id : null, $error);
    }

    /** A refund from the payment provider: takes back what is left of the pack, in proportion to the amount refunded. */
    public function refunded(string $purchaseUlid, int $refundedCents): void
    {
        $db = DB::connection(Connections::cell());
        $p = $db->table('credit_purchases')->where('tenant_id', $this->tenants->id())->where('ulid', $purchaseUlid)->where('status', 'paid')->first();
        if ($p === null) {
            return;
        }
        $paid = max(1, (int) ($p->total_cents ?: $p->price_cents));
        $share = (int) ceil((int) $p->credits * min($refundedCents, $paid) / $paid);
        $take = min($share, (int) $p->remaining);
        $balance = $this->ledger->balanceOf($p->account_user_id !== null ? (int) $p->account_user_id : null);
        $take = min($take, max(0, $balance));
        if ($take > 0) {
            $this->ledger->add($p->account_user_id !== null ? (int) $p->account_user_id : null, -$take, 'adjust', 'Refund of '.$p->pack_name, null, 'refund-purchase:'.$purchaseUlid);
        }
        $db->table('credit_purchases')->where('id', $p->id)->update(['status' => 'refunded', 'remaining' => 0, 'refunded_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('billing.credits.purchase_refunded', metadata: ['purchase' => $purchaseUlid, 'refunded_cents' => $refundedCents, 'credits_removed' => $take],
            severity: 'notice');
    }

    /**
     * Purchases for the credits page: managers see all of the brokerage's; agents their own.
     *
     * @return list<array{ulid: string, pack: string, credits: int, price_cents: int, total_cents: int, status: string, paid_at: string|null,
     *     expires_at: string|null, remaining: int, for: string, buyer: string}>
     */
    public function list(User $viewer, int $limit = 50): array
    {
        $q = DB::connection(Connections::cell())->table('credit_purchases')->where('tenant_id', $this->tenants->id())->whereIn('status', ['paid', 'refunded']);
        if (! self::isManager($viewer)) {
            $q->where('buyer_id', $viewer->id);
        }
        $rows = $q->orderByDesc('id')->limit($limit)->get();
        $names = User::query()->whereIn('id', $rows->pluck('buyer_id'))->get()->keyBy('id');

        return array_values($rows->map(fn (object $p): array => ['ulid' => (string) $p->ulid, 'pack' => (string) $p->pack_name, 'credits' => (int) $p->credits,
            'price_cents' => (int) $p->price_cents, 'total_cents' => (int) ($p->total_cents ?: $p->price_cents), 'status' => (string) $p->status,
            'paid_at' => $p->paid_at !== null ? (string) $p->paid_at : null, 'expires_at' => $p->expires_at !== null ? (string) $p->expires_at : null,
            'remaining' => (int) $p->remaining, 'for' => $p->account_user_id === null ? 'Brokerage' : 'Personal',
            'buyer' => $names->get($p->buyer_id)->name ?? 'Former user'])->all());
    }

    /**
     * One receipt (the buyer, or an owner or staff member).
     *
     * @return array{ulid: string, pack: string, credits: int, price_cents: int, tax_cents: int, total_cents: int, status: string, paid_at: string,
     *     expires_at: string, for: string, buyer: string, buyer_email: string, brokerage: string, payment_ref: string}
     */
    public function receipt(User $viewer, string $ulid): array
    {
        $p = DB::connection(Connections::cell())->table('credit_purchases')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->whereIn('status', ['paid', 'refunded'])->first();
        abort_if($p === null || (! self::isManager($viewer) && (int) $p->buyer_id !== $viewer->id), 404);
        $buyer = User::query()->find($p->buyer_id);

        return ['ulid' => (string) $p->ulid, 'pack' => (string) $p->pack_name, 'credits' => (int) $p->credits, 'price_cents' => (int) $p->price_cents,
            'tax_cents' => (int) $p->tax_cents, 'total_cents' => (int) ($p->total_cents ?: $p->price_cents), 'status' => (string) $p->status,
            'paid_at' => (string) $p->paid_at, 'expires_at' => (string) $p->expires_at, 'for' => $p->account_user_id === null ? 'Brokerage credits' : 'Personal credits',
            'buyer' => $buyer instanceof User ? $buyer->name : 'Former user', 'buyer_email' => $buyer instanceof User ? $buyer->email : '',
            'brokerage' => $this->tenants->current()->name, 'payment_ref' => (string) ($p->gateway_payment ?: $p->gateway_session)];
    }

    private static function isManager(User $user): bool
    {
        return Can::manage($user, 'billing.credits.manage');
    }
}
